# WP Allegro Sync

WordPress + WooCommerce plugin, amely a kiválasztott termékeket szinkronizálja
az **Allegro** piactérrel az Allegro hivatalos REST API-ján keresztül
(https://developer.allegro.pl).

**Plugin mappa neve:** `WP-Allegro-Sync` (`wp-content/plugins/WP-Allegro-Sync`)

**Verziózás:** dátum alapú, `YYMMDD` formátum (pl. `260801` = 2026.08.01.).
Minden éles kiadásnál a `wp-allegro-sync.php` fejlécében és a
`FBAS_VERSION` konstansban egyaránt frissítendő.

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
  valamint 15 percenkénti háttér (WP-Cron) job, **kötegelve** (lásd
  "Teljesítmény / optimalizálás" lent).
- Manuális "Szinkronizálás most" gomb.
- Napló nézet a sikeres/hibás műveletekről.
- Ajánlat törlése az Allegro-ról egy kattintással.

## Telepítés

1. Töltsd fel a `WP-Allegro-Sync` mappát a
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

- **Ajánlat címe**: az Allegro maximum **75 karaktert** enged az ajánlat/
  termék címében (korábban 50 volt). Ha a WooCommerce termékneved ennél
  hosszabb, a plugin automatikusan, szóhatáron vágva rövidíti - nem kell
  kézzel figyelned rá. (Az Allegro emellett minimum 12 karaktert és 3 szót
  is megkövetel a címben - ha a terméknév ennél rövidebb, érdemes
  hosszabbra átnevezni a terméket.)

- **Nyelv**: a paraméter-nevek és listaértékek (pl. "Rodzaj", "Marka") a
  WordPress oldal nyelvi beállítása (`get_locale()`) szerint kerülnek
  lekérdezésre az Allegro API-tól (`Accept-Language` fejléc). Az Allegro
  jelenleg **csak lengyelt és angolt** támogat kategória-adatokhoz - ha a
  WP oldal nyelve ettől eltérő (pl. magyar), az Allegro automatikusan
  **angolra** esik vissza (ez az ő API-juk hivatalos viselkedése, nem a
  plugin korlátja). Magyar WP-nél tehát angol paraméter-neveket fogsz
  látni ("Type" / "Brand" stb.), nem lengyelt.

- **API végpont**: a plugin a jelenleg támogatott **`/sale/product-offers`**
  végpontot használja ajánlat létrehozásra/frissítésre. A régi
  `/sale/offers` végpontot az Allegro 2024 eleje óta teljesen letiltotta
  ajánlat írásra ("This resource is no longer supported..." hibát ad rá).
  Az új végpont előnye, hogy **egyetlen kéréssel** létrehozza ÉS azonnal
  publikálja is az ajánlatot (`publication.status: "ACTIVE"`), nincs
  többé szükség külön "publikálás" lépésre. Egy ajánlat eltávolítása
  (mivel a DELETE is le van tiltva) a `publication.status: "ENDED"`
  beállításával történik.

- **Képek**: az Allegro nem fogadja el a külső (pl. a WordPress oldaladon
  tárolt) kép URL-eket közvetlenül egy ajánlatban - "Invalid image URL.
  Image must be present on allegro server." hibát ad, ha megpróbálod. A
  plugin ezért **minden szinkron előtt automatikusan feltölti** a termék
  fő- és galériaképeit az Allegro saját képszerverére
  (`POST /sale/images`), és a visszakapott, már Allegro-n tárolt URL-eket
  használja az ajánlatban. A feltöltött kép URL-eket a plugin
  termékenként cache-eli (postmeta), így egy változatlan képet nem tölt
  fel újra minden szinkronnál - csak akkor, ha a termékkép megváltozik.
  **Legalább 1 kép kötelező** minden termékhez, különben a szinkron
  hibával leáll.

- **Kategória-specifikus paraméterek**: az Allegro minden kategóriához
  kötelező paramétereket definiál (pl. vonalkód/EAN, márka, anyag) -
  ezek nélkül az ajánlat létrehozása `422 Unprocessable entity` hibával
  elutasul. A plugin **Beállítások** oldalán, a "Kötelező
  kategória-paraméterek" kártyán ("Kötelező paraméterek lekérdezése" gomb)
  egyből listázhatod és ki is töltheted a beállított kategóriához tartozó
  összes paramétert (szótár/dictionary típusúaknál legördülő listával,
  egyébként szövegmezővel) - nem kell kézzel Postmant vagy fejlesztői
  tudást bevetni hozzá. Ez azokra az értékekre való, amik **minden
  termékre egyformán** vonatkoznak (pl. "Márka: Fountainbridge").

  Ha egy paraméter **termékenként eltérő** (pl. szín, méret, minta), azt
  a `fbas_offer_parameters` szűrőn keresztül lehet dinamikusan, a
  `WC_Product` objektum alapján beállítani a saját `functions.php`-ból
  vagy egy site-specifikus pluginból:
  ```php
  add_filter( 'fbas_offer_parameters', function ( $parameters, $product ) {
      $parameters[] = array(
          'id'     => '11323', // pl. "Szín" paraméter ID az adott kategóriában
          'values' => array( $product->get_attribute( 'szín' ) ),
      );
      return $parameters;
  }, 10, 2 );
  ```
  (A Beállítások oldalon mentett és a szűrőből jövő paraméterek
  összefésülődnek - a szűrő felülírhatja/kiegészítheti a mentett
  értékeket.)

  Az **ajánlat szintű** paraméterekhez (leggyakrabban "Állapot /
  Condition", pl. "Új") külön szűrő van, mivel ezek nem a termékhez,
  hanem magához az ajánlathoz tartoznak:
  ```php
  add_filter( 'fbas_offer_state_parameters', function ( $parameters, $product ) {
      $parameters[] = array(
          'id'        => '11323', // "Stan" / "Condition" paraméter ID
          'valuesIds' => array( '11323_1' ), // "Nowy" / "New" érték ID
      );
      return $parameters;
  }, 10, 2 );
  ```

- **Emberi nyelvű hibaüzenetek**: ha az Allegro egy "hiányzó kötelező
  paraméterek" típusú hibát ad vissza (nyers paraméter ID-k listájával),
  a plugin automatikusan lekérdezi és feloldja ezeket a tényleges
  paraméter-nevekre (pl. "Vonalkód (EAN) (ID: 224017)"), így a
  **Termékek** oldalon és a **Napló** oldalon is olvasható, mit kell
  pontosan kitölteni - nem csak a nyers ID-kat kell találgatni.

- Első körben mindenképp **sandbox** környezetben tesztelj
  (https://allegro.pl.allegrosandbox.pl), csak utána válts éles módra.
- A plugin a termék `_fbas_allegro_offer_id` post meta mezőben tárolja az
  Allegro ajánlat azonosítóját — ez köti össze a WooCommerce terméket az
  Allegro ajánlattal ismételt frissítéseknél.

## Teljesítmény / optimalizálás

- **Kötegelt (batch) szinkron**: a 15 percenkénti cron (és a "Szinkronizálás
  most" gomb) nem az összes kijelölt terméket dolgozza fel egyszerre, hanem
  a Beállítások oldalon megadott **köteg méret** (alapértelmezett: 20)
  szerinti darabszámban - ez elkerüli a nagyobb termékkatalógusnál
  jelentkező PHP/HTTP időtúllépést. A rotáció mindig a legrégebben
  szinkronizált (vagy még soha nem szinkronizált) termékeket dolgozza fel
  elsőként, így idővel minden kijelölt termék sorra kerül.
- **Kép-feltöltés cache**: egy adott termékkép csak egyszer töltődik fel az
  Allegro szerverére; a visszakapott URL-t a plugin postmeta-ban tárolja,
  és csak akkor tölti fel újra, ha a termékkép ténylegesen megváltozik.
- **Ár/készlet gyorsfrissítés**: ha a termékhez már létezik Allegro
  ajánlat, a készlet-/árváltozás egy kis `PATCH` kérést küld (nem építi
  újra és nem tölti fel újra a teljes ajánlatot és a képeket).
- **Beállítás-cache kérésen belül**: a `FBAS_Settings` osztály egyszer
  olvassa be és értelmezi a mentett beállításokat egy oldalbetöltésen /
  AJAX kérésen belül, nem minden egyes `get()` hívásnál.
- **Késleltetett eszközbetöltés**: az admin-felület csak a plugin saját
  admin oldalain és a termékszerkesztőn tölti be a CSS/JS fájlokat, máshol
  nem terheli a betöltést.

## Fájlstruktúra

```
WP-Allegro-Sync/
├── wp-allegro-sync.php   # Fő plugin fájl
├── includes/
│   ├── class-fbas-settings.php       # Beállítások tárolása
│   ├── class-fbas-api-client.php     # Allegro OAuth2 + REST kliens
│   ├── class-fbas-product-mapper.php # WooCommerce -> Allegro payload
│   ├── class-fbas-sync.php           # Szinkron logika, cron, batch
│   ├── class-fbas-admin.php          # Admin UI, AJAX végpontok
│   ├── class-fbas-logger.php         # Naplózás
│   └── views/                        # Admin oldal sablonok
└── assets/
    ├── admin.css
    └── admin.js
```
