# Fountainbridge Allegro Sync

WordPress + WooCommerce plugin, amely a kiválasztott termékeket szinkronizálja
az **Allegro** piactérrel az Allegro hivatalos REST API-ján keresztül
(https://developer.allegro.pl).

## Mit tud?

- OAuth2 **Device Code Flow** hitelesítés (nincs szükség publikus redirect
  URI-ra, admin felületről kódbevitellel köthető össze a fiók).
- Termékenként bekapcsolható szinkron (checkbox a termék szerkesztő oldalon
  és a plugin saját "Termékek" listájában).
- Új Allegro ajánlat (offer) létrehozása draft állapotban, majd automatikus
  publikálás.
- Meglévő ajánlat ár- és készletfrissítése (PATCH), illetve teljes
  frissítése (PUT).
- Automatikus szinkron: termék mentésekor / készletváltozáskor (opcionális),
  valamint 15 percenkénti háttér (WP-Cron) job az összes kijelölt termékre.
- Manuális "Szinkronizálás most" gomb.
- Napló nézet a sikeres/hibás műveletekről.
- Ajánlat törlése az Allegro-ról egy kattintással.

## Telepítés

1. Töltsd fel a `fountainbridge-allegro-sync` mappát a
   `wp-content/plugins/` könyvtárba (vagy töltsd fel ZIP-ként a
   Beépülő modulok → Új hozzáadása → Feltöltés menüben).
2. Aktiváld a pluginot (WooCommerce szükséges hozzá).
3. Regisztrálj egy alkalmazást az Allegro fejlesztői konzoljában
   (https://apps.developer.allegro.pl), és másold a **Client ID** /
   **Client Secret** adatokat a plugin **Allegro Sync → Beállítások**
   oldalára.
4. Kattints az **"Összekötés az Allegro-val"** gombra, majd a megjelenő
   kódot add meg az Allegro oldalán a felhasználói fiókoddal bejelentkezve.
5. Add meg az ajánlat alapadatait: Allegro kategória ID, szállítási sablon
   ID stb. **A fiók összekötése után ezekhez a mezőkhöz kereső / lekérdező
   doboz jelenik meg** a Beállítások oldalon:
   - **Kategória ID**: írj be egy kulcsszót (pl. "szablon malarski"), a
     plugin lekérdezi az Allegro `matching-categories` végpontjét, és a
     találatra kattintva automatikusan kitölti a mezőt.
   - **Szállítási sablon / Garancia / Visszaküldési szabályzat ID**: egy
     gombbal lekérdezhető a saját Allegro fiókodban már létrehozott
     sablonok listája (`/sale/shipping-rates`, `/sale/warranties`,
     `/sale/return-policies`), és kiválasztással töltődik ki a mező -
     nem kell kézzel másolgatni az ID-kat.
6. A **Termékek** oldalon kapcsold be a szinkront azokhoz a termékekhez,
   amelyeket Allegro-n is szeretnél árulni, vagy indíts manuális
   szinkront a **Beállítások** oldalról.

## Fontos megjegyzések

- **Kategória-specifikus paraméterek**: az Allegro minden kategóriához
  kötelező paramétereket definiál (pl. márka, méret, szín). Ezeket a
  `fbas_offer_parameters` szűrőn keresztül lehet a saját `functions.php`-ból
  vagy egy site-specifikus pluginból hozzáadni:

  ```php
  add_filter( 'fbas_offer_parameters', function ( $parameters, $product ) {
      $parameters[] = array(
          'id'     => '11323', // pl. "Márka" paraméter ID az adott kategóriában
          'values' => array( $product->get_attribute( 'márka' ) ),
      );
      return $parameters;
  }, 10, 2 );
  ```

- Első körben mindenképp **sandbox** környezetben tesztelj
  (https://allegro.pl.allegrosandbox.pl), csak utána válts éles módra.
- A plugin a termék `_fbas_allegro_offer_id` post meta mezőben tárolja az
  Allegro ajánlat azonosítóját — ez köti össze a WooCommerce terméket az
  Allegro ajánlattal ismételt frissítéseknél.

## Fájlstruktúra

```
fountainbridge-allegro-sync/
├── fountainbridge-allegro-sync.php   # Fő plugin fájl
├── includes/
│   ├── class-fbas-settings.php       # Beállítások tárolása
│   ├── class-fbas-api-client.php     # Allegro OAuth2 + REST kliens
│   ├── class-fbas-product-mapper.php # WooCommerce -> Allegro payload
│   ├── class-fbas-sync.php           # Szinkron logika, cron
│   ├── class-fbas-admin.php          # Admin UI, AJAX végpontok
│   ├── class-fbas-logger.php         # Naplózás
│   └── views/                        # Admin oldal sablonok
└── assets/
    ├── admin.css
    └── admin.js
```
