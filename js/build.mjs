// esbuild build script.
// Outputs (all fully self-contained, no CDN / external URLs — wp.org guideline):
//   ../assets/js/dist/a11yfy-engine.js        - IIFE, window.A11yfyEngine = { analyze, version }
//   ../assets/js/dist/a11yfy-engine.worker.js - standalone Web Worker wrapper
//
// pdf.js runs in "fake worker" mode: the pdf.js worker module is bundled
// inline (globalThis.pdfjsWorker), so no separate pdf.worker.js file is
// required. The caller is expected to run the engine inside a dedicated Web
// Worker (a11yfy-engine.worker.js) anyway.
import * as esbuild from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(here, '../assets/js/dist');

const common = {
  bundle: true,
  minify: true,
  sourcemap: false,
  format: 'iife',
  target: ['es2020'],
  platform: 'browser',
  legalComments: 'none',
  logLevel: 'warning',
  // pdf.js dynamically imports GlobalWorkerOptions.workerSrc as a last-resort
  // fallback; the inline worker (globalThis.pdfjsWorker) makes that path dead
  // code, so the unresolved dynamic import is safe to keep as-is.
  logOverride: { 'unsupported-dynamic-import': 'silent' },
};

const builds = [
  { entry: 'src/browser-entry.js', outfile: 'a11yfy-engine.js' },
  { entry: 'src/worker-entry.js', outfile: 'a11yfy-engine.worker.js' },
  { entry: 'src/viewer-entry.js', outfile: 'a11yfy-viewer.js' },
];

for (const { entry, outfile } of builds) {
  const result = await esbuild.build({
    ...common,
    entryPoints: [path.resolve(here, entry)],
    outfile: path.resolve(outDir, outfile),
    metafile: true,
  });
  const out = Object.entries(result.metafile.outputs)[0];
  console.log(`${outfile}: ${(out[1].bytes / 1024 / 1024).toFixed(2)} MB`);
}
