// Group: annotations — annotation-related requirements (Matterhorn 25/28).
import { KNOWN_ANNOTATION_SUBTYPES } from '../constants.js';
import {
  PDFArray,
  PDFDict,
  PDFRawStream,
  arrayItems,
  dictGet,
  streamText,
} from '../pdflib-utils.js';
import { fail, failOrPass, inapplicable, item, pass, requireStruct } from '../check-utils.js';

const allAnnots = (ctx) => ctx.pagesInfo.flatMap((p) => p.annots);

const hasText = (s) => typeof s === 'string' && s.trim().length > 0;

/**
 * Is the annotation (by its indirect-ref tag) referenced from an OBJR whose
 * enclosing structure element — or one of its ancestors — has the given role?
 * @returns {'ok'|'missing'|'wrong-role'|'unmatchable'}
 */
function objrNesting(struct, annot, role) {
  if (annot.refTag === undefined) return 'unmatchable'; // inline annot dict — cannot pair reliably
  const owners = struct.objrByAnnot.get(annot.refTag);
  if (!owners || owners.length === 0) return 'missing';
  for (const owner of owners) {
    for (let node = owner; node; node = node.parent) {
      if (node.role === role) return 'ok';
    }
  }
  return 'wrong-role';
}

function nestingCheck(ctx, subtype, role) {
  const struct = requireStruct(ctx);
  const annots = allAnnots(ctx).filter((a) => a.subtype === subtype);
  if (annots.length === 0) return inapplicable();
  if (!struct.hasStructTreeRoot) return inapplicable(); // untagged doc — structural checks cover this
  const items = [];
  let unmatchable = 0;
  for (const annot of annots) {
    const state = objrNesting(struct, annot, role);
    if (state === 'missing') {
      items.push(item(annot.page, `${subtype} annotation is not referenced (OBJR) from the structure tree`, annot.rect && [annot.rect]));
    } else if (state === 'wrong-role') {
      items.push(item(annot.page, `${subtype} annotation is not nested within a ${role} tag`, annot.rect && [annot.rect]));
    } else if (state === 'unmatchable') {
      unmatchable++;
    }
  }
  if (items.length === 0 && unmatchable > 0) {
    // Pairing not reliably possible — do not report a false positive.
    throw new Error(`${unmatchable} inline ${subtype} annotation(s) cannot be paired with OBJR entries`);
  }
  return failOrPass(items);
}

/** Does any OBJR owner (or an ancestor of one) carry a non-empty Alt? */
function enclosingAlt(struct, annot) {
  if (annot.refTag === undefined) return false;
  const owners = struct.objrByAnnot.get(annot.refTag) || [];
  for (const owner of owners) {
    for (let node = owner; node; node = node.parent) {
      if (hasText(node.alt)) return true;
    }
  }
  return false;
}

