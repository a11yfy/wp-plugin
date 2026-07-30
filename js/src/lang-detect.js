// Data-driven language detection for the lang-mismatch check.
// Two signals, both conservative: dominant writing script (CJK/Arabic/Hebrew)
// and high-frequency stopword voting for alphabetic scripts. Returns null
// whenever the evidence is weak — a wrong "mismatch" verdict is worse than
// no verdict. Adding a language is DATA: extend STOPWORDS, nothing else.

const STOPWORDS = {
  en: 'the and of to in is that for it with as was on are this be by not have from at which',
  hu: 'és hogy nem az egy meg már csak mint vagy ha van még azt ezt volt lesz kell minden ennek arra',
  de: 'und der die das nicht ist mit für auf den dem ein eine sich als auch werden bei oder wird sind nach zum',
  fr: 'le la les des une est dans que qui pour sur avec pas par plus sont être cette aux ont mais vous du',
  es: 'el los las una es en que por para con del se su al como más pero sus este esta son entre también',
  it: 'il lo la gli le una è che di per con non sono del della nel alla più anche come dal questo questa',
  pt: 'os as um uma é que não para com dos das se na no por mais como mas foi são pelo pela também',
  nl: 'de het een en van is dat op te zijn voor met niet aan er ook maar om dit bij uit naar wordt',
  pl: 'i w nie na się jest do że z o jak po co tak za od przez ale być tym oraz lub które jego',
  cs: 'a se na je že s z do v to jako za by po ale jsou nebo které být pro tak při od jeho podle',
  sk: 'a sa na je že s z do v to ako za by po ale sú alebo ktoré byť pre tak pri od jeho podľa',
  ro: 'și în de la cu pe este un o care nu pentru din sau mai sunt ce se dar după până fost ale',
  da: 'og i at det en den til er som på de med han af for ikke der var kan skal vil eller også efter',
  sv: 'och i att det en den till är som på de med av för inte har om ett man var kan ska eller också',
  nb: 'og i at det en den til er som på de med av for ikke har om et man var kan skal eller også',
  fi: 'ja on ei että se hän oli mutta joka kun niin myös tai sekä ovat jos mukaan vain kuin tämä voi sen',
  et: 'ja on ei et see oli aga kui ka või ning oma mis selle tema siis üle nagu veel kes kuid pärast',
  lv: 'un ir ka par ar no uz kā bet vai tikai arī tas šī viņš kas pēc līdz jau vēl savu gan',
  lt: 'ir yra kad apie su iš į kaip bet arba tik taip tai jis kuris po iki jau dar savo bei nes',
  sl: 'in je da se na za so ki z v ne po kot tudi ali pa bo bi od pri še le',
  hr: 'i je da se na za su koji s u ne po kao ili pa će bi od pri još samo ali što',
  tr: 've bir bu da de için ile olarak olan en çok daha gibi ancak veya ama sonra kadar her ne var',
  el: 'και το να της την του με για στο από που είναι τα δεν ως στη μια ένα αλλά ή των σε',
  ru: 'и в не на что с по как это для от за был его но они или же так все при до',
  uk: 'і в не на що з по як це для від за був його але вони або ж так всі при до та у',
  bg: 'и в не на че с по как това за от до беше него но те или така всички при са да се',
};

const SETS = Object.fromEntries(
  Object.entries(STOPWORDS).map(([lang, words]) => [lang, new Set(words.split(' '))]),
);

/** Languages the detector can vote on (primary subtags). */
export const DETECTABLE_LANGS = new Set([
  ...Object.keys(SETS),
  'ar', 'he', 'ja', 'ko', 'zh',
]);

const SCRIPTS = [
  ['arabic', /[؀-ۿݐ-ݿ]/u],
  ['hebrew', /[֐-׿]/u],
  ['hangul', /[가-힯ᄀ-ᇿ]/u],
  ['kana', /[぀-ヿ]/u],
  ['han', /[一-鿿㐀-䶿]/u],
  ['cyrillic', /[Ѐ-ӿ]/u],
  ['greek', /[Ͱ-Ͽ]/u],
  ['latin', /[A-Za-zÀ-ɏḀ-ỿ]/u],
];

function scriptCounts(sample) {
  const counts = Object.fromEntries(SCRIPTS.map(([name]) => [name, 0]));
  let letters = 0;
  for (const ch of sample) {
    for (const [name, re] of SCRIPTS) {
      if (re.test(ch)) {
        counts[name] += 1;
        letters += 1;
        break;
      }
    }
  }
  return { counts, letters };
}

const MIN_LETTERS = 60;
const MIN_TOKENS = 15;
const MIN_HITS = 5;

/**
 * Detect the dominant natural language of a text sample.
 * @param {string} sample plain text (a few thousand chars is plenty)
 * @returns {string|null} primary language subtag, or null when unsure
 */
export function detectLanguage(sample) {
  if (typeof sample !== 'string' || sample.length === 0) return null;
  const { counts, letters } = scriptCounts(sample);
  if (letters < MIN_LETTERS) return null;

  // Script-dominant cases first (no stopwords possible for unsegmented CJK).
  if (counts.arabic / letters > 0.5) return 'ar';
  if (counts.hebrew / letters > 0.5) return 'he';
  if (counts.hangul / letters > 0.5) return 'ko';
  const cjk = counts.kana + counts.han;
  if (cjk / letters > 0.5) return counts.kana > cjk * 0.05 ? 'ja' : 'zh';

  // Alphabetic scripts: stopword voting with a clear-margin requirement.
  const tokens = sample.toLowerCase().split(/[^\p{L}]+/u).filter(Boolean);
  if (tokens.length < MIN_TOKENS) return null;
  let best = null;
  let second = null;
  for (const [lang, set] of Object.entries(SETS)) {
    let hits = 0;
    for (const token of tokens) {
      if (set.has(token)) hits += 1;
    }
    const entry = { lang, hits };
    if (!best || hits > best.hits) {
      second = best;
      best = entry;
    } else if (!second || hits > second.hits) {
      second = entry;
    }
  }
  if (!best || best.hits < MIN_HITS) return null;
  if (second && second.hits > 0 && best.hits < second.hits * 1.6) return null;
  return best.lang;
}
