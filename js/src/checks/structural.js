// Group: structural — is the document tagged at all?
import { MIN_DOC_TEXT_CHARS } from '../constants.js';
import { fail, failOrPass, inapplicable, item, pass, requireStruct } from '../check-utils.js';

export const structuralChecks = [
  {
    id: 'struct-tree-root',
    group: 'structural',
    // PDF/UA-1 requires a logical structure tree (ISO 14289-1, 7.1).
    run(ctx) {
      const struct = requireStruct(ctx);
      if (struct.hasStructTreeRoot) return pass();
      return fail([item(null, 'Document catalog has no StructTreeRoot — the PDF is untagged')]);
    },
  },
  {
    id: 'markinfo-marked',
    group: 'structural',
    // MarkInfo dictionary with Marked=true (ISO 14289-1, 7.1).
    run(ctx) {
      const struct = requireStruct(ctx);
      if (struct.marked === true) return pass();
      if (struct.marked === false) return fail([item(null, 'MarkInfo/Marked is false')]);
      return fail([item(null, 'MarkInfo dictionary missing or has no Marked entry')]);
    },
  },
  {
    id: '01-007',
    group: 'structural',
    // Matterhorn 01-007: Suspects entry has a value of true.
    run(ctx) {
      const struct = requireStruct(ctx);
      if (struct.suspects === true) {
        return fail([item(null, 'MarkInfo/Suspects is true — the tagging is flagged as unreliable')]);
      }
      return pass();
    },
  },
  {
    id: '01-005',
    group: 'structural',
    // Matterhorn 01-005: content is neither tagged nor an artifact. Untagged
    // document → one document-level fail; tagged document → the deep variant
    // reports per-page untagged runs (with rects for the viewer overlay).
    run(ctx) {
      const struct = requireStruct(ctx);
      if (!ctx.text) throw new Error('text extraction unavailable');

      if (!struct.hasStructTreeRoot) {
        if (ctx.text.totalChars >= MIN_DOC_TEXT_CHARS) {
          return fail([
            item(null, `~${ctx.text.totalChars} characters of text content are neither tagged nor artifacts`),
          ]);
        }
        return inapplicable(); // no (extractable) text — likely scanned; covered elsewhere
      }

      const untagged = ctx.text.untagged || [];
      return failOrPass(
        untagged.map((u) => item(
          u.page,
          `~${u.chars} of ~${u.totalChars} characters on the page are neither tagged nor artifacts`,
          u.rects,
        )),
        untagged.reduce((sum, u) => sum + u.chars, 0),
      );
    },
  },
];
