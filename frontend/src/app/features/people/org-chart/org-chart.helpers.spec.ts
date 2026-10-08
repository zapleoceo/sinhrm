import { OrgNode } from '../people.model';
import { exportFileName, exportOrgChart, readExportColors } from './org-export';
import { orgKeyCommand, outsideViewport } from './org-keys';
import { CARD_H, CARD_W, layoutForest } from './org-layout';

const node = (id: number, name: string, reports: OrgNode[] = []): OrgNode => ({
  id,
  full_name: name,
  avatar_url: null,
  position: null,
  department: null,
  branch: null,
  reports_count: reports.length,
  reports,
});

describe('org chart keyboard map', () => {
  it('arrows move, Enter selects, Space toggles — and cancel the page default', () => {
    expect(orgKeyCommand('ArrowLeft')).toEqual({ command: { kind: 'move', key: 'ArrowLeft' }, prevent: true });
    expect(orgKeyCommand('Enter')).toEqual({ command: { kind: 'select' }, prevent: true });
    expect(orgKeyCommand(' ')).toEqual({ command: { kind: 'toggle' }, prevent: true });
  });

  it('zoom and fit keys keep the page default; other keys are not handled', () => {
    expect(orgKeyCommand('+')).toEqual({ command: { kind: 'zoom', factor: 1.25 }, prevent: false });
    expect(orgKeyCommand('=')).toEqual({ command: { kind: 'zoom', factor: 1.25 }, prevent: false });
    expect(orgKeyCommand('-')).toEqual({ command: { kind: 'zoom', factor: 0.8 }, prevent: false });
    expect(orgKeyCommand('0')).toEqual({ command: { kind: 'fit' }, prevent: false });
    expect(orgKeyCommand('a')).toBeNull();
    expect(orgKeyCommand('Tab')).toBeNull();
  });

  it('a card is outside the viewport when any edge leaves it (after zoom and pan)', () => {
    const t = { x: 0, y: 0, k: 1 };
    expect(outsideViewport({ x: 0, y: 0 }, t, CARD_W, CARD_H)).toBe(false);
    expect(outsideViewport({ x: 1, y: 0 }, t, CARD_W, CARD_H)).toBe(true);
    expect(outsideViewport({ x: 0, y: 0 }, { x: -1, y: 0, k: 1 }, 1000, 1000)).toBe(true);
    expect(outsideViewport({ x: 100, y: 100 }, { x: 0, y: 0, k: 2 }, 200 + CARD_W * 2, 200 + CARD_H * 2)).toBe(false);
    expect(outsideViewport({ x: 100, y: 100 }, { x: 0, y: 0, k: 2 }, 200 + CARD_W * 2 - 1, 1000)).toBe(true);
  });
});

describe('org chart export', () => {
  afterEach(() => vi.restoreAllMocks());

  it('names the file by the UTC day of the export', () => {
    expect(exportFileName(new Date('2026-10-08T23:30:00Z'))).toBe('org-chart-2026-10-08');
  });

  it('reads the colours of the rendered chart', () => {
    const viewport = document.createElement('div');
    viewport.innerHTML = '<div class="card" style="background-color: rgb(1, 2, 3); color: rgb(4, 5, 6); border: 1px solid rgb(7, 8, 9)"><span class="pos" style="color: rgb(10, 11, 12)"></span></div>';
    document.body.append(viewport);
    const colors = readExportColors(viewport);
    viewport.remove();
    expect(colors).toMatchObject({ card: 'rgb(1, 2, 3)', text: 'rgb(4, 5, 6)', border: 'rgb(7, 8, 9)', muted: 'rgb(10, 11, 12)', link: 'rgb(7, 8, 9)' });
  });

  it('saves an SVG of the layout under the dated name', () => {
    const created: Blob[] = [];
    URL.createObjectURL = vi.fn((b: Blob) => (created.push(b), 'blob:x'));
    URL.revokeObjectURL = vi.fn();
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    const layout = layoutForest([node(1, 'Ann', [node(2, 'Bob')])], new Set([1]), 'vertical');
    const colors = { bg: '#fff', card: '#fff', border: '#000', text: '#000', muted: '#666', link: '#999' };

    exportOrgChart(layout, colors, 'svg', new Date('2026-10-08T10:00:00Z'));

    expect(click).toHaveBeenCalledTimes(1);
    expect((click.mock.contexts[0] as HTMLAnchorElement).download).toBe('org-chart-2026-10-08.svg');
    expect(created[0].type).toBe('image/svg+xml;charset=utf-8');
  });
});
