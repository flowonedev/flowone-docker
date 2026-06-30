# Statisztikai Adatgyűjtő Rendszer -- Projekt Áttekintés

---

## 1. Összefoglaló

A rendszer célja a jelenlegi Excel-alapú negyedéves statisztikai adatgyűjtés kiváltása egy modern, webes alkalmazással. A cégek böngészőn keresztül, egy Excel-szerű táblázatban adják meg adataikat, amelyeket ellenőrzés és jóváhagyás után a rendszer automatikusan összesít.

**Elérhetőség:** https://statisztika.asvanyvizek.hu

**Felhasználók:**
- Tagvállalatok / Cégek (adatszolgáltatók)
- Szövetség (adatok ellenőrzése, javítása, jóváhagyása, teljes rendszerkezelés)

A rendszer a Magyar Ásványvíz, Gyümölcslé és Üdítőital Szövetség számára készül.

---

## 2. Mit vált ki a rendszer?

Jelenleg a cégek egyenként Excel fájlokat töltenek ki (1.xls, 2.xls, ...), amelyeket egy összesítő Excel (_Egyesitett_v8.xls) olvas be és összegez. Ez a folyamat:

- kézi munkát igényel
- hibalehetőségeket rejt
- nehezen követhető, ki mikor mit módosított
- nem biztosít automatikus ellenőrzést

Az új rendszer mindezt kiváltja:

- a cégek online, webes felületen adnak meg adatokat
- az adatbevitel azonnal mentődik (automatikus mentés)
- a rendszer automatikusan összesít és számol
- minden módosítás naplózásra kerül (ki, mikor, mit változtatott)
- beépített jóváhagyási folyamat (beküldés, ellenőrzés, jóváhagyás)
- az adatok biztonságosan, adatbázisban tárolódnak

---

## 3. Felhasználói szerepkörök

### Szövetség (Adminisztrátor)
- Teljes hozzáférés a rendszer minden funkciójához
- Felhasználók kezelése (létrehozás, módosítás, törlés)
- Cégek kezelése (hozzáadás, módosítás, inaktiválás)
- Időszakok (negyedévek) kezelése, megnyitása és lezárása (kézi vagy automatikus határidővel)
- Termékcsoportok, csomagolástípusok, méretek konfigurálása
- Alapértelmezett import értékek beállítása cégenként
- Minden cég adatainak megtekintése, szerkesztése és exportálása -- beleértve a cégenkénti bontást
- Bármely cég adatainak közvetlen módosítása (hibák javítása)
- Adatok jóváhagyása, elutasítása
- Összesített statisztikák és jelentések készítése
- Publikálás a nyilvános felületre

### Ellenőrző
- Minden cég beküldött adatainak megtekintése
- Cégek adatainak közvetlen szerkesztése (hibák javítása jóváhagyás előtt)
- Összehasonlítás az előző negyedévvel
- Adatok jóváhagyása vagy elutasítása (indoklással)
- Összesített statisztikák és jelentések készítése, megtekintése
- NEM férhet hozzá: felhasználók kezelése, cégek kezelése, rendszerkonfiguráció (termékcsoportok, méretek, időszakok)

### Tagvállalat (Ügyfél)
- Bejelentkezés a saját cég fiókjába
- Adatbevitel az Excel-szerű táblázatban
- XML vagy CSV fájl feltöltése az adatok importálásához (letölthető sablon alapján)
- Automatizált adatfeltöltés saját szerverről (API kulcs alapján)
- Adatok beküldése ellenőrzésre
- Saját cég összesítéseinek megtekintése
- Iparági összesített adatok megtekintése (anonim, cégenkénti bontás nélkül)
- NEM láthatja más cégek egyedi adatait

---

## 4. Az adatbeviteli felület

### 4.1 Fő adattáblázat (Termékcsoport szerint)

A fő adatbeviteli felület egy Excel-szerű táblázat, amely megegyezik a jelenlegi Excel struktúrájával.

**Sorok:** Minden sor egy termék-csomagolás kombináció. Például:
- Szénsavas ásványvíz - Üveg - egyutas
- Szénsavas ásványvíz - Üveg - visszaváltható
- Szénsavas ásványvíz - Műanyag - egyutas
- stb.

