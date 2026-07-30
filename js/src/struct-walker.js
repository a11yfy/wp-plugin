// Shared structure-tree walker. Recursively walks StructTreeRoot /K, resolves
// the RoleMap, and collects everything the individual checks need:
// roles (raw + mapped), Alt/ActualText/Lang/ID, /A attributes (Scope, Headers,
// ListNumbering), page association, OBJR annotation references and MCID
// content presence.
import { STANDARD_TYPES } from './constants.js';
import {
  PDFArray,
  PDFDict,
  PDFName,
  PDFNumber,
  arrayItemsRaw,
  boolVal,
  dictGet,
  dictGetRaw,
  nameStr,
  numVal,
  refTag,
  resolve,
  textStr,
} from './pdflib-utils.js';

const MAX_DEPTH = 512;

/**
 * Resolve a raw role through the RoleMap until it reaches a standard type,
 * the chain ends, or a cycle is detected.
 * @returns {{ terminal: string, chain: string[], cyclic: boolean }}
 */
export function mapRole(rawRole, roleMap) {
  const chain = [rawRole];
  const seen = new Set([rawRole]);
  let current = rawRole;
  while (!STANDARD_TYPES.has(current) && roleMap.has(current)) {
    const next = roleMap.get(current);
    if (seen.has(next)) {
      chain.push(next);
      return { terminal: next, chain, cyclic: true };
    }
    seen.add(next);
    chain.push(next);
    current = next;
  }
  return { terminal: current, chain, cyclic: false };
}

/** Read the /A attribute entry (dict | array of dicts/numbers | ref). */
function readAttributes(context, elemDict) {
  const attrs = { scope: undefined, hasHeaders: false, listNumbering: undefined };
  const a = dictGet(context, elemDict, 'A');
  const dicts = [];
  if (a instanceof PDFDict) dicts.push(a);
  else if (a instanceof PDFArray) {
    for (const item of a.asArray()) {
      const r = resolve(context, item);
      if (r instanceof PDFDict) dicts.push(r);
      // PDFNumber items are revision markers — skip.
    }
  }
  for (const d of dicts) {
    const scope = dictGet(context, d, 'Scope');
    if (scope instanceof PDFName && attrs.scope === undefined) attrs.scope = nameStr(scope);
    const headers = dictGet(context, d, 'Headers');
    if (headers instanceof PDFArray && headers.size() > 0) attrs.hasHeaders = true;
    const listNumbering = dictGet(context, d, 'ListNumbering');
    if (listNumbering instanceof PDFName && attrs.listNumbering === undefined) {
      attrs.listNumbering = nameStr(listNumbering);
    }
  }
  return attrs;
}

/**
 * Walk the structure tree of a pdf-lib document.
 * @param {import('pdf-lib').PDFDocument} doc
 * @param {Map<string, number>} pageRefToNum map of page ref tag -> 1-based page number
 */
