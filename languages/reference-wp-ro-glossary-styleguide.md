# WordPress Romanian (ro_RO) Locale Translation Glossary & Style Guide

> **Sources:**
> - Official Glossary: `https://translate.wordpress.org/locale/ro/default/glossary` (accessed 2025-07-30)
> - WordPress.com Romanian Glossary: `https://translate.wordpress.com/languages/ro/default/glossary/` (accessed 2025-07-30)
> - Style Guide: `https://ro.wordpress.org/team/handbook/ghid/` (last updated 2024-10-02)
> - Actual WP Core ro_RO translations from `https://translate.wordpress.org/projects/wp/dev/ro/default/` (queried 2025-07-30)
> - "O piesă" blog post: `https://ro.wordpress.org/2015/05/09/o-piesa/` (motivation for widget→piesă)

---

## 1. Glossary (English → Romanian)

> **Note:** The glossary page on translate.wordpress.org is publicly accessible but renders as a JS-enhanced table. The data below was extracted from the raw HTML. Terms marked with `†` were found in the WordPress.com Romanian glossary (generally more complete). Terms marked with `[core]` were verified against actual WP core translations.

### Terms Specifically Requested

| English Term | Romanian Translation | Part of Speech | Notes / Context |
|---|---|---|---|
| **browser** | **navigator** | noun | |
| **Dashboard** | **Panou control** [core] | noun | Glossary entry says "ecran principal pentru administrare (WordPress, Jetpack, WooCommerce și altele)" — but in actual core translations, "Dashboard" = "Panou control" (e.g., "Go to the Dashboard" → "Mergi la Panou control", "User Dashboard: %s" → "Panou de control utilizator: %s") |
| **plugin** | **modul** | noun | Plural: **module**. Note: English "module" (e.g., Jetpack modules) → Romanian **extensie**. "complex plugins have several modules" → "modulele complexe au mai multe extensii" |
| **plugins** | **module** | noun (plural) | |
| **email** | **email** | noun | Without hyphen (`e-mail` → greșit). Plural: **emailuri** |
| **webhook** | **[not in glossary]** | — | Not present in either glossary nor in WP core strings. In practice, likely kept as "webhook" (loanword) or potentially "cârlig web" — but no official translation exists. |
| **please** | **te rog** | expression | "Ca formă de adresare." Used in imperative sentences: "Please test it." → "Te rog să o testezi." |
| **warning** | **avertizare** | noun | "Warning: …" → "Avertizare: …". "Warning notice" → "Notificare pentru avertizare". "without warning" → "fără avertizare" |
| **render** | **a randa** | verb | Noun: **randare**. Adjective: **randat**. "Cannot be rendered" → "nu poate fi randat". "Server side render" → "randarea pe server". "Block rendering halted" → "randarea blocului a fost oprită". |
| **rendered** | **randat** | adjective | "Is the block dynamically rendered" → "Este blocul randat dinamic" |
| **remove** | **înlătura** | verb | "Removing %1$s manually" → "Înlăturarea manuală a %1$s". "External images can be removed" → "Imaginile externe pot fi eliminate" (alternative: a elimina) |
| **removes** | **înlătură** | verb (3rd pers sg) | Conjugation of "a înlătura". Also: **elimină** depending on context. |
| **update** (verb) | **actualizează** | verb | Imperative, 2nd person singular |
| **update** (noun) | **actualizare** | noun | "(la zi)"; plural: **actualizări** |
| **updates** (noun plural) | **actualizări** | noun | |
| **review** | **recenzie** | noun | Reviewer → **recenzent** |
| **fix** (verb) | **corecta** | verb | Also: **a repara** (for code). "reparație (cod)" |
| **fix** (noun) | **corecție** | noun | "reparație (cod)" |
| **heading** | **subtitlu** / **titlu** | noun | Context-dependent. "Heading" as HTML heading element → **subtitlu** is the standard translation (e.g., "All headings" → "Toate subtitlurile"). "Accordion Heading" → "Subtitlu acordeon". **titlu** used where heading acts as title. |
| **headings** | **subtitluri** | noun (plural) | |
| **header** | **antet** | noun | From glossary: "header → antet" |
| **headers** | **antete** | noun (plural) | |
| **placeholder** | **substituent** | noun | "front-end placeholders" → "substituenți de conținut (înlocuitori)". Plural: **substituenți**. |
| **link** | **legătură** | noun | "Click the link" → "Dă clic pe legătură" |
| **bookmark** (noun) | **semn de carte** | noun | Plural: **semne de carte** |
| **bookmark** (verb) | **pune un semn de carte** | verb | |
| **bookmarks** | **semne de carte** | noun (plural) | |
| **content** | **conținut** | noun | Implicit throughout all translations. "render content" → "a randa conținutul". |
| **embedded** | **înglobat** | adjective | "embed" → "a îngloba". "embeded → înglobat" |
| **request** (noun) | **cerere** | noun | "your request" → "cererii tale". "Request data deletion" → "Cere ștergerea datelor" |
| **request** (verb) | **cere** | verb | "Please request a new link" → "Te rog cere una nouă" |
| **response** | **[not in glossary]** † | — | Not explicitly in glossary. In practice: **răspuns** (e.g., API response → răspuns API). Seen in core: "reply" → "răspunde". |
| **restore** | **restaura** / **restaurează** | verb | Imperative: **Restaurează**. "Restore the backup" → "Restaurează copia de siguranță". "Restored to revision" → "Am restaurat la revizia". "Restore from Trash" → "Restaurează de la gunoi". |
| **You are not allowed to** | **Nu ai voie să** | expression | "Sorry, you are not allowed to …" → **"Regret, nu ai voie să …"** (e.g., "Sorry, you are not allowed to edit users." → "Regret, nu ai voie să editezi utilizatorii."). Also: "not allowed" → "nu sunt permise" when impersonal. |
| **Action Scheduler** | **[not in glossary]** | proper noun | Proper name — do not translate per style guide rules ("Nu se traduc numele temelor, modulelor, serviciilor externe"). |

