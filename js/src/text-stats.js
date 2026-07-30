// Unified pdf.js content pass: per-page text statistics (scanned heuristic),
// marked-content coverage (Matterhorn 01-005 deep variant) and image
// positions (figure-untagged). One document open, one page loop — the extra
// cost over the old text-only pass is a single getOperatorList per page.
//
// Coordinates are PDF user space [x1, y1, x2, y2] (origin bottom-left),
// converted by the viewer with pdf.js viewport.convertToViewportRectangle.
import { MAX_TEXT_PAGES, SCANNED_MIN_CHARS_PER_PAGE, SCANNED_PAGE_RATIO } from './constants.js';
import { loadPdfjsDocument, pdfjsOps } from './pdfjs-setup.js';

const MAX_RECTS_PER_PAGE = 8;
// getOperatorList parses every content-stream operator — on huge, graphics-
// heavy documents that is the expensive half of the pass. Image findings are
// collected from the first N pages only (text coverage still runs on all
// MAX_TEXT_PAGES pages); the check's count reflects what was scanned.
const MAX_OPLIST_PAGES = 50;
// Ignore micro-runs (page ornaments, stray glyphs) — a real 01-005 fail needs
// at least this many untagged characters on a page.
const MIN_UNTAGGED_CHARS_PER_PAGE = 10;

/**
 * CTM concatenation for a `cm` operator: identical to pdf.js
 * Util.transform(ctm, args) — the new matrix applies to content first
 * (row-vector convention), so mul(ctm, args) is the correct order.
 */
function mul(m1, m2) {
  return [
    m2[0] * m1[0] + m2[1] * m1[2],
    m2[0] * m1[1] + m2[1] * m1[3],
    m2[2] * m1[0] + m2[3] * m1[2],
    m2[2] * m1[1] + m2[3] * m1[3],
    m2[4] * m1[0] + m2[5] * m1[2] + m1[4],
    m2[4] * m1[1] + m2[5] * m1[3] + m1[5],
  ];
}

/** Bounding box of the CTM-transformed unit square (image placement). */
function unitSquareBBox(m) {
  const xs = [m[4], m[0] + m[4], m[2] + m[4], m[0] + m[2] + m[4]];
  const ys = [m[5], m[1] + m[5], m[3] + m[5], m[1] + m[3] + m[5]];
  return [Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)];
}

/** Bounding box of a CTM-transformed [minX, minY, maxX, maxY] path extent. */
function minMaxBBox(m, [x0, y0, x1, y1]) {
  const pts = [[x0, y0], [x1, y0], [x0, y1], [x1, y1]]
    .map(([x, y]) => [x * m[0] + y * m[2] + m[4], x * m[1] + y * m[3] + m[5]]);
  const xs = pts.map((p) => p[0]);
  const ys = pts.map((p) => p[1]);
  return [Math.min(...xs), Math.min(...ys), Math.max(...xs), Math.max(...ys)];
}

/** Marked-content tag may arrive as a string or as a pdf.js Name ({name}). */
const tagName = (tag) => (typeof tag === 'string' ? tag : tag && typeof tag === 'object' ? tag.name : undefined);

const round1 = (n) => Math.round(n * 10) / 10;
const roundRect = (r) => r.map(round1);

/** Merge text-item boxes that sit on the same baseline into line rects. */
function mergeLineRects(boxes) {
  const lines = [];
  for (const box of boxes) {
    const line = lines.find((l) => Math.abs(l.y - box.y) < Math.max(2, box.h * 0.5));
    if (line) {
      line.rect[0] = Math.min(line.rect[0], box.x);
      line.rect[1] = Math.min(line.rect[1], box.y);
      line.rect[2] = Math.max(line.rect[2], box.x + box.w);
      line.rect[3] = Math.max(line.rect[3], box.y + box.h);
      line.chars += box.chars;
    } else {
      lines.push({ y: box.y, chars: box.chars, rect: [box.x, box.y, box.x + box.w, box.y + box.h] });
    }
  }
  // Largest runs first, capped — the report contract stays small.
  lines.sort((a, b) => b.chars - a.chars);
  return lines.slice(0, MAX_RECTS_PER_PAGE).map((l) => roundRect(l.rect));
}

