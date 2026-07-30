// (4) Score / risk consistency, band boundaries, invariants (score v3:
// absolute deduction — weight × saturate(count) per fail — minus coverage
// penalty, with a hard floor for the two structure-killers).
import { describe, expect, it } from 'vitest';
import { computeScore, riskLevel, buildReport } from '../src/report.js';
import { severityOf } from '../src/severities.js';
import { FIXTURES, TAGGED_FIXTURES, availableFixtures } from './helpers.js';
import { analyzed } from './report-shape.test.js';

// Known-weight ids: critical=5, major=2, minor=1.
// 01-005 and struct-tree-root also trigger the critical floor (≤40).
const CRIT = '25-001';
const MAJOR = '06-001';
const MINOR = '20-001';
const mk = (id, status, count = 1) => ({ id, status, count });

describe('severityOf', () => {
  it('mirrors the curated catalog severities', () => {
    expect(severityOf('struct-tree-root')).toBe('critical');
    expect(severityOf('figure-untagged')).toBe('critical');
    expect(severityOf('01-005')).toBe('critical');
    expect(severityOf('17-002')).toBe('minor');
    expect(severityOf('06-001')).toBe('major');
    expect(severityOf('never-heard-of-it')).toBe('major'); // default
  });
});

describe('computeScore (absolute deduction + coverage penalty)', () => {
  it('deducts weight × saturate(count) per failed check', () => {
    // count=1 → saturate=1 → plain weights.
    expect(computeScore([mk(MAJOR, 'pass'), mk(MAJOR, 'fail')])).toBe(98);
    expect(computeScore([mk(CRIT, 'fail')])).toBe(95);
    expect(computeScore([mk(MINOR, 'fail')])).toBe(99);
    // count=4 → saturate = 1 + log2(4) = 3 → major deducts 6.
    expect(computeScore([mk(MAJOR, 'fail', 4)])).toBe(94);
    // saturation: count=1024 → capped at 4 → major deducts 8, not 22.
    expect(computeScore([mk(MAJOR, 'fail', 1024)])).toBe(92);
  });

  it('passes and inapplicable/error checks never deduct', () => {
    expect(computeScore([mk(MAJOR, 'pass'), mk(MAJOR, 'inapplicable'), mk(MAJOR, 'error')])).toBe(100);
    expect(computeScore([mk(MAJOR, 'fail'), mk(CRIT, 'inapplicable'), mk(CRIT, 'error')])).toBe(98);
  });

  it('is independent of how many checks were applicable (comparability)', () => {
    // Same fail set, different pass/inapplicable padding → same score.
    const fails = [mk(MAJOR, 'fail', 3)];
    const padded = [...fails, mk(CRIT, 'pass'), mk(MINOR, 'pass'), mk(MAJOR, 'inapplicable')];
    expect(computeScore(fails)).toBe(computeScore(padded));
  });

  it('ignores the advisory quality group', () => {
    const qualityFail = { id: 'quality-no-headings', group: 'quality', status: 'fail', count: 9 };
    expect(computeScore([mk(MAJOR, 'pass'), qualityFail], { untaggedChars: 0, totalChars: 10 })).toBe(100);
  });

  it('subtracts up to 40 points for untagged text coverage', () => {
    const clean = [mk(MAJOR, 'pass')];
    expect(computeScore(clean, { untaggedChars: 0, totalChars: 1000 })).toBe(100);
    expect(computeScore(clean, { untaggedChars: 500, totalChars: 1000 })).toBe(80);
    expect(computeScore(clean, { untaggedChars: 1000, totalChars: 1000 })).toBe(60);
    // Ratio is capped at 1 and the score is floored at 0.
    expect(computeScore(clean, { untaggedChars: 5000, totalChars: 1000 })).toBe(60);
    // No text (scanned) → no coverage penalty.
    expect(computeScore(clean, { untaggedChars: 0, totalChars: 0 })).toBe(100);
  });

  it('caps the score at 40 when the structure tree is missing or body text untagged', () => {
    expect(computeScore([mk('struct-tree-root', 'fail')])).toBe(40);
    expect(computeScore([mk('01-005', 'fail')])).toBe(40);
    // Without other deductions the floor is exactly the cap…
    expect(computeScore([mk('01-005', 'fail'), mk(MAJOR, 'pass')])).toBe(40);
    // …and further deductions still lower it below the cap.
    expect(
      computeScore([mk('01-005', 'fail', 16)], { untaggedChars: 1000, totalChars: 1000 }),
    ).toBe(40); // 100 − 5×4 − 40 = 40 → min(40, 40)
    expect(
      computeScore([mk('01-005', 'fail', 16), mk(CRIT, 'fail', 16)], { untaggedChars: 1000, totalChars: 1000 }),
    ).toBe(20);
  });

  it('caps a scanned, untagged document at 5 (fully opaque to AT)', () => {
    // Pure scan: no struct tree, no extractable text → no coverage penalty,
    // but the document is a stack of pictures — the 40-cap would be absurd.
    expect(
      computeScore([mk('struct-tree-root', 'fail'), mk('figure-untagged', 'fail', 8)],
        { untaggedChars: 0, totalChars: 0 }, { scannedLikely: true }),
    ).toBe(5);
    // The same fail set on a born-digital document keeps the 40 cap.
    expect(
      computeScore([mk('struct-tree-root', 'fail')], { untaggedChars: 0, totalChars: 0 }),
    ).toBe(40);
    // scannedLikely alone (structure present, killers pass) never caps.
    expect(
      computeScore([mk(MAJOR, 'fail')], { untaggedChars: 0, totalChars: 0 }, { scannedLikely: true }),
    ).toBe(98);
  });
});

