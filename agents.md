# Pokyny pro agenty

- Jde o veřejnou mapu pokrytí internetové sítě HKFree.org. Při úpravách dbej na to, aby mapa neprozrazovala zbytečné interní informace.
- Po každé dokončené změně vytvoř commit v tomto Git repozitáři. Nesouvisející změny uživatele nezahrnuj.
- Pokyny potřebné pro další agenty udržuj v tomto souboru `agents.md`.
- Přihlašovací údaje z lokálního `config.php` neukládej do Gitu ani nevypisuj do výstupů.

## Lokální vývoj

- Kořen webu je `/root/mapa-v3`, vstupní stránka `index.php`.
- PHP vývojový server běží na `http://192.168.5.37:18080/`.
- Spuštění na pozadí: `setsid -f php -S 192.168.5.37:18080 -t /root/mapa-v3 > /tmp/mapa-v3-php-18080.log 2>&1 < /dev/null`.
- Před opětovným spuštěním ověř, zda server již neposlouchá. Log je mimo repozitář v `/tmp/mapa-v3-php-18080.log`.
- Backend `proxy.php` vyžaduje Composer závislosti v `vendor/` a lokální `config.php`; závislosti nainstaluj pomocí `composer install`.