**Oszlopok:** Minden oszlop egy-egy kiszerelési méret:
- 0,1 liter, 0,2 liter, 0,25 liter, 0,33 liter, 0,5 liter, 0,7 liter, 0,75 liter, 0,9 liter, 1 liter, 1,25 liter, 1,5 liter, 1,75 liter, 2 liter, 2,25 liter, 2,5 liter, 3 liter, 5 liter, 10 liter, 11 liter, 19 liter

**Értékek:** A cégek darabszámot (db) adnak meg. A literben kifejezett értékeket a rendszer automatikusan számolja.

**Három adatszekció oszloponként:**
- Hazai termelés
- Saját import (admin által előre beállított alapértékekkel)
- Export

**Fülrendszer:** A 8 termékcsoport külön-külön füleken jelenik meg:
1. I. Vizek
2. II. Ízesített ásványvíz (italok)
3. III. Szénsavas üdítőitalok
4. IV. Gyümölcslevek
5. V. Jeges teák
6. VI. Sport- és energiaitalok
7. VII. Szörpök
8. VIII. Citromlé

**Összesítő sorok:** A rendszer automatikusan számítja az al- és főösszegeket (piros, nem szerkeszthető sorok), pontosan úgy, ahogy a jelenlegi Excelben megjelennek.

### 4.2 Ízek szerinti bontás

Külön táblázat, ahol a cégek az alábbi ízkategóriák szerint bontják meg az adatokat:

Cola, Narancs, Citrom, Tonic, Alma, Szőlő, Őszibarack, Sárgabarack, Körte, Málna, Paradicsom, Áfonya, Egyéb hazai gyümölcs, Egyéb trópusi gyümölcs, Multivitamin, Egyéb kevert gyümölcs

Az íz szerinti bontás az alábbi termékcsoportokra vonatkozik:
- Ízesített ásványvíz és Szénsavas üdítőitalok
- Gyümölcslevek (Juice, Nektár, Ital)
- Jeges teák és Szörpök

Oszlopok: Belföld, Export, Összesen (1000 literben).

### 4.3 Cukortartalom szerinti bontás

A cégek megadják az adataikat az alábbi kategóriák szerint:
- Kizárólag cukorral ízesítve (ideértve az izocukrot is)
- Cukrot és édesítőszert tartalmaz
- Kizárólag édesítőszert tartalmaz
- Sem cukrot, sem édesítőszert nem tartalmaz

### 4.4 Kalóriatartalom

A cégek megadják az átlagos kalóriatartalmat (kcal/100ml) termékcsoport-csoportonként.

---

## 5. Adat importálás (XML és CSV)

A tagvállalatok nem csak kézzel adhatják meg az adatokat a webes felületen, hanem fájl feltöltéssel vagy automatizált API-n keresztül is importálhatják azokat.

### 5.1 XML importálás (elsődleges)

Az XML az elsődleges importálási formátum, amely kompatibilis a SAP, ERP és egyéb vállalatirányítási rendszerekkel:

- A rendszer letölthető XML sablont biztosít az aktuális negyedév struktúrájával
- A cég kitölti a sablont a saját rendszeréből származó adatokkal
- A kitöltött XML fájlt feltölti a rendszerbe
- A rendszer validálja a struktúrát és automatikusan kitölti az adatbeviteli táblázatot
- Hibás formátum vagy hiányzó mezők esetén részletes hibaüzenet jelenik meg

### 5.2 CSV importálás (másodlagos)

Azoknak a cégeknek, amelyek egyszerűbb rendszert használnak:

- Letölthető CSV sablon az aktuális negyedév oszlopaival és soraival
- Kitöltés után feltöltés a rendszerbe
- A rendszer automatikusan beolvassa és kitölti a táblázatot
- A feltöltés után a cég ellenőrizheti és módosíthatja az adatokat

### 5.3 Automatizált API (gépi adatfeltöltés)

Azok a tagvállalatok, amelyek szeretnék a folyamatot teljesen automatizálni:

