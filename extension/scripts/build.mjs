// Builds the unpacked extension into build/: bundles TS with esbuild, copies static files,
// and generates simple PNG icons (no binary assets in the repo).
import { build } from 'esbuild';
import { cp, mkdir, rm, writeFile } from 'node:fs/promises';
import { deflateSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.join(root, 'build');

await rm(out, { recursive: true, force: true });
await mkdir(path.join(out, 'icons'), { recursive: true });

const common = { bundle: true, target: 'chrome110', logLevel: 'info', legalComments: 'none' };

await build({
  ...common,
  entryPoints: { popup: path.join(root, 'src/popup.ts'), options: path.join(root, 'src/options.ts') },
  outdir: out,
  format: 'iife',
});

// Injected script: `var __sinhrmClipper = (() => {...})(); __sinhrmClipper.run();`
// executeScript({files}) resolves with the completion value of the last statement = run() result.
await build({
  ...common,
  entryPoints: [path.join(root, 'src/extract.ts')],
  outfile: path.join(out, 'extract.js'),
  format: 'iife',
  globalName: '__sinhrmClipper',
  footer: { js: '__sinhrmClipper.run();' },
});

await cp(path.join(root, 'static'), out, { recursive: true });

// --- minimal PNG encoder for solid rounded-square icons ---
const crcTable = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});
const crc32 = (buf) => {
  let c = 0xffffffff;
  for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
};
const chunk = (type, data) => {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
};
function icon(size) {
  const r = Math.round(size * 0.2);
  const rows = [];
  for (let y = 0; y < size; y++) {
    const row = Buffer.alloc(1 + size * 4);
    for (let x = 0; x < size; x++) {
      const dx = Math.max(r - x, x - (size - 1 - r), 0);
      const dy = Math.max(r - y, y - (size - 1 - r), 0);
      const inside = dx * dx + dy * dy <= r * r;
      // white "S"-ish bar in the middle third, blue background
      const bar = y > size * 0.42 && y < size * 0.58 && x > size * 0.25 && x < size * 0.75;
      const px = inside ? (bar ? [255, 255, 255, 255] : [37, 99, 235, 255]) : [0, 0, 0, 0];
      row.set(px, 1 + x * 4);
    }
    rows.push(row);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr.set([8, 6, 0, 0, 0], 8);
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(Buffer.concat(rows))),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}
for (const size of [16, 48, 128]) await writeFile(path.join(out, 'icons', `icon${size}.png`), icon(size));

console.log('Built extension into', out);
