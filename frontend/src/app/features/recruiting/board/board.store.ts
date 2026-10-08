import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { Application, Board, MoveApplication, PersonalBoard, PersonalColumn, RejectReason, SavePersonalColumn, Stage } from '../recruiting.model';
import { rankByScreening } from '../screening-ranking';
import { groupByStage, statusForStage } from '../recruiting.format';
import { RecruitingService, recruitingErrorKey } from '../recruiting.service';
import { LatestRequest } from '../../../core/ui/table/latest-request';

/** Where a card can go: a shared funnel stage (real move) or an own column (personal filing, stage untouched). */
export type BoardTarget = { type: 'stage'; stage: Stage } | { type: 'personal'; column: PersonalColumn };

/** One column of the combined board: a funnel stage (fixed order) or an own column (movable). */
export type BoardLane =
  | { key: string; kind: 'stage'; stage: Stage; items: Application[] }
  | { key: string; kind: 'personal'; column: PersonalColumn; items: Application[] };

/**
 * Moves the visible column from → to (indexes among visible lanes) inside the full layout: hidden keys keep their
 * slots, stages keep their relative (funnel) order because only the moved own column changes place.
 */
export function moveInLayout(layout: readonly string[], visible: readonly string[], from: number, to: number): string[] {
  const order = [...visible];
  const [key] = order.splice(from, 1);
  if (key === undefined || key.startsWith('stage:')) {
    return [...layout];
  }
  order.splice(Math.max(0, Math.min(to, order.length)), 0, key);
  const shown = new Set(visible);
  let i = 0;
  return layout.map((k) => (shown.has(k) ? order[i++] : k));
}

/**
 * Kanban board of one vacancy. Moves are optimistic: the card jumps to the new column at once and goes back
 * (with an i18n error key) if the server refuses. With the personal layer (/candidates) the user's own columns
 * follow the stages; a card filed into a visible own column leaves its stage column only in this user's view.
 */
@Injectable()
export class BoardStore {
  private readonly api = inject(RecruitingService);