/** Numeric MCID from a pdf.js marked-content item id ("p5R_mc12" → 12). */
function mcidFromId(id) {
  if (typeof id !== 'string') return null;
  const m = /_mc(\d+)$/.exec(id);
  return m ? parseInt(m[1], 10) : null;
}

/** Expand (or create) a union bbox with a text-item box. */
function unionBox(rect, box) {
  if (!rect) return [box.x, box.y, box.x + box.w, box.y + box.h];
  rect[0] = Math.min(rect[0], box.x);
  rect[1] = Math.min(rect[1], box.y);
  rect[2] = Math.max(rect[2], box.x + box.w);
  rect[3] = Math.max(rect[3], box.y + box.h);
  return rect;
}

/**
 * Marked-content walk of getTextContent(includeMarkedContent) items.
 * "Tagged" = inside a marked-content sequence carrying an MCID; "artifact" =
 * inside an Artifact sequence. Everything else is untagged real content.
 * Also unions a bbox per MCID (struct-element findings anchor to these).
 */
function scanTextItems(items) {
  const stack = [];
  let chars = 0;
  let untaggedChars = 0;
  const untaggedBoxes = [];
  const mcidRects = {};
  for (const it of items) {
    if (it.type === 'beginMarkedContentProps' || it.type === 'beginMarkedContent') {
      stack.push({
        artifact: tagName(it.tag) === 'Artifact',
        mcid: mcidFromId(it.id) ?? (Number.isInteger(it.mcid) ? it.mcid : null),
      });
      continue;
    }
    if (it.type === 'endMarkedContent') {
      if (stack.length > 0) stack.pop();
      continue;
    }
    if (typeof it.str !== 'string') continue;
    const len = it.str.trim().length;
    if (len === 0) continue;
    chars += len;
    const box = Array.isArray(it.transform)
      ? {
        x: it.transform[4],
        y: it.transform[5],
        w: it.width || 0,
        h: it.height || Math.abs(it.transform[3]) || 0,
        chars: len,
      }
      : null;
    const artifact = stack.some((s) => s.artifact);
    // Innermost MCID owns the content (nested Spans carry their own MCID).
    let mcid = null;
    for (let i = stack.length - 1; i >= 0; i--) {
      if (stack[i].mcid !== null) {
        mcid = stack[i].mcid;
        break;
      }
    }
    if (mcid !== null && box) {
      mcidRects[mcid] = unionBox(mcidRects[mcid], box);
    }
    if (!artifact && mcid === null) {
      untaggedChars += len;
      if (box) untaggedBoxes.push(box);
    }
  }
  return { chars, untaggedChars, untaggedBoxes, mcidRects };
}

/**
 * Operator-list walk: image paint positions + their marked-content state,
 * plus untagged vector paints (path fill/stroke, shading) — the veraPDF
 * 7.1-t3 blind spot the char-based coverage cannot see.
 * Conservative: when the MCID cannot be read from the operator arguments the
 * content counts as tagged (no false positives).
 *
 * pdf.js 4.x contract: a `constructPath` op carries [subOps, coords, minMax]
 * and the PAINTING op (fill/stroke/…) follows as a separate operator; a
 * clip + endPath sequence marks nothing. Annotation appearance streams
 * (beginAnnotation/endAnnotation) are skipped — 7.1-t3 is page content only.
 */
