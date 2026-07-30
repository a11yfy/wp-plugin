// Web Worker message protocol, extracted as a pure function so it can be unit
// tested without a worker/jsdom environment.
//
// In:  { id, buffer, password? }  (buffer: ArrayBuffer | typed array)
// Out: { id, report }             on success
//      { id, error: string }      on failure

/**
 * @param {(buffer: ArrayBuffer, password?: string) => Promise<object>} analyzeFn
 * @param {(message: object) => void} post
 * @returns {(event: {data: {id:any, buffer:ArrayBuffer, password?:string}}) => Promise<void>}
 */
export function createMessageHandler(analyzeFn, post) {
  return async function onMessage(event) {
    const data = (event && event.data) || {};
    const id = data.id;
    try {
      if (!data.buffer) throw new Error('Missing "buffer" in worker message');
      const report = await analyzeFn(data.buffer, data.password);
      post({ id, report });
    } catch (err) {
      post({ id, error: err && err.message ? String(err.message) : String(err) });
    }
  };
}
