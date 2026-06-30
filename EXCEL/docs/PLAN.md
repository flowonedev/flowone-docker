# Statisztikai Adatgyűjtő Rendszer -- Megvalósítási Terv

---

## Áttekintés

| Jellemző | Érték |
|----------|-------|
| Alkalmazás | Önálló webes statisztikai adatgyűjtő rendszer |
| Domain | statisztika.asvanyvizek.hu |
| Megrendelő | Magyar Ásványvíz, Gyümölcslé és Üdítőital Szövetség |
| Backend | PHP 8.3 (egyedi Router, nincs framework) |
| Frontend | Vue 3 + Vite + Pinia + Tailwind CSS |
| Adatbázis | MariaDB (utf8mb4 / utf8mb4_hungarian_ci) |
| Hitelesítés | JWT (HS256) + refresh token rotáció |
| Ikonok | Google Material Symbols |
| Diagramok | ApexCharts |
| Nyelv | Teljes egészében magyar (UI, hibaüzenetek, API válaszok) |

---

## 1. fázis -- Alaprendszer és biztonság

### 1.1 Projekt váz

- `EXCEL/backend/` könyvtárstruktúra kialakítása (core, controllers, services, repositories, middleware, validators)
- `EXCEL/frontend/` Vue 3 + Vite projekt inicializálása (Pinia, Tailwind, Vue Router)
- `.env.example` sablon az összes szükséges kulccsal
- `index.php` belépési pont: .env betöltés, autoload, router indítás

### 1.2 Adatbázis -- auth táblák

```
users                  -- email, jelszó (bcrypt), név, szerepkör, company_id
user_sessions          -- aktív munkamenetek, eszköz, IP, utolsó aktivitás
refresh_tokens         -- JWT refresh token rotáció
login_attempts         -- brute-force védelem naplója
```

### 1.3 Hitelesítés (email app mintájára)

- Router + Request + Response osztályok adaptálása
- SessionService: JWT létrehozás / validálás (HS256)
- AuthController: login, logout, refresh, me végpontok
- AuthMiddleware: Bearer token ellenőrzés minden védett végponton
- RoleMiddleware: szerepkör-alapú hozzáférés-szabályozás
- PasswordService: bcrypt hash + verify

### 1.4 Biztonsági réteg (email app mintájára)

- RateLimitService: bejelentkezési próbálkozások korlátozása (IP + email alapon)
- RateLimitMiddleware: API kérések számának korlátozása
- SessionTrackingService: aktív munkamenetek nyilvántartása, visszavonás
- LogRedactor: érzékeny adatok (jelszó, token) eltávolítása naplókból

### 1.5 Frontend alap

- Login oldal (email + jelszó)
- Fetch wrapper (`client.js`): JWT injektálás, automatikus refresh 401-re, JSON kezelés
- AuthStore (Pinia): bejelentkezés állapota, felhasználó adatai, token kezelés
- AppShell: sidebar + topbar layout
- Route guard: védett oldalak átirányítása login-ra

### Eredmény

Működő bejelentkezés, szerepkör-ellenőrzés, biztonságos API alap.

---

## 2. fázis -- Konfiguráció, adatszerkezet, 2FA

### 2.1 Adatbázis -- konfiguráció táblák

```
companies              -- cégek (név, sorrend, aktív/inaktív)
periods                -- negyedévek (év, negyedév, állapot, határidő, snapshot_id)
product_groups         -- 8 termékcsoport (kód: I-VIII, név, sorrend)
sub_categories         -- alkategóriák (pl. Szénsavas ásványvíz) → product_group_id
packaging_types        -- 7 csomagolástípus (Üveg egyutas, Műanyag visszaváltható, stb.)
sub_category_packaging -- mely alkategóriához mely csomagolás tartozik
sizes                  -- 20+ kiszerelési méret (0,1L - 19L)
flow_types             -- 3 forgalmi típus (Hazai termelés, Saját import, Export)
config_snapshots       -- negyedévhez rögzített konfiguráció-pillanatkép (JSON)
```

