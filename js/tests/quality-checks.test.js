import { describe, expect, it } from 'vitest';
import { PDFDocument, StandardFonts } from 'pdf-lib';
import { analyze } from '../src/analyze.js';
import { computeScore } from '../src/report.js';
import { availableFixtures, loadFixture, TAGGED_FIXTURES } from './helpers.js';

const getCheck = (report, id) => report.checks.find((c) => c.id === id);

async function plainDoc(pages) {
  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.Helvetica);
  for (let i = 0; i < pages; i++) {
    doc.addPage([300, 300]).drawText(`Page ${i + 1}`, { x: 20, y: 250, size: 12, font });
  }
  return doc;
}

describe('quality checks (advisory layer)', () => {
  it('flags a long document without bookmarks', async () => {
    const doc = await plainDoc(12);
    const report = await analyze(await doc.save());
    const check = getCheck(report, 'quality-no-outline');
    expect(check.status).toBe('fail');
    expect(check.items[0].detail).toContain('12 pages');
  });

  it('does not apply to short documents', async () => {
    const doc = await plainDoc(2);
    const report = await analyze(await doc.save());
    expect(getCheck(report, 'quality-no-outline').status).toBe('inapplicable');
  });

  it('quality failures never lower the score', () => {
    const checks = [
      { id: '01-005', group: 'content', status: 'pass' },
      { id: 'quality-no-outline', group: 'quality', status: 'fail' },
      { id: 'quality-suspect-alt', group: 'quality', status: 'fail' },
    ];
    expect(computeScore(checks, { untaggedChars: 0, totalChars: 100 })).toBe(100);
  });

  it('runs cleanly on tagged fixtures', async () => {
    for (const [, fixturePath] of availableFixtures(TAGGED_FIXTURES)) {
      const report = await analyze(loadFixture(fixturePath));
      for (const id of ['quality-no-headings', 'quality-table-no-th', 'quality-suspect-alt']) {
        expect(['pass', 'fail', 'inapplicable']).toContain(getCheck(report, id).status);
      }
    }
  });
});
