// (2)+(3) Untagged fixtures must be classified as untagged/high-risk;
// tagged remediated fixtures must be tagged and structurally clean.
import { describe, expect, it } from 'vitest';
import { FIXTURES, TAGGED_FIXTURES, availableFixtures } from './helpers.js';
import { analyzed } from './report-shape.test.js';

const byId = (report, id) => report.checks.find((c) => c.id === id);

describe('untagged fixtures', () => {
  for (const name of ['T-szamla.pdf', 'erste_3p.pdf', 'test_doc.pdf', 'dmu_first10.pdf']) {
    const filePath = FIXTURES[name];
    it(`${name}: tagged=false, structural failures, high/critical risk`, async () => {
      const report = await analyzed(filePath);
      expect(report.tagged).toBe(false);
      expect(byId(report, 'struct-tree-root').status).toBe('fail');
      expect(byId(report, 'markinfo-marked').status).toBe('fail');
      expect(report.failedGroups).toContain('structural');
      expect(['high', 'critical']).toContain(report.risk);
      expect(report.encrypted).toBe(false);
    });
  }

  it('erste_3p.pdf: has text content, so 01-005 (untagged real content) fails', async () => {
    const report = await analyzed(FIXTURES['erste_3p.pdf']);
    expect(byId(report, '01-005').status).toBe('fail');
    expect(report.scannedLikely).toBe(false);
  });

  it('dmu_first10.pdf (scanned): scannedLikely=true', async () => {
    const report = await analyzed(FIXTURES['dmu_first10.pdf']);
    expect(report.scannedLikely).toBe(true);
  });
});

describe('tagged wild fixtures', () => {
  it('0000713.pdf: tagged=true and structure-tree checks run', async () => {
    const report = await analyzed(FIXTURES['0000713.pdf']);
    expect(report.tagged).toBe(true);
    expect(byId(report, 'struct-tree-root').status).toBe('pass');
    expect(byId(report, 'markinfo-marked').status).toBe('pass');
  });
});

describe('tagged remediated fixtures (near-pass)', () => {
  const tagged = availableFixtures(TAGGED_FIXTURES);
  if (!tagged.length) {
    // The near-pass fixtures are local-only (gitignored repo-root PDFs) — CI
    // has none, and vitest fails an entirely empty describe() with
    // "No test found in suite". Register an explicit skip instead.
    it.skip('tagged remediated fixtures are not present in this checkout', () => {});
    return;
  }
  for (const [name, filePath] of tagged) {
    it(`${name}: tagged=true, structural + metadata groups clean, low risk`, async () => {
      const report = await analyzed(filePath);
      expect(report.tagged).toBe(true);
      const structural = report.checks.filter((c) => c.group === 'structural');
      expect(structural.every((c) => c.status === 'pass' || c.status === 'inapplicable')).toBe(true);
      const metadata = report.checks.filter((c) => c.group === 'metadata');
      const metadataFails = metadata.filter((c) => c.status === 'fail');
      expect(metadataFails.length).toBeLessThanOrEqual(1); // "zömében pass"
      expect(report.failedGroups).not.toContain('structural');
      expect(report.score).toBeGreaterThanOrEqual(90);
      expect(report.risk).toBe('low');
      expect(report.scannedLikely).toBe(false);
    });
  }
});