---

### Full Glossary Entries

> The following is a comprehensive extraction of all terms from both the translate.wordpress.org and translate.wordpress.com Romanian glossaries.

| English Term | Romanian Translation | Part of Speech | Notes |
|---|---|---|---|
| add-on | supliment | noun | adițional? |
| are you sure...? | sigur ...? | expression | "În loc de: Ești sigur/sigură că ...? - evitarea genului" |
| aside | notă | noun | format de articol (post format) |
| assets | resurse | noun | context: structura unui modul sau a unei teme |
| backend | partea administrativă a site-ului | noun | |
| backup | copie de siguranță | noun | plural: copii de siguranță |
| bandwith | lățime de bandă | expression | |
| blogger | bloger | noun | plural: blogeri |
| bookmark | semn de carte / pune un semn de carte | noun/verb | |
| bookmarklet | marcator | noun | |
| bot(s), botnet | bot, bot de rețea | expression | plural: boți (parte a cuvântului ro-bot) |
| bounce rate | rată de respingere | expression | |
| box | casetă | noun | |
| boxed | încasetat | adjective | stil de „layout”, un tip de aranjament |
| breadcrumbs | firimituri | noun | |
| browse | răsfoi | verb | |
| browser | navigator | noun | |
| buffered | înșiruite | noun | buffered queries → Interogări înșiruite |
| bug | eroare | noun | |
| builder | constructor | noun | |
| built-in | nativ | adjective | built-in post type → tip de articol nativ |
| bullet list | listă punctată | expression | listă cu buline |
| cache | cache | noun | intraductibil?! |
| call to action | îndemn la acțiune, apel la acțiune | expression | |
| callback | apel-înapoi / retro-apel | adjective | tip de funcție |
| cancel | anulează / anulare | verb/noun | |
| caption | text asociat | expression | pentru imagini; pentru video: subtitrare |
| carousel | carusel | noun | sinonim cu slider |
| change | a schimba | verb | dacă modificarea se face între un număr finit de valori |
| changelog | istoric modificări | expression | |
| chart | diagramă | noun | |
| checkbox | bifă | noun | check the option → bifează opțiunea |
| click | dă clic | verb | Click link → Dă clic pe legătură |
| clipboard | spațiu de stocare temporară în editare | noun | |
| code snippet | fragment de cod | expression | |
| codec | codec | noun | plural: codecuri |
| collaborate | a colabora | verb | sinonim cu „contribute" |
| contributor | contributor | noun | cel care contribuie la cod |
| copyright | drepturi de autor | expression | |
| core | nucleu | noun | |
| create | creează | verb | persoana a II-a singular, imperativ |
| cross-browser | compatibilitate cu navigatoare mai vechi | expression | backwards browser compatible |
| custom post type | tip de articol personalizat | expression | |
| customize | personalizare | noun | |
| customizer | personalizator | noun | Unealtă în care vezi, în timp real, modificările |
| cut and paste | taie și plasează / taie și lipește | expression | Microsoft a tradus taie și lipește |
| Dashboard | ecran principal pentru administrare / Panou control | noun | În practică: Panou control |
| debug | depana / depanare | verb/noun | |
| deprecated | învechit / abandonat | adjective | |
| display | afișează / afișare | verb/noun | displayed → afișat |
| docked | andocat | adjective | |
| documentation | documentație | noun | |
| download | descărca | verb | vezi și upload |
| draft | ciornă | noun | |
| drag and drop | trage și plasează | expression | |
| drop cap | letrină | noun | majusculă mărită la început de paragraf |
| dropdown menu | meniu derulant | expression | |
| e-commerce | comerț electronic | expression | |
| edit | editează / editare | verb/noun | |
| email | email | noun | fără cratimă; plural: emailuri |
| embed | îngloba | verb | embedded → înglobat |
| emoticon | emoticon | noun | singular: emoticon |
| endpoint | punct-final | noun | în API-uri |
| escape | escape | noun | înlocuirea caracterelor speciale (ex: "adi pop!" → "adi%20pop%21") |
| excerpt | rezumat | noun | |
| fade | estompare | noun | efecte de animație, claritate/estompare |
| fail | eșua | verb | |
| FAQ | Întrebări frecvente | expression | Poate ÎF sau ÎPF dacă spațiul nu permite |
| feature (noun) | funcționalitate | noun | plural: funcționalități; uneori facilitate |
| feature (verb) | evidenția | verb | |
| featured | reprezentativ | adjective | articol reprezentativ, conținut reprezentativ, secțiune reprezentativă etc. |
| feed | flux | noun | |
| feedback | impresii | noun | |
| fetch | aduce, prelua | verb | se scot, se extrag... |
| fix | corecta / corecție | verb/noun | |
| folower | urmăritor | noun | |
| font | font | noun | plural: fonturi |
| footer | subsol | noun | |
| front end | partea din față | expression | a site-ului |
| front page | pagina din față | expression | la fel pentru home page; poate fi și pagină de pornire |
| geotagging | geo-etichetare | expression | |
| grid | grilă | noun | |
| header | antet | noun | |
| homepage | prima pagină | expression | |
| hover | trece peste | verb | hover the link → treci peste legătură |
| icon | icon | noun | plural: iconuri |
| importer | importator | noun | module importatoare/exportatoare (de conținut) |
| inbox | inbox | noun | context: Google Analytics; outbox → ieșiri |
| indent | a indenta | verb | |
| inline | în-linie | expression | |
| insights | privire generală | noun | context: statistici și trafic; în alt context: perspective |
| item | element | noun | Pentru comerțul electronic: produs, articol de vânzare |
| layout | aranjament | noun | tip de aranjare în pagină |
| library | bibliotecă | noun | |
| like | apreciere | noun | plural: aprecieri; Liked → apreciat/apreciată |
| link | legătură | noun | |
| locale | locală | noun | |
| logged in | autentificat | adjective | |
| logged out | dezautentificat | adjective | |
| login | autentifica / autentificare | verb/noun | |
| look and feel | aspect general / aspect și senzație | expression | |
| ltr | [nu se traduce] | — | left-to-right; rtl → right-to-left |
| markup | markup | adjective | limbaj markup, schemă (de) markup |
| media | media | noun | element media |
| membership | calitatea de membru | noun | participant, membru în |
| merge | îmbină / combină | verb | |
| metadata | metadate | noun | |
| minify | a minifica / minificare | verb/noun | minificat |
| miscellaneous | diverse | adjective | |
| module | extensie | noun | exemplu Jetpack: "complex plugins have several modules" → "modulele complexe au mai multe extensii" |
| mouse | maus | noun | |
| namespace | spațiu de nume | noun | numele spațiului de șiruri |
| nonce | nunic | noun | criptografie: n(umăr) unic; plural: nunice |
| off | [se traduce doar când nu e valoare de variabilă] | — | |
| on | [se traduce doar când nu e valoare de variabilă] | — | |
| override | contramanda | verb | contramandează/anulează o funcție; adjectiv: contramandat |
| padding | distanțare | noun | |
| parallax | paralaxă | noun | |
| parse | a interpreta | verb | |
| parser | interpretor | noun | |
| patch | petec (de cod) | noun | sună ciudat :) |
| permalink | legătură permanentă | expression | |
| picker | selector | noun | |
| placeholder | substituent | noun | "front-end placeholders" → substituenți de conținut |
| play | rula | verb | to play a video → a rula un video; sau a reda? |
| player | player | noun | plural: playere |
| please | te rog | expression | formă de adresare |
| plugin | modul | noun | plural: module. Nu se confundă cu module (engleză) = extensie |
| post (noun) | articol | noun | |
| post (verb) | publica | verb | uneori a trimite prin corespondență |
| privacy | confidențialitate | adjective | |
| proofread | corectură | noun | verb: a corecta, a verifica (textul) |
| publicize | publicitate | expression | |
| push notifications | notificări imediate | expression | notificări „împinse" imediat |
| raster | rastru | noun | rasterizat, rasterizare |
| rating | evaluare | noun | |
| referrer | referent | noun | plural: referenți |
| refresh | (re)împrospăta | verb | |
| related | similar | adjective | |
| release | lansare | noun | |
| remove | înlătura | verb | |
| render | a randa / randare | verb/noun | |
| repository | depozitar | noun | |
| request | cerere / cere | noun/verb | |
| responsive | responsiv | adjective | Temă responsivă, modul responsiv, site responsiv |
| restore | restaura | verb | restaurează (imperativ) |
| review | recenzie | noun | |
| reviewer | recenzent | noun | |
| rtl | [nu se traduce] | — | vezi ltr |
| sanitize | sanitiza | verb | to sanitize the code → a sanitiza codul; sinonim: a curăța |
| screen | ecran | noun | administrative screen → ecran de administrare |
| scroll | derula | verb | smooth scroll → derulare lină |
| search | caută | verb | |
| set up | inițializa | verb | |
| setup | inițializare / setări inițiale | noun | |
| share | partaja | verb | uneori a împărtăși (idei, păreri) |
| shipping | livrare | noun | WooCommerce și alte module de comerț electronic |
| shortcode | scurt-cod | noun | |
| shortcut | scurtătură | noun | |
| shortlink | legătură scurtă | noun | |
| show | arată | verb | antonim: hide → ascunde; uneori „a afișa" |
| showcase | prezenta / prezentare | verb/noun | vitrină sau casetă de expunere/prezentare |
| site | site | noun | site-ul, site-ului, site-uri, site-urilor |
| sitemap | hartă site | noun | plural: hărți site |
| skin | aspect | noun | |
| slide | diapozitiv | noun | plural: diapozitive |
| slider | carusel | noun | |
| slug | descriptor | noun | |
| smooth | lin | adjective | smooth scroll → derulare lină |
| snippet | fragment | noun | fragment de cod |
| sorry, but | regret, dar | expression | |
| staging | punere în scenă | noun | |
| sticky | reprezentativ | adjective | sticky post → articol reprezentativ |
| string | șir | noun | |
| submit | trimite | verb | |
| subscript | indice | noun | |
| superscript | la putere | noun | |
| switch | comutator / comuta | noun/verb | |
| tab | filă | noun | "tab control" → control cu file (multi-filă) |
| tag | etichetă | noun | Excepție: pentru «HTML tag» se va folosi «tag HTML» |
| tagline | slogan | noun | |
| tap | atinge / dă tap | verb | "tap the link" → "dă tap pe legătură" (pe ecrane tactile) |
| template | șablon | noun | |
| testimonial | testimonial | noun | |
| text area | zonă text | expression | tip de câmp |
| theme | temă | noun | |
| thumbnail | miniatură | noun | |
| tiled | placat | adjective | |
| tips | sfaturi | noun | |
| toggle | comuta / comutator | verb/noun | |
| token | token | noun | plural: tokeni |
| toolbar | bară de unelte | noun | |
| tooltip | text explicativ | expression | mesaj care apare când cursorul este poziționat deasupra |
| trash | aruncă la gunoi / gunoi | verb/noun | trashed → aruncat la gunoi |
| trigger | declanșator | noun | |
| tweet | twit | noun | plural: twituri |
| typografy | tipografice | noun | se referă la fonturi |
| underscore | liniuță-jos | noun | plural: liniuțe-jos; „_" |
| undo | revino / revenire | verb/noun | a anula comenzile executate |
| unfollow | ne-urmărire | noun | scoatere de sub urmărire |
| uninstall | dezinstalare | noun | idem pentru upgrade |
| update | actualizează / actualizare | verb/noun | (la zi); plural: actualizări |
| upgrade | actualizează (la o versiune superioară) | verb | |
| upload | încarcă / încărcare | verb/noun | |
| usability | ușurință în utilizare | expression | |
| view | vezi / vizualizare | verb/noun | |
| viewport | fereastră de vizualizare | noun | |
| warning | avertizare | noun | |
| widget | piesă | noun | Vezi motivația: https://ro.wordpress.org/2015/05/09/o-piesa/ |
| widgets area | zonă pentru piese / zone asamblabile | expression | |
| wildcard | substitut | noun | |
| workaround | soluție de compromis | expression | pentru o eroare (bug) |
| zip | arhivează / arhivă | verb/noun | uneori merge și arhivează/dezarhivează |

