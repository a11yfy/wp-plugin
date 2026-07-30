// Group: misc — optional content, embedded files, reference XObjects,
// language propagation (document-level and structure-tree level).
import {
  PDFArray,
  PDFDict,
  PDFRawStream,
  arrayItems,
  dictGet,
  dictGetRaw,
  nameStr,
  refTag,
  resolve,
  textStr,
} from '../pdflib-utils.js';
import { failOrPass, inapplicable, item, nodeRects, requireStruct } from '../check-utils.js';
import { DETECTABLE_LANGS, detectLanguage } from '../lang-detect.js';

function getOcConfigs(ctx) {
  const oc = dictGet(ctx.context, ctx.catalog, 'OCProperties');
  if (!(oc instanceof PDFDict)) return null;
  const d = dictGet(ctx.context, oc, 'D');
  const configsArr = dictGet(ctx.context, oc, 'Configs');
  const configs = configsArr instanceof PDFArray
    ? arrayItems(ctx.context, configsArr).filter((c) => c instanceof PDFDict)
    : [];
  return { d: d instanceof PDFDict ? d : undefined, configs };
}

/** Traverse the EmbeddedFiles name tree, returning file specification dicts. */
function collectEmbeddedFileSpecs(ctx) {
  const names = dictGet(ctx.context, ctx.catalog, 'Names');
  if (!(names instanceof PDFDict)) return [];
  const root = dictGet(ctx.context, names, 'EmbeddedFiles');
  if (!(root instanceof PDFDict)) return [];
  const specs = [];
  const visited = new Set();
  const walk = (node, depth) => {
    if (!(node instanceof PDFDict) || depth > 64) return;
    const kids = dictGet(ctx.context, node, 'Kids');
    if (kids instanceof PDFArray) {
      for (const raw of kids.asArray()) {
        const tag = refTag(raw);
        if (tag !== undefined) {
          if (visited.has(tag)) continue;
          visited.add(tag);
        }
        walk(resolve(ctx.context, raw), depth + 1);
      }
    }
    const entries = dictGet(ctx.context, node, 'Names');
    if (entries instanceof PDFArray) {
      const arr = arrayItems(ctx.context, entries);
      for (let i = 1; i < arr.length; i += 2) {
        if (arr[i] instanceof PDFDict) specs.push(arr[i]);
      }
    }
  };
  walk(root, 0);
  return specs;
}

/** Recursively scan page resources for Form XObjects carrying a /Ref key. */
function findReferenceXObjects(ctx) {
  const found = [];
  const visited = new Set();
  const scanResources = (resources, page, depth) => {
    if (!(resources instanceof PDFDict) || depth > 16) return;
    const xobjects = dictGet(ctx.context, resources, 'XObject');
    if (!(xobjects instanceof PDFDict)) return;
    for (const key of xobjects.keys()) {
      const raw = xobjects.get(key);
      const tag = refTag(raw);
      if (tag !== undefined) {
        if (visited.has(tag)) continue;
        visited.add(tag);
      }
      const xobj = resolve(ctx.context, raw);
      if (!(xobj instanceof PDFRawStream)) continue;
      const subtype = nameStr(dictGet(ctx.context, xobj.dict, 'Subtype'));
      if (subtype !== 'Form') continue;
      if (dictGetRaw(xobj.dict, 'Ref') !== undefined) {
        found.push({ page, name: nameStr(key) });
      }
      scanResources(dictGet(ctx.context, xobj.dict, 'Resources'), page, depth + 1);
    }
  };
  for (const page of ctx.pagesInfo) {
    scanResources(dictGet(ctx.context, page.node, 'Resources'), page.page, 0);
  }
  return found;
}

/** Collect outline items (bounded, cycle-safe). Shared with checks/quality.js. */
export function collectOutlineItems(ctx) {
  const outlines = dictGet(ctx.context, ctx.catalog, 'Outlines');
  if (!(outlines instanceof PDFDict)) return [];
  const items = [];
  const visited = new Set();
  const walk = (node, depth) => {
    let current = node;
    while (current instanceof PDFDict && depth <= 64 && items.length < 5000) {
      const title = textStr(dictGet(ctx.context, current, 'Title'));
      if (title !== undefined) items.push({ title });
      const firstRaw = dictGetRaw(current, 'First');
      const firstTag = refTag(firstRaw);
      if (firstTag !== undefined && !visited.has(firstTag)) {
        visited.add(firstTag);
        walk(resolve(ctx.context, firstRaw), depth + 1);
      }
      const nextRaw = dictGetRaw(current, 'Next');
      const nextTag = refTag(nextRaw);
      if (nextTag === undefined || visited.has(nextTag)) break;
      visited.add(nextTag);
      current = resolve(ctx.context, nextRaw);
    }
  };
  walk(resolve(ctx.context, dictGetRaw(outlines, 'First')), 0);
  return items;
}

const docLang = (ctx) => {
  const lang = textStr(dictGet(ctx.context, ctx.catalog, 'Lang'));
  return typeof lang === 'string' && lang.trim().length > 0 ? lang : undefined;
};

