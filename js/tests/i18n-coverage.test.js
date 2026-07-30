// i18n gate: every engine check id must resolve to a human title in the web
// catalogs (matterhorn + verapdf_rules + matterhorn_friendly merge, the same
// precedence as web/src/lib/utils/matterhorn-lookup.ts). Without an entry the
// UI falls back to the raw English detail string / the bare id — that is how
// figure-untagged and the quality-* checks shipped untranslated once.
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { ALL_CHECKS } from '../src/checks/index.js';

// Development-tree-only integration test: the web catalogs are not part of
// the public plugin repo — the whole suite skips when they are absent.
const WEB_MESSAGES = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'web', 'src', 'messages');
// The web ships these four catalog locales (matterhorn-lookup.ts CATALOG_IMPORTS).
const LOCALES = ['hu', 'en', 'de', 'fr'];
// Doc-blocker ids surface through the dedicated blocker UI, not the check list.
const IDS = ALL_CHECKS.map((c) => c.id);

function catalogKeys(locale) {
  const merged = {};
  for (const file of ['matterhorn', 'verapdf_rules', 'matterhorn_friendly']) {
    const path = join(WEB_MESSAGES, `${file}.${locale}.json`);
    Object.assign(merged, JSON.parse(readFileSync(path, 'utf8')));
  }
  return merged;
}

describe.skipIf(!existsSync(WEB_MESSAGES))('web catalog covers every engine check id', () => {
  for (const locale of LOCALES) {
    it(`locale ${locale}: every check id has a titled catalog entry`, () => {
      const catalog = catalogKeys(locale);
      const missing = IDS.filter((id) => !catalog[id]?.title);
      expect(missing, `untranslated check ids in ${locale}`).toEqual([]);
    });
  }
});
