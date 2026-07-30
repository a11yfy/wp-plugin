// Group: syntax — structure element containment rules (ISO 32000-1 tables
// 333/336/337, Matterhorn 09-004/09-005/09-006). Works on RoleMap-resolved roles.
import { LBL_PARENTS, LIST_CHILD_ROLES, TABLE_CHILD_ROLES, TABLE_ROW_PARENTS, TOC_CHILD_ROLES } from '../constants.js';
import { failOrPass, inapplicable, item, nodeRects, requireStruct } from '../check-utils.js';
import { dfs } from '../struct-walker.js';

function collectRoles(struct, roles) {
  const found = [];
  dfs(struct, (node) => {
    if (roles.has(node.role)) found.push(node);
  });
  return found;
}

const parentRole = (node) => (node.parent ? node.parent.role : '(document root)');

export const syntaxChecks = [
  {
    id: '09-004',
    group: 'syntax',
    // Table structure elements used in a non-conforming way.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const tableNodes = collectRoles(struct, new Set(['Table', 'TR', 'TH', 'TD', 'THead', 'TBody', 'TFoot']));
      if (tableNodes.length === 0) return inapplicable();
      const items = [];
      for (const node of tableNodes) {
        if (node.role === 'TR' && !TABLE_ROW_PARENTS.has(parentRole(node))) {
          items.push(item(node.page, `TR inside ${parentRole(node)} (must be Table/THead/TBody/TFoot)`, nodeRects(ctx, node)));
        }
        if ((node.role === 'TH' || node.role === 'TD') && parentRole(node) !== 'TR') {
          items.push(item(node.page, `${node.role} inside ${parentRole(node)} (must be TR)`, nodeRects(ctx, node)));
        }
        if (node.role === 'Table') {
          for (const child of node.children) {
            if (!TABLE_CHILD_ROLES.has(child.role)) {
              items.push(item(child.page, `Table contains ${child.role} (only TR/THead/TBody/TFoot/Caption allowed)`, nodeRects(ctx, child)));
            }
          }
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '09-005',
    group: 'syntax',
    // List structure elements used in a non-conforming way.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const listNodes = collectRoles(struct, new Set(['L', 'LI', 'Lbl', 'LBody']));
      if (listNodes.length === 0) return inapplicable();
      const items = [];
      for (const node of listNodes) {
        if (node.role === 'LI' && parentRole(node) !== 'L') {
          items.push(item(node.page, `LI inside ${parentRole(node)} (must be L)`, nodeRects(ctx, node)));
        }
        if (node.role === 'L') {
          for (const child of node.children) {
            if (!LIST_CHILD_ROLES.has(child.role)) {
              items.push(item(child.page, `L contains ${child.role} (only LI/Caption/L allowed)`, nodeRects(ctx, child)));
            }
          }
        }
        if (node.role === 'Lbl' && !LBL_PARENTS.has(parentRole(node))) {
          items.push(item(node.page, `Lbl inside ${parentRole(node)} (must be LI)`, nodeRects(ctx, node)));
        }
        if (node.role === 'LBody' && parentRole(node) !== 'LI') {
          items.push(item(node.page, `LBody inside ${parentRole(node)} (must be LI)`, nodeRects(ctx, node)));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '09-006',
    group: 'syntax',
    // TOC structure elements used in a non-conforming way.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const tocNodes = collectRoles(struct, new Set(['TOC', 'TOCI']));
      if (tocNodes.length === 0) return inapplicable();
      const items = [];
      for (const node of tocNodes) {
        if (node.role === 'TOCI' && parentRole(node) !== 'TOC') {
          items.push(item(node.page, `TOCI inside ${parentRole(node)} (must be TOC)`, nodeRects(ctx, node)));
        }
        if (node.role === 'TOC') {
          for (const child of node.children) {
            if (!TOC_CHILD_ROLES.has(child.role)) {
              items.push(item(child.page, `TOC contains ${child.role} (only TOC/TOCI/Caption allowed)`, nodeRects(ctx, child)));
            }
          }
        }
      }
      return failOrPass(items);
    },
  },
];
