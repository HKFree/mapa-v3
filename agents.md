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

- Konfigurace: `USERDB_URL`, `USERDB_USER`, `USERDB_API_KEY`, `MAPBOX_TOKEN`; proměnné procesu mají přednost před `.env`. Vzor je `.env.example`.
- Mapbox token je veřejný prohlížečový token. Nastavuj ho v `.env` nebo prostředí, ne ve zdrojovém kódu. `index.php` předává JavaScriptu pouze tento token přes bezpečně zakódovaný JSON v `#map-config`; údaje UserDB do HTML nepatří.
- Vývojový server vždy spouštěj s `router.php`, aby nevydával `.env` a další neveřejné soubory. Apache používá přiložený `.htaccess`; u jiného serveru nastav odpovídající zákaz přístupu.
- jQuery prozatím zachovej. Údaje z API v popiscích a popupech vkládej přes DOM (`textContent`, `createTextNode`), nikoli spojováním HTML řetězců. Odkazy sestavuj přes DOM s pevným schématem `mailto:`.
- Bílé zvýraznění ikon a popisků zobrazuj pouze na podkladu Mapbox Satellite pomocí třídy `satellite-map`. Stín ikony má sledovat neprůhledné pixely (`drop-shadow`), nikoli vyplňovat průhledné pozadí obrázku.
- URL ukládá pohled a podklad jako `#map=zoom/lat/lng&layer=streets` (podklady: `osm`, `streets`, `satellite`, `light`). Aktualizuj ji přes `replaceState`, aby posuny nezaplňovaly historii; parametry před použitím ověřuj.
- Zoom může být desetinný (`zoomSnap: 0.01`), což umožňuje těsný úvodní výřez. Zachovej desetinný zoom i při ukládání a obnovování URL. Odsazení výřezu počítá také s velikostí a ukotvením značek.
- Po prvním načtení bodů nastav výřez přes `fitBounds`, pouze pokud odkaz neobsahuje platný pohled a uživatel se mezitím nepohnul mapou. Zahrň všechny vytvořené značky s odsazením od okrajů. Při prázdných datech zachovej výchozí výřez; u jediného bodu omez přiblížení na zoom 16.
- `proxy.php` vrací pouze veřejná pole: oblast má `email`, `aps` (jen `jmeno`, `gps`) a `admins` (jen `nick`). Pole vytvářej explicitně a seznamy přečísluj, aby neunikala interní ID ani v klíčích. Body bez GPS a oblasti bez zobrazitelných bodů vynechávej.
