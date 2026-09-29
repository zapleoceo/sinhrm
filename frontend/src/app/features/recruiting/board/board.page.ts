import { NgTemplateOutlet } from '@angular/common';
import { CdkDrag, CdkDragDrop, CdkDropList, CdkDropListGroup } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, booleanAttribute, computed, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { HireAction } from '../../people/hire.action';
import { CandidateDialog, CandidateDialogData } from '../candidates/candidate.dialog';
import { canWriteRecruiting } from '../recruiting.access';
import { daysSince } from '../recruiting.format';
import { Application, PERSONAL_COLORS, PersonalColumn, Stage } from '../recruiting.model';
import { BoardStore, BoardTarget } from './board.store';
import { RejectDialog, RejectDialogData, RejectDialogResult } from './reject.dialog';
import { VacancySources } from './vacancy-sources';

/**
 * Kanban of one vacancy: a column per pipeline stage, drag & drop between columns (CDK). Moving to the reject
 * stage asks for a reason first. Stale cards (no contact for 3+ days) are marked with an icon and text.
 */
@Component({
  selector: 'app-board-page',
  imports: [NgTemplateOutlet, CdkDropListGroup, CdkDropList, CdkDrag, MatButtonModule, MatIconModule, MatMenuModule, MatProgressBarModule, MatTooltipModule, RouterLink, TranslocoPipe, VacancySources],
  providers: [BoardStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (personal()) {
      <div class="personal-bar">
        <span class="muted">{{ 'recruiting.personalBoard.hint' | transloco }}</span>
        <button mat-stroked-button type="button" [matMenuTriggerFor]="setup"><mat-icon>tune</mat-icon>{{ 'recruiting.personalBoard.setup' | transloco }}</button>
        <mat-menu #setup="matMenu">
          @for (c of store.hiddenColumns(); track c.id) {
            <button mat-menu-item type="button" (click)="store.updateColumn(c, { hidden: false }, toast)"><mat-icon>visibility</mat-icon>{{ c.title }}</button>
          } @empty {
            <p class="menu-note muted">{{ 'recruiting.personalBoard.noHidden' | transloco }}</p>
          }
          <button mat-menu-item type="button" (click)="reset()"><mat-icon>restart_alt</mat-icon>{{ 'recruiting.personalBoard.reset' | transloco }}</button>
        </mat-menu>
      </div>
    } @else if (store.board(); as board) {
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
      <app-vacancy-sources [vacancyId]="board.vacancy.id" />
    }
    @if (store.loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.failed()) {
      <div class="state">
        <p>{{ 'recruiting.loadError' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
      </div>
    }

    <div class="board" cdkDropListGroup>
      @for (col of store.columns(); track col.stage.id) {
        <section
          class="column"
          [attr.data-kind]="col.stage.kind"
          cdkDropList
          [cdkDropListData]="stageTarget(col.stage)"
          [cdkDropListDisabled]="!canWrite() && !personal()"
          (cdkDropListDropped)="onDrop($event)"
          [attr.aria-label]="col.stage.name"
        >
          <h2 class="col-head">
            <span>{{ col.stage.name }}</span>
            <span class="muted">{{ col.items.length }}</span>
          </h2>
          @for (app of col.items; track app.id) {
            <ng-container *ngTemplateOutlet="card; context: { $implicit: app, hire: col.stage.is_hire }" />
          } @empty {
            <p class="empty muted">—</p>
          }
        </section>
      }
      @if (personal()) {
        @for (pc of store.personalColumns(); track pc.column.id; let first = $first; let last = $last) {
          <section
            class="column own"
            [attr.data-color]="pc.column.color"
            cdkDropList
            [cdkDropListData]="personalTarget(pc.column)"
            (cdkDropListDropped)="onDrop($event)"
            [attr.aria-label]="pc.column.title"
          >
            <h2 class="col-head">
              @if (renaming() === pc.column.id) {
                <input
                  #title
                  class="col-input"
                  maxlength="40"
                  [value]="pc.column.title"
                  [attr.aria-label]="'recruiting.personalBoard.rename' | transloco"
                  (keydown.enter)="rename(pc.column, title.value)"
                  (keydown.escape)="renaming.set(null)"
                  (blur)="rename(pc.column, title.value)"
                />
              } @else {
                <span><mat-icon inline aria-hidden="true">person</mat-icon> {{ pc.column.title }}</span>
              }
              <span class="muted">{{ pc.items.length }}</span>
              <button mat-icon-button type="button" class="col-menu" [matMenuTriggerFor]="colMenu" [attr.aria-label]="'recruiting.personalBoard.columnMenu' | transloco: { name: pc.column.title }">
                <mat-icon>more_vert</mat-icon>
              </button>
            </h2>
            <mat-menu #colMenu="matMenu">
              <button mat-menu-item type="button" (click)="renaming.set(pc.column.id)"><mat-icon>edit</mat-icon>{{ 'recruiting.personalBoard.rename' | transloco }}</button>
              <button mat-menu-item type="button" [matMenuTriggerFor]="colors"><mat-icon>palette</mat-icon>{{ 'recruiting.personalBoard.color' | transloco }}</button>
              <button mat-menu-item type="button" [disabled]="first" (click)="store.shiftColumn(id(), pc.column, -1, toast)"><mat-icon>chevron_left</mat-icon>{{ 'recruiting.personalBoard.left' | transloco }}</button>
              <button mat-menu-item type="button" [disabled]="last" (click)="store.shiftColumn(id(), pc.column, 1, toast)"><mat-icon>chevron_right</mat-icon>{{ 'recruiting.personalBoard.right' | transloco }}</button>
              <button mat-menu-item type="button" (click)="store.updateColumn(pc.column, { hidden: true }, toast)"><mat-icon>visibility_off</mat-icon>{{ 'recruiting.personalBoard.hide' | transloco }}</button>
              <button mat-menu-item type="button" (click)="store.deleteColumn(pc.column, toast)"><mat-icon>delete</mat-icon>{{ 'recruiting.personalBoard.delete' | transloco }}</button>
            </mat-menu>
            <mat-menu #colors="matMenu">
              @for (c of colorKeys; track c) {
                <button mat-menu-item type="button" (click)="store.updateColumn(pc.column, { color: c }, toast)">
                  <span class="swatch" [attr.data-color]="c"></span>{{ 'recruiting.personalBoard.colors.' + c | transloco }}
                </button>
              }
            </mat-menu>
            @for (app of pc.items; track app.id) {
              <ng-container *ngTemplateOutlet="card; context: { $implicit: app, hire: false, chip: true }" />
            } @empty {
              <p class="empty muted">—</p>
            }
          </section>
        }
        <form class="column add" (submit)="addColumn($event, newTitle)">
          <input #newTitle class="col-input" maxlength="40" [placeholder]="'recruiting.personalBoard.newPlaceholder' | transloco" [attr.aria-label]="'recruiting.personalBoard.add' | transloco" />
          <button mat-button type="submit"><mat-icon>add</mat-icon>{{ 'recruiting.personalBoard.add' | transloco }}</button>
        </form>
      }
    </div>

    <ng-template #card let-app let-isHire="hire" let-chip="chip">
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
        <div class="card-top">
          <a class="name" [routerLink]="['/candidates', app.candidate_id]">{{ app.candidate?.full_name }}</a>
          @if (canWrite() || personal()) {
          <button mat-icon-button type="button" class="move-btn" [matMenuTriggerFor]="moveMenu" [matMenuTriggerData]="{ app: app }" [attr.aria-label]="'recruiting.personalBoard.moveTo' | transloco">
            <mat-icon>drive_file_move</mat-icon>
          </button>
          }
        </div>
        <span class="meta muted">{{ app.candidate?.phone ?? app.candidate?.email ?? '' }}</span>
        @if (chip) {
          <span class="stage-chip" [matTooltip]="'recruiting.personalBoard.stageChip' | transloco">{{ stageName(app.stage_id) }}</span>
        }
        @if (app.is_stale) {
          <span class="stale-text"><mat-icon inline>schedule</mat-icon>{{ 'recruiting.board.stale' | transloco: { days: idle(app) } }}</span>
        }
        @if (canWrite() && isHire) {
          <button mat-stroked-button type="button" class="hire" (click)="hire.run(app.id)">
            <mat-icon>badge</mat-icon>{{ 'people.hire.action' | transloco }}
          </button>
        }
      </article>
    </ng-template>

    <mat-menu #moveMenu="matMenu">
      <ng-template matMenuContent let-app="app">
        @if (canWrite() || personal()) {
          @for (col of store.columns(); track col.stage.id) {
            <button mat-menu-item type="button" (click)="moveTo(app, stageTarget(col.stage))">{{ col.stage.name }}</button>
          }
        }
        @if (personal()) {
          @for (pc of store.personalColumns(); track pc.column.id) {
            <button mat-menu-item type="button" (click)="moveTo(app, personalTarget(pc.column))"><mat-icon>person</mat-icon>{{ pc.column.title }}</button>
          }
        }
      </ng-template>
    </mat-menu>
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
    .hire { margin-top: 0.25rem; align-self: flex-start; }
    .cdk-drag-preview { box-shadow: var(--mat-sys-level3); }
    .cdk-drag-placeholder { opacity: 0.3; }
    .cdk-drag-animating, .cdk-drop-list-dragging .card:not(.cdk-drag-placeholder) { transition: transform 150ms ease-out; }
    .personal-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
    .menu-note { padding: 0 1rem; font-size: 0.85rem; }
    .col-head { align-items: center; gap: 0.25rem; }
    .col-head > span:first-child { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .col-menu { margin: -0.5rem -0.25rem -0.5rem 0; }
    .col-input {
      flex: 1; min-width: 0; font: inherit; padding: 0.25rem 0.4rem; border: 1px solid var(--app-border); border-radius: 6px;
      background: var(--mat-sys-surface); color: var(--mat-sys-on-surface);
    }
    .column.own { border-top-style: dashed; }
    .column.add { display: flex; flex-direction: column; gap: 0.25rem; min-height: 0; background: transparent; border: 1px dashed var(--app-border); }
    .column[data-color='blue'], .swatch[data-color='blue'] { border-top-color: var(--mat-sys-primary); --swatch: var(--mat-sys-primary); }
    .column[data-color='green'], .swatch[data-color='green'] { border-top-color: var(--app-success); --swatch: var(--app-success); }
    .column[data-color='amber'], .swatch[data-color='amber'] { border-top-color: var(--app-warning); --swatch: var(--app-warning); }
    .column[data-color='red'], .swatch[data-color='red'] { border-top-color: var(--app-danger); --swatch: var(--app-danger); }
    .column[data-color='purple'], .swatch[data-color='purple'] { border-top-color: var(--mat-sys-tertiary); --swatch: var(--mat-sys-tertiary); }
    .column[data-color='grey'], .swatch[data-color='grey'] { border-top-color: var(--mat-sys-outline); --swatch: var(--mat-sys-outline); }
    .swatch { display: inline-block; width: 0.8rem; height: 0.8rem; border-radius: 50%; margin-right: 0.5rem; background: var(--swatch); vertical-align: middle; }
    .card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.25rem; }
    .move-btn { margin: -0.5rem -0.5rem -0.5rem 0; flex: none; }
    .stage-chip {
      align-self: flex-start; font-size: 0.75rem; padding: 0 0.5rem; border-radius: 999px;
      background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container);
    }
    @media (max-width: 600px) {
      .board { scroll-snap-type: x mandatory; }
      .column { flex-basis: 85vw; scroll-snap-align: start; }
    }
  `,
})
export class BoardPage {
  /** Route param :id (withComponentInputBinding); on /candidates — the chosen vacancy. */
  readonly id = input.required({ transform: numberAttribute });
  /** /candidates board: the user's own columns follow the shared stages (personal view only). */
  readonly personal = input(false, { transform: booleanAttribute });

  protected readonly store = inject(BoardStore);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  protected readonly hire = inject(HireAction);
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  protected readonly colorKeys = PERSONAL_COLORS;
  protected readonly renaming = signal<number | null>(null);
  protected readonly toast = (key: string): void => {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  };

  constructor() {
    effect(() => this.store.load(this.id(), this.personal()));
  }

  protected load(): void {
    this.store.load(this.id(), this.personal());
  }

  protected idle(app: Application): number {
    return daysSince(app.last_touch_at ?? app.created_at);
  }

  protected open(app: Application): void {
    void this.router.navigate(['/candidates', app.candidate_id]);
  }

  protected stageTarget(stage: Stage): BoardTarget {
    return { type: 'stage', stage };
  }

  protected personalTarget(column: PersonalColumn): BoardTarget {
    return { type: 'personal', column };
  }

  protected stageName(stageId: number): string {
    return this.store.board()?.vacancy.stages.find((s) => s.id === stageId)?.name ?? '';
  }

  protected onDrop(event: CdkDragDrop<BoardTarget, BoardTarget, Application>): void {
    if (event.previousContainer !== event.container) {
      this.moveTo(event.item.data, event.container.data);
    }
  }

  /**
   * Own column: personal filing only, the stage stays. Stage column: leaves the own column (if any) and, when the
   * stage differs, goes through the shared move (same API and policy as the vacancy board).
   */
  protected moveTo(app: Application, target: BoardTarget): void {
    if (target.type === 'personal') {
      this.store.file(app, target.column.id, this.toast);
      return;
    }
    const stage = target.stage;
    if (this.store.filed().has(app.id)) {
      this.store.file(app, null, this.toast);
    }
    if (app.stage_id === stage.id) {
      return;
    }
    if (!this.canWrite()) {
      this.toast('recruiting.errors.forbidden');
      return;
    }
    if (!this.store.needsReason(stage)) {
      this.store.move(app, stage, {}, this.toast);
      return;
    }
    this.dialog
      .open<RejectDialog, RejectDialogData, RejectDialogResult>(RejectDialog, {
        data: { candidateName: app.candidate?.full_name ?? '', reasons: this.store.rejectReasons() },
      })
      .afterClosed()
      .subscribe((result) => {
        if (result) {
          this.store.move(app, stage, result, this.toast);
        }
      });
  }

  protected addColumn(event: Event, input: HTMLInputElement): void {
    event.preventDefault();
    const title = input.value.trim();
    if (title) {
      this.store.addColumn(this.id(), title, this.toast);
      input.value = '';
    }
  }

  protected rename(column: PersonalColumn, value: string): void {
    const title = value.trim();
    this.renaming.set(null);
    if (title && title !== column.title) {
      this.store.updateColumn(column, { title }, this.toast);
    }
  }

  protected reset(): void {
    if (window.confirm(this.i18n.translate('recruiting.personalBoard.resetConfirm'))) {
      this.store.reset(this.id(), this.toast);
    }
  }

  protected addCandidate(): void {
    this.dialog
      .open<CandidateDialog, CandidateDialogData>(CandidateDialog, { data: { vacancyId: this.id() } })
      .afterClosed()
      .subscribe((created) => {
        if (created) {
          this.load();
        }
      });
  }
}
