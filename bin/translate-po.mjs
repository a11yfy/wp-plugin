#!/usr/bin/env node
// languages/a11yfy.pot → a11yfy-{locale}.po + .mo minden támogatott nyelvre,
// DeepL-lel. Inkrementális:
// újrafuttatva csak a hiányzó/üres msgstr-eket fordítja, a kézzel javított
// bejegyzésekhez nem nyúl.
//
// Futtatás (a wp-plugin/ könyvtárból, npm install után):
//   DEEPL_API_KEY=... node bin/translate-po.mjs [--provider deepl] [locale ...]
//   L10N_API_KEY=...  node bin/translate-po.mjs --provider l10n [locale ...]
// Locale-szűrés nélkül az összes célnyelvet frissíti.

import { readFileSync, writeFileSync, existsSync } from "node:fs";
import gettextParser from "gettext-parser";

const argv = process.argv.slice(2);
const providerIdx = argv.indexOf("--provider");
const PROVIDER = providerIdx >= 0 ? argv.splice(providerIdx, 2)[1] : "deepl";
if (!["deepl", "l10n"].includes(PROVIDER)) { console.error(`Ismeretlen provider: ${PROVIDER}`); process.exit(1); }

const API_KEY = PROVIDER === "l10n" ? process.env.L10N_API_KEY : process.env.DEEPL_API_KEY;
const API_URL = process.env.DEEPL_API_URL ?? "https://api-free.deepl.com/v2/translate";
if (!API_KEY) { console.error(`${PROVIDER === "l10n" ? "L10N" : "DEEPL"}_API_KEY hiányzik`); process.exit(1); }

const POT_PATH = "languages/a11yfy.pot";

// WP locale → { deepl, plural, nplurals, singularSlot }
// A plural-forms kifejezések a gettext/WP-ben bevett alakok.
const P2 = { plural: "nplurals=2; plural=(n != 1);", nplurals: 2 };
const LOCALES = {
	// EU / EEA
	bg_BG: { deepl: "BG", ...P2 },
	cs_CZ: { deepl: "CS", plural: "nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;", nplurals: 3 },
	da_DK: { deepl: "DA", ...P2 },
	de_DE: { deepl: "DE", ...P2 },
	el:    { deepl: "EL", ...P2 },
	es_ES: { deepl: "ES", ...P2 },
	et:    { deepl: "ET", ...P2 },
	fi:    { deepl: "FI", ...P2 },
	fr_FR: { deepl: "FR", plural: "nplurals=2; plural=(n > 1);", nplurals: 2 },
	it_IT: { deepl: "IT", ...P2 },
	lt_LT: { deepl: "LT", plural: "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);", nplurals: 3 },
	lv:    { deepl: "LV", plural: "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n != 0 ? 1 : 2);", nplurals: 3 },
	nl_NL: { deepl: "NL", ...P2 },
	pl_PL: { deepl: "PL", plural: "nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);", nplurals: 3 },
	pt_PT: { deepl: "PT-PT", ...P2 },
	pt_BR: { deepl: "PT-BR", plural: "nplurals=2; plural=(n > 1);", nplurals: 2 },
	ro_RO: { deepl: "RO", plural: "nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100 > 0 && n%100 < 20)) ? 1 : 2);", nplurals: 3 },
	sk_SK: { deepl: "SK", plural: "nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;", nplurals: 3 },
	sl_SI: { deepl: "SL", plural: "nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);", nplurals: 4 },
	sv_SE: { deepl: "SV", ...P2 },
	hu_HU: { deepl: "HU", ...P2 },
	nb_NO: { deepl: "NB", ...P2 },
	// Nagy nem-EU nyelvek (belefér a keretbe, lásd README)
	tr_TR: { deepl: "TR", plural: "nplurals=2; plural=(n > 1);", nplurals: 2 },
	uk:    { deepl: "UK", plural: "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);", nplurals: 3 },
	ru_RU: { deepl: "RU", plural: "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);", nplurals: 3 },
	ja:    { deepl: "JA", plural: "nplurals=1; plural=0;", nplurals: 1 },
	zh_CN: { deepl: "ZH", plural: "nplurals=1; plural=0;", nplurals: 1 },
	ko_KR: { deepl: "KO", plural: "nplurals=1; plural=0;", nplurals: 1 },
	id_ID: { deepl: "ID", plural: "nplurals=1; plural=0;", nplurals: 1 },
	ar:    { deepl: "AR", plural: "nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);", nplurals: 6, singularSlot: 1 },
};

