// Group: rolemap — RoleMap sanity (Matterhorn checkpoint 02).
import { STANDARD_TYPES } from '../constants.js';
import { failOrPass, inapplicable, item, requireStruct } from '../check-utils.js';
import { mapRole } from '../struct-walker.js';

function roleMapApplicable(ctx) {
  const struct = requireStruct(ctx);
  if (!struct.hasStructTreeRoot || struct.roleMap.size === 0) return null;
  return struct;
}

export const rolemapChecks = [
  {
    id: '02-001',
    group: 'rolemap',
    // Non-standard tag's mapping does not terminate with a standard type.
    run(ctx) {
      const struct = roleMapApplicable(ctx);
      if (!struct) return inapplicable();
      const items = [];
      for (const from of struct.roleMap.keys()) {
        if (STANDARD_TYPES.has(from)) continue; // 02-004 territory
        const { terminal, chain, cyclic } = mapRole(from, struct.roleMap);
        if (!cyclic && !STANDARD_TYPES.has(terminal)) {
          items.push(item(null, `RoleMap chain ${chain.join(' → ')} does not end in a standard type`));
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '02-003',
    group: 'rolemap',
    // A circular mapping exists.
    run(ctx) {
      const struct = roleMapApplicable(ctx);
      if (!struct) return inapplicable();
      const items = [];
      const flagged = new Set();
      for (const from of struct.roleMap.keys()) {
        const { chain, cyclic } = mapRole(from, struct.roleMap);
        if (cyclic) {
          const key = [...chain].sort().join('|');
          if (!flagged.has(key)) {
            flagged.add(key);
            items.push(item(null, `Circular RoleMap chain: ${chain.join(' → ')}`));
          }
        }
      }
      return failOrPass(items);
    },
  },
  {
    id: '02-004',
    group: 'rolemap',
    // One or more standard types are remapped.
    run(ctx) {
      const struct = roleMapApplicable(ctx);
      if (!struct) return inapplicable();
      const items = [];
      for (const [from, to] of struct.roleMap.entries()) {
        if (STANDARD_TYPES.has(from)) {
          items.push(item(null, `Standard type ${from} is remapped to ${to}`));
        }
      }
      return failOrPass(items);
    },
  },
];
