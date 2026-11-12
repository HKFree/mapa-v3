# Pokyny pro agenty

- Jde o veřejnou mapu pokrytí internetové sítě HKFree.org. Při úpravách dbej na to, aby mapa neprozrazovala zbytečné interní informace.
- Po každé dokončené změně vytvoř commit v tomto Git repozitáři. Nesouvisející změny uživatele nezahrnuj.
- Pokyny potřebné pro další agenty udržuj v tomto souboru `agents.md`.
- Přihlašovací údaje z lokálního `.env` neukládej do Gitu ani nevypisuj do výstupů.

## Lokální vývoj

- Kořen webu je `/root/mapa-v3`, vstupní stránka `index.php`.
- PHP vývojový server běží na `http://192.168.5.37:18080/`.
- Spuštění na pozadí: `setsid -f php -S 192.168.5.37:18080 -t /root/mapa-v3 /root/mapa-v3/router.php > /tmp/mapa-v3-php-18080.log 2>&1 < /dev/null`.
- Před opětovným spuštěním ověř, zda server již neposlouchá. Log je mimo repozitář v `/tmp/mapa-v3-php-18080.log`.
- Backend `proxy.php` vyžaduje Composer závislosti v `vendor/` a konfiguraci UserDB z prostředí nebo lokálního `.env`; závislosti nainstaluj pomocí `composer install`.

- Konfigurace: `USERDB_URL`, `USERDB_USER`, `USERDB_API_KEY`; proměnné procesu mají přednost před `.env`. Vzor je `.env.example`.
- Vývojový server vždy spouštěj s `router.php`, aby nevydával `.env` a další neveřejné soubory. Apache používá přiložený `.htaccess`; u jiného serveru nastav odpovídající zákaz přístupu.
- jQuery prozatím zachovej. Bezpečné skládání HTML je odložené na samostatnou úpravu.
- `proxy.php` vrací pouze veřejná pole: oblast má `email`, `aps` (jen `jmeno`, `gps`) a `admins` (jen `nick`). Pole vytvářej explicitně a seznamy přečísluj, aby neunikala interní ID ani v klíčích. Body bez GPS a oblasti bez zobrazitelných bodů vynechávej.
