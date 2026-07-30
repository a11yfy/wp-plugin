// (5) Worker wrapper message protocol — tested as a pure function, no jsdom.
import { describe, expect, it } from 'vitest';
import { createMessageHandler } from '../src/worker-logic.js';

describe('worker message handler', () => {
  it('posts { id, report } on success', async () => {
    const posted = [];
    const report = { engineVersion: '0.1.0', score: 100 };
    const handler = createMessageHandler(async () => report, (m) => posted.push(m));
    await handler({ data: { id: 'job-1', buffer: new ArrayBuffer(4) } });
    expect(posted).toEqual([{ id: 'job-1', report }]);
  });

  it('passes the buffer through to analyze', async () => {
    const seen = [];
    const handler = createMessageHandler(async (b) => { seen.push(b); return {}; }, () => {});
    const buffer = new ArrayBuffer(8);
    await handler({ data: { id: 1, buffer } });
    expect(seen[0]).toBe(buffer);
  });

  it('posts { id, error } with a string message on failure', async () => {
    const posted = [];
    const handler = createMessageHandler(async () => { throw new Error('boom'); }, (m) => posted.push(m));
    await handler({ data: { id: 42, buffer: new ArrayBuffer(1) } });
    expect(posted).toEqual([{ id: 42, error: 'boom' }]);
    expect(posted[0].error).toBeTypeOf('string');
  });

  it('reports an error when the buffer is missing', async () => {
    const posted = [];
    const handler = createMessageHandler(async () => ({}), (m) => posted.push(m));
    await handler({ data: { id: 'no-buf' } });
    expect(posted).toHaveLength(1);
    expect(posted[0].id).toBe('no-buf');
    expect(posted[0].error).toMatch(/buffer/i);
  });

  it('never throws, even on a malformed event', async () => {
    const posted = [];
    const handler = createMessageHandler(async () => ({}), (m) => posted.push(m));
    await expect(handler(undefined)).resolves.toBeUndefined();
    expect(posted[0].error).toBeTypeOf('string');
  });
});
