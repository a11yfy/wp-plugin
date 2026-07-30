// Content-pass checks (01-005 deep variant, figure-untagged): the remediated
// (tagged) fixtures must stay clean — everything is tagged or artifact there —
// while wild untagged fixtures with real text must fail 01-005.
import { describe, expect, it } from 'vitest';
import { analyze } from '../src/analyze.js';
import { FIXTURES, TAGGED_FIXTURES, availableFixtures, loadFixture } from './helpers.js';

const byId = (report, id) => report.checks.find((c) => c.id === id);

describe('content coverage checks', () => {
  for (const [name, filePath] of availableFixtures(TAGGED_FIXTURES)) {
    it(`remediated fixture ${name} passes 01-005, figure-untagged and graphics-untagged`, async () => {
      const report = await analyze(loadFixture(filePath));
      expect(byId(report, '01-005').status).not.toBe('fail');
      expect(byId(report, 'figure-untagged').status).not.toBe('fail');
      // A pipeline minden vektor-grafikát artifactol/tagel — a check nem
      // üthet false positive-ot a saját compliant kimeneteinken.
      expect(byId(report, 'graphics-untagged').status).not.toBe('fail');
    });
  }

  it('an untagged fixture with vector chrome fails graphics-untagged with page items', async () => {
    // T-szamla: táblázat-keretek/kitöltések marked content nélkül — a
    // veraPDF 7.1-t3 kliens-oldali megfelelője (per-oldal aggregált items).
    const wild = availableFixtures(FIXTURES).filter(([n]) => n === 'T-szamla.pdf');
    expect(wild.length).toBe(1);
    const report = await analyze(loadFixture(wild[0][1]));
    const check = byId(report, 'graphics-untagged');
    expect(check.status).toBe('fail');
    expect(check.count).toBeGreaterThan(0);
    expect(check.items.length).toBeGreaterThan(0);
    expect(check.items[0].page).toBeGreaterThan(0);
  });

  it('an untagged fixture with text fails 01-005 with a document-level item', async () => {
    const wild = availableFixtures(FIXTURES).filter(([n]) => n === '0000054.pdf');
    expect(wild.length).toBe(1);
    const report = await analyze(loadFixture(wild[0][1]));
    const check = byId(report, '01-005');
    expect(check.status).toBe('fail');
    expect(check.items.length).toBeGreaterThan(0);
  });

  it('failed checks may carry viewer rects in PDF user space', async () => {
    const all = availableFixtures(FIXTURES);
    let sawRect = false;
    for (const [, filePath] of all) {
      const report = await analyze(loadFixture(filePath));
      for (const check of report.checks) {
        for (const item of check.items) {
          if (item.rects && item.rects.length) sawRect = true;
        }
      }
    }
    expect(sawRect).toBe(true);
  });
});
