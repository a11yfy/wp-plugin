import { structuralChecks } from './structural.js';
import { rolemapChecks } from './rolemap.js';
import { metadataChecks } from './metadata.js';
import { encryptionChecks } from './encryption.js';
import { syntaxChecks } from './syntax.js';
import { headingChecks } from './headings.js';
import { attributeChecks } from './attributes.js';
import { annotationChecks } from './annotations.js';
import { contentChecks } from './content.js';
import { fontChecks } from './fonts.js';
import { miscChecks } from './misc.js';
import { qualityChecks } from './quality.js';

export const ALL_CHECKS = [
  ...contentChecks,
  ...structuralChecks,
  ...rolemapChecks,
  ...metadataChecks,
  ...encryptionChecks,
  ...syntaxChecks,
  ...headingChecks,
  ...attributeChecks,
  ...annotationChecks,
  ...fontChecks,
  ...miscChecks,
  ...qualityChecks,
];