describe('score invariants (feature spec 5.1)', () => {
  it('1. clean document is exactly 100 (compliant gate)', () => {
    expect(computeScore(
      [mk(CRIT, 'pass'), mk(MAJOR, 'pass'), mk(MINOR, 'pass')],
      { untaggedChars: 0, totalChars: 12345 },
    )).toBe(100);
    // Quality-group advisories do not break the gate.
    expect(computeScore(
      [mk(MAJOR, 'pass'), { id: 'quality-no-outline', group: 'quality', status: 'fail', count: 1 }],
      { untaggedChars: 0, totalChars: 12345 },
    )).toBe(100);
  });

  it('2. score is always within [0, 100]', () => {
    const worst = [
      mk('struct-tree-root', 'fail', 1000),
      mk('01-005', 'fail', 1000),
      mk(CRIT, 'fail', 1000),
      mk(MAJOR, 'fail', 1000),
      mk(MINOR, 'fail', 1000),
    ];
    const s = computeScore(worst, { untaggedChars: 9999, totalChars: 9999 });
    expect(s).toBeGreaterThanOrEqual(0);
    expect(s).toBeLessThanOrEqual(100);
    expect(computeScore([], { untaggedChars: 0, totalChars: 0 })).toBeLessThanOrEqual(100);
  });

  it('3. monotone in count: more occurrences never raise the score', () => {
    for (const id of [CRIT, MAJOR, MINOR]) {
      let prev = Infinity;
      for (const count of [1, 2, 3, 5, 10, 50, 1000]) {
        const s = computeScore([mk(id, 'fail', count)]);
        expect(s).toBeLessThanOrEqual(prev);
        prev = s;
      }
    }
  });

  it('4. monotone in coverage: more untagged text never raises the score', () => {
    let prev = Infinity;
    for (const untagged of [0, 100, 250, 500, 900, 1000]) {
      const s = computeScore([mk(MAJOR, 'pass')], { untaggedChars: untagged, totalChars: 1000 });
      expect(s).toBeLessThanOrEqual(prev);
      prev = s;
    }
  });

  it('5. fully untagged document scores below a tagged one with a few errors', () => {
    // Fully untagged: no struct tree, 100% untagged text.
    const untagged = computeScore(
      [mk('struct-tree-root', 'fail'), mk('01-005', 'fail', 40)],
      { untaggedChars: 5000, totalChars: 5000 },
    );
    // Tagged with a handful of issues: missing alts + one table problem.
    const taggedWithIssues = computeScore(
      [mk(MAJOR, 'fail', 3), mk(MINOR, 'fail', 2), mk(CRIT, 'pass')],
      { untaggedChars: 0, totalChars: 5000 },
    );
    expect(untagged).toBeLessThan(taggedWithIssues);
  });
});

describe('buildReport: failedGroups and partial', () => {
  const base = { pages: 1, tagged: true, encrypted: false, scannedLikely: false, coverage: { untaggedChars: 0, totalChars: 10 } };

  it('failedGroups excludes the advisory quality group', () => {
    const report = buildReport({
      ...base,
      checks: [
        { id: '06-001', group: 'metadata', status: 'fail', count: 1 },
        { id: 'quality-no-headings', group: 'quality', status: 'fail', count: 1 },
      ],
    });
    expect(report.failedGroups).toEqual(['metadata']);
  });

  it('partial is true iff any check errored', () => {
    const ok = buildReport({ ...base, checks: [mk(MAJOR, 'pass')] });
    expect(ok.partial).toBe(false);
    const errored = buildReport({ ...base, checks: [mk(MAJOR, 'pass'), mk(MINOR, 'error')] });
    expect(errored.partial).toBe(true);
  });
});

describe('riskLevel band boundaries (research §4.2)', () => {
  it.each([
    [100, 'low'],
    [90, 'low'],
    [89, 'medium'],
    [70, 'medium'],
    [69, 'high'],
    [40, 'high'],
    [39, 'critical'],
    [0, 'critical'],
  ])('score %i → %s', (score, risk) => {
    expect(riskLevel(score)).toBe(risk);
  });
});

describe('score/risk consistency on real fixtures', () => {
  const all = [...availableFixtures(FIXTURES), ...availableFixtures(TAGGED_FIXTURES)];
  for (const [name, filePath] of all) {
    it(`${name}: report score matches recomputed score and risk band`, async () => {
      const report = await analyzed(filePath);
      expect(report.score).toBe(
        computeScore(report.checks, report.coverage, { scannedLikely: report.scannedLikely === true }),
      );
      expect(report.risk).toBe(riskLevel(report.score));
    });
  }
});
