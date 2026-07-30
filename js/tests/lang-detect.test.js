import { describe, expect, it } from 'vitest';
import { PDFDocument, StandardFonts } from 'pdf-lib';
import { detectLanguage } from '../src/lang-detect.js';
import { analyze } from '../src/analyze.js';

const HU = `A dokumentum akadálymentessége nem csak jogi kérdés, hanem arról szól,
hogy minden felhasználó el tudja olvasni a tartalmat. Ha egy PDF nem tartalmaz
címkéket, akkor a képernyőolvasó nem tudja, hogy mi a címsor és mi a bekezdés,
ezért a tartalom csak egy hosszú, szerkezet nélküli folyam lesz. Ez azt jelenti,
hogy a vak vagy gyengénlátó felhasználó nem tudja hatékonyan használni azt.`;

const EN = `Document accessibility is not only a legal question, it is about
making sure that every user can actually read the content. If a PDF has no tags,
the screen reader does not know what is a heading and what is a paragraph, so
the content becomes one long stream with no structure at all for the user.`;

const DE = `Die Barrierefreiheit von Dokumenten ist nicht nur eine rechtliche
Frage, sondern es geht darum, dass alle Nutzer den Inhalt lesen können. Wenn ein
PDF keine Tags enthält, weiß der Screenreader nicht, was eine Überschrift ist
und was ein Absatz ist, und der Inhalt wird zu einem langen Strom ohne Struktur.`;

describe('detectLanguage', () => {
  it('detects Hungarian', () => {
    expect(detectLanguage(HU)).toBe('hu');
  });

  it('detects English', () => {
    expect(detectLanguage(EN)).toBe('en');
  });

  it('detects German', () => {
    expect(detectLanguage(DE)).toBe('de');
  });

  it('detects CJK by script', () => {
    expect(detectLanguage('本文書は、すべての利用者が内容を読めるようにするための'.repeat(3))).toBe('ja');
    expect(detectLanguage('文档的无障碍性不仅是法律问题而是要确保所有用户都能阅读内容'.repeat(3))).toBe('zh');
    expect(detectLanguage('문서 접근성은 법적 문제일 뿐만 아니라 모든 사용자가 내용을 읽을 수 있도록'.repeat(3))).toBe('ko');
  });

  it('returns null for short or ambiguous input', () => {
    expect(detectLanguage('')).toBe(null);
    expect(detectLanguage('hello world')).toBe(null);
    expect(detectLanguage('1234 5678 !!! ???')).toBe(null);
    expect(detectLanguage(null)).toBe(null);
  });
});

describe('lang-mismatch check', () => {
  // WinAnsi-safe Hungarian (no ő/ű — pdf-lib standard fonts cannot encode them).
  const HU_WINANSI = 'Ez a dokumentum arról szól, hogy minden felhasználó el tudja '
    + 'olvasni a tartalmat, mert ha nem érti a rendszer, akkor az olvasó nem tudja '
    + 'használni azt, és ez nem csak jogi kérdés, hanem az emberek mindennapi élete. '
    + 'A cél az, hogy a tartalom minden olvasó számára azonos módon legyen elérhetó.';

  async function huDocDeclaring(lang) {
    const doc = await PDFDocument.create();
    doc.setLanguage(lang);
    const font = await doc.embedFont(StandardFonts.Helvetica);
    const page = doc.addPage([500, 500]);
    let y = 470;
    for (let i = 0; i < 5; i++) {
      page.drawText(HU_WINANSI.slice(i * 70, (i + 1) * 70), { x: 20, y, size: 10, font });
      y -= 16;
    }
    return doc;
  }

  const getCheck = (report, id) => report.checks.find((c) => c.id === id);

  it('fails when the declared language contradicts the text', async () => {
    const doc = await huDocDeclaring('en-US');
    const report = await analyze(await doc.save());
    const check = getCheck(report, 'lang-mismatch');
    expect(check.status).toBe('fail');
    expect(check.items[0].detail).toContain('"hu"');
  });

  it('passes when the declared language matches the text', async () => {
    const doc = await huDocDeclaring('hu-HU');
    const report = await analyze(await doc.save());
    expect(getCheck(report, 'lang-mismatch').status).toBe('pass');
  });
});
