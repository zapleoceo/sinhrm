// Port of the UI parity harness server (e2e/serve.mjs): env E2E_PORT, default 4317 (docs/guides/ui-parity.md).
// One place for the Playwright config, the mock API (harness.ts) and the fixture recorder.

/** @param {string | undefined} raw @returns {number} */
export function parsePort(raw) {
  if (raw === undefined || raw === '') return 4317;
  const port = Number(raw);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error(`E2E_PORT must be a port number 1..65535, got "${raw}"`);
  return port;
}

export const E2E_PORT = parsePort(process.env['E2E_PORT']);
/** host:port the app is served from — the only host the mock lets through. */
export const E2E_HOST = `127.0.0.1:${E2E_PORT}`;
export const E2E_ORIGIN = `http://${E2E_HOST}`;
