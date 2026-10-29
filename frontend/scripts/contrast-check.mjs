// WCAG 2.x contrast check for the actual root tokens in src/styles.scss.
// Usage: node scripts/contrast-check.mjs. Checks each supported accent in light/dark custom palettes and Sintegrum.
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../src/styles.scss', import.meta.url), 'utf8');
const rootStart = source.indexOf('\nhtml {');
const rootEnd = source.indexOf('// Explicit user choice', rootStart);
const rootTokens = source.slice(rootStart, rootEnd);

function property(text, name) {
  const match = text.match(new RegExp(`(?:^|\\n)\\s*${name.replaceAll('-', '\\-')}:\\s*([^;]+);`));
  if (!match) throw new Error(`Missing SCSS token: ${name}`);
  return match[1].trim();
}

function selectorBlock(selector) {
  const start = source.indexOf(selector);
  if (start < 0) return '';
  const open = source.indexOf('{', start);
  const close = source.indexOf('\n}', open);
  return source.slice(open + 1, close);
}

function resolve(value, theme) {
  const lightDark = value.match(/light-dark\((#[0-9a-f]{6}),\s*(#[0-9a-f]{6})\)/i);
  return lightDark ? lightDark[theme === 'light' ? 1 : 2] : value;
}

function channel(hex, index) { return Number.parseInt(hex.slice(1 + index * 2, 3 + index * 2), 16); }
function mix(foreground, background, amount) {
  return `#${[0, 1, 2].map((i) => Math.round(channel(foreground, i) * amount + channel(background, i) * (1 - amount)).toString(16).padStart(2, '0')).join('')}`;
}
function luminance(hex) {
  const [r, g, b] = [0, 1, 2].map((i) => channel(hex, i) / 255)
    .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function ratio(a, b) {
  const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (lighter + 0.05) / (darker + 0.05);
}

const readRoot = (key, theme) => resolve(property(rootTokens, key), theme);
const readScheme = (block, key, theme, depth = 0) => {
  if (depth > 8) throw new Error(`Cyclic SCSS token reference: ${key}`);
  let value;
  try { value = property(block, key); } catch { value = property(rootTokens, key); }
  const alias = value.match(/^var\((--[\w-]+)\)$/);
  return alias ? readScheme(block, alias[1], theme, depth + 1) : resolve(value, theme);
};
const semantic = (key, theme, schemeBlock = '') => readScheme(schemeBlock, key, theme);
const configs = [];
for (const accent of ['blue', 'violet', 'green', 'graphite']) {
  const accentBlock = accent === 'blue' ? '' : selectorBlock(`html[data-color-scheme='custom'][data-accent='${accent}']`);
  for (const theme of ['light', 'dark']) configs.push({ scheme: 'custom', accent, theme, accentBlock });
}
configs.push({ scheme: 'sintegrum', accent: 'reference', theme: 'dark', accentBlock: selectorBlock("html[data-color-scheme='sintegrum']") });

let failures = 0;
for (const config of configs) {
  const { scheme, accent, theme, accentBlock } = config;
  const surface = semantic('--os-surface', theme, accentBlock);
  const card = semantic('--os-card', theme, accentBlock);
  const quiet = semantic('--os-card-2', theme, accentBlock);
  const ink = semantic('--os-on-surface', theme, accentBlock);
  const muted = semantic('--os-on-surface-variant', theme, accentBlock);
  const inversePrimary = semantic('--os-inverse-primary', theme, accentBlock);
  const inverseSurface = semantic('--os-on-surface', theme, accentBlock);
  const primary = semantic('--os-primary', theme, accentBlock);
  const onPrimary = semantic('--os-on-primary', theme, accentBlock);
  const container = semantic('--os-primary-container', theme, accentBlock);
  const onContainer = semantic('--os-on-primary-container', theme, accentBlock);
  const outline = semantic('--os-outline', theme, accentBlock);
  const good = semantic('--app-good-text', theme, accentBlock);
  const warning = semantic('--app-warn-text', theme, accentBlock);
  const danger = semantic('--app-bad-text', theme, accentBlock);
  const navText = semantic('--app-nav-active-text', theme, accentBlock);
  const stageColors = ['--app-stage-new', '--app-stage-screen', '--app-stage-interview', '--app-stage-offer', '--app-stage-hire', '--app-stage-closed']
    .map((key) => semantic(key, theme, accentBlock));
  const tintedNav = mix(primary, card, 0.12);
  const pairs = [
    ['body text / page', ink, surface, 4.5], ['body text / card', ink, card, 4.5], ['body text / quiet fill', ink, quiet, 4.5],
    ['muted text / page', muted, surface, 4.5], ['muted text / card', muted, card, 4.5], ['muted text / sidebar', muted, card, 4.5],
    ['primary text / page', primary, surface, 4.5], ['primary text / card', primary, card, 4.5],
    ['primary button label', onPrimary, primary, 4.5], ['primary-container label', onContainer, container, 4.5],
    ['inverse primary / inverse surface', inversePrimary, inverseSurface, 4.5],
    ['active navigation / accent fill', navText, tintedNav, 4.5], ['input outline / card', outline, card, 3], ['input outline / page', outline, surface, 3],
    ['focus ring / card', primary, card, 3], ['focus ring / page', primary, surface, 3],
    ['success label / card', good, card, 4.5], ['warning label / card', warning, card, 4.5], ['error label / card', danger, card, 4.5],
    ['success pill / tint', good, mix(good, card, 0.12), 4.5], ['warning pill / tint', warning, mix(warning, card, 0.12), 4.5],
    ['error pill / tint', danger, mix(danger, card, 0.12), 4.5], ['info pill / tint', primary, mix(primary, card, 0.12), 4.5],
    ...stageColors.map((color, index) => [`stage ${index + 1} text / card`, color, card, 4.5]),
  ];
  console.log(`\n${scheme}/${accent}/${theme}`);
  for (const [name, foreground, background, minimum] of pairs) {
    const measured = ratio(foreground, background);
    const passed = measured >= minimum;
    if (!passed) failures++;
    console.log(`${passed ? 'PASS' : 'FAIL'}  ${measured.toFixed(2).padStart(5)} (>=${minimum})  ${name}  ${foreground} on ${background}`);
  }
}
process.exit(failures ? 1 : 0);
