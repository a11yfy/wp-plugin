// Low-level pdf-lib helpers. Every object coming out of a PDFDict/PDFArray may
// be an indirect reference (PDFRef) — always resolve through the context.
import {
  PDFArray,
  PDFBool,
  PDFDict,
  PDFHexString,
  PDFName,
  PDFNumber,
  PDFRawStream,
  PDFRef,
  PDFString,
  decodePDFRawStream,
} from 'pdf-lib';

/** Resolve an (indirect) object through the document context. */
export function resolve(context, obj) {
  if (obj === undefined || obj === null) return undefined;
  if (obj instanceof PDFRef) {
    const looked = context.lookup(obj);
    return looked === undefined ? undefined : looked;
  }
  return obj;
}

/** Get + resolve a dict entry by key name. */
export function dictGet(context, dict, key) {
  if (!(dict instanceof PDFDict)) return undefined;
  return resolve(context, dict.get(PDFName.of(key)));
}

/** Get a dict entry WITHOUT resolving (to inspect the raw PDFRef). */
export function dictGetRaw(dict, key) {
  if (!(dict instanceof PDFDict)) return undefined;
  return dict.get(PDFName.of(key));
}

/** Decode a PDFName to a plain string without the leading slash, #xx-unescaped. */
export function nameStr(obj) {
  if (!(obj instanceof PDFName)) return undefined;
  const raw = obj.asString().replace(/^\//, '');
  return raw.replace(/#([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
}

/** Decode a PDFString / PDFHexString to text; undefined for anything else. */
export function textStr(obj) {
  if (obj instanceof PDFString || obj instanceof PDFHexString) {
    try {
      return obj.decodeText();
    } catch {
      return obj.toString();
    }
  }
  return undefined;
}

/** Boolean value of a PDFBool; undefined for anything else. */
export function boolVal(obj) {
  if (obj instanceof PDFBool) return obj === PDFBool.True;
  return undefined;
}

/** Numeric value of a PDFNumber; undefined for anything else. */
export function numVal(obj) {
  if (obj instanceof PDFNumber) return obj.asNumber();
  return undefined;
}

/** Return the resolved items of a PDFArray (or [] if not an array). */
export function arrayItems(context, obj) {
  if (!(obj instanceof PDFArray)) return [];
  return obj.asArray().map((item) => resolve(context, item));
}

/** Return the raw (unresolved) items of a PDFArray (or []). */
export function arrayItemsRaw(obj) {
  if (!(obj instanceof PDFArray)) return [];
  return obj.asArray();
}

/**
 * Decode a stream's content bytes to a string (best effort). Tries filter
 * decoding first, falls back to the raw (possibly still encoded) bytes.
 */
export function streamText(obj) {
  if (!(obj instanceof PDFRawStream)) return undefined;
  let bytes;
  try {
    bytes = decodePDFRawStream(obj).decode();
  } catch {
    bytes = obj.contents;
  }
  try {
    return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
  } catch {
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return s;
  }
}

/** Stable identity tag for a PDFRef ("12 0 R"), or undefined. */
export function refTag(obj) {
  return obj instanceof PDFRef ? obj.toString() : undefined;
}

export { PDFArray, PDFDict, PDFName, PDFNumber, PDFRef, PDFRawStream, PDFString, PDFHexString };