- Az adminisztrátor cégenként egyedi API kulcsot generál
- A cég saját szervere automatikusan küldi az adatokat XML formátumban a rendszer API végpontjára
- Nincs szükség kézi bejelentkezésre vagy fájl feltöltésre
- Az adatok ugyanúgy a piszkozat táblába kerülnek, a cég felületen ellenőrizheti és beküldheti
- A rendszer naplózza az összes API-n keresztül érkezett feltöltést

---

## 6. Automatikus mentés

Az adatbevitel során a rendszer folyamatosan, automatikusan menti az adatokat:

- Minden szerkesztés után rövid várakozás (kb. fél másodperc), majd automatikus mentés
- A mentés állapota látható a felületen (mentés folyamatban / mentve / hiba)
- Ha az internetkapcsolat megszakad, az adatok a böngészőben helyben tárolódnak
- Újratöltéskor a rendszer visszaállítja a legutóbbi állapotot
- Sikertelen mentés esetén automatikus újrapróbálkozás

---

## 7. Jóváhagyási folyamat

Az adatbeküldés és jóváhagyás lépései:

```
Piszkozat → Beküldve → Jóváhagyva → Lezárva → Publikálva
```

**Piszkozat:** A cég szabadon szerkesztheti az adatokat. Automatikus mentés aktív.

**Beküldve:** A cég elküldi az adatokat ellenőrzésre. Ettől kezdve a cég nem módosíthatja az adatokat. Az ellenőrző látja a beküldött adatokat.

**Jóváhagyva:** Az ellenőrző elfogadta az adatokat. Az adatok átkerülnek a végleges táblába, amely a továbbiakban nem módosítható.

**Javítva az ellenőrző által:** Ha az ellenőrző kisebb hibát talál, közvetlenül javíthatja a cég adatait a jóváhagyás előtt.

**Elutasítva:** Ha az ellenőrző nagyobb hibát talál, visszaküldi a cégnek indoklással. A cég újra szerkesztheti és ismét beküldheti.

**Lezárva / Publikálva:** Az adatok véglegesek és nyilvánosan elérhetők.

### Verziókövetés

Minden beküldés egy új verziót hoz létre. Ha egy cég módosítás után újra beküldi az adatokat, az ellenőrző látja, mi változott a korábbi és az új verzió között.

### Kereszt-ellenőrzés beküldéskor

A rendszer a beküldés előtt automatikusan ellenőrzi:
- Az ízek szerinti bontás összege egyezik-e a fő táblázat összegével
- A cukortartalom összege egyezik-e az ízek összegével
- Minden kötelező adatlap ki van-e töltve
- Jelentős eltérés az előző negyedévhez képest (30%-nál nagyobb változás figyelmeztetést kap)

---

## 8. Összesítések és jelentések

A rendszer automatikusan előállítja az alábbi összesítéseket (ezek pontosan megfelelnek a jelenlegi Egyesitett Excel tartalmának):

### 8.1 Összesített termelési adatok
- Termékcsoportonként
- Hazai termelés / Saját import / Hazai + Saját import / Export / Összesen
- Értékek literben

### 8.2 Összesítés kiszerelési méret szerint
- Méretenként (0,1L-tól 19L-ig)
- Üveg egyutas db és 1000 liter
- Üveg visszaváltható db és 1000 liter
- Műanyag egyutas db és 1000 liter
- Műanyag visszaváltható db és 1000 liter

### 8.3 Ízek szerinti összesítés
- Összes cég adatainak összegzése ízenként és termékcsoportonként

### 8.4 Cukortartalom összesítés
- Összes cég adatainak összegzése cukortartalom-kategóriánként

### 8.5 Kalória összesítés
- Átlagos kalóriatartalom termékcsoport-csoportonként
- Millió liter x átlagos kalória számítás

---

## 9. Diagramok és infografikák

Az összesített adatokból a rendszer automatikusan, dinamikusan generál vizuálisan látványos diagramokat és infografikákat. Ezek interaktívak: az egérrel való rámutatáskor részletes adatok jelennek meg, és kattintással tovább lehet bontani az adatokat.

### 9.1 Elérhető diagram típusok

