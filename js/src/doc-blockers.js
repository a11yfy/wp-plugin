// Document-level remediation blockers (beyond encryption): properties that
// make the file unsendable — remediation would either destroy something the
// owner cares about (digital signature) or cannot represent the content at
// all (XFA form, PDF portfolio). Consumed by the PHP side as report booleans;
// the server-side guardrail mirrors these with byte-window heuristics.
import { PDFDict, PDFName } from 'pdf-lib';
import { dictGet } from './pdflib-utils.js';

/**
 * A signature value dictionary must carry /ByteRange + /Contents in the file
 * (ISO 32000-1 §12.8.1) — presence of such a dict means the document (or a
 * certification/timestamp layer) is actually signed, not merely that a signer
 * field exists.
 */
function hasSignatureDict(context) {
  for (const [, obj] of context.enumerateIndirectObjects()) {
    if (
      obj instanceof PDFDict &&
      obj.has(PDFName.of('ByteRange')) &&
      obj.has(PDFName.of('Contents'))
    ) {
      return true;
    }
  }
  return false;
}

/**
 * @param {{context: object, catalog: object}} ctx analyze() context
 * @returns {{signed: boolean, xfa: boolean, portfolio: boolean}}
 */
export function detectBlockers(ctx) {
  const { context, catalog } = ctx;

  let signed = false;
  let xfa = false;
  try {
    const acroForm = dictGet(context, catalog, 'AcroForm');
    xfa = acroForm instanceof PDFDict && acroForm.has(PDFName.of('XFA'));
    signed = hasSignatureDict(context);
  } catch {
    // Malformed form/signature structures must not kill the scan.
  }

  let portfolio = false;
  try {
    portfolio = dictGet(context, catalog, 'Collection') instanceof PDFDict;
  } catch {
    portfolio = false;
  }

  return { signed, xfa, portfolio };
}