// Nem fordítandó msgid-k (URL-ek, márkanév).
const SKIP = new Set([
	"a11yfy",
	"https://a11yfy.com",
	"https://a11yfy.com/wordpress",
	"a11yfy – PDF Accessibility Checker & Fixer",
]);

const pot = gettextParser.po.parse(readFileSync(POT_PATH));
const potEntries = pot.translations[""] ?? {};

let spentChars = 0;

async function deeplBatch(texts, target) {
	const out = [];
	const CHUNK = 40;
	for (let i = 0; i < texts.length; i += CHUNK) {
		const chunk = texts.slice(i, i + CHUNK);
		const body = new URLSearchParams();
		for (const t of chunk) body.append("text", t);
		body.append("source_lang", "EN");
		body.append("target_lang", target);
		body.append("preserve_formatting", "1");
		const res = await fetch(API_URL, {
			method: "POST",
			headers: { Authorization: `DeepL-Auth-Key ${API_KEY}`, "Content-Type": "application/x-www-form-urlencoded" },
			body,
		});
		if (!res.ok) {
			console.error(`DeepL ${res.status} (${target}): ${await res.text()}`);
			process.exit(2);
		}
		const json = await res.json();
		out.push(...json.translations.map((t) => t.text));
		spentChars += chunk.reduce((n, t) => n + t.length, 0);
	}
	return out;
}

// l10n.dev WP-locale → nyelvkód: pt_BR→pt-BR / pt_PT→pt-PT, egyébként a prefix.
function l10nCode(locale) {
	return locale.startsWith("pt_") ? locale.replace("_", "-") : locale.split("_")[0];
}

// l10n.dev batch (minta: scripts/translate-all.mjs l10nTranslate): a szövegek
// {"0":"…"} JSON-ként mennek, a válasz translations ugyanilyen JSON string.
async function l10nBatch(texts, locale) {
	const out = [];
	const CHUNK = 40;
	for (let i = 0; i < texts.length; i += CHUNK) {
		const chunk = texts.slice(i, i + CHUNK);
		const obj = {};
		chunk.forEach((t, k) => { obj[String(k)] = t; });
		const res = await fetch("https://api.l10n.dev/v2/translate", {
			method: "POST",
			headers: { "Content-Type": "application/json", "X-API-Key": API_KEY },
			body: JSON.stringify({
				sourceStrings: JSON.stringify(obj),
				sourceLanguageCode: "en",
				targetLanguageCode: l10nCode(locale),
				format: "json",
				client: "a11yfy-wp-po",
			}),
		});
		if (res.status === 402) { console.error("l10n.dev egyenleg elfogyott (402)."); process.exit(2); }
		if (!res.ok) { console.error(`l10n.dev ${res.status} (${locale}): ${await res.text()}`); process.exit(2); }
		const json = await res.json();
		if (json.finishReason && json.finishReason !== "stop") {
			console.error(`  ⚠ l10n finishReason=${json.finishReason} (${locale}) — részleges/szűrt találat lehet`);
		}
		const parsed = JSON.parse(json.translations);
		out.push(...chunk.map((t, k) => parsed[String(k)] ?? t));
		spentChars += chunk.reduce((n, t) => n + t.length, 0);
	}
	return out;
}