**Kör- és fánkdiagramok:**
- Piaci részesedés cégenként
- Csomagolástípusok eloszlása
- Cukortartalom szerinti megoszlás
- Íz szerinti megoszlás

**Oszlopdiagramok:**
- Termelési mennyiség termékcsoportonként
- Összehasonlítás forgalmi típusonként (hazai / saját import / export)
- Kiszerelési méret szerinti eloszlás

**Halmozott oszlopdiagramok:**
- Termelés csomagolástípus szerint, termékcsoportonként bontva
- Forgalmi típusok arányai termékcsoportonként

**Vonaldiagramok:**
- Negyedéves trendek termékcsoportonként
- Negyedéves trendek cégenként
- Szezonális változások nyomon követése

**Fa-térkép (treemap):**
- Teljes piaci összetétel termékcsoportok és alkategóriák szerint

### 9.2 Ki mit lát

**Tagvállalat (Ügyfél):**
- Saját cég adatainak diagramjai
- Saját részesedés az iparági összesítésben (más cégek adatai anonim)
- Saját negyedéves trendek

**Ellenőrző:**
- Minden cég adatainak diagramjai
- Cégek összehasonlítása egymás mellett
- Teljes iparági áttekintés

**Szövetség (Adminisztrátor):**
- Minden, amit az ellenőrző lát
- Rendszerszintű statisztikák
- Publikálás a nyilvános felületre

**Nyilvános felület (bejelentkezés nélkül):**
- Kiválasztott publikált összesítések és diagramok

### 9.3 Testreszabhatóság

A diagramok szűrhetők és konfigurálhatók:
- Időszak kiválasztása (melyik negyedév)
- Termékcsoport kiválasztása
- Forgalmi típus szűrése (hazai / import / export)
- Több negyedév összehasonlítása egymás mellett

### 9.4 Exportálás

A diagramok és jelentések exportálhatók:
- **PNG kép:** Bármely diagram letölthető képként (prezentációkhoz, emailekhez)
- **PDF jelentés:** Teljes összesítő jelentés generálása diagramokkal és táblázatokkal együtt, nyomtatásra kész formátumban

---

## 10. Konfiguráció kezelése

Az adminisztrátor a rendszer felületén kezeli az összes beállítást:

- **Cégek:** Új cég hozzáadása, meglévő módosítása, inaktiválása
- **Időszakok:** Új negyedév megnyitása, lezárása, publikálása
- **Termékcsoportok:** Új termékcsoport vagy alkategória hozzáadása
- **Csomagolástípusok:** Üveg, műanyag, fémdoboz, karton, italautomata kezelése
- **Méretek:** Új kiszerelési méret hozzáadása (pl. 0,15L)
- **Import alapértékek:** Cégenként előre beállítható Saját import értékek

### Konfiguráció-pillanatkép

Amikor az adminisztrátor megnyit egy új negyedévet, a rendszer „pillanatképet" készít az aktuális konfigurációról. Ezáltal:
- Egy negyedév adatbeviteli táblázata mindig az adott időszakhoz rögzített konfigurációt használja
- Ha később új méretet adunk hozzá, az csak a következő negyedévben jelenik meg
- A korábbi negyedévek táblázatai nem változnak visszamenőlegesen

---

## 11. Biztonság

### Bejelentkezés és hozzáférés
- Minden felhasználó egyedi email + jelszó kombinációval jelentkezik be
- Kétfaktoros hitelesítés (2FA) -- opcionálisan bekapcsolható, telefonos alkalmazáson alapuló kód
- Jelszavak titkosítva tárolódnak (bcrypt)
- Automatikus kijelentkezés inaktivitás után

### Munkamenet-kezelés
- Biztonságos token-alapú hitelesítés (JWT)
- Token automatikus megújítása minden használatkor
- Aktív munkamenetek listája (a felhasználó látja, hol van bejelentkezve)
- Lopott token esetén azonnali visszavonási lehetőség

### Védelmi mechanizmusok
- Bejelentkezési próbálkozások korlátozása (túl sok sikertelen kísérlet után blokkolás)
- API kérések számának korlátozása (túlterhelés elleni védelem)
- Érzékeny adatok (jelszavak, tokenek) automatikus eltávolítása a naplófájlokból
- Minden bemenet szerver-oldali ellenőrzése (SQL injection, XSS védelem)

