// pdf.js bootstrap. The worker module is bundled inline and exposed as
// globalThis.pdfjsWorker, which makes pdf.js use its main-thread "fake worker"
// path — no separate worker file and no CDN fetch is ever needed. The whole
// engine is expected to run inside a dedicated Web Worker anyway (see
// worker-entry.js), so the fake worker costs nothing in practice.
//
// If the host page provides window.a11yfyEngineConfig.workerSrc it is honored
// as GlobalWorkerOptions.workerSrc (harmless with the inline worker present).
import * as pdfjsWorker from 'pdfjs-dist/legacy/build/pdf.worker.mjs';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';

if (typeof globalThis !== 'undefined' && !globalThis.pdfjsWorker) {
  globalThis.pdfjsWorker = pdfjsWorker;
}

const config = (typeof globalThis !== 'undefined' && globalThis.a11yfyEngineConfig) || {};
if (config.workerSrc && !pdfjs.GlobalWorkerOptions.workerSrc) {
  pdfjs.GlobalWorkerOptions.workerSrc = config.workerSrc;
}

/** pdf.js operator constants (image + marked-content ops for the content pass). */
export function pdfjsOps() {
  return pdfjs.OPS;
}

/** Load a document with pdf.js (deterministic, no network, no fonts). */
export function loadPdfjsDocument(bytes, password) {
  return pdfjs.getDocument({
    data: bytes,
    password,
    useSystemFonts: false,
    isEvalSupported: false,
    disableAutoFetch: true,
    verbosity: 0,
  }).promise;
}
