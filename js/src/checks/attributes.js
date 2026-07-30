// Group: attributes — required accessibility attributes on structure elements.
import { failOrPass, inapplicable, item, nodeRects, requireStruct } from '../check-utils.js';
import { dfs } from '../struct-walker.js';

function collect(struct, role) {
  const nodes = [];
  dfs(struct, (node) => {
    if (node.role === role) nodes.push(node);
  });
  return nodes;
}

function taggedNodes(ctx, role) {
  const struct = requireStruct(ctx);
  if (!struct.hasStructTreeRoot) return null;
  const nodes = collect(struct, role);
  return nodes.length === 0 ? null : nodes;
}

const hasText = (s) => typeof s === 'string' && s.trim().length > 0;

export const attributeChecks = [
  {
    id: '13-004',
    group: 'attributes',
    // Figure tag alternative or replacement text missing.
    run(ctx) {
      const figures = taggedNodes(ctx, 'Figure');
      if (!figures) return inapplicable();
      const items = [];
      for (const node of figures) {
        if (!hasText(node.alt) && !hasText(node.actualText)) {
          items.push(item(node.page, 'Figure has neither Alt nor ActualText', nodeRects(ctx, node)));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '17-002',
    group: 'attributes',
    // Formula tag is missing an Alt attribute.
    run(ctx) {
      const formulas = taggedNodes(ctx, 'Formula');
      if (!formulas) return inapplicable();
      const items = [];
      for (const node of formulas) {
        if (!hasText(node.alt)) items.push(item(node.page, 'Formula has no Alt attribute', nodeRects(ctx, node)));
      }
      return failOrPass(items);
    },
  },
  {
    id: '15-003',
    group: 'attributes',
    // TH cell (not organized with Headers/IDs) has no Scope attribute.
    run(ctx) {
      const ths = taggedNodes(ctx, 'TH');
      if (!ths) return inapplicable();
      const items = [];
      for (const node of ths) {
        if (!node.attrs.hasHeaders && node.attrs.scope === undefined) {
          items.push(item(node.page, 'TH cell has neither Scope nor Headers attribute', nodeRects(ctx, node)));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '19-003',
    group: 'attributes',
    // ID key of the Note tag is not present.
    run(ctx) {
      const notes = taggedNodes(ctx, 'Note');
      if (!notes) return inapplicable();
      const items = [];
      for (const node of notes) {
        if (!hasText(node.id)) items.push(item(node.page, 'Note element has no ID entry', nodeRects(ctx, node)));
      }
      return failOrPass(items);
    },
  },
  {
    id: '19-004',
    group: 'attributes',
    // ID key of the Note tag is non-unique.
    run(ctx) {
      const notes = taggedNodes(ctx, 'Note');
      if (!notes) return inapplicable();
      const seen = new Map();
      const items = [];
      for (const node of notes) {
        if (!hasText(node.id)) continue; // 19-003 territory
        if (seen.has(node.id)) {
          items.push(item(node.page, `Duplicate Note ID "${node.id}"`, nodeRects(ctx, node)));
        } else {
          seen.set(node.id, node);
        }
      }
      return failOrPass(items);
    },
  },
];
