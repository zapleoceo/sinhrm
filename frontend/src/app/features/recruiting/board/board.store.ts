import { Injectable, computed, inject, signal } from '@angular/core';
import { Application, Board, MoveApplication, RejectReason, Stage } from '../recruiting.model';
import { groupByStage, statusForStage } from '../recruiting.format';
import { RecruitingService, recruitingErrorKey } from '../recruiting.service';

/**
 * Kanban board of one vacancy. Moves are optimistic: the card jumps to the new column at once and goes back
 * (with an i18n error key) if the server refuses.
 */
@Injectable()
export class BoardStore {
  private readonly api = inject(RecruitingService);

  readonly board = signal<Board | null>(null);
  readonly rejectReasons = signal<RejectReason[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly pending = signal<ReadonlySet<number>>(new Set());

  readonly columns = computed(() => {
    const b = this.board();
    return b ? groupByStage(b.vacancy.stages, b.applications) : [];
  });
  readonly staleCount = computed(() => this.board()?.applications.filter((a) => a.is_stale).length ?? 0);

  load(vacancyId: number): void {
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
