// Standard structure types of PDF 1.7 / PDF/UA-1 (ISO 32000-1, 14.8.4).
export const STANDARD_TYPES = new Set([
  'Document', 'Part', 'Art', 'Sect', 'Div', 'BlockQuote', 'Caption', 'TOC',
  'TOCI', 'Index', 'NonStruct', 'Private', 'P', 'H', 'H1', 'H2', 'H3', 'H4',
  'H5', 'H6', 'L', 'LI', 'Lbl', 'LBody', 'Table', 'TR', 'TH', 'TD', 'THead',
  'TBody', 'TFoot', 'Span', 'Quote', 'Note', 'Reference', 'BibEntry', 'Code',
  'Link', 'Annot', 'Ruby', 'RB', 'RT', 'RP', 'Warichu', 'WT', 'WP', 'Figure',
  'Formula', 'Form',
]);

// Annotation subtypes defined by ISO 32000-1 (Table 169) + common extensions.
export const KNOWN_ANNOTATION_SUBTYPES = new Set([
  'Text', 'Link', 'FreeText', 'Line', 'Square', 'Circle', 'Polygon',
  'PolyLine', 'Highlight', 'Underline', 'Squiggly', 'StrikeOut', 'Stamp',
  'Caret', 'Ink', 'Popup', 'FileAttachment', 'Sound', 'Movie', 'Widget',
  'Screen', 'PrinterMark', 'TrapNet', 'Watermark', '3D', 'Redact',
  'Projection', 'RichMedia',
]);

// Legal parents for table structure elements (ISO 32000-1 Table 337).
export const TABLE_ROW_PARENTS = new Set(['Table', 'THead', 'TBody', 'TFoot']);
export const TABLE_CHILD_ROLES = new Set(['TR', 'THead', 'TBody', 'TFoot', 'Caption']);

// Legal children for L (ISO 32000-1 Table 336).
export const LIST_CHILD_ROLES = new Set(['LI', 'Caption', 'L']);
// Lbl is also legal inside TOCI and Note per ISO 32000-1 (avoids false positives).
export const LBL_PARENTS = new Set(['LI', 'TOCI', 'Note']);

// TOC hierarchy (ISO 32000-1 Table 333).
export const TOC_CHILD_ROLES = new Set(['TOC', 'TOCI', 'Caption']);

// Max reported items per check (report contract).
export const MAX_ITEMS_PER_CHECK = 20;
// Max detail string length (report contract).
export const MAX_DETAIL_LENGTH = 120;

// Scanned heuristic: a page with fewer characters than this counts as "no text".
export const SCANNED_MIN_CHARS_PER_PAGE = 10;
// Ratio of textless pages at (or above) which the document is likely scanned.
export const SCANNED_PAGE_RATIO = 0.6;
// Hard cap on pages inspected with getTextContent (perf guard on huge docs).
export const MAX_TEXT_PAGES = 200;

// Minimum total characters for "the document has real text content" (01-005).
export const MIN_DOC_TEXT_CHARS = 50;