### 2.2 Adminisztrációs felület

- Cégek kezelése: CRUD, aktiválás/inaktiválás, sorrend
- Időszakok kezelése: megnyitás, lezárás, publikálás, határidő beállítás
- Termékcsoportok / alkategóriák / csomagolástípusok kezelése
- Méretek kezelése: hozzáadás, sorrend, állapot (pending/approved/disabled)
- Felhasználók kezelése: CRUD, szerepkör hozzárendelés, céghez kötés

### 2.3 Konfiguráció-pillanatkép rendszer

- Negyedév megnyitásakor az aktuális konfiguráció JSON-ként mentődik
- Adatbeviteli táblázat mindig a negyedévhez rögzített pillanatképet használja
- Új méret/alkategória hozzáadása nem módosítja a korábbi negyedéveket

### 2.4 Kétfaktoros hitelesítés (2FA)

- TOTP (Time-based One-Time Password) implementáció
- QR kód generálás (titkos kulcs)
- Opcionális bekapcsolás felhasználónként
- Adaptálva az email app TwoFactorController mintájából

### Eredmény

Teljes konfigurációs rendszer, admin felület, 2FA.

---

## 3. fázis -- Adatbevitel és import

### 3.1 Adatbázis -- adatbeviteli táblák

```
entries_draft          -- piszkozat adatok (cég + időszak + alkategória + csomag + méret + forgalom = db)
entries_final          -- jóváhagyott végleges adatok (ugyanaz a struktúra, read-only)
dataset_states         -- cég + időszak állapota (draft/submitted/approved/locked/published) + verzió
import_defaults        -- admin által beállított "Saját import" alapértékek cégenként
```

### 3.2 Excel-szerű adatbeviteli táblázat

- DataGrid komponens: dinamikusan generált a konfiguráció-pillanatképből
- Fülrendszer: 8 termékcsoport = 8 fül
- Sorok: alkategória x csomagolás kombináció
- Oszlopok: méretek (0,1L - 19L) x 3 szekció (Hazai/Import/Export)
- Szerkeszthető cellák: csak levél csomópontok (leaf nodes)
- Összesítő sorok: automatikus számítás, nem szerkeszthető (piros)
- Liter számítás: `db * méret / 1000` (backend-en)

### 3.3 Automatikus mentés (autosave)

- Debounce: 400ms várakozás az utolsó szerkesztés után
- UPSERT viselkedés: meglévő bejegyzés frissítése vagy új létrehozása
- Mentés állapot jelzése: mentés folyamatban / mentve / hiba
- Böngésző helyi tárolás (localStorage): offline védelem
- Újratöltéskor visszaállítás
- Sikertelen mentés: automatikus újrapróbálkozás

### 3.4 Import alapértékek

- Admin felületen cégenként beállítható "Saját import" értékek
- Táblázat megnyitásakor automatikusan kitöltődnek
- A cég felülírhatja őket

### 3.5 XML importálás (elsődleges)

- Letölthető XML sablon generálás az aktuális negyedév struktúrájával
- Feltöltés + validálás (struktúra, hiányzó mezők, hibás értékek)
- Sikeres import → piszkozat tábla kitöltése
- Részletes hibaüzenet hibás formátum esetén
- SAP/ERP kompatibilis séma

### 3.6 CSV importálás (másodlagos)

- Letölthető CSV sablon generálás
- Feltöltés + feldolgozás
- Piszkozat táblába mentés
- Feltöltés utáni ellenőrzés és módosítás lehetősége

### 3.7 Automatizált API (gépi adatfeltöltés)

```
api_keys               -- cégenként egyedi API kulcs (admin generálja)
```