### Naplózás
- Minden adatmódosítás naplózásra kerül:
  - Ki módosított
  - Mikor
  - Mi volt a régi érték
  - Mi az új érték
- A napló nem törölhető és nem módosítható

---

## 12. Email értesítések

A rendszer automatikus email értesítéseket küld a tagvállalatoknak:

- **Publikáláskor:** Amikor az adminisztrátor publikálja egy negyedév összesített adatait, minden érintett tagvállalat automatikus értesítő emailt kap
- **Határidő közeledésekor:** Emlékeztető email a beküldési határidő előtt (konfigurálható: pl. 7 nappal és 1 nappal előtte)
- **Elutasításkor:** Ha az ellenőrző visszaküldi a cég adatait, a cég automatikus értesítést kap az indoklással

Az email küldéshez a rendszer SMTP kapcsolatot használ, amelyet az adminisztrátor konfigurál.

---

## 13. Automatikus időszak-lezárás

Az adminisztrátor beállíthat egy határidőt minden negyedévhez:

- A megadott dátum elérkezésekor a rendszer automatikusan lezárja az adatbeviteli időszakot
- A lezárás előtt emlékeztető emailt küld a cégeknek
- A lezárás után a cégek nem módosíthatják és nem küldhetik be az adataikat
- Az adminisztrátor bármikor felülírhatja az automatikus lezárást (korábbi lezárás vagy határidő hosszabbítás)

---

## 14. Háromszintű adatláthatóság

A rendszer három különböző szinten teszi elérhetővé az adatokat:

### Szövetség (belső, adminisztrátori szint)
- Teljes hozzáférés minden adathoz, beleértve a cégenkénti bontást
- Cégek összehasonlítása
- Részletes statisztikák és exportok

### Tagi szint (bejelentkezett tagvállalatok)
- Összesített iparági adatok megtekintése
- Saját cég adatainak részletes megtekintése
- Más cégek egyedi adatai NEM láthatók (anonim összesítés)
- Védett felület, bejelentkezés szükséges

### Nyilvános felület (bejelentkezés nélkül)
- Kiválasztott, publikált összesített statisztikák böngészőből megtekinthetők
- Nem tartalmaz cégenkénti bontást
- Vizuális diagramok és alapvető összefoglalók
- Az adminisztrátor választja ki, mely adatok kerülnek a nyilvános felületre

---

## 15. Történelmi adatok importálása

A rendszernek képesnek kell lennie a korábbi évek adatainak befogadására:

- 2019-től 2025-ig terjedő negyedéves adatok importálása a meglévő Excel fájlokból
- Az importált adatok ugyanolyan szerkezetben tárolódnak, mint az új adatok
- A történelmi adatok lehetővé teszik a hosszú távú trendek elemzését és összehasonlítását
- Az import egyszeri, adminisztrátor által végzett művelet

---

## 16. Cégek listája

A rendszerben jelenleg az alábbi cégek szerepelnek:

1. Medaqua Kft.
2. Hungarospa Hajdúszoboszlói Zrt.
3. Aqua Vivien Kft.
4. Magyarvíz Kft.
5. MÜF Kft
6. Szentkirályi Kft
7. Aqua Lorenzo Kft
8. Red Bull Hungária Kft.
9. Coca Cola HBC Magyarország
10. Rauch Hungária Kft.
11. Egyéb
12. Fonte Viva Kft.
13. Márka Üdítőgyártó Kft.
14. AVE Ásványvíz Gyártó és Forgalmazó Kft.
15. Sió-Eckes Kft.
16. Pölöskei Italgyártó Zrt.

Új cégek az adminisztrációs felületen bármikor hozzáadhatók kódfejlesztés nélkül.

---

## 17. Termékcsoportok

### I. Vizek
- Szénsavas ásványvíz (üveg/műanyag, egyutas/visszaváltható)
- Enyhén szénsavas ásványvíz
- Szénsavmentes ásványvíz
- Szénsavas forrásvíz
- Szénsavmentes forrásvíz
- Gyógyvíz
- Dúsított vízalapú ital
- Ivóvíz

