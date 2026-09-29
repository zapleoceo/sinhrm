import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { Application, Board, MoveApplication, PersonalBoard, PersonalColumn, RejectReason, SavePersonalColumn, Stage } from '../recruiting.model';
import { groupByStage, statusForStage } from '../recruiting.format';
import { RecruitingService, recruitingErrorKey } from '../recruiting.service';

/** Where a card can go: a shared funnel stage (real move) or an own column (personal filing, stage untouched). */
export type BoardTarget = { type: 'stage'; stage: Stage } | { type: 'personal'; column: PersonalColumn };

/**
 * Kanban board of one vacancy. Moves are optimistic: the card jumps to the new column at once and goes back
 * (with an i18n error key) if the server refuses. With the personal layer (/candidates) the user's own columns
 * follow the stages; a card filed into a visible own column leaves its stage column only in this user's view.
 */
@Injectable()
export class BoardStore {
  private readonly api = inject(RecruitingService);

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
  readonly hiddenColumns = computed(() => (this.personal()?.columns ?? []).filter((c) => c.hidden));
  readonly staleCount = computed(() => this.board()?.applications.filter((a) => a.is_stale).length ?? 0);

  load(vacancyId: number, withPersonal = false): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.board(vacancyId).subscribe({
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
    if (withPersonal) {
      this.api.personalBoard(vacancyId).subscribe({ next: (p) => this.personal.set(p), error: () => undefined });
    }
    if (this.rejectReasons().length === 0) {
      this.api.rejectReasons().subscribe({ next: (list) => this.rejectReasons.set(list), error: () => undefined });
    }
  }

  /** A move to a reject stage needs a reason: the page asks for it first. */
  needsReason(stage: Stage): boolean {
    return stage.is_reject;
  }

  move(application: Application, stage: Stage, extra: Omit<MoveApplication, 'stage_id'>, onError: (key: string) => void): void {
    if (application.stage_id === stage.id || this.pending().has(application.id)) {
      return;
    }
    const previous = application;
    this.replace({ ...application, stage_id: stage.id, status: statusForStage(stage), is_stale: false });
    this.setPending(application.id, true);
    this.api.move(application.id, { stage_id: stage.id, ...extra }).subscribe({
      next: (saved) => {
        this.replace({ ...saved, candidate: previous.candidate });
        this.setPending(application.id, false);
      },
      error: (e: unknown) => {
        this.replace(previous);
        this.setPending(application.id, false);
        onError(recruitingErrorKey(e));
      },
    });
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

  addColumn(vacancyId: number, title: string, onError: (key: string) => void): void {
    this.api.addPersonalColumn(vacancyId, { title }).subscribe({
      next: (column) => this.personal.update((p) => (p ? { ...p, columns: [...p.columns, column] } : p)),
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
      (p) => ({ columns: p.columns.filter((c) => c.id !== column.id), cards: p.cards.filter((c) => c.column_id !== column.id) }),
      this.api.deletePersonalColumn(column.id),
      onError,
    );
  }

  /** Swaps the column with its neighbour (-1 left, +1 right) among all own columns. */
  shiftColumn(vacancyId: number, column: PersonalColumn, step: -1 | 1, onError: (key: string) => void): void {
    const list = [...(this.personal()?.columns ?? [])];
    const i = list.findIndex((c) => c.id === column.id);
    const j = i + step;
    if (i < 0 || j < 0 || j >= list.length) {
      return;
    }
    [list[i], list[j]] = [list[j], list[i]];
    this.optimistic((p) => ({ ...p, columns: list }), this.api.reorderPersonalColumns(vacancyId, list.map((c) => c.id)), onError);
  }

  reset(vacancyId: number, onError: (key: string) => void): void {
    this.optimistic(() => ({ columns: [], cards: [] }), this.api.resetPersonalBoard(vacancyId), onError);
  }

  private optimistic(change: (p: PersonalBoard) => PersonalBoard, request: Observable<unknown>, onError: (key: string) => void): void {
    const before = this.personal();
    if (!before) {
      return;
    }
    this.personal.set(change(before));
    request.subscribe({
      error: (e: unknown) => {
        this.personal.set(before);
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