const emptyName = (obj) => {
  const s = textStr(obj);
  return s === undefined || s.trim().length === 0;
};

export const miscChecks = [
  {
    id: '20-001',
    group: 'misc',
    // Name entry omitted/empty in an Optional Content Configuration Dictionary.
    run(ctx) {
      const oc = getOcConfigs(ctx);
      if (!oc || oc.configs.length === 0) return inapplicable();
      const items = [];
      oc.configs.forEach((config, i) => {
        if (emptyName(dictGet(ctx.context, config, 'Name'))) {
          items.push(item(null, `OCProperties/Configs[${i}] has no (non-empty) Name`));
        }
      });
      return failOrPass(items);
    },
  },
  {
    id: '20-002',
    group: 'misc',
    // Name entry omitted/empty in the default OC Configuration (D).
    run(ctx) {
      const oc = getOcConfigs(ctx);
      if (!oc || !oc.d) return inapplicable();
      if (emptyName(dictGet(ctx.context, oc.d, 'Name'))) {
        return failOrPass([item(null, 'OCProperties/D has no (non-empty) Name')]);
      }
      return failOrPass([]);
    },
  },
  {
    id: '20-003',
    group: 'misc',
    // An AS key appears in an Optional Content Configuration Dictionary.
    run(ctx) {
      const oc = getOcConfigs(ctx);
      if (!oc) return inapplicable();
      const items = [];
      if (oc.d && dictGetRaw(oc.d, 'AS') !== undefined) {
        items.push(item(null, 'OCProperties/D contains an AS key'));
      }
      oc.configs.forEach((config, i) => {
        if (dictGetRaw(config, 'AS') !== undefined) {
          items.push(item(null, `OCProperties/Configs[${i}] contains an AS key`));
        }
      });
      return failOrPass(items);
    },
  },
  {
    id: '21-001',
    group: 'misc',
    // Embedded file specification dictionary lacks F and/or UF keys.
    run(ctx) {
      const specs = collectEmbeddedFileSpecs(ctx);
      if (specs.length === 0) return inapplicable();
      const items = [];
      specs.forEach((spec, i) => {
        const missing = [];
        if (dictGetRaw(spec, 'F') === undefined) missing.push('F');
        if (dictGetRaw(spec, 'UF') === undefined) missing.push('UF');
        if (missing.length > 0) {
          items.push(item(null, `Embedded file spec #${i + 1} is missing ${missing.join(' and ')}`));
        }
      });
      return failOrPass(items);
    },
  },
  {
    id: '30-001',
    group: 'misc',
    // A reference XObject is present (prohibited in PDF/UA-1).
    run(ctx) {
      const found = findReferenceXObjects(ctx);
      return failOrPass(found.map((f) => item(f.page, `Reference XObject ${f.name ?? ''} (/Ref key present)`)));
    },
  },
  {
    id: '11-001',
    group: 'misc',
    // Natural language of page content cannot be determined: no catalog /Lang
    // and structure branches carrying content have no Lang of their own.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      if (docLang(ctx) !== undefined) return failOrPass([]);
      const offenders = [];
      const walk = (node, inheritedLang) => {
        const covered = inheritedLang || (typeof node.lang === 'string' && node.lang.trim().length > 0);
        if (!covered && node.hasMcidContent) offenders.push(node);
        for (const child of node.children) walk(child, covered);
      };
      for (const root of struct.roots) walk(root, false);
      return failOrPass(
        offenders.slice(0, 20).map((n) => item(n.page, `${n.role} content has no determinable language (no /Lang anywhere)`, nodeRects(ctx, n))),
        offenders.length,
      );
    },
  },
  {
    id: 'lang-mismatch',
    group: 'misc',
    // Plugin extra: the declared catalog /Lang does not match the language the
    // page text is actually written in (stopword/script detection, see
    // lang-detect.js). Conservative: only fires on a confident detection.
    run(ctx) {
      const declared = docLang(ctx);
      if (declared === undefined) return inapplicable(); // 11-001 covers missing
      const primary = declared.trim().toLowerCase().split(/[-_]/)[0];
      if (!DETECTABLE_LANGS.has(primary)) return inapplicable();
      const detected = detectLanguage(ctx.text && ctx.text.textSample);
      if (detected === null) return inapplicable();
      if (detected === primary) return failOrPass([]);
      return failOrPass([
        item(null, `Document declares /Lang "${declared}" but the text reads as "${detected}"`),
      ]);
    },
  },
  {
    id: '11-003',
    group: 'misc',
    // Natural language of Outline entries cannot be determined (document-level
    // variant: outlines exist but no catalog /Lang provides a language).
    run(ctx) {
      const outlineItems = collectOutlineItems(ctx);
      if (outlineItems.length === 0) return inapplicable();
      if (docLang(ctx) !== undefined) return failOrPass([]);
      return failOrPass(
        [item(null, `${outlineItems.length} outline entries have no determinable language (no catalog /Lang)`)],
        outlineItems.length,
      );
    },
  },
];