### II. Ízesített ásványvíz (italok)

### III. Szénsavas üdítőitalok
(post-mix is, fogyasztási hígításban)

### IV. Gyümölcslevek
- Juice (100%-os gyümölcs-/zöldségtartalom)
- Nektárok (50-99%)
- Szénsavmentes üdítőitalok:
  - 25% gyümölcstartalom és felette
  - 10-24% gyümölcstartalom
  - 9% gyümölcstartalom és alatta

### V. Jeges teák

### VI. Sport- és energiaitalok

### VII. Szörpök
- Szörpök gyümölcsből (visszahígítás nélkül)
- Egyéb szörpök (visszahígítás nélkül)

### VIII. Citromlé

---

## 18. Csomagolástípusok

| # | Típus | Megjegyzés |
|---|-------|-----------|
| 1 | Üveg - egyutas | |
| 2 | Üveg - visszaváltható | |
| 3 | Műanyag - egyutas | |
| 4 | Műanyag - visszaváltható | |
| 5 | Fémdoboz | Csak III-VIII. termékcsoportoknál |
| 6 | Karton | Csak III-VIII. termékcsoportoknál |
| 7 | Italautomata (fogy.-i hígításban) | Csak III-VIII. termékcsoportoknál |

Az I. Vizek termékcsoport csak az 1-4. csomagolástípust használja.
A többi termékcsoport (III-VIII.) mind a 7 típust.

---

## 19. Megvalósítás ütemezése

### 1. fázis: Alaprendszer
- Bejelentkezés, felhasználókezelés, biztonság
- Alapvető rendszerszerkezet

### 2. fázis: Konfiguráció és adatszerkezet
- Teljes adatbázis felépítése
- Adminisztrációs felület (cégek, időszakok, termékcsoportok, méretek kezelése)
- Kétfaktoros hitelesítés (2FA)
- Konfiguráció-pillanatkép rendszer

### 3. fázis: Adatbevitel
- Excel-szerű adatbeviteli táblázat
- XML importálás (elsődleges) és CSV importálás (másodlagos)
- Automatizált API végpont és API kulcs rendszer
- Automatikus mentés
- Saját import alapértékek
- Adatellenőrzés

### 4. fázis: Jóváhagyási folyamat
- Beküldés, jóváhagyás, elutasítás
- Kereszt-ellenőrzés
- Ellenőrzői felület összehasonlítással
- Verziókövetés

### 5. fázis: Kiegészítő adatok
- Ízek szerinti bontás
- Cukortartalom szerinti bontás
- Kalóriatartalom adatok

### 6. fázis: Összesítések, diagramok és jelentések
- Összesített termelési adatok
- Összesítés kiszerelési méret szerint
- Íz és cukortartalom összesítések
- Kalória összesítések
- Interaktív diagramok és infografikák (kör, oszlop, vonal, treemap)
- PNG/PDF exportálás
- Nyomtatásra kész nézetek

### 7. fázis: Értesítések, nyilvános felület, kiegészítések
- Email értesítések (publikálás, határidő emlékeztető, elutasítás)
- Automatikus időszak-lezárás
- Nyilvános felület (bejelentkezés nélküli összesített adatok)
- Háromszintű adatláthatóság (szövetség / tag / nyilvános)
- CSV exportálás
- Negyedévek összehasonlítása

### 8. fázis: Történelmi adatok
- 2019-2025 közötti negyedéves adatok importálása a meglévő Excel fájlokból
- Adatok ellenőrzése és összeegyeztetése

---

## 20. Adatbázis áttekintés

Az alábbiakban a rendszer teljes adatszerkezete látható. Minden tábla egy-egy adatcsoportot képvisel, amelyek együtt alkotják a rendszer alapját.

### 20.1 Felhasználók és biztonság

**Felhasználók (users)**
Tárolja az összes felhasználó adatait: email cím, titkosított jelszó, név, szerepkör (adminisztrátor / ellenőrző / ügyfél), és az ügyfél esetében a hozzárendelt cég. Minden felhasználónak egyedi email címe van.

