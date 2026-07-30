// IIFE bundle entry: exposes window.A11yfyEngine = { analyze, version }.
import { analyze, version } from './analyze.js';

const globalObj = typeof window !== 'undefined' ? window : globalThis;
globalObj.A11yfyEngine = { analyze, version };
