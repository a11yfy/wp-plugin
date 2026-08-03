<!-- a11yfy readme 1.1.0 új blokkjai — ro (deepseek-lektorált fordítás, 2026-08-03) -->

### Descriere — paragraf nou

Three working modes: **automatic** (every new upload is fixed), **manual** (you pick what to fix), and **on demand** — visitors who click a not-yet-accessible PDF can request an accessible version in an accessible dialog and get an email when it is ready, so you only pay for documents people actually need.

**Traducere:**

Trei moduri de lucru: **automat** (fiecare document nou încărcat este remediat), **manual** (alegi tu ce să repari) și **la cerere** — vizitatorii care dau clic pe un PDF încă neaccesibil pot solicita o versiune accesibilă într-un dialog accesibil și primesc un email când aceasta este gata, astfel că plătești doar pentru documentele de care oamenii au cu adevărat nevoie.

---

### Servicii externe — bullet modificat

* **When you remediate a PDF** (manually, via bulk action, through the automatic mode you enabled, or when a visitor requests an accessible version in the on-demand mode you enabled): the PDF file itself, its file name, and your API key are sent to `https://a11yfy.com/v1/jobs`. Processing happens in the EU. A requester's email address is stored only on your site and is never sent to the a11yfy API.

**Traducere:**

* **Când remediezi un PDF** (manual, prin acțiune în masă, prin modul automat pe care l-ai activat sau atunci când un vizitator solicită o versiune accesibilă în modul la cerere pe care l-ai activat): fișierul PDF, numele său și cheia ta API sunt trimise la `https://a11yfy.com/v1/jobs`. Procesarea are loc în UE. Adresa de email a solicitantului este stocată doar pe site-ul tău și nu este trimisă niciodată către API-ul a11yfy.

---

### Întrebări frecvente — întrebare + răspuns nou

= How does the "On demand" mode work? =

When a visitor clicks a link to a PDF that has not passed the accessibility pre-check, an accessible dialog appears. The visitor can open the document as it is, or request an accessible version by entering their email address. The plugin remediates the document once — no matter how many visitors ask for it — and emails everyone who requested it as soon as the accessible version is available. From then on, every visitor gets the accessible file at the same link, with no dialog. The email address is used solely for this one notification, is never sent to the a11yfy API, and is deleted automatically after 30 days. The dialog texts, the notification email and the button style can be customized under a11yfy → Settings.

**Traducere:**

= Cum funcționează modul „La cerere”? =

Atunci când un vizitator dă clic pe un link către un PDF care nu a trecut de verificarea preliminară de accesibilitate, apare un dialog accesibil. Vizitatorul poate deschide documentul așa cum este sau poate solicita o versiune accesibilă, introducându-și adresa de email. Modulul remediază documentul o singură dată — indiferent de câți vizitatori îl solicită — și trimite un email tuturor celor care au cerut acest lucru, imediat ce versiunea accesibilă este disponibilă. Din acel moment, toți vizitatorii primesc fișierul accesibil la același link, fără niciun dialog. Adresa de email este folosită exclusiv pentru această notificare, nu este trimisă niciodată către API-ul a11yfy și este ștearsă automat după 30 de zile. Textele dialogului, emailul de notificare și stilul butoanelor pot fi personalizate la a11yfy → Setări.

---

### Changelog — 1.1.0

= 1.1.0 =
* New "On demand" working mode: when a visitor clicks a PDF that is not accessible yet, an accessible dialog offers to open the document as-is or to request an accessible version by email. Remediation runs only on real demand.
* Visitors are notified by email as soon as the accessible version is ready; every dialog text (including the privacy note) and the notification email are fully customizable in Settings.
* The dialog inherits the typography of your theme, and on block themes the buttons follow your theme's button style automatically; alternatively you can pick an accent color.
* If the credit balance does not cover a visitor request, the request is parked, the site owner is warned by email, and remediation starts automatically once enough credits are available.
* Requester email addresses are stored only until the notification is sent (30-day retention), with WordPress privacy export/erase support.

**Traducere:**

= 1.1.0 =
* Mod de lucru nou „La cerere”: atunci când un vizitator dă clic pe un PDF care nu este încă accesibil, un dialog accesibil oferă posibilitatea de a deschide documentul ca atare sau de a solicita o versiune accesibilă prin email. Remedierea rulează doar la cerere reală.
* Vizitatorii sunt notificați prin email imediat ce versiunea accesibilă este gata; fiecare text din dialog (inclusiv nota privind confidențialitatea) și emailul de notificare sunt complet personalizabile în Setări.
* Dialogul moștenește tipografia temei tale, iar în temele de tip block butoanele urmează automat stilul butoanelor din temă; alternativ, poți alege o culoare de accent.
* Dacă soldul de credite nu acoperă o solicitare a unui vizitator, solicitarea este pusă în așteptare, proprietarul site-ului este avertizat prin email, iar remedierea pornește automat imediat ce sunt disponibile suficiente credite.
* Adresele de email ale solicitanților sunt stocate doar până la trimiterea notificării (reținere 30 de zile), cu suport pentru exportarea și ștergerea datelor conform reglementărilor WordPress privind confidențialitatea.

---

## Observații finale

### Gravitate

| Nivel | Descriere | Acțiune |
|---|---|---|
| 🔴 **Critic** | Eroare semantică: „accessible” → „disponibil” (#2), „visitor requests” → „de vizită” (#1) | **Blochează publicarea.** Schimbă sensul fundamental. |
| 🟠 **Major** | „Request explanation” și „Request document” — confuzie imperativ/substantiv (#3, #4) | **Corectat înainte de publicare.** Afectează UX-ul direct (butoane/etichete). |
| 🟡 **Mediu** | Glosar: „e-mail” → „email” (8×), „face clic” → „dă clic” (2×) | **Corectat.** Reviewerele WP ro_RO vor respinge traducerea din acest motiv. |
| 🟡 **Mediu** | Inconsecvență: „culoare de evidențiere” vs. „culoare de accent” (#9) | **Uniformizat.** |
| 🟢 **Minor** | Registru formal vs. informal (12 șiruri) | **De corectat** conform ghidului WP ro_RO, dar decizia finală aparține echipei, având în vedere contextul visitor-facing. |
| 🟢 **Minor** | „Ready-email” → formulare neclară (#8) | **Îmbunătățit.** Nu blochează, dar scade calitatea. |

### Verdict general

Traducerea este **funcțională, dar necesită revizie înainte de publicare**. Cele mai grave probleme sunt cele două erori semantice (#1 și #2), care pot induce utilizatorii în eroare, și încălcarea sistematică a glosarului privind „email” (fără cratimă). Problemele de registru sunt numeroase, dar consecvente — se poate lua o decizie la nivel de proiect dacă se păstrează adresarea formală pentru vizitatori (contra ghidului WP) sau se adoptă informalul uniform.

Toate placeholder-ele (`%s`, `%1$d`, `%2$d`, `%3$d`, `{site_name}`, `{document_title}`, `{document_url}`, `{request_date}`) sunt **păstrate intacte** în traducere — ✅.

Ghilimelele românești (`„...„`) sunt folosite corect în majoritatea cazurilor — ✅.

Diacriticele sunt corecte (virgulă-sub, nu sedilă): ș, ț, ă, â, î — ✅.