**Munkamenetek (user_sessions)**
Nyilvántartja az aktív bejelentkezéseket: melyik felhasználó, melyik eszközről, mikor lépett be utoljára. Lehetővé teszi az aktív munkamenetek megtekintését és a gyanús munkamenetek visszavonását.

**Frissítő tokenek (refresh_tokens)**
A biztonságos bejelentkezés technikai háttere. Minden bejelentkezéskor új token generálódik, amelyet a rendszer automatikusan megújít.

**Bejelentkezési kísérletek (login_attempts)**
Naplózza a sikeres és sikertelen bejelentkezési próbálkozásokat. Túl sok sikertelen kísérlet után a rendszer ideiglenesen blokkolja a hozzáférést.

---

### 20.2 Cégek és időszakok

**Cégek (companies)**
A rendszerben nyilvántartott adatszolgáltató cégek listája. Tartalmazza a cég nevét, sorrendjét és állapotát (aktív / inaktív).

**Időszakok (periods)**
A negyedéves adatgyűjtési időszakok nyilvántartása. Minden időszak tartalmazza az évet, negyedévet, állapotát (piszkozat / nyitott / lezárt / publikált), a hozzá rögzített konfiguráció-pillanatképet, valamint a beküldési határidőt (automatikus lezáráshoz).

---

### 20.3 Konfiguráció (rendszerbeállítások)

Ezek a táblák határozzák meg, hogy az adatbeviteli táblázat hogyan néz ki: milyen sorok, oszlopok és opciók jelennek meg.

**Termékcsoportok (product_groups)**
A 8 fő termékcsoport (I. Vizek, II. Ízesített ásványvíz, ..., VIII. Citromlé). Tartalmazza a csoport kódját (I, II, III...), nevét és sorrendjét.

**Alkategóriák (sub_categories)**
A termékcsoportokon belüli bontás. Például az „I. Vizek" csoporton belül: Szénsavas ásványvíz, Enyhén szénsavas ásványvíz, Szénsavmentes ásványvíz, stb. Minden alkategória egy termékcsoporthoz tartozik.

**Csomagolástípusok (packaging_types)**
A 7 lehetséges csomagolási forma: Üveg egyutas, Üveg visszaváltható, Műanyag egyutas, Műanyag visszaváltható, Fémdoboz, Karton, Italautomata.

**Alkategória-csomagolás kapcsolat (sub_category_packaging)**
Meghatározza, hogy melyik alkategóriához mely csomagolástípusok tartoznak. Például a Vizek alkategóriái csak 4 csomagolástípust használnak, míg a Szénsavas üdítőitalok mind a 7-et.

**Kiszerelési méretek (sizes)**
A 22 lehetséges kiszerelési méret: 0,1 litertől 19 literig. Ezek alkotják az adatbeviteli táblázat oszlopait.

**Forgalmi típusok (flow_types)**
A 3 adatbeviteli szekció: Hazai termelés, Saját import, Export.

**Konfiguráció-pillanatképek (config_snapshots)**
Amikor egy új negyedév megnyílik, a rendszer „lefényképezi" az aktuális konfigurációt. Ezáltal a negyedév táblázata nem változik visszamenőlegesen, ha később új méretet vagy terméket adnak hozzá.

---

### 20.4 Adatbevitel

**Piszkozat adatok (entries_draft)**
A cégek által bevitt nyers adatok tárolása. Minden sor egy egyedi kombináció: cég + időszak + alkategória + csomagolástípus + méret + forgalmi típus = darabszám. Szabadon szerkeszthető, amíg a cég be nem küldi ellenőrzésre.

**Végleges adatok (entries_final)**
A jóváhagyott, végleges adatok. Ugyanolyan szerkezetű, mint a piszkozat tábla, de csak jóváhagyás után kerülnek ide az adatok. Nem módosítható.

**Adatkészlet állapota (dataset_states)**
Nyilvántartja, hogy egy adott cég egy adott negyedévre milyen állapotban van: piszkozat, beküldve, jóváhagyva, lezárva. Tartalmazza a verziószámot is (hányadik beküldés).

**Import alapértékek (import_defaults)**
Az adminisztrátor által cégenként előre beállított „Saját import" értékek. Amikor egy cég megnyitja a táblázatot, ezek az értékek automatikusan kitöltődnek, de a cég felülírhatja őket.