export function buildStructInfo(doc, pageRefToNum) {
  const context = doc.context;
  const catalog = doc.catalog;

  const markInfo = dictGet(context, catalog, 'MarkInfo');
  const marked = markInfo instanceof PDFDict ? boolVal(dictGet(context, markInfo, 'Marked')) : undefined;
  const suspects = markInfo instanceof PDFDict ? boolVal(dictGet(context, markInfo, 'Suspects')) : undefined;

  const structTreeRoot = dictGet(context, catalog, 'StructTreeRoot');
  const hasStructTreeRoot = structTreeRoot instanceof PDFDict;

  // RoleMap: raw role -> mapped role (single hop).
  const roleMap = new Map();
  if (hasStructTreeRoot) {
    const rm = dictGet(context, structTreeRoot, 'RoleMap');
    if (rm instanceof PDFDict) {
      for (const key of rm.keys()) {
        const from = nameStr(key);
        const to = resolve(context, rm.get(key));
        if (from !== undefined && to instanceof PDFName) roleMap.set(from, nameStr(to));
      }
    }
  }

  const info = {
    hasStructTreeRoot,
    marked,
    suspects,
    roleMap,
    roots: [],
    all: [],
    // annotation ref tag -> array of element nodes whose /K holds an OBJR to it
    objrByAnnot: new Map(),
    truncated: false,
  };
  if (!hasStructTreeRoot) return info;

  const visitedElems = new Set();

  const walkElement = (elemDict, elemRef, parent, depth) => {
    if (depth > MAX_DEPTH || info.all.length > 100000) {
      info.truncated = true;
      return null;
    }
    const tag = refTag(elemRef);
    if (tag !== undefined) {
      if (visitedElems.has(tag)) return null; // cycle guard
      visitedElems.add(tag);
    }

    const rawRole = nameStr(dictGet(context, elemDict, 'S')) ?? '';
    const mapped = mapRole(rawRole, roleMap);
    const pg = dictGetRaw(elemDict, 'Pg');
    const pgTag = refTag(pg);
    const node = {
      rawRole,
      role: mapped.terminal,
      parent,
      children: [],
      alt: textStr(dictGet(context, elemDict, 'Alt')),
      actualText: textStr(dictGet(context, elemDict, 'ActualText')),
      lang: textStr(dictGet(context, elemDict, 'Lang')),
      id: (() => {
        const idObj = dictGet(context, elemDict, 'ID');
        return textStr(idObj) ?? (idObj !== undefined ? String(idObj) : undefined);
      })(),
      attrs: readAttributes(context, elemDict),
      page: pgTag !== undefined && pageRefToNum ? pageRefToNum.get(pgTag) ?? null : parent ? parent.page : null,
      hasMcidContent: false,
      // Marked-content anchors of this element: {page, mcid} (capped) — the
      // viewer overlay resolves them to rects via the content pass.
      mcids: [],
    };
    if (node.page === undefined) node.page = null;
    info.all.push(node);
    if (parent) parent.children.push(node);
    else info.roots.push(node);

    const kRaw = dictGetRaw(elemDict, 'K');
    const k = resolve(context, kRaw);
    const kids = [];
    if (k instanceof PDFArray) {
      for (const rawItem of arrayItemsRaw(k)) kids.push(rawItem);
    } else if (k !== undefined) {
      kids.push(kRaw ?? k);
    }

    const pushMcid = (page, mcid) => {
      if (Number.isInteger(mcid) && Number.isInteger(page) && node.mcids.length < 16) {
        node.mcids.push({ page, mcid });
      }
    };

    for (const rawKid of kids) {
      const kid = resolve(context, rawKid);
      if (kid instanceof PDFNumber) {
        node.hasMcidContent = true; // bare MCID
        pushMcid(node.page, numVal(kid));
      } else if (kid instanceof PDFDict) {
        const type = nameStr(dictGet(context, kid, 'Type'));
        if (type === 'OBJR') {
          const objRaw = dictGetRaw(kid, 'Obj');
          const objTag = refTag(objRaw);
          if (objTag !== undefined) {
            if (!info.objrByAnnot.has(objTag)) info.objrByAnnot.set(objTag, []);
            info.objrByAnnot.get(objTag).push(node);
          }
        } else if (type === 'MCR') {
          node.hasMcidContent = true;
          const mcrPgTag = refTag(dictGetRaw(kid, 'Pg'));
          const mcrPage = mcrPgTag !== undefined && pageRefToNum ? pageRefToNum.get(mcrPgTag) ?? node.page : node.page;
          pushMcid(mcrPage, numVal(dictGet(context, kid, 'MCID')));
        } else if (kid.get(PDFName.of('S')) !== undefined || kid.get(PDFName.of('K')) !== undefined) {
          // Nested structure element.
          walkElement(kid, rawKid, node, depth + 1);
        }
      }
    }
    return node;
  };

  const kRaw = dictGetRaw(structTreeRoot, 'K');
  const k = resolve(context, kRaw);
  const rootKids = [];
  if (k instanceof PDFArray) rootKids.push(...arrayItemsRaw(k));
  else if (k !== undefined) rootKids.push(kRaw ?? k);
  for (const rawKid of rootKids) {
    const kid = resolve(context, rawKid);
    if (kid instanceof PDFDict) walkElement(kid, rawKid, null, 0);
  }

  return info;
}

/** Depth-first flat list in document (tree) order. */
export function dfs(info, visit) {
  const stack = [...info.roots].reverse();
  while (stack.length > 0) {
    const node = stack.pop();
    visit(node);
    for (let i = node.children.length - 1; i >= 0; i--) stack.push(node.children[i]);
  }
}
