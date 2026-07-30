// wp.org SVN-assets generátor — banner (772x250 + 1544x500) és icon (128/256) PNG-k
// a brand SVG-lockupokból (web/static/brand/ a forrás-igazság).
// Futtatás a wp-plugin/-ból: OUT_DIR=./.wordpress-org node bin/wporg-assets.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const OUT = process.env.OUT_DIR;
if (!OUT) throw new Error('OUT_DIR env kötelező');
mkdirSync(OUT, { recursive: true });

const diamond = (s) => `
  <svg viewBox="215 187 250 218" style="height:${s}px" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
        <stop offset="0%" stop-color="#5DCAA5"/>
        <stop offset="100%" stop-color="#0F6E56"/>
      </linearGradient>
    </defs>
    <path d="M340 390 L230 280 L308 202 L372 202 L450 280 Z" fill="url(#g)"
      stroke="#04342C" stroke-width="10" stroke-linejoin="round"/>
    <path d="M308 202 L372 202 L340 224 Z" fill="#D85A30"
      stroke="#04342C" stroke-width="6" stroke-linejoin="round"/>
  </svg>`;

// A bannerben a gyémánt kontúrja világos (a sötétzöld háttéren a #04342C körvonal eltűnne)
const diamondLight = (s) => diamond(s)
  .replaceAll('stroke="#04342C"', 'stroke="#E9F4EF"');

function bannerHtml(scale) {
  const s = (n) => n * scale;
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { width:${s(772)}px; height:${s(250)}px; overflow:hidden;
      background: linear-gradient(120deg, #04342C 0%, #06423A 55%, #0A5244 100%);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      display:flex; align-items:center; position:relative; }
    .deco { position:absolute; right:${s(-70)}px; top:${s(-90)}px; opacity:0.14; }
    .wrap { display:flex; align-items:center; gap:${s(28)}px; padding-left:${s(48)}px; position:relative; }
    .text { display:flex; flex-direction:column; gap:${s(10)}px; }
    .word { font-size:${s(54)}px; font-weight:600; letter-spacing:${s(1)}px; color:#F2F7F5; line-height:1; }
    .word .n { color:#E0764E; }
    .tag { font-size:${s(19)}px; font-weight:400; color:#BFD9CF; letter-spacing:${s(0.3)}px; }
    .badges { display:flex; gap:${s(10)}px; margin-top:${s(4)}px; }
    .badge { font-size:${s(13)}px; font-weight:500; color:#E9F4EF; letter-spacing:${s(0.2)}px;
      border:1px solid rgba(233,244,239,.4); border-radius:${s(999)}px;
      padding:${s(4)}px ${s(12)}px; background:rgba(255,255,255,.07);
      display:flex; align-items:center; gap:${s(6)}px; line-height:1.4; }
  </style></head><body>
    <div class="deco">${diamondLight(s(430))}</div>
    <div class="wrap">
      ${diamondLight(s(120))}
      <div class="text">
        <div class="word">A<span class="n">11</span>YFY</div>
        <div class="tag">Free PDF accessibility scan &amp; one-click remediation</div>
        <div class="badges">
          <span class="badge">🇪🇺 EAA — European Accessibility Act</span>
          <span class="badge">🇺🇸 ADA &middot; Section 508</span>
        </div>
      </div>
    </div>
  </body></html>`;
}

function iconHtml(size) {
  // Átlátszó háttér, ~7% belső margó
  const pad = Math.round(size * 0.07);
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    * { margin:0; padding:0; } body { width:${size}px; height:${size}px;
    display:flex; align-items:center; justify-content:center; }
  </style></head><body>${diamond(size - 2 * pad)}</body></html>`;
}

const browser = await chromium.launch();
const jobs = [
  { file: 'banner-772x250.png', html: bannerHtml(1), w: 772, h: 250, transparent: false },
  { file: 'banner-1544x500.png', html: bannerHtml(2), w: 1544, h: 500, transparent: false },
  { file: 'icon-128x128.png', html: iconHtml(128), w: 128, h: 128, transparent: true },
  { file: 'icon-256x256.png', html: iconHtml(256), w: 256, h: 256, transparent: true },
];
for (const j of jobs) {
  const page = await browser.newPage({ viewport: { width: j.w, height: j.h } });
  await page.setContent(j.html, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${OUT}/${j.file}`, omitBackground: j.transparent });
  await page.close();
  console.log('OK', j.file);
}
await browser.close();