// A placeholderek (%s, %1$d, %2$s…) sértetlenségének ellenőrzése.
const PH_RE = /%(?:\d+\$)?[sd]/g;
function placeholdersMatch(src, dst) {
	const a = (src.match(PH_RE) ?? []).sort().join(",");
	const b = (dst.match(PH_RE) ?? []).sort().join(",");
	return a === b;
}

const batch = PROVIDER === "l10n"
	? (texts, cfg, locale) => l10nBatch(texts, locale)
	: (texts, cfg) => deeplBatch(texts, cfg.deepl);

const requested = argv;
const targets = requested.length ? requested : Object.keys(LOCALES);

for (const locale of targets) {
	const cfg = LOCALES[locale];
	if (!cfg) { console.error(`Ismeretlen locale: ${locale}`); process.exit(1); }

	const poPath = `languages/a11yfy-${locale}.po`;
	const po = existsSync(poPath)
		? gettextParser.po.parse(readFileSync(poPath))
		: JSON.parse(JSON.stringify(pot));

	po.headers = {
		...po.headers,
		"Language": locale,
		"Plural-Forms": cfg.plural,
		"PO-Revision-Date": new Date().toISOString().replace("T", " ").slice(0, 16) + "+0000",
		"Last-Translator": `a11yfy (${PROVIDER === "l10n" ? "l10n.dev" : "DeepL"} machine translation)`,
		"Language-Team": locale,
		"X-Generator": `a11yfy translate-po.mjs (${PROVIDER === "l10n" ? "l10n.dev" : "DeepL"})`,
	};

	const trans = (po.translations[""] ??= {});
	const todo = [];
	for (const [msgid, potEntry] of Object.entries(potEntries)) {
		if (!msgid) continue;
		const existing = trans[msgid];
		const missing = !existing || !existing.msgstr || !existing.msgstr[0];
		if (!trans[msgid]) trans[msgid] = JSON.parse(JSON.stringify(potEntry));
		if (SKIP.has(msgid)) {
			trans[msgid].msgstr = potEntry.msgid_plural
				? Array(cfg.nplurals).fill(msgid)
				: [msgid];
			continue;
		}
		if (missing) todo.push(msgid);
	}

	if (todo.length) {
		// Egy batchben: minden hiányzó msgid + a plural alakok.
		const singulars = await batch(todo, cfg, locale);
		const pluralIds = todo.filter((id) => potEntries[id].msgid_plural);
		const plurals = pluralIds.length
			? await batch(pluralIds.map((id) => potEntries[id].msgid_plural), cfg, locale)
			: [];
		const pluralMap = Object.fromEntries(pluralIds.map((id, i) => [id, plurals[i]]));

		todo.forEach((msgid, i) => {
			const entry = trans[msgid];
			let single = singulars[i];
			if (!placeholdersMatch(msgid, single)) {
				console.error(`  ⚠ placeholder-eltérés (${locale}): "${msgid}" → "${single}" — angol marad`);
				single = msgid;
			}
			if (potEntries[msgid].msgid_plural) {
				let plural = pluralMap[msgid];
				if (!placeholdersMatch(potEntries[msgid].msgid_plural, plural)) {
					console.error(`  ⚠ placeholder-eltérés (${locale}, plural): "${plural}" — angol marad`);
					plural = potEntries[msgid].msgid_plural;
				}
				const slot = cfg.singularSlot ?? 0;
				entry.msgstr = Array.from({ length: cfg.nplurals }, (_, k) => (k === slot ? single : plural));
			} else {
				entry.msgstr = [single];
			}
		});
	}

	writeFileSync(poPath, gettextParser.po.compile(po, { foldLength: 120 }));
	writeFileSync(`languages/a11yfy-${locale}.mo`, gettextParser.mo.compile(po));
	console.error(`${locale}: ${todo.length} új fordítás (${Object.keys(potEntries).length - 1} bejegyzés összesen)`);
}

console.error(`${PROVIDER === "l10n" ? "l10n.dev" : "DeepL"}-karakterfogyasztás ebben a futásban: ${spentChars}`);
