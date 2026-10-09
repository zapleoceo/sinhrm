import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = path.join(__dirname, '..');
const manifest = JSON.parse(readFileSync(path.join(root, 'static', 'manifest.json'), 'utf8')) as Record<string, unknown>;

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    return e.isDirectory() ? sources(p) : p.endsWith('.ts') ? [p] : [];
  });
}

/**
 * The extension has no runtime message channel: there is no background worker and no persistent content script,
 * so there is no onMessage handler whose sender could be validated. These tests pin that: adding a channel must be a
 * conscious change that also adds sender validation tests.
 */
describe('no message channel into the extension', () => {
  it('manifest declares no background worker, content scripts or external messaging', () => {
    for (const key of ['background', 'content_scripts', 'externally_connectable', 'web_accessible_resources']) {
      expect(manifest[key], key).toBeUndefined();
    }
  });

  it('source never registers message listeners or opens ports', () => {
    const forbidden =
      /onMessage(External)?\b|onConnect(External)?\b|runtime\.sendMessage|tabs\.sendMessage|addEventListener\(\s*['"]message['"]|postMessage\(/;
    for (const file of sources(path.join(root, 'src'))) {
      expect(readFileSync(file, 'utf8'), path.relative(root, file)).not.toMatch(forbidden);
    }
  });

  it('page-side extractor never touches chrome.* or the network', () => {
    const files = sources(path.join(root, 'src', 'extractors')).concat(
      path.join(root, 'src', 'extract.ts'),
      path.join(root, 'src', 'detect.ts'),
    );
    for (const file of files) {
      const code = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
      expect(code, path.relative(root, file)).not.toMatch(/\bchrome\.|\bfetch\(|XMLHttpRequest|localStorage|sessionStorage/);
    }
  });
});
