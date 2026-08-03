<!-- a11yfy readme 1.1.0 új blokkjai — de (deepseek-lektorált fordítás, 2026-08-03) -->

Az alábbiakban a `readme-additions-en.md` teljes tartalmának német fordítása. A formázás (`=`, `**`, backtick, URL-ek, `a11yfy`) változatlan.

---

### Description — új bekezdés

```
Drei Arbeitsmodi: **automatisch** (jede neu hochgeladene Datei wird barrierefrei gemacht), **manuell** (Sie wählen aus, was bearbeitet werden soll) und **auf Anfrage** — Besucher, die auf eine noch nicht barrierefreie PDF-Datei klicken, können in einem barrierefreien Dialog eine zugängliche Version anfordern und erhalten eine E-Mail, sobald diese bereitsteht. So zahlen Sie nur für Dokumente, die tatsächlich benötigt werden.
```

---

### External Services — módosult bullet

```
* **Wenn Sie eine PDF-Datei barrierefrei machen lassen** (manuell, über die Sammelaktion, durch den von Ihnen aktivierten Automatikmodus oder wenn ein Besucher im von Ihnen aktivierten On-Demand-Modus eine barrierefreie Version anfordert): Die PDF-Datei selbst, ihr Dateiname und Ihr API-Schlüssel werden an `https://a11yfy.com/v1/jobs` gesendet. Die Verarbeitung findet in der EU statt. Die E-Mail-Adresse eines Anforderers wird ausschließlich auf Ihrer Website gespeichert und zu keinem Zeitpunkt an die a11yfy-API übermittelt.
```

---

### FAQ — új kérdés + válasz

```
= Wie funktioniert der „Auf Anfrage“-Modus? =

Wenn ein Besucher auf einen Link zu einer PDF-Datei klickt, die die Barrierefreiheits-Prüfung noch nicht bestanden hat, erscheint ein barrierefreier Dialog. Der Besucher kann das Dokument entweder im aktuellen Zustand öffnen oder eine barrierefreie Version anfordern, indem er seine E-Mail-Adresse eingibt. Das Plugin bearbeitet das Dokument nur einmal — unabhängig davon, wie viele Besucher es anfordern — und sendet allen, die es angefordert haben, eine E-Mail, sobald die barrierefreie Version verfügbar ist. Ab diesem Zeitpunkt erhalten alle Besucher unter demselben Link die barrierefreie Datei, ohne dass ein Dialog erscheint. Die E-Mail-Adresse wird ausschließlich für diese eine Benachrichtigung verwendet, zu keinem Zeitpunkt an die a11yfy-API gesendet und nach 30 Tagen automatisch gelöscht. Die Dialogtexte, die Benachrichtigungs-E-Mail und der Schaltflächenstil können unter a11yfy → Einstellungen angepasst werden.
```

---

### Changelog — 1.1.0

```
= 1.1.0 =
* Neuer „Auf Anfrage“-Modus: Wenn ein Besucher auf eine noch nicht barrierefreie PDF-Datei klickt, bietet ein barrierefreier Dialog die Möglichkeit, das Dokument unverändert zu öffnen oder eine barrierefreie Version per E-Mail anzufordern. Die Anpassung erfolgt nur bei tatsächlicher Nachfrage.
* Besucher werden per E-Mail benachrichtigt, sobald die barrierefreie Version bereitsteht; sämtliche Dialogtexte (einschließlich des Datenschutzhinweises) und die Benachrichtigungs-E-Mail sind in den Einstellungen vollständig anpassbar.
* Der Dialog übernimmt die Typografie Ihres Themes; bei Block-Themes folgen die Schaltflächen automatisch dem Schaltflächenstil des Themes — alternativ können Sie eine Akzentfarbe festlegen.
* Deckt das Guthaben eine Besucheranfrage nicht ab, wird die Anfrage zurückgestellt, der Website-Betreiber per E-Mail gewarnt und die Bearbeitung startet automatisch, sobald ausreichend Guthaben verfügbar ist.
* E-Mail-Adressen von Anforderern werden nur bis zum Versand der Benachrichtigung gespeichert (30-Tage-Aufbewahrung), mit Unterstützung für WordPress-Datenexport und -Löschung (Privacy).
```

---

## Összegzés

| Kategória | Darab |
|-----------|-------|
| Súlyos szemantikai hiba (accessible → verfügbar) | 1 |
| Félrefordítás (főnévi → igei, ready-email) | 3 |
| Regisztertörés (du/Sie) | 1 |
| Helytelen elöljáró / dátumjelölés | 1 |
| Stilisztikai / terminológiai javaslat | 4 |
| **Összes hiba/javaslat** | **10** |

**Összbenyomás:** A fordítás alapvetően jó minőségű, a német WordPress-konvenciókat nagyrészt követi, és a szakmai terminológia (Guthaben, barrierefrei, On-Demand-Modus) végig pontos. A 6 hibajavítás közül kettő súlyos jelentésmódosulást okoz (accessible → verfügbar, Request explanation → Erläuterung anfordern). A stilisztikai javaslatok a fordítás professzionális szintjét emelik tovább.
