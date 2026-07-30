import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from 'vitest';

// Fixture PDFs live in the private development tree, not in the public
// plugin repo — fixture-based tests skip automatically when they are absent
// (availableFixtures), the pure unit tests run everywhere.
const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '../../..');
const fixturesDir = path.join(repoRoot, 'web/tests/e2e/fixtures');

/** Untagged / wild fixtures (read-only). */
export const FIXTURES = {
  'T-szamla.pdf': path.join(fixturesDir, 'T-szamla.pdf'),
  'erste_3p.pdf': path.join(fixturesDir, 'erste_3p.pdf'),
  'test_doc.pdf': path.join(fixturesDir, 'test_doc.pdf'),
  '0000054.pdf': path.join(fixturesDir, '0000054.pdf'),
  '0000713.pdf': path.join(fixturesDir, '0000713.pdf'),
  'dmu_first10.pdf': path.join(fixturesDir, 'dmu_first10.pdf'),
};

/** Tagged, remediated outputs living in the repo root (near-pass fixtures). */
export const TAGGED_FIXTURES = {
  'igenybejelentes.tagged.pdf': path.join(repoRoot, 'igenybejelentes.tagged.pdf'),
  'testdoc_fixed.tagged.pdf': path.join(repoRoot, 'testdoc_fixed.tagged.pdf'),
};

export const availableFixtures = (map) =>
  Object.entries(map).filter(([, p]) => fs.existsSync(p));

export function loadFixture(filePath) {
  const buf = fs.readFileSync(filePath);
  // Hand over a real ArrayBuffer, like the browser FileReader would.
  return buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);
}

const STATUSES = new Set(['pass', 'fail', 'inapplicable', 'error']);
const RISKS = new Set(['low', 'medium', 'high', 'critical']);

/** Assert the stable Report contract consumed by the PHP side. */
export function expectValidReport(report) {
  expect(report).toBeTypeOf('object');
  expect(report.engineVersion).toMatch(/^\d+\.\d+\.\d+$/);
  expect(Number.isInteger(report.pages)).toBe(true);
  expect(report.pages).toBeGreaterThanOrEqual(0);
  expect(report.tagged).toBeTypeOf('boolean');
  expect(report.encrypted).toBeTypeOf('boolean');
  expect(report.scannedLikely).toBeTypeOf('boolean');
  expect(Number.isInteger(report.score)).toBe(true);
  expect(report.score).toBeGreaterThanOrEqual(0);
  expect(report.score).toBeLessThanOrEqual(100);
  expect(RISKS.has(report.risk)).toBe(true);
  expect(report.coverage).toBeTypeOf('object');
  expect(Number.isInteger(report.coverage.untaggedChars)).toBe(true);
  expect(Number.isInteger(report.coverage.totalChars)).toBe(true);
  expect(report.coverage.untaggedChars).toBeGreaterThanOrEqual(0);
  expect(Array.isArray(report.checks)).toBe(true);
  expect(report.checks.length).toBeGreaterThan(30);
  for (const check of report.checks) {
    expect(check.id).toBeTypeOf('string');
    expect(check.group).toBeTypeOf('string');
    expect(STATUSES.has(check.status)).toBe(true);
    expect(Number.isInteger(check.count)).toBe(true);
    expect(check.count).toBeGreaterThanOrEqual(0);
    expect(Array.isArray(check.items)).toBe(true);
    expect(check.items.length).toBeLessThanOrEqual(20);
    for (const item of check.items) {
      expect(item.page === null || (Number.isInteger(item.page) && item.page >= 1)).toBe(true);
      expect(item.detail).toBeTypeOf('string');
      expect(item.detail.length).toBeLessThanOrEqual(121);
      if ('rects' in item) {
        expect(Array.isArray(item.rects)).toBe(true);
        expect(item.rects.length).toBeLessThanOrEqual(8);
        for (const rect of item.rects) {
          expect(rect).toHaveLength(4);
          expect(rect.every(Number.isFinite)).toBe(true);
        }
      }
    }
    if (check.status === 'fail') expect(check.count).toBeGreaterThanOrEqual(1);
    else if (check.status !== 'error') expect(check.count).toBe(0);
  }
  const expectedFailedGroups = [
    ...new Set(
      report.checks.filter((c) => c.status === 'fail' && c.group !== 'quality').map((c) => c.group),
    ),
  ];
  expect(report.failedGroups).toEqual(expectedFailedGroups);
  expect(report.partial).toBe(report.checks.some((c) => c.status === 'error'));
}