---

### 20.5 Kiegészítő adatok

**Ízek (flavors)**
Az ízkategóriák listája: Cola, Narancs, Citrom, Tonic, Alma, Szőlő, Őszibarack, stb. (16 íz).

**Íz-kategória csoportok (flavor_categories)**
Az íz szerinti bontás oszlopcsoportjai: Ízesített ásványvíz, Szénsavas üdítőitalok, Juice, Nektár, Ital, Jeges tea, Szörp.

**Cukortartalom típusok (sugar_types)**
A 4 cukortartalom-kategória: Csak cukor, Cukor + édesítőszer, Csak édesítőszer, Egyik sem.

**Íz szerinti adatok (flavor_entries_draft / flavor_entries_final)**
A cégek íz szerinti bontásban megadott adatai: cég + időszak + íz + kategóriacsoport + forgalmi típus = érték (1000 liter).

**Cukortartalom adatok (sugar_entries_draft / sugar_entries_final)**
A cégek cukortartalom szerinti bontásban megadott adatai.

**Kalóriatartalom adatok (calorie_entries_draft / calorie_entries_final)**
A cégek által megadott átlagos kalóriatartalom (kcal/100ml) termékcsoport-csoportonként.

---

### 20.6 Naplózás

**Változásnapló (audit_logs)**
A rendszer minden adatmódosítást automatikusan rögzít. Tartalmazza: ki módosított, mikor, melyik tábla melyik rekordját, mi volt a régi érték és mi az új. A napló nem törölhető és nem módosítható -- ez biztosítja a teljes visszakövethetőséget.

---

### 20.7 API és értesítések

**API kulcsok (api_keys)**
Az automatizált adatfeltöltéshez használt kulcsok. Minden kulcs egy céghez tartozik, az adminisztrátor generálja. A cég saját szervere ezzel a kulccsal küldheti az adatokat XML formátumban.

**Email értesítések naplója (email_notifications)**
Az összes kiküldött értesítő email nyilvántartása: kinek küldtük, mikor, milyen típusú értesítés (publikálás, emlékeztető, elutasítás), és sikeres volt-e a kézbesítés.

---

### 20.8 Adatkapcsolatok áttekintése

Az alábbi ábra szemlélteti, hogyan kapcsolódnak egymáshoz a fő adattáblák:

```
Cég ──────────┐
              ├──→ Adatbevitel (piszkozat / végleges)
Időszak ──────┤        ↑          ↑          ↑          ↑
              │   Alkategória  Csomagolás   Méret   Forgalom
              │        ↑
              │   Termékcsoport
              │
              ├──→ Adatkészlet állapota (piszkozat / beküldve / jóváhagyva)
              │
              └──→ Import alapértékek

Felhasználó ──→ Cég (ügyfél esetén)
           ──→ Változásnapló (minden művelet naplózva)

Konfiguráció-pillanatkép ──→ Időszak (rögzített konfiguráció)
```

Minden adat egy-egy céghez és időszakhoz kötődik. A konfiguráció (termékcsoportok, méretek, csomagolás) határozza meg az adatbeviteli táblázat szerkezetét. A rendszer ezt automatikusan állítja elő a beállítások alapján.

---

## 21. Technikai összefoglaló

| Jellemző | Megoldás |
|----------|---------|
| Elérhetőség | Böngészőből, bármilyen eszközről |
| Szerver | statisztika.asvanyvizek.hu |
| Nyelv | Teljes egészében magyar nyelvű felület |
| Adattárolás | Saját, dedikált adatbázis (MariaDB) |
| Biztonság | Titkosított jelszavak, JWT token, 2FA, naplózás |
| Automatikus mentés | Igen, folyamatos |
| Offline védelem | Böngésző helyi tárolás, ha az internet megszakad |
| Bővíthetőség | Új méretek, termékcsoportok, cégek kódfejlesztés nélkül hozzáadhatók |
| Visszakövethetőség | Teljes naplózás minden adatmódosításról |

---

*Dokumentum verziója: 1.3*
*Készült: 2026. április 2.*
