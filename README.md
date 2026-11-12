# HKFree.org mapa

Veřejná mapa bodů sítě. PHP proxy vybírá z UserDB jen údaje zobrazované na mapě. Zahrnuje pouze AP s `aktivni` rovným číslu `1` a vyplněnou GPS; oblasti bez takových bodů vynechává. Klient používá Leaflet a jQuery. Hlavní větev je **`master`**.

## Vývoj a serverové testy

```sh
composer install
cp .env.example .env
# Vyplňte USERDB_URL, USERDB_USER, USERDB_API_KEY a MAPBOX_TOKEN.
php -S 127.0.0.1:18080 -t . router.php
```

Vývojový server spouštějte s `router.php`, který nevydává neveřejné soubory. `.env` necommitujte. Proměnné procesu mají přednost před `.env`. Mapbox používá veřejný prohlížečový token; po předání do mapy je viditelný návštěvníkům.

```sh
composer test
composer test:deploy
```

První sada prověřuje serverové HTTP odpovědi a konfiguraci s lokální náhradou UserDB. Druhá používá dočasné Git repozitáře a falešný příkaz `docker`; žádný Docker daemon, obraz ani produkční server nespouští. Potřebuje PHP CLI, Git, Bash a `flock`. Testy nečtou pracovní `.env`, nepoužívají skutečné přihlašovací údaje a po sobě uklízejí. Prohlížečové testy nejsou součástí projektu.

Sestavení a ověření skutečného kontejneru zajišťuje GitHub Actions. Na tomto vývojovém serveru Docker nespouštějte ani obraz nesestavujte. Workflow po sestavení nastaví `TEST_IMAGE` a spustí `composer test:production`: test vytvoří izolovanou síť a dva dočasné kontejnery, prověří HTML, veřejné soubory, filtrování proxy, chyby UserDB, ochranu interních cest a healthcheck. Používá pouze falešné údaje a lokální náhradu API.

## Jak funguje nasazování

1. Push nebo pull request spustí kontroly syntaxe a serverové testy v PHP 8.4.
2. Jen push na `master` po úspěšných kontrolách sestaví obraz pro `linux/amd64`. Obraz používá PHP 8.4 s Apache; Composer závislosti pocházejí z `composer.lock`.
3. CI ověří skutečný obraz a teprve potom publikuje `ghcr.io/<owner>/<repo>:latest` a `sha-<celé SHA>`. V názvu obrazu jsou malá písmena.
4. Je-li zapnuto `DEPLOY_ENABLED=true`, CI se připojí přes SSH jako `deploy` do `/opt/web-mapa`, přepne checkout na otestovaný commit, stáhne hotový obraz a spustí jej. Produkční server nic nesestavuje.

Workflow stejné větve a samotný deploy jsou serializované. Skript odmítne lokální změny, souběžné spuštění a obraz s jinou revizí. Starý běh CI přeskočí, pokud se `master` posunul. Serverový `.env` se nikdy nepřepisuje. Před výměnou kontejneru se kontroluje konfigurace, potom Docker healthcheck a HTTP odpověď. Kontrola zdraví nevolá UserDB, takže jeho výpadek nezpůsobí nezdravý stav samotné mapové aplikace.

## 1. Přesun repozitáře na GitHub

Vytvořte prázdný repozitář, bez počátečního README a dalších souborů. Pokud `origin` stále ukazuje na dosavadní GitLab, lze jej zachovat pod jiným názvem:

```sh
git remote rename origin gitlab
git remote add origin git@github.com:OWNER/REPOSITORY.git
git push -u origin master
```

Nahraďte `OWNER/REPOSITORY`. Pokud už `origin` ukazuje na GitHub, přejmenování ani přidání neopakujte. Na GitHubu nastavte výchozí větev `master`. Autodeploy zatím nechte vypnutý. První push spustí kontroly, sestavení a publikování obrazu.

