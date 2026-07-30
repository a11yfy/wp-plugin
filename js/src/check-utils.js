// Shared helpers for check implementations and result shaping.
import { MAX_DETAIL_LENGTH, MAX_ITEMS_PER_CHECK } from './constants.js';
import { severityOf } from './severities.js';

export function truncateDetail(s) {
  const str = String(s);
  return str.length > MAX_DETAIL_LENGTH ? `${str.slice(0, MAX_DETAIL_LENGTH - 1)}…` : str;
}

const MAX_RECTS_PER_ITEM = 8;

/**
 * Build one item entry for the report. Optional rects: PDF user-space
 * [x1, y1, x2, y2] boxes for the viewer overlay (capped, page-anchored).
 */
export function item(page, detail, rects) {
  const entry = { page: Number.isInteger(page) ? page : null, detail: truncateDetail(detail) };
  if (Array.isArray(rects) && rects.length > 0) {
    entry.rects = rects
      .filter((r) => Array.isArray(r) && r.length === 4 && r.every(Number.isFinite))
      .slice(0, MAX_RECTS_PER_ITEM);
  }
  return entry;
}

export const pass = () => ({ status: 'pass', count: 0, items: [] });
export const inapplicable = () => ({ status: 'inapplicable', count: 0, items: [] });

/** Fail with a list of offending items (count = total, items capped). */
export function fail(items, count) {
  return {
    status: 'fail',
    count: count ?? items.length,
    items: items.slice(0, MAX_ITEMS_PER_CHECK),
  };
}

/** fail when items non-empty, otherwise pass. */
export function failOrPass(items, count) {
  return items.length > 0 || (count ?? 0) > 0 ? fail(items, count) : pass();
}

/** Throw if the structure walker crashed — the check will report 'error'. */
export function requireStruct(ctx) {
  if (ctx.structError) throw ctx.structError;
  return ctx.struct;
}

/**
 * Viewer rects for a structure element: its MCID anchors resolved against the
 * content pass. Only rects on the node's own page are returned (one box per
 * MCID, capped by item()).
 */
export function nodeRects(ctx, node) {
  const byPage = ctx.text && ctx.text.mcidRects;
  if (!byPage || !node || !Array.isArray(node.mcids)) return undefined;
  const rects = [];
  for (const { page, mcid } of node.mcids) {
    if (page !== node.page) continue; // item() anchors to node.page
    const rect = byPage[page] && byPage[page][mcid];
    if (rect) rects.push(rect);
  }
  return rects.length > 0 ? rects : undefined;
}

/** Run one check defensively. */
export function runCheck(check, ctx) {
  try {
    const result = check.run(ctx);
    return {
      id: check.id,
      group: check.group,
      severity: severityOf(check.id),
      status: result.status,
      count: result.count ?? 0,
      items: result.items ?? [],
    };
  } catch (err) {
    return {
      id: check.id,
      group: check.group,
      severity: severityOf(check.id),
      status: 'error',
      count: 0,
      items: [item(null, `check crashed: ${err && err.message ? err.message : err}`)],
    };
  }
}