- `POST /api/import/xml` végpont (API kulcs hitelesítéssel)
- Adatok a piszkozat táblába kerülnek
- Naplózás: minden API-n érkező feltöltés rögzítve
- Rate limit az API kulcsos végpontra is

### Eredmény

Teljes adatbeviteli felület, XML/CSV import, automatizált API.

---

## 4. fázis -- Jóváhagyási folyamat

### 4.1 Munkafolyamat állapotok

```
Piszkozat → Beküldve → Jóváhagyva → Lezárva → Publikálva
```

- WorkflowService: állapotátmenetek kezelése
- Beküldés: cég zárolása az adott negyedévben
- Jóváhagyás: adatok átmásolása entries_draft → entries_final
- Elutasítás: visszaküldés indoklással, cég újra szerkeszthet
- Ellenőrző közvetlen szerkesztése: draft adatok javítása jóváhagyás előtt

### 4.2 Kereszt-ellenőrzés (beküldés előtt)

- Ízek bontás összege = fő táblázat összege
- Cukortartalom összege = ízek összege
- Minden kötelező adatlap kitöltöttség ellenőrzése
- Anomália-észlelés: >30% eltérés az előző negyedévtől → figyelmeztetés

### 4.3 Verziókövetés

- Minden beküldés új verziót hoz létre (dataset_states.version)
- Ellenőrző látja a korábbi és új verzió közötti különbségeket (diff nézet)
- Verzió-összehasonlítás felületen

### 4.4 Ellenőrzői felület

- Minden cég beküldött adatainak listája
- Részletes adatnézet per cég
- Összehasonlítás az előző negyedévvel
- Közvetlen szerkesztés lehetőség
- Jóváhagyás / Elutasítás (indoklással) gombok

### Eredmény

Teljes jóváhagyási munkafolyamat, verziókövetés, ellenőrzői felület.

---

## 5. fázis -- Kiegészítő adattáblák

### 5.1 Adatbázis -- kiegészítő táblák

```
flavors                -- 16 ízkategória (Cola, Narancs, Citrom, stb.)
flavor_categories      -- íz-bontás csoportjai (Ízesített ásványvíz, Juice, Nektár, stb.)
flavor_entries_draft   -- íz szerinti bontás piszkozat adatok
flavor_entries_final   -- íz szerinti bontás végleges adatok
sugar_types            -- 4 cukortartalom-kategória
sugar_entries_draft    -- cukortartalom piszkozat adatok
sugar_entries_final    -- cukortartalom végleges adatok
calorie_entries_draft  -- kalóriatartalom piszkozat adatok
calorie_entries_final  -- kalóriatartalom végleges adatok
```

### 5.2 Ízek szerinti bontás felület

- Külön táblázat: 16 íz x forgalmi típus (Belföld, Export, Összesen)
- Értékek 1000 literben
- Vonatkozó termékcsoportok: Ízesített ásványvíz, Szénsavas üdítőitalok, Gyümölcslevek, Jeges teák, Szörpök

### 5.3 Cukortartalom bontás felület

- 4 kategória: Csak cukor, Cukor + édesítőszer, Csak édesítőszer, Egyik sem

### 5.4 Kalóriatartalom felület

- Átlagos kcal/100ml termékcsoport-csoportonként
- Millió liter x átlagos kalória számítás

### Eredmény

Teljes kiegészítő adatrögzítés (ízek, cukor, kalória).

---

## 6. fázis -- Összesítések, diagramok, jelentések

### 6.1 Összesítések (backend számítás)

- Termelési adatok összesítése termékcsoportonként
- Összesítés kiszerelési méret szerint (db + 1000 liter)
- Ízek szerinti összesítés
- Cukortartalom összesítés
- Kalória összesítés
- Minden összesítés a `entries_final` táblából számolva

### 6.2 Interaktív diagramok (ApexCharts)

