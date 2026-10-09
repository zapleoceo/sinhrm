import { ExportColors, OrgLayout, toSvg } from './org-layout';

/** File name of an export: `org-chart-YYYY-MM-DD` (the UTC day of `now`, as the chart always named it). */
export function exportFileName(now: Date): string {
  return `org-chart-${now.toISOString().slice(0, 10)}`;
}

/**
 * Colours of the chart as the user sees it (theme, light/dark), read from the rendered viewport: page background,
 * card fill/line/text, the muted position line and the link stroke.
 */
export function readExportColors(viewport: HTMLElement): ExportColors {
  const card = viewport.querySelector<HTMLElement>('.card');
  const cs = getComputedStyle(viewport.parentElement ?? viewport);
  const cardCs = card ? getComputedStyle(card) : cs;
  const pos = card?.querySelector<HTMLElement>('.pos');
  const link = viewport.querySelector('.links path');
  return {
    bg: getComputedStyle(document.body).backgroundColor || '#ffffff',
    card: cardCs.backgroundColor,
    border: cardCs.borderTopColor,
    text: cardCs.color || cs.color,
    muted: pos ? getComputedStyle(pos).color : cs.color,
    link: link ? getComputedStyle(link).stroke : cardCs.borderTopColor,
  };
}

/** Saves the chart as SVG, or as PNG rendered at twice the size through a canvas (sharp on HiDPI screens). */
export function exportOrgChart(layout: OrgLayout, colors: ExportColors, kind: 'png' | 'svg', now = new Date()): void {
  const name = exportFileName(now);
  const blob = new Blob([toSvg(layout, colors)], { type: 'image/svg+xml;charset=utf-8' });
  if (kind === 'svg') {
    downloadBlob(blob, `${name}.svg`);
    return;
  }
  const url = URL.createObjectURL(blob);
  const img = new Image();
  img.onload = () => {
    const scale = 2;
    const canvas = document.createElement('canvas');
    canvas.width = img.width * scale;
    canvas.height = img.height * scale;
    const ctx = canvas.getContext('2d');
    ctx?.scale(scale, scale);
    ctx?.drawImage(img, 0, 0);
    URL.revokeObjectURL(url);
    canvas.toBlob((png) => png && downloadBlob(png, `${name}.png`), 'image/png');
  };
  img.src = url;
}

/** Downloads a blob; the object URL lives one more second so the browser can start the download. */
function downloadBlob(blob: Blob, filename: string): void {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