function scanOperators(opList, OPS) {
  const images = [];
  const untaggedGraphics = [];
  let graphicsTotal = 0;
  let pendingMinMax = null;
  let annotationDepth = 0;
  const pathPaints = new Set([
    OPS.stroke, OPS.closeStroke, OPS.fill, OPS.eoFill, OPS.fillStroke,
    OPS.eoFillStroke, OPS.closeFillStroke, OPS.closeEOFillStroke,
  ].filter((op) => op !== undefined));
  let ctm = [1, 0, 0, 1, 0, 0];
  const ctmStack = [];
  const mcStack = [];
  for (let i = 0; i < opList.fnArray.length; i++) {
    const fn = opList.fnArray[i];
    const args = opList.argsArray[i];
    if (fn === OPS.save) {
      ctmStack.push(ctm);
    } else if (fn === OPS.restore) {
      // Unbalanced Q keeps the current CTM — resetting to identity would
      // misplace every subsequent image on malformed streams.
      if (ctmStack.length > 0) ctm = ctmStack.pop();
    } else if (fn === OPS.transform) {
      ctm = mul(ctm, args);
    } else if (fn === OPS.beginMarkedContentProps || fn === OPS.beginMarkedContent) {
      const tag = tagName(args && args[0]);
      const props = args && args[1];
      let mcid = null;
      let unknown = false;
      if (Number.isInteger(props)) {
        mcid = props;
      } else if (props && typeof props === 'object' && Number.isInteger(props.mcid)) {
        mcid = props.mcid;
      } else if (props && typeof props === 'object' && Number.isInteger(props.MCID)) {
        mcid = props.MCID;
      } else if (props !== null && props !== undefined && fn === OPS.beginMarkedContentProps) {
        unknown = true; // props present but unreadable — assume tagged
      }
      mcStack.push({ artifact: tag === 'Artifact', mcid, unknown });
    } else if (fn === OPS.endMarkedContent) {
      if (mcStack.length > 0) mcStack.pop();
    } else if (fn === OPS.paintImageXObject || fn === OPS.paintInlineImageXObject) {
      let mcid = null;
      for (let j = mcStack.length - 1; j >= 0; j--) {
        if (mcStack[j].mcid !== null) {
          mcid = mcStack[j].mcid;
          break;
        }
      }
      images.push({
        rect: roundRect(unitSquareBBox(ctm)),
        artifact: mcStack.some((s) => s.artifact),
        tagged: mcStack.some((s) => s.mcid !== null || s.unknown),
        mcid,
      });
    } else if (fn === OPS.beginAnnotation || fn === OPS.beginAnnotations) {
      annotationDepth++;
    } else if (fn === OPS.endAnnotation || fn === OPS.endAnnotations) {
      if (annotationDepth > 0) annotationDepth--;
    } else if (fn === OPS.constructPath) {
      const mm = args && args[2];
      pendingMinMax =
        mm && mm.length >= 4 && [0, 1, 2, 3].every((k) => Number.isFinite(mm[k]))
          ? [mm[0], mm[1], mm[2], mm[3]]
          : null;
    } else if (pathPaints.has(fn) || fn === OPS.shadingFill) {
      if (annotationDepth === 0) {
        graphicsTotal++;
        const artifact = mcStack.some((s) => s.artifact);
        const tagged = mcStack.some((s) => s.mcid !== null || s.unknown);
        if (!artifact && !tagged) {
          // shadingFill fills the current clip region — nincs path-extent.
          const rect =
            fn !== OPS.shadingFill && pendingMinMax
              ? roundRect(minMaxBBox(ctm, pendingMinMax))
              : null;
          untaggedGraphics.push({ rect });
        }
      }
      pendingMinMax = null;
    }
  }
  return { images, untaggedGraphics, graphicsTotal };
}

/**
 * @param {Uint8Array} bytes own copy — pdf.js may transfer/detach the buffer
 * @param {string} [password] user password for encrypted PDFs
 * @returns {Promise<object|null>} null when pdf.js cannot open the document.
 *   Shape: { pages, pagesScanned, perPage, totalChars,
 *            untagged: [{page, chars, totalChars, rects}],
 *            images:   [{page, rect, tagged, artifact}],
 *            untaggedGraphics: [{page, count, rects}], graphicsTotal }
 */
