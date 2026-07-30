// Group: metadata — XMP metadata, viewer preferences, document language.
import { PDFDict, PDFName, PDFRawStream, boolVal, dictGet, dictGetRaw, streamText, textStr } from '../pdflib-utils.js';
import { fail, inapplicable, item, pass } from '../check-utils.js';

/** Lazily extract + cache the decoded XMP metadata XML (or null). */
export function getXmpText(ctx) {
  if (ctx._xmpText === undefined) {
    const meta = dictGet(ctx.context, ctx.catalog, 'Metadata');
    ctx._xmpText = meta instanceof PDFRawStream ? streamText(meta) ?? null : null;
  }
  return ctx._xmpText;
}

export const metadataChecks = [
  {
    id: '06-001',
    group: 'metadata',
    // Document does not contain an XMP metadata stream.
    run(ctx) {
      const meta = dictGet(ctx.context, ctx.catalog, 'Metadata');
      if (meta instanceof PDFRawStream) return pass();
      return fail([item(null, 'Document catalog has no /Metadata XMP stream')]);
    },
  },
  {
    id: '06-002',
    group: 'metadata',
    // Metadata stream does not include the PDF/UA identifier (pdfuaid:part).
    run(ctx) {
      const xmp = getXmpText(ctx);
      if (xmp === null) return inapplicable(); // covered by 06-001
      const hasPdfuaId = /pdfuaid:part\s*(=|>)/.test(xmp)
        || (/http:\/\/www\.aiim\.org\/pdfua\/ns\/id\//.test(xmp) && /part/.test(xmp));
      if (hasPdfuaId) return pass();
      return fail([item(null, 'XMP metadata has no pdfuaid:part (PDF/UA identifier)')]);
    },
  },
  {
    id: '06-003',
    group: 'metadata',
    // Metadata stream does not contain dc:title.
    run(ctx) {
      const xmp = getXmpText(ctx);
      if (xmp === null) return inapplicable(); // covered by 06-001
      if (/<dc:title\s*\/>/.test(xmp) || !/<dc:title[\s>]/.test(xmp)) {
        return fail([item(null, 'XMP metadata has no dc:title entry')]);
      }
      return pass();
    },
  },
  {
    id: '07-001',
    group: 'metadata',
    // ViewerPreferences dictionary does not contain DisplayDocTitle.
    run(ctx) {
      const vp = dictGet(ctx.context, ctx.catalog, 'ViewerPreferences');
      if (vp instanceof PDFDict && dictGetRaw(vp, 'DisplayDocTitle') !== undefined) return pass();
      return fail([
        item(null, vp instanceof PDFDict
          ? 'ViewerPreferences has no DisplayDocTitle key'
          : 'Document has no ViewerPreferences dictionary (DisplayDocTitle missing)'),
      ]);
    },
  },
  {
    id: '07-002',
    group: 'metadata',
    // ViewerPreferences contains DisplayDocTitle with a value of false.
    run(ctx) {
      const vp = dictGet(ctx.context, ctx.catalog, 'ViewerPreferences');
      if (!(vp instanceof PDFDict)) return inapplicable();
      const ddt = dictGet(ctx.context, vp, 'DisplayDocTitle');
      if (ddt === undefined) return inapplicable(); // covered by 07-001
      if (boolVal(ddt) === false) return fail([item(null, 'DisplayDocTitle is false')]);
      return pass();
    },
  },
  {
    id: '11-006',
    group: 'metadata',
    // Natural language for document metadata cannot be determined (catalog /Lang).
    run(ctx) {
      const lang = textStr(dictGet(ctx.context, ctx.catalog, 'Lang'));
      if (typeof lang === 'string' && lang.trim().length > 0) return pass();
      return fail([
        item(null, ctx.catalog.get(PDFName.of('Lang')) === undefined
          ? 'Document catalog has no /Lang entry'
          : 'Document catalog /Lang is empty'),
      ]);
    },
  },
];