  readonly rankScreening = signal(false);
  readonly board = signal<Board | null>(null);
  readonly personal = signal<PersonalBoard | null>(null);
  readonly rejectReasons = signal<RejectReason[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<number>>(new Set());

  /** application id → own column id, only for visible own columns. */
  readonly filed = computed(() => {
    const p = this.personal();
    const visible = new Set((p?.columns ?? []).filter((c) => !c.hidden).map((c) => c.id));
    return new Map((p?.cards ?? []).filter((c) => visible.has(c.column_id)).map((c) => [c.application_id, c.column_id]));
  });
  readonly columns = computed(() => {
    const b = this.board();
    const filed = this.filed();
    return b ? groupByStage(b.vacancy.stages, b.applications.filter((a) => !filed.has(a.id))) : [];
  });
  readonly personalColumns = computed(() => {
    const apps = this.board()?.applications ?? [];
    const filed = this.filed();
    return (this.personal()?.columns ?? [])
      .filter((c) => !c.hidden)
      .map((column) => ({ column, items: apps.filter((a) => filed.get(a.id) === column.id) }));
  });
  /** Combined order; without a personal layer — just the funnel stages. */
  readonly lanes = computed<BoardLane[]>(() => {
    const b = this.board();
    if (!b) {
      return [];
    }
    const stageCols = new Map(this.columns().map((c) => [`stage:${c.stage.id}`, c]));
    const ownCols = new Map(this.personalColumns().map((c) => [`col:${c.column.id}`, c]));
    const layout = this.personal()?.layout ?? [...stageCols.keys()];
    const keys = [...layout, ...[...stageCols.keys()].filter((k) => !layout.includes(k))];
    return keys.flatMap((key): BoardLane[] => {
      const rank = (items: Application[]): Application[] => this.rankScreening() ? rankByScreening(items) : items;
      const s = stageCols.get(key);
      if (s) {
        return [{ key, kind: 'stage', stage: s.stage, items: rank(s.items) }];
      }
      const c = ownCols.get(key);
      return c ? [{ key, kind: 'personal', column: c.column, items: rank(c.items) }] : [];
    });
  });
  readonly hiddenColumns = computed(() => (this.personal()?.columns ?? []).filter((c) => c.hidden));
  readonly staleCount = computed(() => this.board()?.applications.filter((a) => a.is_stale).length ?? 0);

  /** Another load() cancels both requests of the previous one: a late answer never shows another vacancy. */
  private readonly boardRequest = new LatestRequest();
  private readonly personalRequest = new LatestRequest();
  /** Vacancy of the last load(): late answers to calls made for another vacancy are ignored. */
  private vacancyId: number | null = null;

  load(vacancyId: number, withPersonal = false): void {
    this.vacancyId = vacancyId;
    this.loading.set(true);
    this.failed.set(false);
    this.boardRequest.run(this.api.board(vacancyId), {
      next: (board) => {
        this.board.set(board);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
    this.personal.set(null);
    this.personalRequest.cancel();
    if (withPersonal) {
      this.personalRequest.run(this.api.personalBoard(vacancyId), { next: (p) => this.personal.set(p), error: () => undefined });
    }
    if (this.rejectReasons().length === 0) {
      this.api.rejectReasons().subscribe({ next: (list) => this.rejectReasons.set(list), error: () => undefined });
    }
  }

  /** A move to a reject stage needs a reason: the page asks for it first. */
  needsReason(stage: Stage): boolean {
    return stage.is_reject;
  }

  move(
    application: Application,
    stage: Stage,
    extra: Omit<MoveApplication, 'stage_id'>,
    onError: (key: string) => void,
    onDone?: () => void,
  ): void {
    if (application.stage_id === stage.id || this.pending().has(application.id)) {
      return;
    }
    const previous = application;
    this.replace({ ...application, stage_id: stage.id, status: statusForStage(stage), is_stale: false });
    this.setPending(application.id, true);
    this.api.move(application.id, { stage_id: stage.id, ...extra }).subscribe({
      next: (saved) => {
        // Move responses do not select the read-only board score; moving keeps the saved result.
        this.replace({ ...saved, screening_score: previous.screening_score, candidate: previous.candidate });
        this.setPending(application.id, false);
        onDone?.();
      },
      error: (e: unknown) => {
        this.replace(previous);
        this.setPending(application.id, false);
        onError(recruitingErrorKey(e));
      },
    });
  }

  /**
   * Card → funnel stage from anywhere on the board. A card filed in an own column leaves it only after the server
   * confirmed the stage move (refused move: the card stays where it was). Same stage: just back to the stage column.
   */
  moveToStage(application: Application, stage: Stage, extra: Omit<MoveApplication, 'stage_id'>, onError: (key: string) => void): void {
    const unfile = (): void => {
      if (this.filed().has(application.id)) {
        this.file(application, null, onError);
      }
    };
    if (application.stage_id === stage.id) {
      unfile();
      return;
    }
    this.move(application, stage, extra, onError, unfile);
  }

  /** Personal filing (null: back to the stage column). Optimistic, rolled back on error. Never the stage. */
  file(application: Application, columnId: number | null, onError: (key: string) => void): void {
    const before = this.personal();
    if (!before || (this.filed().get(application.id) ?? null) === columnId) {
      return;
    }
    const cards = before.cards.filter((c) => c.application_id !== application.id);
    this.personal.set({ ...before, cards: columnId === null ? cards : [...cards, { application_id: application.id, column_id: columnId }] });
    this.api.fileCard(application.id, columnId).subscribe({
      error: (e: unknown) => {
        this.personal.set(before);
        onError(recruitingErrorKey(e));
      },
    });
  }

  /** New own column; with [at] (index among visible lanes) it lands right there, otherwise last. */
  addColumn(vacancyId: number, title: string, onError: (key: string) => void, at?: number): void {
    this.api.addPersonalColumn(vacancyId, { title }).subscribe({
      next: (column) => {
        if (vacancyId !== this.vacancyId) {
          return; // the user switched to another vacancy meanwhile: this column belongs to the old board
        }
        const key = `col:${column.id}`;
        this.personal.update((p) => (p ? { ...p, columns: [...p.columns, column], layout: [...p.layout, key] } : p));
        const visible = this.lanes().map((l) => l.key);
        if (at !== undefined && at < visible.length - 1) {
          this.moveLane(vacancyId, visible.length - 1, at, onError);
        }
      },
      error: (e: unknown) => onError(recruitingErrorKey(e)),
    });
  }

  /** Rename / colour / hide / show: optimistic, rolled back on error. */
  updateColumn(column: PersonalColumn, patch: SavePersonalColumn, onError: (key: string) => void): void {
    this.optimistic((p) => ({ ...p, columns: p.columns.map((c) => (c.id === column.id ? { ...c, ...patch } : c)) }), this.api.updatePersonalColumn(column.id, patch), onError);
  }

  /** Its cards return to their stage columns. */
  deleteColumn(column: PersonalColumn, onError: (key: string) => void): void {
    this.optimistic(
      (p) => ({
        columns: p.columns.filter((c) => c.id !== column.id),
        cards: p.cards.filter((c) => c.column_id !== column.id),
        layout: p.layout.filter((k) => k !== `col:${column.id}`),
      }),
      this.api.deletePersonalColumn(column.id),
      onError,
    );
  }

  /** Column drag & drop (indexes among visible lanes, as CDK reports them). Stages themselves never move. */
  moveLane(vacancyId: number, from: number, to: number, onError: (key: string) => void): void {
    const moved = this.lanes()[from];
    if (!this.personal() || from === to || moved?.kind !== 'personal') {
      return;
    }
    const layout = moveInLayout(this.fullLayout(), this.lanes().map((l) => l.key), from, to);
    this.optimistic((cur) => ({ ...cur, layout }), this.api.savePersonalLayout(vacancyId, layout), onError);
  }

  /** Keyboard / mobile fallback: one step left or right, jumping over funnel stages too. */
  shiftColumn(vacancyId: number, column: PersonalColumn, step: -1 | 1, onError: (key: string) => void): void {
    const from = this.lanes().findIndex((l) => l.key === `col:${column.id}`);
    const to = from + step;
    if (from >= 0 && to >= 0 && to < this.lanes().length) {
      this.moveLane(vacancyId, from, to, onError);
    }
  }

  reset(vacancyId: number, onError: (key: string) => void): void {
    this.optimistic(() => ({ columns: [], cards: [], layout: [] }), this.api.resetPersonalBoard(vacancyId), onError);
  }

  /** Stored layout plus anything not in it yet (all lanes are always present). */
  private fullLayout(): string[] {
    const layout = this.personal()?.layout ?? [];
    return [...layout, ...this.lanes().map((l) => l.key).filter((k) => !layout.includes(k))];
  }

  private optimistic(change: (p: PersonalBoard) => PersonalBoard, request: Observable<unknown>, onError: (key: string) => void): void {
    const before = this.personal();
    const vacancyId = this.vacancyId;
    if (!before) {
      return;
    }
    this.personal.set(change(before));
    request.subscribe({
      error: (e: unknown) => {
        // Roll back only the board it was made on: after a vacancy switch "before" is the old vacancy's layer.
        if (vacancyId === this.vacancyId) {
          this.personal.set(before);
        }
        onError(recruitingErrorKey(e));
      },
    });
  }

  private replace(app: Application): void {
    this.board.update((b) => (b ? { ...b, applications: b.applications.map((a) => (a.id === app.id ? app : a)) } : b));
  }

  private setPending(id: number, on: boolean): void {
    this.pending.update((set) => {
      const next = new Set(set);
      if (on) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });
  }
}