---

## 2. Style Guide Rules

### 2.1 Formal vs. Informal Address

**The WordPress Romanian locale mandates INFORMAL address (`tu` / persoana a II-a singular).**

- Use **stilul informal**, **persoana a II-a singular**. Respectă tonul colocvial.
- DO NOT use polite/formal pronouns (`dumneavoastră`, `dumneata`).
- Keep the translation as close to the original as possible, but do not translate word-for-word.

**Examples:**
- `Te rog să încerci din nou.` (not: "Vă rugăm să încercați din nou.")
- `Dă clic pentru a continua.` (not: "Dați clic pentru a continua.")
- `Sigur?` (not: "Ești sigur?" — see gender avoidance below)

### 2.2 Imperative vs. Infinitive

**Use imperative, 2nd person singular for buttons and singular complex actions.**

- Buttons: `Save` → `Salvează`, `Delete` → `Șterge`, `Restore` → `Restaurează`
- When the application says it's doing something, use **1st person singular**:
  - `Încerc recuperarea datelor...`
  - `Am actualizat articolul.`

### 2.3 Gender Avoidance

**Avoid gender references** in translations. Romanian adjectives and past participles vary by gender, so rephrase to avoid them:

- `Are you sure?` → `Sigur?` (NOT: `Ești sigur?` or `Ești sigură?`)
- Use neutral phrasing whenever possible.

