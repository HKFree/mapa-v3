#!/usr/bin/env bash
set -Eeuo pipefail

die() { printf '%s\n' "$*" >&2; exit 1; }
revision=${1:-}
project_dir=${2:-/opt/web-mapa}
[[ -z "$revision" || "$revision" =~ ^[0-9a-f]{40}$ ]] || die 'Expected a full Git commit SHA.'
for tool in git docker flock; do
  command -v "$tool" >/dev/null || die "Missing command: $tool"
done
cd "$project_dir"
[[ "$(git rev-parse --show-toplevel)" == "$(pwd -P)" ]] || die 'Deploy directory must be the repository root.'
exec 9>"$(git rev-parse --git-path mapa-deploy.lock)"
flock -n 9 || die 'Another deployment is running.'
[[ -z "$(git status --porcelain --untracked-files=normal)" ]] || die 'Repository has local changes; resolve them before deploying.'
[[ -f .env ]] || die 'Missing server .env.'
docker info >/dev/null

git fetch --no-tags origin '+refs/heads/master:refs/remotes/origin/master'
revision=${revision:-$(git rev-parse refs/remotes/origin/master)}
git cat-file -e "${revision}^{commit}"
if [[ "$revision" != "$(git rev-parse refs/remotes/origin/master)" ]]; then
  printf 'Skipping %s: master has moved to another commit.\n' "$revision"
  exit 0
fi

previous_revision=$(git rev-parse HEAD)
previous_branch=$(git symbolic-ref --quiet --short HEAD || true)
checked_out=false
activated=false
# Invoked indirectly by the EXIT trap.
# shellcheck disable=SC2329
cleanup() {
  status=$?
  if (( status != 0 )) && [[ "$checked_out" == true ]]; then
    if [[ "$activated" == false ]]; then
      printf 'Deployment stopped before activation; restoring checkout %s.\n' "$previous_revision" >&2
      git checkout --quiet "${previous_branch:-$previous_revision}" || true
    else
      printf 'Deployment failed during activation or HTTP check; inspect docker compose logs web. No automatic rollback was performed.\n' >&2
    fi
  fi
}
trap 'cleanup' EXIT
git checkout --quiet --detach "$revision"
checked_out=true

# Validate without printing configuration, which contains credentials.
docker compose config --quiet
image=$(docker compose config --images)
[[ -n "$image" && "$image" != *$'\n'* ]] || die 'Expected exactly one application image.'
docker compose pull web
image_revision=$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")
[[ "$image_revision" == "$revision" ]] || die 'Published image does not match the requested commit; deployment refused.'
docker compose run --rm --no-deps --entrypoint php web -r 'require "/var/www/html/bootstrap.php";'

# Failures after this point must not pretend that the old container was restored.
activated=true
docker compose up -d --no-build --no-deps --wait --wait-timeout 60 web
docker compose exec -T web php /usr/local/lib/mapa/healthcheck.php
printf 'Deployed %s; HTTP check passed.\n' "$revision"
