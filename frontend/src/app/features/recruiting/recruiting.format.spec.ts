import { daysSince, formatDuration, groupByStage, isoDate, lastDays, statusForStage } from './recruiting.format';
import { Application, Stage } from './recruiting.model';

const stage = (id: number, position: number, extra: Partial<Stage> = {}): Stage => ({
  id,
  name: `S${id}`,
  kind: 'select',
  position,
  is_terminal: false,
  is_reject: false,
  is_hire: false,
  ...extra,
});

const app = (id: number, stageId: number): Application =>
  ({ id, stage_id: stageId, status: 'active', is_stale: false }) as Application;

describe('recruiting.format', () => {
  it('formats durations compactly', () => {
    expect(formatDuration(30)).toBe('<1m');
    expect(formatDuration(600)).toBe('10m');
    expect(formatDuration(5400)).toBe('1h 30m');
    expect(formatDuration(7200)).toBe('2h');
    expect(formatDuration(86400 * 3)).toBe('3d');
    expect(formatDuration(86400 * 3 + 3600 * 5)).toBe('3d 5h');
  });

  it('counts whole days since a date', () => {
    const now = new Date('2026-09-20T12:00:00Z');
    expect(daysSince('2026-09-17T11:00:00Z', now)).toBe(3);
    expect(daysSince(null, now)).toBe(0);
    expect(daysSince('2026-09-21T00:00:00Z', now)).toBe(0);
  });

  it('groups applications into ordered stage columns', () => {
    const cols = groupByStage([stage(2, 2), stage(1, 1)], [app(10, 1), app(11, 2), app(12, 1)]);
    expect(cols.map((c) => c.stage.id)).toEqual([1, 2]);
    expect(cols[0].items.map((a) => a.id)).toEqual([10, 12]);
  });

  it('derives the status of a stage like the backend', () => {
    expect(statusForStage(stage(1, 1, { is_reject: true, is_terminal: true, kind: 'closed' }))).toBe('rejected');
    expect(statusForStage(stage(1, 1, { is_hire: true, is_terminal: true, kind: 'hire' }))).toBe('hired');
    expect(statusForStage(stage(1, 1))).toBe('active');
  });

  it('builds the default report range', () => {
    expect(lastDays(30, new Date(2026, 8, 30))).toEqual({ from: '2026-09-01', to: '2026-09-30' });
    expect(isoDate(new Date(2026, 0, 5))).toBe('2026-01-05');
  });
});
