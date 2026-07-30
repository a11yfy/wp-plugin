// Blocker flags (signed / xfa / portfolio): synthetic PDFs built with pdf-lib,
// then run through the real analyze() entry — the same path the worker uses.
import { describe, it, expect } from 'vitest';
import { PDFDocument, PDFName, PDFHexString, PDFString } from 'pdf-lib';
import { analyze } from '../src/analyze.js';
import { availableFixtures, loadFixture, FIXTURES } from './helpers.js';

async function buildPdf(mutate) {
  const doc = await PDFDocument.create();
  doc.addPage([200, 200]);
  if (mutate) mutate(doc);
  const bytes = await doc.save({ useObjectStreams: false });
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
}

describe('doc-blockers', () => {
  it('plain PDF has no blocker flags', async () => {
    const report = await analyze(await buildPdf());
    expect(report.signed).toBe(false);
    expect(report.xfa).toBe(false);
    expect(report.portfolio).toBe(false);
  });

  it('detects a digital signature (ByteRange + Contents dict)', async () => {
    const buffer = await buildPdf((doc) => {
      const ctx = doc.context;
      const sig = ctx.obj({
        Type: 'Sig',
        Filter: 'Adobe.PPKLite',
        SubFilter: 'adbe.pkcs7.detached',
        ByteRange: [0, 100, 200, 100],
        Contents: PDFHexString.of('00'.repeat(16)),
      });
      const sigRef = ctx.register(sig);
      const field = ctx.obj({
        FT: 'Sig',
        T: PDFString.of('Signature1'),
        V: sigRef,
      });
      const acroForm = ctx.obj({ Fields: [ctx.register(field)], SigFlags: 3 });
      doc.catalog.set(PDFName.of('AcroForm'), ctx.register(acroForm));
    });
    const report = await analyze(buffer);
    expect(report.signed).toBe(true);
    expect(report.xfa).toBe(false);
    expect(report.portfolio).toBe(false);
  });

  it('detects an XFA form', async () => {
    const buffer = await buildPdf((doc) => {
      const ctx = doc.context;
      const acroForm = ctx.obj({ Fields: [], XFA: PDFString.of('<xdp/>') });
      doc.catalog.set(PDFName.of('AcroForm'), ctx.register(acroForm));
    });
    const report = await analyze(buffer);
    expect(report.xfa).toBe(true);
    expect(report.signed).toBe(false);
  });

  it('detects a PDF portfolio (/Collection)', async () => {
    const buffer = await buildPdf((doc) => {
      const ctx = doc.context;
      doc.catalog.set(PDFName.of('Collection'), ctx.register(ctx.obj({ Type: 'Collection' })));
    });
    const report = await analyze(buffer);
    expect(report.portfolio).toBe(true);
  });

  it('flags the really-signed T-szamla fixture, leaves unsigned ones alone', async () => {
    const fixtures = Object.fromEntries(availableFixtures(FIXTURES));
    if (fixtures['T-szamla.pdf']) {
      // Telekom invoice: genuinely PAdES-signed (/SigFlags 3, /Type /Sig).
      const signedReport = await analyze(loadFixture(fixtures['T-szamla.pdf']));
      expect(signedReport.signed).toBe(true);
    }
    for (const name of ['test_doc.pdf', '0000054.pdf']) {
      if (!fixtures[name]) continue;
      const report = await analyze(loadFixture(fixtures[name]));
      expect(report.signed).toBe(false);
      expect(report.xfa).toBe(false);
      expect(report.portfolio).toBe(false);
    }
  });
});