### 2.4 Diacritics

**Use correct Romanian diacritics** — comma-below, NOT cedilla:

| Correct | Wrong |
|---|---|
| **Ă ă** | Ã ã |
| **Î î** | (same) |
| **Â â** | (same) |
| **Ș ș** | Ş ş |
| **Ț ț** | Ţ ţ |

Reference: `https://ro.wordpress.org/team/handbook/folosirea-tastaturii-in-limba-romana/`

### 2.5 Quotation Marks

**Use Romanian-style quotation marks: `„...“`** („ = U+201E, ” = U+201D)

| English | Romanian |
|---|---|
| `"abc"` or `'abc'` or `“abc”` | `„abc”` |

**Exception:** Use straight double quotes (`"cuvânt"`) inside HTML tags. Example: `<a href="..."> text </a>`

### 2.6 Capitalization Rules

**Romanian does NOT use Title Case for every word in headings/buttons/labels.**

- Only capitalize the **first word** and **proper names** (commands, pages, themes, buttons, plugins, proper nouns).
- After a colon (`:`) or hyphen (`-`), continue with **lowercase**.

### 2.7 Punctuation and Spacing

**Not preceded by space, followed by one space:**
`. , ; : ? ! …` (period, comma, semicolon, colon, question mark, exclamation, ellipsis)
- `Traducerea este completă. Acum poți…`
- `Mărimi: mare, mic și mediu.`

