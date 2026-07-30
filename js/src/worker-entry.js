// Standalone Web Worker bundle entry.
// Usage from the host page:
//   const w = new Worker(a11yfyEngineWorkerUrl);
//   w.postMessage({ id, buffer }, [buffer]);
//   w.onmessage = ({ data }) => data.report ?? data.error;
import { analyze } from './analyze.js';
import { createMessageHandler } from './worker-logic.js';

self.onmessage = createMessageHandler(analyze, (message) => self.postMessage(message));
