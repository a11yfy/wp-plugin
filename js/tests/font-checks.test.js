import { describe, expect, it } from 'vitest';
import { PDFDocument, PDFName, StandardFonts } from 'pdf-lib';
import { analyze } from '../src/analyze.js';

const getCheck = (report, id) => report.checks.find((c) => c.id === id);

/** A minimal one-page document using an embedded standard font (mappable). */
async function docWithStandardFont() {
  const doc = await PDFDocument.create();
  const page = doc.addPage([300, 300]);
  const font = await doc.embedFont(StandardFonts.Helvetica);
  page.drawText('Hello', { x: 20, y: 250, size: 12, font });
  return doc;
}

/** Attach a raw font dict to the first page's /Font resources. */
function addRawFont(doc, name, fontDict) {
  const page = doc.getPage(0);
  const ref = doc.context.register(doc.context.obj(fontDict));
  page.node.normalizedEntries().Font.set(PDFName.of(name), ref);
}

describe('font checks (10-001 ToUnicode)', () => {
  it('passes for a standard-font document', async () => {
    const doc = await docWithStandardFont();
    const report = await analyze(await doc.save());
    expect(getCheck(report, '10-001').status).toBe('pass');
  });

  it('fails for a Type0 Identity-H font without ToUnicode', async () => {
    const doc = await docWithStandardFont();
    addRawFont(doc, 'FBad', {
      Type: 'Font',
      Subtype: 'Type0',
      BaseFont: 'ABCDEF+NoMap',
      Encoding: 'Identity-H',
      DescendantFonts: [],
    });
    const report = await analyze(await doc.save());
    const check = getCheck(report, '10-001');
    expect(check.status).toBe('fail');
    expect(check.items[0].detail).toContain('Identity-H');
    expect(check.items[0].page).toBe(1);
  });

  it('passes for a Type0 Identity-H font WITH a ToUnicode CMap', async () => {
    const doc = await docWithStandardFont();
    const toUnicode = doc.context.register(doc.context.stream('dummy cmap'));
    addRawFont(doc, 'FMapped', {
      Type: 'Font',
      Subtype: 'Type0',
      BaseFont: 'ABCDEF+Mapped',
      Encoding: 'Identity-H',
      DescendantFonts: [],
      ToUnicode: toUnicode,
    });
    const report = await analyze(await doc.save());
    expect(getCheck(report, '10-001').status).toBe('pass');
  });

  it('fails for a symbolic TrueType font without Encoding or ToUnicode', async () => {
    const doc = await docWithStandardFont();
    const descriptor = doc.context.register(doc.context.obj({
      Type: 'FontDescriptor',
      FontName: 'ABCDEF+Sym',
      Flags: 4, // symbolic
    }));
    addRawFont(doc, 'FSym', {
      Type: 'Font',
      Subtype: 'TrueType',
      BaseFont: 'ABCDEF+Sym',
      FontDescriptor: descriptor,
    });
    const report = await analyze(await doc.save());
    const check = getCheck(report, '10-001');
    expect(check.status).toBe('fail');
    expect(check.items[0].detail).toContain('symbolic TrueType');
  });

  it('passes for a non-symbolic TrueType font without Encoding', async () => {
    const doc = await docWithStandardFont();
    const descriptor = doc.context.register(doc.context.obj({
      Type: 'FontDescriptor',
      FontName: 'ABCDEF+Plain',
      Flags: 32, // non-symbolic
    }));
    addRawFont(doc, 'FPlain', {
      Type: 'Font',
      Subtype: 'TrueType',
      BaseFont: 'ABCDEF+Plain',
      FontDescriptor: descriptor,
    });
    const report = await analyze(await doc.save());
    expect(getCheck(report, '10-001').status).toBe('pass');
  });
});
