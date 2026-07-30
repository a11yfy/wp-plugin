// Group: quality — advisory best-practice warnings beyond PDF/UA conformance.
// These are heuristics ("probably worth fixing"), not Matterhorn failures:
// the group is excluded from the score and the compliant gate (see report.js).
import { failOrPass, inapplicable, item, nodeRects, requireStruct } from '../check-utils.js';
import { dfs } from '../struct-walker.js';
import { collectOutlineItems } from './misc.js';

const HEADING_ROLE = /^H[1-6]?$/;
// A document this long without navigation aids is a real usability problem.
const OUTLINE_MIN_PAGES = 10;
const HEADINGS_MIN_CHARS = 1500;

/** Count descendants (node included) whose role matches. */
function countRole(node, role) {
  let count = node.role === role ? 1 : 0;
  for (const child of node.children) count += countRole(child, role);
  return count;
}

const FILENAME_ALT = /\.(png|jpe?g|gif|tiff?|bmp|svg|webp|eps|pdf)\s*$/i;
const GENERIC_ALT = /^(image|img|picture|pic|photo|figure|fig|graphic|graphics|untitled|dsc|screenshot|scan)[\s_-]*\d*$/i;

/** Why an Alt text looks like a placeholder, or null when it seems genuine. */
function suspectAltReason(alt) {
  const trimmed = alt.trim();
  if (trimmed.length < 3) return 'is too short to describe anything';
  if (FILENAME_ALT.test(trimmed)) return 'looks like a file name';
  if (GENERIC_ALT.test(trimmed)) return 'is a generic placeholder';
  return null;
}

export const qualityChecks = [
  {
    id: 'quality-no-headings',
    group: 'quality',
    // A tagged document with substantial text but no heading elements at all.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const totalChars = ctx.text ? ctx.text.totalChars : 0;
      if (totalChars < HEADINGS_MIN_CHARS) return inapplicable();
      let headings = 0;
      dfs(struct, (node) => {
        if (HEADING_ROLE.test(node.role)) headings += 1;
      });
      if (headings > 0) return failOrPass([]);
      return failOrPass([item(null, 'Document has substantial text but no heading elements (H1–H6)')]);
    },
  },
  {
    id: 'quality-no-outline',
    group: 'quality',
    // A long document without bookmarks (document outline).
    run(ctx) {
      if (ctx.pagesInfo.length < OUTLINE_MIN_PAGES) return inapplicable();
      if (collectOutlineItems(ctx).length > 0) return failOrPass([]);
      return failOrPass([
        item(null, `Document has ${ctx.pagesInfo.length} pages but no bookmarks (outline)`),
      ]);
    },
  },
  {
    id: 'quality-table-no-th',
    group: 'quality',
    // A data table (2+ rows) without a single TH header cell.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const tables = [];
      dfs(struct, (node) => {
        if (node.role === 'Table') tables.push(node);
      });
      if (tables.length === 0) return inapplicable();
      const items = [];
      for (const table of tables) {
        if (countRole(table, 'TR') >= 2 && countRole(table, 'TH') === 0) {
          items.push(item(table.page, 'Table has no header cells (TH)', nodeRects(ctx, table)));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: 'quality-suspect-alt',
    group: 'quality',
    // Figure/Formula Alt text that is present but looks like a placeholder.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      const items = [];
      dfs(struct, (node) => {
        if (node.role !== 'Figure' && node.role !== 'Formula') return;
        if (typeof node.alt !== 'string' || node.alt.trim().length === 0) return; // 13-004/17-002 cover missing
        const reason = suspectAltReason(node.alt);
        if (reason) {
          items.push(item(node.page, `${node.role} Alt "${node.alt.trim().slice(0, 40)}" ${reason}`, nodeRects(ctx, node)));
        }
      });
      return failOrPass(items);
    },
  },
];
