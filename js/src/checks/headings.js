// Group: headings — heading level usage (Matterhorn checkpoint 14).
import { failOrPass, inapplicable, item, nodeRects, requireStruct } from '../check-utils.js';
import { dfs } from '../struct-walker.js';

const NUMBERED_RE = /^H([1-9][0-9]*)$/;

/** Headings in document (tree) order: { node, level|null } — level null = plain H. */
function collectHeadings(struct) {
  const headings = [];
  dfs(struct, (node) => {
    if (node.role === 'H') headings.push({ node, level: null });
    else {
      const m = NUMBERED_RE.exec(node.role);
      if (m) headings.push({ node, level: parseInt(m[1], 10) });
    }
  });
  return headings;
}

function applicableHeadings(ctx) {
  const struct = requireStruct(ctx);
  if (!struct.hasStructTreeRoot) return null;
  const headings = collectHeadings(struct);
  return headings.length === 0 ? null : headings;
}

export const headingChecks = [
  {
    id: '14-002',
    group: 'headings',
    // Uses numbered headings, but the first heading tag is not H1.
    run(ctx) {
      const headings = applicableHeadings(ctx);
      if (!headings) return inapplicable();
      const numbered = headings.filter((h) => h.level !== null);
      if (numbered.length === 0) return inapplicable();
      const first = numbered[0];
      if (first.level !== 1) {
        return failOrPass([item(first.node.page, `First numbered heading is H${first.level}, not H1`, nodeRects(ctx, first.node))]);
      }
      return failOrPass([]);
    },
  },
  {
    id: '14-003',
    group: 'headings',
    // Numbered heading levels in descending sequence are skipped (e.g. H1 -> H3).
    run(ctx) {
      const headings = applicableHeadings(ctx);
      if (!headings) return inapplicable();
      const numbered = headings.filter((h) => h.level !== null);
      if (numbered.length < 2) return numbered.length === 0 ? inapplicable() : failOrPass([]);
      const items = [];
      for (let i = 1; i < numbered.length; i++) {
        const prev = numbered[i - 1].level;
        const cur = numbered[i].level;
        if (cur > prev + 1) {
          items.push(item(numbered[i].node.page, `Heading level jump H${prev} → H${cur}`, nodeRects(ctx, numbered[i].node)));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '14-006',
    group: 'headings',
    // A node contains more than one H tag.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!struct.hasStructTreeRoot) return inapplicable();
      if (!collectHeadings(struct).some((h) => h.level === null)) return inapplicable();
      const items = [];
      dfs(struct, (node) => {
        const hChildren = node.children.filter((c) => c.role === 'H');
        if (hChildren.length > 1) {
          items.push(item(node.page, `${node.role} element contains ${hChildren.length} H tags`));
        }
      });
      return failOrPass(items);
    },
  },
  {
    id: '14-007',
    group: 'headings',
    // Document uses both H and H# tags.
    run(ctx) {
      const headings = applicableHeadings(ctx);
      if (!headings) return inapplicable();
      const hasPlain = headings.some((h) => h.level === null);
      const hasNumbered = headings.some((h) => h.level !== null);
      if (hasPlain && hasNumbered) {
        return failOrPass([item(null, 'Document mixes unnumbered H and numbered H# heading tags')]);
      }
      return failOrPass([]);
    },
  },
];
