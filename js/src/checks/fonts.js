// Group: fonts — Matterhorn checkpoint 10 (character mappings).
// 10-001: character codes cannot be mapped to Unicode. Structural detection of
// the high-confidence unmappable cases only (reliable-core): ambiguous
// encodings pass rather than risk false positives.
import {
  PDFDict,
  PDFRawStream,
  dictGet,
  dictGetRaw,
  nameStr,
  numVal,
  refTag,
  resolve,
} from '../pdflib-utils.js';
import { failOrPass, inapplicable, item } from '../check-utils.js';

const SYMBOLIC_FLAG = 1 << 2; // FontDescriptor /Flags bit 3

/** All font dicts reachable from page resources (incl. nested Form XObjects). */
function collectFonts(ctx) {
  const fonts = [];
  const seen = new Set();
  const scanResources = (resources, page, depth) => {
    if (!(resources instanceof PDFDict) || depth > 16) return;
    const fontMap = dictGet(ctx.context, resources, 'Font');
    if (fontMap instanceof PDFDict) {
      for (const key of fontMap.keys()) {
        const raw = fontMap.get(key);
        const tag = refTag(raw);
        if (tag !== undefined) {
          if (seen.has(tag)) continue;
          seen.add(tag);
        }
        const font = resolve(ctx.context, raw);
        if (font instanceof PDFDict) fonts.push({ font, page });
      }
    }
    const xobjects = dictGet(ctx.context, resources, 'XObject');
    if (xobjects instanceof PDFDict) {
      for (const key of xobjects.keys()) {
        const raw = xobjects.get(key);
        const tag = refTag(raw);
        if (tag !== undefined) {
          if (seen.has(tag)) continue;
          seen.add(tag);
        }
        const xobj = resolve(ctx.context, raw);
        if (!(xobj instanceof PDFRawStream)) continue;
        if (nameStr(dictGet(ctx.context, xobj.dict, 'Subtype')) !== 'Form') continue;
        scanResources(dictGet(ctx.context, xobj.dict, 'Resources'), page, depth + 1);
      }
    }
  };
  for (const page of ctx.pagesInfo) {
    scanResources(dictGet(ctx.context, page.node, 'Resources'), page.page, 0);
  }
  return fonts;
}

/**
 * Why this font's character codes cannot be mapped to Unicode, or null when a
 * mapping path exists (ToUnicode CMap, predefined CID CMap, encoding glyph
 * names via the Adobe Glyph List, or a non-symbolic standard encoding).
 */
function unmappableReason(ctx, font) {
  if (dictGetRaw(font, 'ToUnicode') !== undefined) return null;
  const subtype = nameStr(dictGet(ctx.context, font, 'Subtype'));
  const base = nameStr(dictGet(ctx.context, font, 'BaseFont'));
  const label = base || subtype || 'font';
  if (subtype === 'Type0') {
    const enc = nameStr(dictGet(ctx.context, font, 'Encoding'));
    if (enc === 'Identity-H' || enc === 'Identity-V') {
      return `${label}: ${enc} encoding without a ToUnicode CMap`;
    }
    return null; // predefined CMaps map through the CID registry/ordering
  }
  if (subtype === 'Type3') {
    const enc = dictGet(ctx.context, font, 'Encoding');
    const hasDifferences = enc instanceof PDFDict && dictGetRaw(enc, 'Differences') !== undefined;
    return hasDifferences ? null : `${label}: Type3 font without Encoding Differences or ToUnicode CMap`;
  }
  if (subtype === 'TrueType') {
    if (dictGetRaw(font, 'Encoding') !== undefined) return null; // glyph-name mappable
    const desc = dictGet(ctx.context, font, 'FontDescriptor');
    const flags = desc instanceof PDFDict ? numVal(dictGet(ctx.context, desc, 'Flags')) : undefined;
    if (typeof flags === 'number' && (flags & SYMBOLIC_FLAG) !== 0) {
      return `${label}: symbolic TrueType font without Encoding or ToUnicode CMap`;
    }
    return null;
  }
  return null; // Type1/MMType1: built-in glyph names map via the AGL
}

export const fontChecks = [
  {
    id: '10-001',
    group: 'fonts',
    run(ctx) {
      const fonts = collectFonts(ctx);
      if (fonts.length === 0) return inapplicable();
      const items = [];
      for (const { font, page } of fonts) {
        const reason = unmappableReason(ctx, font);
        if (reason) items.push(item(page, reason));
      }
      return failOrPass(items);
    },
  },
];
