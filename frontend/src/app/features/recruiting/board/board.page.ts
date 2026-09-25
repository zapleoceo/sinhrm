import { CdkDrag, CdkDragDrop, CdkDropList, CdkDropListGroup } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { CandidateDialog, CandidateDialogData } from '../candidates/candidate.dialog';
import { canWriteRecruiting } from '../recruiting.access';
import { daysSince } from '../recruiting.format';
import { Application, Stage } from '../recruiting.model';
import { BoardStore } from './board.store';
import { RejectDialog, RejectDialogData, RejectDialogResult } from './reject.dialog';

/**
 * Kanban of one vacancy: a column per pipeline stage, drag & drop between columns (CDK). Moving to the reject
 * stage asks for a reason first. Stale cards (no contact for 3+ days) are marked with an icon and text.
 */
@Component({
  selector: 'app-board-page',
  imports: [CdkDropListGroup, CdkDropList, CdkDrag, MatButtonModule, MatIconModule, MatProgressBarModule, MatTooltipModule, RouterLink, TranslocoPipe],
  providers: [BoardStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (store.board(); as board) {
      <header class="page-head">
        <div>
          <a mat-button routerLink="/vacancies"><mat-icon>arrow_back</mat-icon>{{ 'recruiting.vacancies.title' | transloco }}</a>
          <h1>{{ board.vacancy.title }}</h1>
          <p class="muted">
            {{ board.vacancy.branch?.name }} · {{ 'recruiting.vacancyStatus.' + board.vacancy.status | transloco }}
            @if (store.staleCount() > 0) {
              · <span class="stale-text"><mat-icon inline>schedule</mat-icon>{{ 'recruiting.board.staleCount' | transloco: { n: store.staleCount() } }}</span>
            }
          </p>
        </div>
        @if (canWrite()) {
          <button mat-flat-button type="button" (click)="addCandidate()"><mat-icon>person_add</mat-icon>{{ 'recruiting.board.add' | transloco }}</button>
        }
      </header>
    }
    @if (store.loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.failed()) {
      <div class="state">
        <p>{{ 'recruiting.loadError' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="store.load(id())">{{ 'common.retry' | transloco }}</button>
      </div>
    }

    <div class="board" cdkDropListGroup>
      @for (col of store.columns(); track col.stage.id) {
        <section
          class="column"
          [attr.data-kind]="col.stage.kind"
          cdkDropList
          [cdkDropListData]="col.stage"
          [cdkDropListDisabled]="!canWrite()"
          (cdkDropListDropped)="onDrop($event)"
          [attr.aria-label]="col.stage.name"
        >
          <h2 class="col-head">
            <span>{{ col.stage.name }}</span>
            <span class="muted">{{ col.items.length }}</span>
          </h2>
          @for (app of col.items; track app.id) {
            <article
              class="card"
              cdkDrag
              [cdkDragData]="app"
              [class.stale]="app.is_stale"
              [class.pending]="store.pending().has(app.id)"
              tabindex="0"
              (keydown.enter)="open(app)"
              (dblclick)="open(app)"
            >
              <a class="name" [routerLink]="['/candidates', app.candidate_id]">{{ app.candidate?.full_name }}</a>
              <span class="meta muted">{{ app.candidate?.phone ?? app.candidate?.email ?? '' }}</span>
              @if (app.is_stale) {
                <span class="stale-text"><mat-icon inline>schedule</mat-icon>{{ 'recruiting.board.stale' | transloco: { days: idle(app) } }}</span>
              }
            </article>
          } @empty {
            <p class="empty muted">—</p>
          }
        </section>
      }
    </div>
  `,
  styles: `
    .board { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 1rem; align-items: flex-start; }
    .column {
      flex: 0 0 15rem; background: var(--mat-sys-surface-container-low); border-radius: var(--app-radius);
      padding: 0.5rem; min-height: 8rem; border-top: 3px solid var(--app-border);
    }
    .column[data-kind='hire'] { border-top-color: var(--app-success); }
    .column[data-kind='closed'] { border-top-color: var(--app-danger); }
    .col-head { display: flex; justify-content: space-between; font: var(--mat-sys-title-small); margin: 0.25rem 0.25rem 0.5rem; }
    .card {
      display: flex; flex-direction: column; gap: 0.125rem; padding: 0.5rem 0.75rem; margin-bottom: 0.5rem;
      background: var(--mat-sys-surface); border: 1px solid var(--app-border); border-radius: 8px; cursor: grab;
    }
    .card:focus-visible { outline: 2px solid var(--mat-sys-primary); }
    .card.stale { border-left: 3px solid var(--app-warning); }
    .card.pending { opacity: 0.6; }
    .name { color: inherit; font-weight: 500; text-decoration: none; }
    .meta { font-size: 0.8rem; font-variant-numeric: tabular-nums; }
    .stale-text { color: var(--app-warning); font-size: 0.8rem; }
    .empty { text-align: center; margin: 1rem 0; }
    .cdk-drag-preview { box-shadow: var(--mat-sys-level3); }
    .cdk-drag-placeholder { opacity: 0.3; }
    .cdk-drag-animating, .cdk-drop-list-dragging .card:not(.cdk-drag-placeholder) { transition: transform 150ms ease-out; }
  `,
})
export class BoardPage {
  /** Route param :id (withComponentInputBinding). */
  readonly id = input.required({ transform: numberAttribute });

  protected readonly store = inject(BoardStore);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));

  constructor() {
    effect(() => this.store.load(this.id()));
  }

  protected idle(app: Application): number {
    return daysSince(app.last_touch_at ?? app.created_at);
  }

  protected open(app: Application): void {
    void this.router.navigate(['/candidates', app.candidate_id]);
  }

  protected onDrop(event: CdkDragDrop<Stage, Stage, Application>): void {
    const app = event.item.data;
    const stage = event.container.data;
    if (event.previousContainer === event.container) {
      return;
    }
    if (!this.store.needsReason(stage)) {
      this.store.move(app, stage, {}, (key) => this.toast(key));
      return;
    }
    this.dialog
      .open<RejectDialog, RejectDialogData, RejectDialogResult>(RejectDialog, {
        data: { candidateName: app.candidate?.full_name ?? '', reasons: this.store.rejectReasons() },
      })
      .afterClosed()
      .subscribe((result) => {
        if (result) {
          this.store.move(app, stage, result, (key) => this.toast(key));
        }
      });
  }

  protected addCandidate(): void {
    this.dialog
      .open<CandidateDialog, CandidateDialogData>(CandidateDialog, { data: { vacancyId: this.id() } })
      .afterClosed()
      .subscribe((created) => {
        if (created) {
          this.store.load(this.id());
        }
      });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
