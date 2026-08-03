<!-- a11yfy readme 1.1.0 új blokkjai — hu (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — új bekezdés

Három működési mód: **automatikus** (minden új feltöltés javításra kerül), **kézi** (te választod ki, mit javíttatsz) és **igény szerinti** — amikor egy látogató egy még nem akadálymentes PDF-re kattint, egy akadálymentes párbeszédablakban kérheti az akadálymentes változatot, és e-mailt kap, amint elkészült. Így csak azokért a dokumentumokért fizetsz, amelyekre tényleg szükség van.

### External Services — módosult bullet

* **Amikor egy PDF-et javíttatsz** (kézileg, tömeges művelettel, az általad engedélyezett automatikus módban, vagy amikor egy látogató az általad engedélyezett igény szerinti módban akadálymentes változatot kér): maga a PDF-fájl, annak fájlneve és az API-kulcsod a `https://a11yfy.com/v1/jobs` címre kerül elküldésre. A feldolgozás az EU-ban történik. Az igénylő e-mail-címe kizárólag a saját oldaladon kerül tárolásra, és soha nem kerül elküldésre az a11yfy API felé.

### FAQ — új kérdés + válasz

= Hogyan működik az „Igény szerinti” mód? =

Amikor egy látogató olyan PDF-re mutató linkre kattint, amely nem felelt meg az akadálymentességi előellenőrzésen, egy akadálymentes párbeszédablak jelenik meg. A látogató megnyithatja a dokumentumot úgy, ahogy van, vagy az e-mail-címe megadásával igényelheti az akadálymentes változatot. A bővítmény dokumentumonként csak egyszer futtatja a javítást — függetlenül attól, hogy hányan kérik —, és e-mailben értesít minden igénylőt, amint az akadálymentes változat elérhető. Ezt követően ugyanazon a linken minden látogató az akadálymentes fájlt kapja, párbeszédablak nélkül. Az e-mail-címet kizárólag erre az egy értesítésre használjuk, soha nem küldjük el az a11yfy API-nak, és 30 nap után automatikusan törlődik. A párbeszédablak szövegei, az értesítő e-mail és a gombstílus az a11yfy → Beállítások menüpont alatt testreszabhatók.

### Changelog — 1.1.0

= 1.1.0 =
* Új „Igény szerinti” működési mód: amikor egy látogató egy még nem akadálymentes PDF-re kattint, egy akadálymentes párbeszédablak felajánlja a dokumentum változatlan megnyitását vagy az akadálymentes változat e-mailes igénylését. A javítás csak valós igény esetén fut le.
* A látogatók e-mailben értesülnek, amint az akadálymentes változat elkészült; a párbeszédablak minden szövege (beleértve az adatkezelési megjegyzést is) és az értesítő e-mail teljes mértékben testreszabható a Beállítások oldalon.
* A párbeszédablak a sablonod tipográfiáját örökli, blokksablonok esetén pedig a gombok automatikusan a sablon gombstílusát követik; alternatívaként egyedi kiemelőszínt is megadhatsz.
* Ha a kreditegyenleg nem fedezi a látogatói kérést, a kérés várakozni fog, az oldal tulajdonosa e-mailes figyelmeztetést kap, és a javítás automatikusan elindul, amint elegendő kredit áll rendelkezésre.
* Az igénylők e-mail-címei csak az értesítés elküldéséig kerülnek tárolásra (30 napos megőrzés), WordPress adatvédelmi exportálási/törlési támogatással.
