// Main entry: analyze(arrayBuffer) -> Report.
// pdf-lib provides low-level object access (catalog, struct tree, annots);
// pdf.js provides text extraction for the scanned heuristic and 01-005.
import { PDFDocument } from 'pdf-lib';
import { ALL_CHECKS } from './checks/index.js';
import { runCheck } from './check-utils.js';
import { buildPageRefMap, collectPagesInfo } from './pages-info.js';
import { buildStructInfo } from './struct-walker.js';
import { collectTextStats, isScannedLikely } from './text-stats.js';
import { getEncryptDict } from './checks/encryption.js';
import { detectBlockers } from './doc-blockers.js';
import { buildReport } from './report.js';
import { ENGINE_VERSION } from './version.js';

export const version = ENGINE_VERSION;

function toBytes(input) {
  if (input instanceof Uint8Array) return new Uint8Array(input); // copy
  if (input instanceof ArrayBuffer) return new Uint8Array(input.slice(0));
  if (ArrayBuffer.isView(input)) {
    return new Uint8Array(input.buffer.slice(input.byteOffset, input.byteOffset + input.byteLength));
  }
  throw new Error('analyze() expects an ArrayBuffer or a typed array');
}

/**
 * Analyze a PDF for client-side detectable PDF/UA-1 (Matterhorn) issues.
 * @param {ArrayBuffer|Uint8Array} input
 * @param {string} [password] User password for encrypted PDFs — lets pdf.js
 *   open the document so text stats / coverage work. pdf-lib still reads with
 *   ignoreEncryption, so string values (alt text, /Lang, title) stay
 *   unreliable on encrypted files; the UI must suppress string-based checks.
 * @returns {Promise<object>} Report (see README for the stable contract)
 */
export async function analyze(input, password) {
  const bytes = toBytes(input);

  // pdf.js text stats (null when the document cannot be opened, e.g. password).
  const textStats = await collectTextStats(bytes.slice(), password);

  // pdf-lib low-level document.
  const doc = await PDFDocument.load(bytes.slice(), {
    ignoreEncryption: true,
    updateMetadata: false,
    throwOnInvalidObject: false,
  });

  const ctx = {
    doc,
    context: doc.context,
    catalog: doc.catalog,
    text: textStats,
    struct: null,
    structError: null,
    pagesInfo: [],
  };

  let pageRefMap = new Map();
  try {
    pageRefMap = buildPageRefMap(doc);
    ctx.pagesInfo = collectPagesInfo(doc);
  } catch (err) {
    ctx.pagesInfoError = err;
  }

  try {
    ctx.struct = buildStructInfo(doc, pageRefMap);
  } catch (err) {
    ctx.structError = err instanceof Error ? err : new Error(String(err));
  }

  const checks = ALL_CHECKS.map((check) => runCheck(check, ctx));

  let pages = 0;
  try {
    pages = doc.getPageCount();
  } catch {
    pages = textStats ? textStats.pages : 0;
  }

  const struct = ctx.struct;
  const tagged = !!(struct && struct.hasStructTreeRoot && struct.marked === true);
  const encrypted = getEncryptDict(ctx) !== undefined || doc.isEncrypted === true;
  const blockers = detectBlockers(ctx);

  const coverage = {
    untaggedChars: textStats ? textStats.untaggedCharsRaw || 0 : 0,
    totalChars: textStats ? textStats.totalChars : 0,
  };

  return buildReport({
    pages,
    tagged,
    encrypted,
    signed: blockers.signed,
    xfa: blockers.xfa,
    portfolio: blockers.portfolio,
    scannedLikely: isScannedLikely(textStats),
    checks,
    coverage,
  });
}