Po úspěšném jobu `image` najdete balíček v GitHub Packages. Pro veřejný obraz nastavte **Package settings → Change visibility → Public**. Produkční server pak nepotřebuje přihlášení do GHCR. U soukromého obrazu se jako `deploy` jednorázově přihlaste příkazem `docker login ghcr.io`, tokenem s oprávněním `read:packages` a přístupem k danému balíčku. Token nevkládejte do příkazu ani do repozitáře. CI publikuje pomocí svého `GITHUB_TOKEN`.

## 2. První instalace na produkční server

Server potřebuje Docker Engine, Docker Compose **v2.20 nebo novější** (kvůli `--wait-timeout`), Git, Bash, `flock` a externí Apache2 s HTTPS. Pipeline publikuje pouze `linux/amd64`; cílový server musí mít architekturu `x86_64`.

Správce připraví uživatele `deploy` s přístupem k Dockeru a adresář:

```sh
sudo install -d -o deploy -g deploy /opt/web-mapa
```

Následující příkazy spouštějte jako `deploy`. Pro soukromý GitHub repozitář nastavte tomuto uživateli samostatný read-only deploy key pro **server → GitHub**.

```sh
git clone --branch master git@github.com:OWNER/REPOSITORY.git /opt/web-mapa
cd /opt/web-mapa
cp .env.example .env
chmod 600 .env
```

Vyplňte `.env`:

| Proměnná | Význam |
| --- | --- |
| `USERDB_URL` | Kořen API, například `https://userdb.hkfree.org/userdb/api/`. |
| `USERDB_USER` | Uživatelské jméno pro UserDB. |
| `USERDB_API_KEY` | Přihlašovací klíč pro UserDB. |
| `MAPBOX_TOKEN` | Veřejný Mapbox token pro produkční mapu. |
| `WEB_IMAGE` | `ghcr.io/owner/repository:latest`, podle vašeho GitHub repozitáře. |
| `WEB_PORT` | Volný HTTP port na hostiteli, výchozí `8088`. |

Obraz se používá beze změn pro různá prostředí. Tajné údaje se předávají až při spuštění přes Compose; `.env` se do obrazu nekopíruje ani nemountuje. Projekt nepotřebuje databázi ani trvalý volume.

Po úspěšném CI spusťte:

```sh
docker compose config --quiet
docker compose pull web
docker compose up -d --no-build --wait --wait-timeout 60 web
docker compose ps
curl --fail --silent --show-error --output /dev/null http://127.0.0.1:8088/
curl --fail --silent --show-error --output /dev/null http://127.0.0.1:8088/proxy.php
```

Při jiném `WEB_PORT` změňte port v příkazech i v Apache. První HTTP kontrola ověří PHP a konfiguraci, druhá navíc skutečné spojení s UserDB. Nepřístupné UserDB se projeví HTTP 502 v proxy. Při změně `.env` již běžícího kontejneru použijte `docker compose up -d --no-build --force-recreate --wait --wait-timeout 60 web`.

## 3. Veřejná HTTPS adresa

Compose publikuje HTTP pouze na `127.0.0.1:${WEB_PORT}`. Předpokládá Apache2 přímo na stejném hostiteli. HTTPS a certifikát zůstávají mimo kontejner; v kontejneru běží samostatný Apache s PHP a omezenými veřejnými cestami.

Vnitřní konfigurace `deploy/apache-app.conf` explicitně mapuje `/icons/` do obrázků aplikace. Toto pravidlo zachovejte: výchozí Debian konfigurace Apache používá stejnou URL pro své systémové ikony.

Použijte vzor [`deploy/apache2.conf`](deploy/apache2.conf). Nahraďte `ServerName`, `X-Forwarded-Host`, cesty k certifikátu a případně port backendu. Existující přesměrování HTTP → HTTPS ponechte. Pokud už VirtualHost pro doménu existuje, zapracujte pravidla do něj.

Na Debianu/Ubuntu správce povolí moduly a načte nakonfigurovaný VirtualHost:

