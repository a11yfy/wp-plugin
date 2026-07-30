#!/usr/bin/env node
// GlotPress Stable Readme fordítás: letölti a wp.org readme-sablont (per
// locale), DeepL-lel fordítja a hiányzó msgstr-eket, és GlotPress-importra
// kész .po fájlokat ír a languages/readme/ alá. A changelog-ot a GlotPress
// eleve nem veszi be; a plugin-nevet és a csupasz URL-eket nem fordítjuk.
//
// Futtatás: DEEPL_API_KEY=... node bin/translate-readme.mjs [gp-locale ...]
// Inkrementális: meglévő kimeneti .po-ból a kitöltött msgstr-eket megtartja.

import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import gettextParser from "gettext-parser";

const API_KEY = process.env.DEEPL_API_KEY;
if (!API_KEY) { console.error("DEEPL_API_KEY hiányzik"); process.exit(1); }
const API_URL = process.env.DEEPL_API_URL ?? "https://api-free.deepl.com/v2/translate";

const SLUG = "a11yfy-pdf-accessibility-checker-fixer";
const GP_BASE = `https://translate.wordpress.org/projects/wp-plugins/${SLUG}/stable-readme`;

// GlotPress locale-slug → DeepL célnyelv (a lektorált 10 nyelv).
const LOCALES = {
	hu: "HU", de: "DE", fr: "FR", es: "ES", it: "IT",
	pl: "PL", nl: "NL", ro: "RO", pt: "PT-PT", cs: "CS",
};

// Nem fordítandó: márkanév + csupasz URL-szegmensek.
const KEEP_AS_IS = (msgid) =>
	msgid === "a11yfy – PDF Accessibility Checker &amp; Fixer" ||
	/^https?:\/\/\S+$/.test(msgid.trim());

const stripTags = (s) => s.replace(/<[^>]+>/g, "");
const urlsOf = (s) => (s.match(/https?:\/\/[^\s"<)]+/g) ?? []).sort().join("|");
const tagsOf = (s) => (s.match(/<\/?[a-z]+[^>]*>/gi) ?? []).map((t) => t.replace(/\s.*(\/?)>$/, "$1>")).sort().join("");

async function deepl(texts, target) {
	const out = [];
	for (let i = 0; i < texts.length; i += 20) {
		const chunk = texts.slice(i, i + 20);
		const body = new URLSearchParams();
		for (const t of chunk) body.append("text", t);
		body.append("source_lang", "EN");
		body.append("target_lang", target);
		body.append("tag_handling", "html");
		body.append("preserve_formatting", "1");
		const res = await fetch(API_URL, {
			method: "POST",
			headers: { Authorization: `DeepL-Auth-Key ${API_KEY}`, "Content-Type": "application/x-www-form-urlencoded" },
			body,
		});
		if (!res.ok) { console.error(`DeepL ${res.status}: ${await res.text()}`); process.exit(2); }
		out.push(...(await res.json()).translations.map((t) => t.text));
	}
	return out;
}

mkdirSync("languages/readme", { recursive: true });
const targets = process.argv.slice(2).length ? process.argv.slice(2) : Object.keys(LOCALES);

for (const loc of targets) {
	const deeplTarget = LOCALES[loc];
	if (!deeplTarget) { console.error(`Ismeretlen locale: ${loc}`); process.exit(1); }

	const res = await fetch(`${GP_BASE}/${loc}/default/export-translations/?format=po`);
	if (!res.ok) { console.error(`GlotPress export ${res.status} (${loc})`); process.exit(2); }
	const po = gettextParser.po.parse(Buffer.from(await res.arrayBuffer()));
	const trans = po.translations[""];

	// Inkrementális: korábbi kimenet kész msgstr-jeinek megtartása.
	const outPath = `languages/readme/${SLUG}-stable-readme-${loc}.po`;
	if (existsSync(outPath)) {
		const prev = gettextParser.po.parse(readFileSync(outPath)).translations[""];
		for (const [id, e] of Object.entries(prev)) {
			if (id && trans[id] && e.msgstr[0]) trans[id].msgstr = e.msgstr;
		}
	}

	const todo = Object.keys(trans).filter((id) => {
		if (!id || trans[id].msgstr[0]) return false;
		if (KEEP_AS_IS(id)) { trans[id].msgstr = [id]; return false; }
		return true;
	});

	if (todo.length) {
		const translated = await deepl(todo, deeplTarget);
		todo.forEach((id, i) => {
			let out = translated[i];
			// Őrök: HTML-tagek és URL-ek sértetlenek maradjanak.
			if (tagsOf(id) !== tagsOf(out) || urlsOf(id) !== urlsOf(out)) {
				console.error(`  ⚠ markup-eltérés (${loc}): "${stripTags(id).slice(0, 60)}" — angol marad`);
				out = id;
			}
			trans[id].msgstr = [out];
		});
	}

	po.headers.Language = loc;
	po.headers["Last-Translator"] = "a11yfy (DeepL machine translation)";
	po.headers["PO-Revision-Date"] = new Date().toISOString().replace("T", " ").slice(0, 16) + "+0000";
	writeFileSync(outPath, gettextParser.po.compile(po, { foldLength: 120 }));
	console.error(`${loc}: ${todo.length} fordítva → ${outPath}`);
}