export const annotationChecks = [
  {
    id: '28-004',
    group: 'annotations',
    // A visible annotation other than Link/Widget/Popup has neither a
    // Contents key nor an Alt entry on its enclosing structure element.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable(); // untagged doc — structural checks cover this
      const annots = allAnnots(ctx).filter(
        (a) => a.subtype !== undefined && a.subtype !== 'Link' && a.subtype !== 'Widget' && a.subtype !== 'Popup' && !a.hidden,
      );
      if (annots.length === 0) return inapplicable();
      const items = [];
      for (const annot of annots) {
        if (hasText(annot.contents) || enclosingAlt(struct, annot)) continue;
        items.push(item(annot.page, `${annot.subtype} annotation has neither /Contents nor an enclosing Alt`, annot.rect && [annot.rect]));
      }
      return failOrPass(items);
    },
  },
  {
    id: '28-005',
    group: 'annotations',
    // A visible form field (widget) has neither a /TU tooltip (own or
    // inherited from the field parent chain) nor an enclosing Alt.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable(); // untagged doc — structural checks cover this
      const widgets = allAnnots(ctx).filter((a) => a.subtype === 'Widget' && !a.hidden);
      if (widgets.length === 0) return inapplicable();
      const items = [];
      for (const annot of widgets) {
        if (hasText(annot.tu) || enclosingAlt(struct, annot)) continue;
        items.push(item(annot.page, 'Form field has no /TU tooltip and no enclosing Alt', annot.rect && [annot.rect]));
      }
      return failOrPass(items);
    },
  },
  {
    id: '28-008',
    group: 'annotations',
    // A page containing an annotation does not contain a Tabs key.
    run(ctx) {
      const annotatedPages = ctx.pagesInfo.filter((p) => p.annots.length > 0);
      if (annotatedPages.length === 0) return inapplicable();
      const items = [];
      for (const page of annotatedPages) {
        if (!page.hasTabsKey) items.push(item(page.page, 'Annotated page has no /Tabs key'));
      }
      return failOrPass(items);
    },
  },
  {
    id: '28-009',
    group: 'annotations',
    // A page containing an annotation has a Tabs key with a value other than S.
    run(ctx) {
      const withTabs = ctx.pagesInfo.filter((p) => p.annots.length > 0 && p.hasTabsKey);
      if (withTabs.length === 0) return inapplicable(); // covered by 28-008
      const items = [];
      for (const page of withTabs) {
        if (page.tabs !== 'S') {
          items.push(item(page.page, `Annotated page /Tabs is ${page.tabs ?? 'invalid'} (must be S)`));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '28-012',
    group: 'annotations',
    // A link annotation does not include an alternate description (Contents).
    run(ctx) {
      const links = allAnnots(ctx).filter((a) => a.subtype === 'Link');
      if (links.length === 0) return inapplicable();
      const items = [];
      for (const annot of links) {
        if (!hasText(annot.contents)) {
          items.push(item(annot.page, 'Link annotation has no /Contents alternate description', annot.rect && [annot.rect]));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '28-007',
    group: 'annotations',
    // An annotation of subtype TrapNet exists (prohibited).
    run(ctx) {
      const trapNets = allAnnots(ctx).filter((a) => a.subtype === 'TrapNet');
      return failOrPass(trapNets.map((a) => item(a.page, 'TrapNet annotation present', a.rect && [a.rect])));
    },
  },
  {
    id: '28-006',
    group: 'annotations',
    // An annotation with an undefined subtype.
    run(ctx) {
      const annots = allAnnots(ctx);
      if (annots.length === 0) return inapplicable();
      const items = [];
      for (const annot of annots) {
        if (annot.subtype === undefined || !KNOWN_ANNOTATION_SUBTYPES.has(annot.subtype)) {
          items.push(item(annot.page, `Annotation with unknown subtype ${annot.subtype ?? '(none)'}`, annot.rect && [annot.rect]));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '25-001',
    group: 'annotations',
    // XFA dynamicRender element with a value of "required".
    run(ctx) {
      const acroForm = dictGet(ctx.context, ctx.catalog, 'AcroForm');
      const xfa = acroForm instanceof PDFDict ? dictGet(ctx.context, acroForm, 'XFA') : undefined;
      if (xfa === undefined) return inapplicable();
      const streams = [];
      if (xfa instanceof PDFRawStream) streams.push(xfa);
      else if (xfa instanceof PDFArray) {
        for (const entry of arrayItems(ctx.context, xfa)) {
          if (entry instanceof PDFRawStream) streams.push(entry);
        }
      }
      for (const stream of streams) {
        const text = streamText(stream) ?? '';
        if (/<\s*dynamicRender[^>]*>\s*required\s*</i.test(text)) {
          return fail([item(null, 'XFA config sets dynamicRender=required (dynamic XFA form)')]);
        }
      }
      return pass();
    },
  },
  {
    id: '28-010',
    group: 'annotations',
    // A widget annotation is not nested within a Form tag.
    run(ctx) {
      return nestingCheck(ctx, 'Widget', 'Form');
    },
  },
  {
    id: '28-011',
    group: 'annotations',
    // A link annotation is not nested within a Link tag.
    run(ctx) {
      return nestingCheck(ctx, 'Link', 'Link');
    },
  },
];