| Típus | Felhasználás |
|-------|-------------|
| Kör/fánk | Piaci részesedés, csomagolás eloszlás, cukor megoszlás |
| Oszlop | Termelés termékcsoportonként, forgalmi típus összehasonlítás |
| Halmozott oszlop | Csomagolás x termékcsoport, forgalmi arányok |
| Vonal | Negyedéves trendek, szezonális változások |
| Treemap | Piaci összetétel termékcsoportok és alkategóriák szerint |

### 6.3 Diagram láthatóság (háromszintű)

| Szint | Látható |
|-------|---------|
| Szövetség (admin) | Minden adat, cégenkénti bontás, összehasonlítás |
| Ellenőrző | Minden cég diagramjai, cégek összehasonlítása |
| Tagvállalat | Saját cég diagramjai, anonim iparági összesítés |
| Nyilvános | Kiválasztott publikált összesítések |

### 6.4 Szűrés és testreszabás

- Időszak kiválasztása
- Termékcsoport szűrés
- Forgalmi típus szűrés
- Több negyedév összehasonlítása

### 6.5 Exportálás

- PNG: bármely diagram letölthető képként
- PDF: teljes összesítő jelentés diagramokkal és táblázatokkal
- CSV: nyers adatok exportálása

### Eredmény

Teljes összesítő és riport rendszer, interaktív diagramok, export.

---

## 7. fázis -- Értesítések, nyilvános felület, kiegészítések

### 7.1 Email értesítések

```
email_notifications    -- kiküldött értesítések naplója
```

- SMTP konfiguráció .env-ben (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, MAIL_FROM)
- Értesítés típusok:
  - Publikáláskor: minden tagvállalat kap emailt
  - Határidő emlékeztető: konfigurálható (pl. 7 nap és 1 nap előtte)
  - Elutasításkor: érintett cég kap emailt az indoklással

### 7.2 Automatikus időszak-lezárás

- Határidő mező a `periods` táblában
- Cron-szerű ellenőrzés (minden API kérésnél vagy dedikált cron endpoint)
- Határidő elérkezésekor: automatikus lezárás
- Lezárás előtt emlékeztető email
- Admin bármikor felülírhatja (korábbi lezárás vagy hosszabbítás)

### 7.3 Nyilvános felület

- Bejelentkezés nélkül elérhető oldal
- Kiválasztott publikált összesítések és diagramok
- Nem tartalmaz cégenkénti bontást
- Admin választja ki, mely adatok kerülnek nyilvánosságra

### 7.4 Háromszintű adatláthatóság

| Szint | Hozzáférés |
|-------|-----------|
| Szövetség | Teljes adat, cégenkénti bontás, export |
| Tagok (bejelentkezve) | Összesített iparági adatok, saját cég részletei |
| Nyilvános | Publikált összesítések, diagramok |

### 7.5 Negyedévek összehasonlítása

- Két vagy több negyedév adatainak egymás melletti összehasonlítása
- Változás százalékban és abszolút értékben
- Trend kimutatás

### Eredmény

Email értesítések, automatikus lezárás, nyilvános felület, háromszintű hozzáférés.

---

## 8. fázis -- Történelmi adatok

### 8.1 Történelmi import

- 2019-2025 közötti negyedéves adatok importálása meglévő Excel fájlokból
- Admin által végzett egyszeri művelet
- Importált adatok közvetlenül az `entries_final` táblába kerülnek
- Konfigurációs pillanatkép létrehozása minden történelmi negyedévhez

### 8.2 Adatvalidáció

- Importált adatok konzisztencia-ellenőrzése
- Hiányzó értékek jelzése
- Összesítések összevetése az eredeti Excel összesítővel

### Eredmény

Teljes történelmi adatbázis 2019-től, lehetőség hosszú távú trendek elemzésére.

---

## Adatbázis séma áttekintés