**Preceded by space, not followed by space:**
- `(` — `Traducerea (în limba română) este completă.`

**No spaces on either side:**
- **Cratima (hyphen):** `N-am găsit nimic.`
- **Bara oblică (slash):** `Nu am găsit nicio temă/piesă.`

**Spaces on BOTH sides:**
- **Linia de dialog / de pauză (em dash):** `Opțiunile temei — piesele, culorile și coloanele — sunt minunate.`

**Ampersand:**
- Translate `&` as **„și”** when it does not represent a project name.

### 2.8 "Please" in Imperative Sentences

**"Please" is translated as `te rog`** (informal, 2nd person singular), used as a parenthetical or preceding clause:

- `Please test it.` → `Te rog să o testezi.`
- `Please try again.` → `Te rog să încerci din nou.`
- `Please request a new link below.` → `Te rog cere una nouă mai jos.`
- `To get started …, please visit the Comments screen.` → `Pentru a …, te rog vizitează ecranul Comentarii.`

### 2.9 Plural Forms

Romanian has **3 plural forms** (standard WordPress plural handling):

| Form | Pattern | Example |
|---|---|---|
| Singular (1) | `%s obiect` | `1 obiect` |
| Plural (0, 2–19) | `%s obiecte` | `19 obiecte` |
| Plural (20+) | `%s de obiecte` | `25 de obiecte` |