export async function collectTextStats(bytes, password) {
  let doc;
  try {
    doc = await loadPdfjsDocument(bytes, password);
  } catch {
    return null;
  }
  try {
    const OPS = pdfjsOps();
    const pages = doc.numPages;
    const pagesScanned = Math.min(pages, MAX_TEXT_PAGES);
    const perPage = [];
    let totalChars = 0;
    // Unfiltered total — the coverage score-penalty must not inherit the
    // per-page reporting threshold (a compliant-gate blind spot otherwise).
    let untaggedCharsRaw = 0;
    const untagged = [];
    const images = [];
    // Jelöletlen vektor-grafika (path/shading): per-oldal összesítés + rects.
    const untaggedGraphics = [];
    let graphicsTotal = 0;
    const mcidRects = {};
    // Plain-text sample for data-driven language detection (lang-mismatch).
    const SAMPLE_MAX = 6000;
    let textSample = '';
    for (let i = 1; i <= pagesScanned; i++) {
      let pageChars = 0;
      try {
        const page = await doc.getPage(i);
        const textContent = await page.getTextContent({ includeMarkedContent: true });
        if (textSample.length < SAMPLE_MAX) {
          for (const it of textContent.items) {
            if (typeof it.str === 'string' && it.str.length > 0) {
              textSample += `${it.str} `;
              if (textSample.length >= SAMPLE_MAX) break;
            }
          }
        }
        const scan = scanTextItems(textContent.items);
        pageChars = scan.chars;
        untaggedCharsRaw += scan.untaggedChars;
        if (Object.keys(scan.mcidRects).length > 0) {
          mcidRects[i] = {};
          for (const mcid of Object.keys(scan.mcidRects)) {
            mcidRects[i][mcid] = roundRect(scan.mcidRects[mcid]);
          }
        }
        if (scan.untaggedChars >= MIN_UNTAGGED_CHARS_PER_PAGE) {
          untagged.push({
            page: i,
            chars: scan.untaggedChars,
            totalChars: scan.chars,
            rects: mergeLineRects(scan.untaggedBoxes),
          });
        }
        if (i <= MAX_OPLIST_PAGES) {
          try {
            const opList = await page.getOperatorList();
            const opScan = scanOperators(opList, OPS);
            graphicsTotal += opScan.graphicsTotal;
            if (opScan.untaggedGraphics.length > 0) {
              untaggedGraphics.push({
                page: i,
                count: opScan.untaggedGraphics.length,
                rects: opScan.untaggedGraphics
                  .filter((g) => g.rect)
                  .slice(0, MAX_RECTS_PER_PAGE)
                  .map((g) => g.rect),
              });
            }
            for (const img of opScan.images) {
              images.push({ page: i, ...img });
              if (img.mcid !== null) {
                mcidRects[i] = mcidRects[i] || {};
                // Union with any text box of the same MCID (e.g. a Figure
                // holding both the image and its caption text).
                const prev = mcidRects[i][img.mcid];
                mcidRects[i][img.mcid] = prev
                  ? [
                    Math.min(prev[0], img.rect[0]), Math.min(prev[1], img.rect[1]),
                    Math.max(prev[2], img.rect[2]), Math.max(prev[3], img.rect[3]),
                  ]
                  : img.rect;
              }
            }
          } catch {
            // Operator list unavailable — image findings skipped for this page.
          }
        }
      } catch {
        pageChars = 0;
      }
      perPage.push(pageChars);
      totalChars += pageChars;
    }
    return {
      pages,
      pagesScanned,
      perPage,
      totalChars,
      untaggedCharsRaw,
      untagged,
      images,
      untaggedGraphics,
      graphicsTotal,
      mcidRects,
      textSample: textSample.slice(0, SAMPLE_MAX),
    };
  } finally {
    try {
      await doc.destroy();
    } catch {
      /* ignore */
    }
  }
}

/** Heuristic: most pages carry (almost) no extractable text. */
export function isScannedLikely(textStats) {
  if (!textStats || textStats.pagesScanned === 0) return false;
  const textless = textStats.perPage.filter((c) => c < SCANNED_MIN_CHARS_PER_PAGE).length;
  return textless / textStats.pagesScanned >= SCANNED_PAGE_RATIO;
}
