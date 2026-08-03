<!-- a11yfy readme 1.1.0 új blokkjai — it (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — nuovo paragrafo

Three working modes: **automatic** (every new upload is fixed), **manual** (you pick what to fix), and **on demand** — visitors who click a not-yet-accessible PDF can request an accessible version in an accessible dialog and get an email when it is ready, so you only pay for documents people actually need.

Tre modalità di funzionamento: **automatica** (ogni nuovo caricamento viene corretto), **manuale** (decidi tu cosa correggere) e **su richiesta** — i visitatori che cliccano su un PDF non ancora accessibile possono richiedere una versione accessibile tramite un dialogo accessibile e ricevere un'email quando è pronta, così paghi solo per i documenti di cui gli utenti hanno effettivamente bisogno.

---

### External Services — bullet modificato

* **When you remediate a PDF** (manually, via bulk action, through the automatic mode you enabled, or when a visitor requests an accessible version in the on-demand mode you enabled): the PDF file itself, its file name, and your API key are sent to `https://a11yfy.com/v1/jobs`. Processing happens in the EU. A requester's email address is stored only on your site and is never sent to the a11yfy API.

* **Quando viene corretta un PDF** (manualmente, tramite azione in blocco, attraverso la modalità automatica che hai attivato, oppure quando un visitatore richiede una versione accessibile nella modalità su richiesta che hai attivato): il file PDF stesso, il suo nome e la tua chiave API vengono inviati a `https://a11yfy.com/v1/jobs`. L'elaborazione avviene nell'UE. L'indirizzo email del richiedente viene memorizzato solo sul tuo sito e non viene mai inviato all'API di a11yfy.

---

### FAQ — nuova domanda + risposta

= How does the "On demand" mode work? =

When a visitor clicks a link to a PDF that has not passed the accessibility pre-check, an accessible dialog appears. The visitor can open the document as it is, or request an accessible version by entering their email address. The plugin remediates the document once — no matter how many visitors ask for it — and emails everyone who requested it as soon as the accessible version is available. From then on, every visitor gets the accessible file at the same link, with no dialog. The email address is used solely for this one notification, is never sent to the a11yfy API, and is deleted automatically after 30 days. The dialog texts, the notification email and the button style can be customized under a11yfy → Settings.

= Come funziona la modalità "Su richiesta"? =

Quando un visitatore clicca su un collegamento a un PDF che non ha superato la verifica preliminare di accessibilità, appare un dialogo accessibile. Il visitatore può aprire il documento così com'è oppure richiedere una versione accessibile inserendo il proprio indirizzo email. Il plugin corregge il documento una sola volta — indipendentemente da quanti visitatori lo richiedano — e invia un'email a tutti coloro che ne hanno fatto richiesta non appena la versione accessibile è disponibile. Da quel momento in poi, ogni visitatore ottiene il file accessibile allo stesso collegamento, senza alcun dialogo. L'indirizzo email viene utilizzato esclusivamente per questa unica notifica, non viene mai inviato all'API di a11yfy e viene eliminato automaticamente dopo 30 giorni. I testi del dialogo, l'email di notifica e lo stile dei pulsanti possono essere personalizzati in a11yfy → Impostazioni.

---

### Changelog — 1.1.0

= 1.1.0 =
* New "On demand" working mode: when a visitor clicks a PDF that is not accessible yet, an accessible dialog offers to open the document as-is or to request an accessible version by email. Remediation runs only on real demand.
* Visitors are notified by email as soon as the accessible version is ready; every dialog text (including the privacy note) and the notification email are fully customizable in Settings.
* The dialog inherits the typography of your theme, and on block themes the buttons follow your theme's button style automatically; alternatively you can pick an accent color.
* If the credit balance does not cover a visitor request, the request is parked, the site owner is warned by email, and remediation starts automatically once enough credits are available.
* Requester email addresses are stored only until the notification is sent (30-day retention), with WordPress privacy export/erase support.

= 1.1.0 =
* Nuova modalità di lavoro "Su richiesta": quando un visitatore clicca su un PDF non ancora accessibile, un dialogo accessibile offre la possibilità di aprire il documento così com'è o di richiedere una versione accessibile via email. La correzione viene eseguita solo in caso di richiesta effettiva.
* I visitatori ricevono una notifica via email non appena la versione accessibile è pronta; tutti i testi del dialogo (inclusa l'informativa sulla privacy) e l'email di notifica sono completamente personalizzabili nelle Impostazioni.
* Il dialogo eredita la tipografia del tuo tema e, nei temi a blocchi, i pulsanti seguono automaticamente lo stile dei pulsanti del tema; in alternativa puoi scegliere un colore di accento.
* Se il saldo crediti non copre la richiesta di un visitatore, la richiesta viene messa in attesa, l'amministratore del sito viene avvisato via email e la correzione si avvia automaticamente non appena sono disponibili crediti sufficienti.
* Gli indirizzi email dei richiedenti vengono conservati solo fino all'invio della notifica (30 giorni), con supporto per l'esportazione e la cancellazione ai sensi della privacy di WordPress.

---

## Verifica placeholder e formattazione

Tutti i placeholder (`%s`, `%d`, `%1$d`, `%2$d`, `%3$d`, `{site_name}`, `{document_title}`, `{document_url}`, `{request_date}`) sono stati verificati e risultano **integri** in tutte le correzioni proposte.

Tutti i marcatori di formattazione readme (`=`, `*`, `**`, backtick, `→`) sono stati preservati.

Il dominio `a11yfy` e tutti gli URL sono stati lasciati invariati.
