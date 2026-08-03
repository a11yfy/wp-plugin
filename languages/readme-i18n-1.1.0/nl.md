<!-- a11yfy readme 1.1.0 új blokkjai — nl (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — nieuwe paragraaf

Three working modes: **automatic** (every new upload is fixed), **manual** (you pick what to fix), and **on demand** — visitors who click a not-yet-accessible PDF can request an accessible version in an accessible dialog and get an email when it is ready, so you only pay for documents people actually need.

**Nederlands:**

Drie werkingsmodi: **automatisch** (elke nieuwe upload wordt hersteld), **handmatig** (je kiest zelf wat je herstelt) en **op aanvraag** — bezoekers die op een nog niet toegankelijke PDF klikken, kunnen via een toegankelijk dialoogvenster een toegankelijke versie aanvragen en krijgen een e-mail zodra deze klaar is. Zo betaal je alleen voor documenten die mensen daadwerkelijk nodig hebben.

---

### External Services — aangepaste bullet

* **When you remediate a PDF** (manually, via bulk action, through the automatic mode you enabled, or when a visitor requests an accessible version in the on-demand mode you enabled): the PDF file itself, its file name, and your API key are sent to `https://a11yfy.com/v1/jobs`. Processing happens in the EU. A requester's email address is stored only on your site and is never sent to the a11yfy API.

**Nederlands:**

* **Wanneer je een PDF herstelt** (handmatig, via een bulkactie, via de automatische modus die je hebt ingeschakeld, of wanneer een bezoeker een toegankelijke versie aanvraagt in de op-aanvraagmodus die je hebt ingeschakeld): het PDF-bestand zelf, de bestandsnaam en je API-sleutel worden verzonden naar `https://a11yfy.com/v1/jobs`. De verwerking vindt plaats in de EU. Het e-mailadres van een aanvrager wordt alleen op je eigen site opgeslagen en nooit naar de a11yfy-API gestuurd.

---

### FAQ — nieuwe vraag + antwoord

= How does the "On demand" mode work? =

When a visitor clicks a link to a PDF that has not passed the accessibility pre-check, an accessible dialog appears. The visitor can open the document as it is, or request an accessible version by entering their email address. The plugin remediates the document once — no matter how many visitors ask for it — and emails everyone who requested it as soon as the accessible version is available. From then on, every visitor gets the accessible file at the same link, with no dialog. The email address is used solely for this one notification, is never sent to the a11yfy API, and is deleted automatically after 30 days. The dialog texts, the notification email and the button style can be customized under a11yfy → Settings.

**Nederlands:**

= Hoe werkt de „op aanvraag”-modus? =

Wanneer een bezoeker op een link naar een PDF klikt die niet door de toegankelijkheidscontrole is gekomen, verschijnt er een toegankelijk dialoogvenster. De bezoeker kan het document openen zoals het is, of een toegankelijke versie aanvragen door zijn of haar e-mailadres in te voeren. De plugin herstelt het document één keer — ongeacht hoeveel bezoekers erom vragen — en stuurt iedereen die het heeft aangevraagd een e-mail zodra de toegankelijke versie beschikbaar is. Vanaf dat moment krijgt elke bezoeker het toegankelijke bestand op dezelfde link, zonder dialoogvenster. Het e-mailadres wordt uitsluitend voor deze ene melding gebruikt, nooit naar de a11yfy-API gestuurd en na 30 dagen automatisch verwijderd. De teksten in het dialoogvenster, de notificatie-e-mail en de knopstijl zijn aan te passen onder a11yfy → Instellingen.

---

### Changelog — 1.1.0

= 1.1.0 =
* New "On demand" working mode: when a visitor clicks a PDF that is not accessible yet, an accessible dialog offers to open the document as-is or to request an accessible version by email. Remediation runs only on real demand.
* Visitors are notified by email as soon as the accessible version is ready; every dialog text (including the privacy note) and the notification email are fully customizable in Settings.
* The dialog inherits the typography of your theme, and on block themes the buttons follow your theme's button style automatically; alternatively you can pick an accent color.
* If the credit balance does not cover a visitor request, the request is parked, the site owner is warned by email, and remediation starts automatically once enough credits are available.
* Requester email addresses are stored only until the notification is sent (30-day retention), with WordPress privacy export/erase support.

**Nederlands:**

= 1.1.0 =
* Nieuwe „op aanvraag”-werkmodus: wanneer een bezoeker op een PDF klikt die nog niet toegankelijk is, biedt een toegankelijk dialoogvenster de keuze om het document ongewijzigd te openen of een toegankelijke versie per e-mail aan te vragen. Herstel vindt alleen plaats op basis van daadwerkelijke vraag.
* Bezoekers krijgen een e-mail zodra de toegankelijke versie klaar is; alle dialoogteksten (inclusief de privacyverklaring) en de notificatie-e-mail zijn volledig aan te passen in de Instellingen.
* Het dialoogvenster neemt de typografie van je thema over en bij blokthema's volgen de knoppen automatisch de knopstijl van je thema; als alternatief kun je een accentkleur kiezen.
* Als het tegoed niet toereikend is voor een bezoekersverzoek, wordt het verzoek in de wachtrij geplaatst, krijgt de sitebeheerder een waarschuwing per e-mail en start het herstel automatisch zodra er voldoende credits beschikbaar zijn.
* E-mailadressen van aanvragers worden alleen bewaard tot de melding is verzonden (30 dagen bewaring), met ondersteuning voor WordPress privacy-export en -verwijdering.

---

## Overzicht

| Onderdeel | Status | Toelichting |
|-----------|--------|-------------|
| Placeholder-integriteit | ✅ PASS | Alle `%s`, `%1$d`, `%2$d`, `%3$d`, `{site_name}`, `{document_title}`, `{document_url}`, `{request_date}` intact |
| PTE-terminologie | ⚠️ DEELS | „remediation” wordt soms als „aanpassing” vertaald i.p.v. „herstel” (zie #6) |
| Register (u/je) | ❌ FAIL | Inconsistente aanspreekvorm — zie correcties #5 |
| Natuurlijk taalgebruik | ⚠️ MINOR | Enkele stijve formuleringen en één verkeerde woordvolgorde (zie #7, #8) |
| Betekenisfouten | ❌ FAIL | Vier betekenis-kritieke fouten (zie #1–#4) |
| README-vertaling | ✅ PASS | Volledige, natuurlijke vertaling met behoud van alle opmaak |

**Algeheel oordeel:** De vertaling is grotendeels goed leesbaar en volgt overwegend de PTE-stijl, maar bevat vier betekenis-kritieke fouten en een systematische register-inconsistentie (u/je) die gecorrigeerd moeten worden vóór publicatie. De README-vertaling is gereed voor gebruik.
