// Group: encryption — accessibility permission of encrypted files.
import { PDFDict, dictGetRaw, numVal, resolve } from '../pdflib-utils.js';
import { fail, inapplicable, item, pass } from '../check-utils.js';

export function getEncryptDict(ctx) {
  const encryptRef = ctx.context.trailerInfo ? ctx.context.trailerInfo.Encrypt : undefined;
  const encrypt = resolve(ctx.context, encryptRef);
  return encrypt instanceof PDFDict ? encrypt : undefined;
}

export const encryptionChecks = [
  {
    id: '26-001',
    group: 'encryption',
    // File is encrypted but its encryption dictionary has no P key.
    run(ctx) {
      const encrypt = getEncryptDict(ctx);
      if (!encrypt) return inapplicable();
      if (dictGetRaw(encrypt, 'P') === undefined) {
        return fail([item(null, 'Encryption dictionary has no /P (permissions) entry')]);
      }
      return pass();
    },
  },
  {
    id: '26-002',
    group: 'encryption',
    // File is encrypted and the P key's 10th bit (0x200, "extract for
    // accessibility") is false.
    run(ctx) {
      const encrypt = getEncryptDict(ctx);
      if (!encrypt) return inapplicable();
      const p = numVal(resolve(ctx.context, dictGetRaw(encrypt, 'P')));
      if (p === undefined) return inapplicable(); // covered by 26-001
      // eslint-disable-next-line no-bitwise
      if (((p | 0) & 0x200) === 0) {
        return fail([item(null, `Accessibility permission bit (10) is not set (P=${p})`)]);
      }
      return pass();
    },
  },
];
