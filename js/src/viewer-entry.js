// Viewer bundle: main-thread pdf.js for the document detail page's canvas
// preview. Kept separate from browser-entry so the engine bundle (worker +
// web app consumer) stays unchanged. Same inline-worker setup as
// pdfjs-setup.js — no separate worker file, no CDN fetch (wp.org guideline).
import * as pdfjsWorker from 'pdfjs-dist/legacy/build/pdf.worker.mjs';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';

if (typeof globalThis !== 'undefined' && !globalThis.pdfjsWorker) {
  globalThis.pdfjsWorker = pdfjsWorker;
}

window.A11yfyViewer = {
  /** Load a document for rendering (system-font fallbacks allowed). */
  load(bytes) {
    return pdfjs.getDocument({
      data: bytes,
      isEvalSupported: false,
      disableAutoFetch: true,
      verbosity: 0,
    }).promise;
  },
};
