// Check severity for score weighting. Mirrors the curated Matterhorn catalog
// (languages/matterhorn.{lang}.json severity_label) so the score and the UI
// tell the same story; default is 'major'.
const CRITICAL = new Set([
  'struct-tree-root', '01-005', '01-007', '02-001', '02-004', '25-001',
  '11-001', 'figure-untagged',
]);
const MINOR = new Set([
  '09-006', '17-002', '28-006', '28-007', '20-001', '20-002', '20-003',
  '21-001', '11-003', '10-001', 'lang-mismatch',
  'quality-no-headings', 'quality-no-outline', 'quality-table-no-th', 'quality-suspect-alt',
]);

/** @returns {'critical'|'major'|'minor'} */
export function severityOf(checkId) {
  if (CRITICAL.has(checkId)) return 'critical';
  if (MINOR.has(checkId)) return 'minor';
  return 'major';
}
