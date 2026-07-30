// Report assembly: score, risk band, failed groups. Stable contract consumed
// by the PHP side of the WordPress plugin — do not change shapes casually.
import { ENGINE_VERSION } from './version.js';
import { severityOf } from './severities.js';

const SEVERITY_WEIGHTS = { critical: 5, major: 2, minor: 1 };
// Saturation cap for the per-check occurrence multiplier (1 + log2(count)).
const SAT_CAP = 4;
// Maximum score deduction when 100% of the extractable text is untagged.
const COVERAGE_PENALTY_MAX = 40;
// A document whose structure tree is missing or whose body text is untagged is
// unusable with a screen reader regardless of how many other checks pass.
const CRITICAL_FLOOR_IDS = new Set(['struct-tree-root', '01-005']);
const CRITICAL_FLOOR = 40;
// A scanned, untagged document is a stack of pictures: a screen reader gets
// neither structure NOR text. The coverage penalty cannot express this
// (totalChars is 0 — there is nothing to measure), so the cap does.
const SCANNED_UNTAGGED_CAP = 5;

/** Occurrence multiplier: monotonically increasing, saturating at SAT_CAP. */
function saturate(count) {
  return Math.min(SAT_CAP, 1 + Math.log2(Math.max(1, count)));
}

/**
 * Absolute-deduction score (v3, engine 0.6):
 *   penalty_check = weight(severity) * saturate(count)   per failed check
 *   penalty_cov   = COVERAGE_PENALTY_MAX * untaggedChars / totalChars
 *   score         = max(0, round(100 - Σ penalty_check - penalty_cov))
 * plus a hard cap of CRITICAL_FLOOR when the structure tree is missing or the
 * body text is untagged (struct-tree-root / 01-005 fail), and a much lower
 * SCANNED_UNTAGGED_CAP when on top of that the document is scanned-likely
 * (no extractable text at all — fully opaque to assistive technology).
 *
 * The advisory 'quality' group and non-'fail' statuses never deduct (heuristic
 * best-practice warnings must not break the server-side `compliant` gate), so
 * a clean document stays exactly 100 and the gate (score === 100) is unchanged.
 * Unlike the v2 pass-ratio this is comparable across documents: the deduction
 * does not depend on how many checks happened to be applicable.
 */
export function computeScore(checks, coverage, { scannedLikely = false } = {}) {
  let penalty = 0;
  let floored = false;
  for (const check of checks) {
    if (check.group === 'quality') continue;
    if (check.status !== 'fail') continue;
    const weight = SEVERITY_WEIGHTS[severityOf(check.id)];
    penalty += weight * saturate(check.count || 1);
    if (CRITICAL_FLOOR_IDS.has(check.id)) floored = true;
  }
  let score = 100 - penalty;
  if (coverage && coverage.totalChars > 0) {
    score -= COVERAGE_PENALTY_MAX * Math.min(1, coverage.untaggedChars / coverage.totalChars);
  }
  score = Math.max(0, Math.round(score));
  if (!floored) return score;
  return Math.min(score, scannedLikely ? SCANNED_UNTAGGED_CAP : CRITICAL_FLOOR);
}

/** Risk bands per research report §4.2. */
export function riskLevel(score) {
  if (score >= 90) return 'low';
  if (score >= 70) return 'medium';
  if (score >= 40) return 'high';
  return 'critical';
}

export function buildReport({ pages, tagged, encrypted, signed, xfa, portfolio, scannedLikely, checks, coverage }) {
  const score = computeScore(checks, coverage, { scannedLikely: scannedLikely === true });
  // 'quality' is advisory: it neither deducts from the score nor counts as a
  // failed group (the UI lists failedGroups as errors).
  const failedGroups = [
    ...new Set(checks.filter((c) => c.status === 'fail' && c.group !== 'quality').map((c) => c.group)),
  ];
  // Partial measurement: at least one check could not run to completion.
  const partial = checks.some((c) => c.status === 'error');
  return {
    engineVersion: ENGINE_VERSION,
    pages,
    tagged,
    encrypted,
    signed: !!signed,
    xfa: !!xfa,
    portfolio: !!portfolio,
    scannedLikely,
    score,
    risk: riskLevel(score),
    coverage: coverage || { untaggedChars: 0, totalChars: 0 },
    checks,
    failedGroups,
    partial,
  };
}
