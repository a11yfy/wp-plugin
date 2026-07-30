// (1) Every fixture must analyze without throwing and yield a valid Report.
import { describe, expect, it } from 'vitest';
import { analyze } from '../src/analyze.js';
import { ENGINE_VERSION } from '../src/version.js';
import { FIXTURES, TAGGED_FIXTURES, availableFixtures, expectValidReport, loadFixture } from './helpers.js';

const reportCache = new Map();
export async function analyzed(filePath) {
  if (!reportCache.has(filePath)) reportCache.set(filePath, await analyze(loadFixture(filePath)));
  return reportCache.get(filePath);
}

describe('report shape (stable PHP-side contract)', () => {
  const all = [...availableFixtures(FIXTURES), ...availableFixtures(TAGGED_FIXTURES)];
  it('has fixtures available', () => {
    expect(all.length).toBeGreaterThanOrEqual(6);
  });

  for (const [name, filePath] of all) {
    it(`analyzes ${name} and returns a valid report`, async () => {
      const report = await analyzed(filePath);
      expectValidReport(report);
      expect(report.engineVersion).toBe(ENGINE_VERSION);
      expect(report.pages).toBeGreaterThan(0);
    });
  }

  it('accepts Uint8Array input as well as ArrayBuffer', async () => {
    const [, filePath] = availableFixtures(FIXTURES)[0];
    const report = await analyze(new Uint8Array(loadFixture(filePath)));
    expectValidReport(report);
  });

  it('rejects non-PDF input with an error', async () => {
    await expect(analyze(new TextEncoder().encode('not a pdf at all').buffer)).rejects.toThrow();
  });
});
