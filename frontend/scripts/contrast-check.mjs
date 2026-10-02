// WCAG 2.x contrast check for the key colour-token pairs of src/styles.scss (light + dark).
// Usage: node scripts/contrast-check.mjs   — exits 1 if any pair is below its threshold.
// Keep the values below in sync with the light-dark() tokens in styles.scss (direction C «Маршрут»).
// The focus ring is drawn with a 2px offset, so it is measured against the page / card it sits on.
const T = {
  light: {
    surface: '#f5f6f8', card: '#ffffff', card2: '#f9fafb', sidebar: '#ffffff', ink: '#121722', muted: '#4e5666',
    primary: '#1a5fb4', onPrimary: '#ffffff', brand: '#1a5fb4', navActive: '#154f97', brandText: '#1a5fb4',
    navHeading: '#4e5666', outline: '#767e8c', accent: '#0e7c73', tableHead: '#ffffff', onInk: '#f5f6f8',
    success: '#2a7330', warning: '#965200', danger: '#b42318', info: '#1a5fb4',
    badgeBg: '#a65a00', badgeText: '#ffffff', inversePrimary: '#86b4f0',
    stageNew: '#5d6b80', stageScreen: '#1a5fb4', stageInterview: '#0e7c73', stageOffer: '#a65a00', stageHire: '#2e7d32', stageClosed: '#b42318',
  },
  dark: {
    surface: '#0e1218', card: '#151a22', card2: '#191f28', sidebar: '#151a22', ink: '#e7eaef', muted: '#a0a8b6',
    primary: '#86b4f0', onPrimary: '#08203f', brand: '#3584e4', navActive: '#a9caf5', brandText: '#86b4f0',
    navHeading: '#a0a8b6', outline: '#7b8494', accent: '#3ccfc0', tableHead: '#151a22', onInk: '#0e1218',
    success: '#72cf7a', warning: '#f0a74a', danger: '#f59a8f', info: '#86b4f0',
    badgeBg: '#f0a74a', badgeText: '#1e1200', inversePrimary: '#1a5fb4',
    stageNew: '#a3afc2', stageScreen: '#86b4f0', stageInterview: '#3ccfc0', stageOffer: '#f0a74a', stageHire: '#72cf7a', stageClosed: '#f59a8f',
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
  ['body text / row hover', t.ink, t.card2, 4.5],
  ['muted text / page', t.muted, t.surface, 4.5],
  ['muted text / card', t.muted, t.card, 4.5],
  ['muted text / sidebar', t.muted, t.sidebar, 4.5],
  ['nav text / sidebar', t.ink, t.sidebar, 4.5],
  ['nav heading / sidebar', t.navHeading, t.sidebar, 4.5],
  ['active nav text / 12% brand pill', t.navActive, mix(t.primary, t.sidebar, 0.12), 4.5],
  ['filled (ink) button label', t.onInk, t.ink, 4.5],
  ['tooltip / snackbar text on ink', t.onInk, t.ink, 4.5],
  ['snackbar action (inverse-primary) on ink', t.inversePrimary, t.ink, 4.5],
  ['primary (brand) button label', t.onPrimary, t.primary, 4.5],
  ['link / text button (primary) / card', t.primary, t.card, 4.5],
  ['link (primary) / page', t.primary, t.surface, 4.5],
  ['table head text / head', t.muted, t.tableHead, 4.5],
  ['nav badge text / badge', t.badgeText, t.badgeBg, 4.5],
  ['input outline (UI) / card', t.outline, t.card, 3],
  ['input outline (UI) / page', t.outline, t.surface, 3],
  ['accent focus ring / page', t.accent, t.surface, 3],
  ['accent focus ring / card', t.accent, t.card, 3],
  ['chip good text / 12% bg', t.success, mix(t.success, t.card, 0.12), 4.5],
  ['chip warn text / 12% bg', t.warning, mix(t.warning, t.card, 0.12), 4.5],
  ['chip bad text / 12% bg', t.danger, mix(t.danger, t.card, 0.12), 4.5],
  ['chip info text / 12% bg', t.info, mix(t.info, t.card, 0.12), 4.5],
  ['stage new as text / card', t.stageNew, t.card, 4.5],
  ['stage screen as text / card', t.stageScreen, t.card, 4.5],
  ['stage interview as text / card', t.stageInterview, t.card, 4.5],
  ['stage offer as text / card', t.stageOffer, t.card, 4.5],
  ['stage hire as text / card', t.stageHire, t.card, 4.5],
  ['stage closed as text / card', t.stageClosed, t.card, 4.5],
  ['stage new line (UI) / page', t.stageNew, t.surface, 3],
  ['stage offer line (UI) / page', t.stageOffer, t.surface, 3],
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
