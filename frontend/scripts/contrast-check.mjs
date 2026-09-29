// WCAG 2.x contrast check for the key colour-token pairs of src/styles.scss (light + dark).
// Usage: node scripts/contrast-check.mjs   — exits 1 if any pair is below its threshold.
// Keep the values below in sync with the light-dark() tokens in styles.scss.
const T = {
  light: {
    surface: '#f7f9fc', card: '#ffffff', sidebar: '#e8eff9', ink: '#172033', muted: '#4a5670',
    primary: '#1a5fb4', onPrimary: '#ffffff', brand: '#1a5fb4', navActive: '#154f97', brandText: '#1a5fb4',
    navHeading: '#52617f', outline: '#737f96', accent: '#0f7f76', tableHead: '#f0f4fa',
    success: '#166534', warning: '#92400e', danger: '#b91c1c', info: '#1a5fb4',
  },
  dark: {
    surface: '#0f1623', card: '#141c2b', sidebar: '#162034', ink: '#e2e8f2', muted: '#a9b4c7',
    primary: '#8fb8ee', onPrimary: '#06264d', brand: '#3584e4', navActive: '#99c1f1', brandText: '#99c1f1',
    navHeading: '#93a6c8', outline: '#7d889c', accent: '#2ec4b6', tableHead: '#182133',
    success: '#4ade80', warning: '#fbbf24', danger: '#f87171', info: '#99c1f1',
  },
};
// 12% tinted chip background over the card colour.
const mix = (fg, bg, a) => '#' + [0, 1, 2].map((i) => {
  const c = (h) => parseInt(h.slice(1 + i * 2, 3 + i * 2), 16);
  return Math.round(c(fg) * a + c(bg) * (1 - a)).toString(16).padStart(2, '0');
}).join('');
const lum = (hex) => {
  const [r, g, b] = [0, 1, 2].map((i) => parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16) / 255)
    .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const pairs = (t) => [
  ['body text / page', t.ink, t.surface, 4.5],
  ['body text / card', t.ink, t.card, 4.5],
  ['muted text / page', t.muted, t.surface, 4.5],
  ['muted text / sidebar', t.muted, t.sidebar, 4.5],
  ['nav text / sidebar', t.ink, t.sidebar, 4.5],
  ['nav heading / sidebar', t.navHeading, t.sidebar, 4.5],
  ['active nav text / 13% brand pill', t.navActive, mix(t.brand, t.sidebar, 0.13), 4.5],
  ['primary button label', t.onPrimary, t.primary, 4.5],
  ['link (primary) / card', t.primary, t.card, 4.5],
  ['table head text / head', t.muted, t.tableHead, 4.5],
  ['outline (UI) / card', t.outline, t.card, 3],
  ['accent focus ring / page', t.accent, t.surface, 3],
  ['accent focus ring / card', t.accent, t.card, 3],
  ['chip good text / 12% bg', t.success, mix(t.success, t.card, 0.12), 4.5],
  ['chip warn text / 12% bg', t.warning, mix(t.warning, t.card, 0.12), 4.5],
  ['chip bad text / 12% bg', t.danger, mix(t.danger, t.card, 0.12), 4.5],
  ['chip info text / 12% bg', t.info, mix(t.info, t.card, 0.12), 4.5],
];
let fail = 0;
for (const [theme, t] of Object.entries(T)) {
  console.log(`\n${theme}`);
  for (const [name, fg, bg, min] of pairs(t)) {
    const r = ratio(fg, bg);
    const ok = r >= min;
    if (!ok) fail++;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${r.toFixed(2).padStart(5)} (>=${min})  ${name}  ${fg} on ${bg}`);
  }
}
process.exit(fail ? 1 : 0);
