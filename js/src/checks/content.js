// Group: content — findings derived from the unified pdf.js content pass
// (text-stats.js): images painted outside any tagged / artifact marked-content
// sequence are invisible to assistive technology.
import { failOrPass, inapplicable, item } from '../check-utils.js';

export const contentChecks = [
  {
    id: 'figure-untagged',
    group: 'content',
    // An image is painted outside any marked-content sequence with an MCID
    // and is not marked as an Artifact — screen readers never encounter it.
    // Conservative by construction: images whose marked-content state cannot
    // be read count as tagged (see text-stats.js scanOperators).
    run(ctx) {
      if (!ctx.text) throw new Error('content pass unavailable');
      const images = ctx.text.images || [];
      if (images.length === 0) return inapplicable();
      const offenders = images.filter((img) => !img.tagged && !img.artifact);
      return failOrPass(
        offenders.map((img) => item(
          img.page,
          'Image is neither tagged (no Figure/MCID) nor marked as an artifact',
          [img.rect],
        )),
        offenders.length,
      );
    },
  },
  {
    id: 'graphics-untagged',
    group: 'content',
    // Vector paints (path fill/stroke, shading) outside any MCID-carrying or
    // Artifact marked-content sequence — the veraPDF 7.1-t3 blind spot that
    // neither the char-based coverage (no characters) nor figure-untagged
    // (no image op) can see. Same conservative rule as figure-untagged:
    // unreadable marked-content state counts as tagged.
    run(ctx) {
      if (!ctx.text) throw new Error('content pass unavailable');
      if (!ctx.text.graphicsTotal) return inapplicable();
      const pages = ctx.text.untaggedGraphics || [];
      const total = pages.reduce((sum, p) => sum + p.count, 0);
      return failOrPass(
        pages.map((p) => item(
          p.page,
          `${p.count} vector graphic element(s) (path/shading) are neither tagged nor marked as an artifact`,
          p.rects.length > 0 ? p.rects : undefined,
        )),
        total,
      );
    },
  },
];
