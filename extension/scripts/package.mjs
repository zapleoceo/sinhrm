// Zips build/ (manifest.json at zip root) into dist/sinhrm-clipper.zip.
import AdmZip from 'adm-zip';
import { existsSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const buildDir = path.join(root, 'build');
if (!existsSync(path.join(buildDir, 'manifest.json'))) {
  console.error('build/manifest.json not found - run npm run build first');
  process.exit(1);
}
mkdirSync(path.join(root, 'dist'), { recursive: true });
const target = path.join(root, 'dist', 'sinhrm-clipper.zip');
const zip = new AdmZip();
zip.addLocalFolder(buildDir);
zip.writeZip(target);
console.log(`Packaged ${zip.getEntries().length} entries into ${target}`);