```sh
sudo a2enmod proxy proxy_http headers ssl
sudo a2ensite NAZEV-KONFIGURACE.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Ověřte veřejnou stránku i `/proxy.php` a zobrazení bodů. Vzor počítá s mapou v kořeni vlastní domény. Případnou podsložku je třeba nakonfigurovat v proxy, včetně přesměrování na URL s koncovým `/`.

## 4. Zapnutí automatického deploye

Připravte **jiný SSH klíč** pro spojení **GitHub Actions → server**. Jeho veřejnou část přidejte do `/home/deploy/.ssh/authorized_keys` na produkci. Soukromou část uložte pouze do GitHub Secrets. `.ssh` má mít práva `700`, `authorized_keys` práva `600`, obojí vlastněné uživatelem `deploy`.

Na GitHubu vytvořte **Settings → Environments → production** a nastavte:

| Druh | Název | Hodnota |
| --- | --- | --- |
| Variable | `DEPLOY_HOST` | SSH hostname nebo IPv4 adresa, dostupná z GitHub runneru; bez protokolu a portu. |
| Variable | `DEPLOY_PORT` | Volitelný SSH port, výchozí `22`. |
| Secret | `DEPLOY_SSH_KEY` | Celý soukromý klíč pro Actions → server. |
| Secret | `DEPLOY_KNOWN_HOSTS` | Ověřený veřejný SSH klíč serveru ve formátu `known_hosts`. |

Záznam `known_hosts` lze získat pomocí `ssh-keyscan -p PORT -t ed25519 HOST`. Jeho otisk porovnejte přes důvěryhodné spojení nebo konzoli s `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` na serveru. Pro nestandardní port má záznam tvar `[host]:port`. Workflow nepovoluje přijetí neověřeného klíče.

Uživatel `deploy` musí zvládat `git fetch origin master`, číst i měnit `/opt/web-mapa` a `.git/`, číst serverový `.env` a spouštět Docker bez interaktivního `sudo`.

Nakonec nastavte **repository variable** `DEPLOY_ENABLED=true` v **Settings → Secrets and variables → Actions → Variables**. Přepínač musí být na úrovni repozitáře, protože se vyhodnocuje před vstupem do environmentu. Další push na `master` po úspěšných kontrolách nasadí novou verzi. Hodnota `false` vypne jen deploy, nikoli testy a publikování.

## Ruční aktualizace, selhání a návrat

Po úspěšném CI lze jako `deploy` spustit:

```sh
cd /opt/web-mapa
bash deploy/deploy.sh
```

Skript načte aktuální `master` a ověří shodu revize obrazu. Checkout po úspěchu zůstává na konkrétním commitu v detached HEAD. Skript podporuje i první instalaci, pokud už existuje čistý klon a správný `.env`.

Před aktivací chyba obnoví původní checkout a dosavadní kontejner nechá běžet. Při výměně kontejneru může vzniknout krátký výpadek. Pokud selže spuštění nebo následná kontrola zdraví, skript oznámí chybu a **neprovádí automatický rollback**. Zkontrolujte:

```sh
docker compose ps
docker compose logs --tail=100 web
```

Pro návrat dočasně vypněte autodeploy, nastavte v `.env` `WEB_IMAGE=ghcr.io/owner/repository:sha-ZNAME_FUNKCNI_SHA` a použijte odpovídající konfiguraci:

```sh
git fetch origin master
git checkout --detach ZNAME_FUNKCNI_SHA
docker compose pull web
docker compose up -d --no-build --wait --wait-timeout 60 web
```

Při návratu k běžnému deployi obnovte `WEB_IMAGE=ghcr.io/owner/repository:latest` a nasaďte aktuální `master`. Skript při běžném nasazení tuto ani žádnou jinou hodnotu `.env` nemění. Zálohujte serverový `.env` a HTTPS konfiguraci; mapa nemá vlastní trvalá data.
