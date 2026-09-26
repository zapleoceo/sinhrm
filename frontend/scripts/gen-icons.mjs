// Regenerates the raster favicon set from public/favicon.svg (light variant).
// One-off tool, not a project dependency:
//   cd frontend && npm i --no-save sharp png-to-ico && node scripts/gen-icons.mjs
import { readFile, writeFile } from 'node:fs/promises';
import sharp from 'sharp';
import pngToIco from 'png-to-ico';

const svg = await readFile(new URL('../public/favicon.svg', import.meta.url));
const png = (size) => sharp(svg, { density: 72 * (size / 32) * 2 }).resize(size, size).png().toBuffer();
const out = (name) => new URL(`../public/${name}`, import.meta.url);

// iOS renders transparency as black — flatten on white.
await writeFile(out('apple-touch-icon.png'), await sharp(await png(180)).flatten({ background: '#ffffff' }).png().toBuffer());
await writeFile(out('icon-192.png'), await png(192));
await writeFile(out('icon-512.png'), await png(512));
await writeFile(out('favicon.ico'), await pngToIco([await png(16), await png(32), await png(48)]));
console.log('icons written');