**Gender adaptation for singular:**
- Feminine: `1 → o zi, o temă, o recenzie, o etichetă, o extensie`
- Masculine: `1 → un comentariu, un articol, un modul`

**Warning:** Sometimes natural translation replaces the `%s` with `o`/`un` at singular, which triggers a warning in the translation tool. **Do not omit placeholders.**

### 2.10 Numbers

- Decimal separator: **comma** (3,14 not 3.14)
- Space between value and unit: `3 m`, `15 px`
- Space between value and currency code, currency after amount: `9 $`
- Thousands separator: **dot** (1.000, 10.000, 111.259, 1.000.000)

### 2.11 Date and Time

- Date format: **d.m.Y** (day.month.year) — `02.10.2024`
- Time format: **G:i** (hour:minute) — `22:17`
- Full PHP date character reference: `https://www.php.net/manual/en/datetime.format.php`

### 2.12 What NOT to Translate

- Theme names, plugin names, external service names, author names
- URLs (except when an official localized URL exists)
- `ltr` / `rtl` (writing direction)
- Variable names
- `on`/`off` — when representing an attribute value (e.g., a font that is on/off)
- Proper names like "WordPress Playground", "Action Scheduler" (plugin names)
- HTML tags and placeholders (`%s`, `%d`, `%1$s`, etc.) — preserve their exact form

### 2.13 Placeholders and HTML Tags

- Preserve ALL placeholders from the original string in their exact form.
- `%1$s`, `%2$s` etc. can be reordered if the translation requires it (e.g., `%1$s and %2$s` → `%2$s și %1$s`), but usually not needed.
- `%%` represents a literal `%` character.
- HTML tags like `<strong>text</strong>` must be preserved with their exact structure.

### 2.14 Automatic Translations

**Not recommended.** Google Translate, DeepL, Bing, Reverso, AI translations etc. "nu oferă calitatea dorită pentru șirurile traduse."

---

## 3. Key Translation Conventions (Observed from Core)

| English Pattern | Romanian Pattern |
|---|---|
| `Sorry, you are not allowed to [verb] [object].` | `Regret, nu ai voie să [verb] [object].` |
| `Warning: [message]` | `Avertizare: [message]` |
| `[Something] has been restored.` | `[Ceva] a fost restaurat.` |
| `Restore` (button) | `Restaurează` |
| `Remove` (button) | `Înlătură` |
| `Update` (button) | `Actualizează` |
| `Please [verb]...` | `Te rog să [verb]...` / `Te rog [verb]...` |
| `Go to the Dashboard` | `Mergi la Panou control` |
| `(opens in a new tab)` | `(se deschide într-o filă nouă)` |
| `Trash` (verb) | `Aruncă la gunoi` |
| `Restore from Trash` | `Restaurează de la gunoi` |
| `Reply` (verb) | `Răspunde` |

---

## 4. Source URLs

| Resource | URL |
|---|---|
| .org Glossary | `https://translate.wordpress.org/locale/ro/default/glossary` |
| .com Glossary | `https://translate.wordpress.com/languages/ro/default/glossary/` |
| Style Guide | `https://ro.wordpress.org/team/handbook/ghid/` |
| Translation Process | `https://ro.wordpress.org/team/handbook/procesul-traducerii/` |
| Keyboard Setup | `https://ro.wordpress.org/team/handbook/folosirea-tastaturii-in-limba-romana/` |
| FAQ | `https://ro.wordpress.org/team/handbook/intrebari-frecvente/` |
| Tools | `https://ro.wordpress.org/team/handbook/instrumente-pentru-traducere/` |
| Localization Landing | `https://ro.wordpress.org/localizare/` |
| "O piesă" (widget rationale) | `https://ro.wordpress.org/2015/05/09/o-piesa/` |
| Polyglots Team Page | `https://make.wordpress.org/polyglots/teams/?locale=ro_RO` |
| Romanian Slack Community | `https://join.slack.com/t/wpromania/` (#poligloti channel) |

---

*Document compiled 2025-07-30 from direct crawling of all above sources. The glossary at translate.wordpress.org is publicly accessible (no login required for viewing) but the page's JS-enhanced editing interface means the raw crawled text includes form markup. All terms were manually extracted and verified against both .org and .com glossaries and actual core translations.*