### Auth és biztonság
| Tábla | Cél |
|-------|-----|
| users | Felhasználók (email, jelszó, szerepkör, company_id) |
| user_sessions | Aktív munkamenetek nyilvántartása |
| refresh_tokens | JWT refresh token rotáció |
| login_attempts | Bejelentkezési kísérletek naplója |

### Cégek és időszakok
| Tábla | Cél |
|-------|-----|
| companies | Adatszolgáltató cégek |
| periods | Negyedéves időszakok |

### Konfiguráció
| Tábla | Cél |
|-------|-----|
| product_groups | 8 fő termékcsoport |
| sub_categories | Alkategóriák (pl. Szénsavas ásványvíz) |
| packaging_types | 7 csomagolástípus |
| sub_category_packaging | Alkategória-csomagolás kapcsolat |
| sizes | 20+ kiszerelési méret |
| flow_types | 3 forgalmi típus |
| config_snapshots | Negyedévhez rögzített konfiguráció |

### Fő adatbevitel
| Tábla | Cél |
|-------|-----|
| entries_draft | Piszkozat adatok |
| entries_final | Végleges (jóváhagyott) adatok |
| dataset_states | Cég+időszak állapota és verziója |
| import_defaults | Admin által beállított "Saját import" alapértékek |

### Kiegészítő adatok
| Tábla | Cél |
|-------|-----|
| flavors | 16 ízkategória |
| flavor_categories | Íz-bontás csoportjai |
| flavor_entries_draft / final | Íz szerinti adatok |
| sugar_types | 4 cukortartalom-kategória |
| sugar_entries_draft / final | Cukortartalom adatok |
| calorie_entries_draft / final | Kalóriatartalom adatok |

### API és értesítések
| Tábla | Cél |
|-------|-----|
| api_keys | Automatizált API feltöltés kulcsok |
| email_notifications | Kiküldött értesítések naplója |

### Naplózás
| Tábla | Cél |
|-------|-----|
| audit_logs | Minden adatmódosítás naplója |

---

## Kulcsfontosságú technikai döntések

| Döntés | Megoldás | Indoklás |
|--------|----------|----------|
| HTTP kliens | Native fetch wrapper | Nincs Axios függőség, kisebb bundle |
| Jelszó hash | bcrypt (password_hash) | PHP natív, biztonságos |
| Token | JWT HS256 + refresh rotáció | Egyszerű, bevált minta az email appból |
| Diagramok | ApexCharts | Interaktív, sok diagram típus, PNG/PDF export |
| Konfig verziókezelés | JSON pillanatkép | Negyedév-specifikus, visszamenőleg nem változik |
| Kereszt-validáció | Backend, beküldéskor | Ízek/cukor összegek ellenőrzése a fő táblázattal |
| Autosave | Debounce 400ms + UPSERT | Gyors, de nem terheli túl a szervert |
| Import formátum | XML (elsődleges) + CSV | SAP/ERP kompatibilitás + egyszerű alternatíva |
| Automatizált API | API kulcs + XML push | Cégek saját szervere automatikusan küldhet adatot |

---

## Referencia fájlok az email app-ból

| Fájl | Mit adaptálunk |
|------|---------------|
| email/backend/src/Services/SessionService.php | JWT logika (create/validate) |
| email/backend/src/Controllers/AuthController.php | Login/logout/refresh flow |
| email/backend/src/Core/Router.php | HTTP routing minta |
| email/backend/routes.php | Route definíciók minta |
| email/backend/src/Controllers/TwoFactorController.php | 2FA implementáció |
| email/backend/src/Services/SessionTrackingService.php | Munkamenet nyilvántartás |
| email/backend/src/Services/RateLimitService.php | Brute-force védelem |
| email/backend/src/Middleware/RateLimitMiddleware.php | API rate limiting |
| email/backend/src/Helpers/LogRedactor.php | Érzékeny adatok szűrése naplókból |

---

*Dokumentum verziója: 2.0*
*Készült: 2026. április 2.*
