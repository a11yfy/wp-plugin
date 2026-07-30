// Page-level info collected with pdf-lib: /Tabs, /Annots (with subtype,
// contents, rect, flags, TU and the annotation's own indirect ref for OBJR
// pairing).
import {
  PDFArray,
  PDFDict,
  arrayItems,
  arrayItemsRaw,
  dictGet,
  dictGetRaw,
  nameStr,
  numVal,
  refTag,
  resolve,
  textStr,
} from './pdflib-utils.js';

/** Normalized annotation /Rect as [x1, y1, x2, y2] (or undefined). */
function annotRect(context, dict) {
  const arr = dictGet(context, dict, 'Rect');
  if (!(arr instanceof PDFArray)) return undefined;
  const nums = arrayItems(context, arr).map((o) => numVal(o));
  if (nums.length !== 4 || !nums.every(Number.isFinite)) return undefined;
  return [
    Math.min(nums[0], nums[2]), Math.min(nums[1], nums[3]),
    Math.max(nums[0], nums[2]), Math.max(nums[1], nums[3]),
  ];
}

/** /TU of a widget annotation, following the field /Parent chain (bounded). */
function widgetTU(context, dict) {
  const seen = new Set();
  let current = dict;
  for (let depth = 0; depth < 8 && current instanceof PDFDict; depth++) {
    const tu = textStr(dictGet(context, current, 'TU'));
    if (tu !== undefined) return tu;
    const parentRaw = dictGetRaw(current, 'Parent');
    const tag = refTag(parentRaw);
    if (tag !== undefined) {
      if (seen.has(tag)) break;
      seen.add(tag);
    }
    current = resolve(context, parentRaw);
  }
  return undefined;
}

/** Map of page ref tag -> 1-based page number. */
export function buildPageRefMap(doc) {
  const map = new Map();
  const pages = doc.getPages();
  for (let i = 0; i < pages.length; i++) {
    const tag = refTag(pages[i].ref);
    if (tag !== undefined) map.set(tag, i + 1);
  }
  return map;
}

export function collectPagesInfo(doc) {
  const context = doc.context;
  const pages = doc.getPages();
  const result = [];
  for (let i = 0; i < pages.length; i++) {
    const node = pages[i].node;
    const tabs = nameStr(dictGet(context, node, 'Tabs'));
    const annotsObj = dictGet(context, node, 'Annots');
    const annots = [];
    if (annotsObj instanceof PDFArray) {
      for (const raw of arrayItemsRaw(annotsObj)) {
        const dict = resolve(context, raw);
        if (!(dict instanceof PDFDict)) continue;
        const subtype = nameStr(dictGet(context, dict, 'Subtype'));
        const flags = numVal(resolve(context, dictGetRaw(dict, 'F')));
        annots.push({
          page: i + 1,
          refTag: refTag(raw), // undefined when the annot is an inline dict
          subtype,
          contents: textStr(dictGet(context, dict, 'Contents')),
          rect: annotRect(context, dict),
          // eslint-disable-next-line no-bitwise
          hidden: (((flags ?? 0) | 0) & 0x2) !== 0,
          tu: subtype === 'Widget' ? widgetTU(context, dict) : undefined,
          dict,
        });
      }
    }
    result.push({ page: i + 1, node, tabs, hasTabsKey: dictGetRaw(node, 'Tabs') !== undefined, annots });
  }
  return result;
}
