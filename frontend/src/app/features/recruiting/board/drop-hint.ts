import { Application } from '../recruiting.model';
import { BoardLane } from './board.store';

/**
 * What dropping the dragged card on a lane will do — drives the column highlight and its caption:
 * `stage` real stage change · `back` return to the card's own stage (leaves the own column) ·
 * `personal` filing into an own column (stage unchanged) · `denied` stage change without write rights.
 */
export type DropKind = 'stage' | 'back' | 'personal' | 'denied';

export interface DropHint {
  key: string;
  kind: DropKind;
  text: string;
}

export function dropHint(
  lane: BoardLane,
  app: Application,
  canWrite: boolean,
  t: (key: string, params: Record<string, string>) => string,
): DropHint {
  const prefix = 'recruiting.personalBoard.';
  if (lane.kind === 'personal') {
    return { key: lane.key, kind: 'personal', text: t(prefix + 'dropPersonal', { name: lane.column.title }) };
  }
  const name = lane.stage.name;
  if (app.stage_id === lane.stage.id) {
    return { key: lane.key, kind: 'back', text: t(prefix + 'dropBack', { name }) };
  }
  if (!canWrite) {
    return { key: lane.key, kind: 'denied', text: t(prefix + 'dropDenied', { name }) };
  }
  return { key: lane.key, kind: 'stage', text: t(prefix + 'dropStage', { name }) };
}
