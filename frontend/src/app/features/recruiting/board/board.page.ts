import { DOCUMENT, NgTemplateOutlet } from '@angular/common';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { CdkScrollable } from '@angular/cdk/scrolling';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, Injector, afterNextRender, booleanAttribute, computed, effect, inject, input, numberAttribute, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
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
import { DropHint, dropHint } from './drop-hint';
import { RejectDialog, RejectDialogData, RejectDialogResult } from './reject.dialog';
import { VacancySources } from './vacancy-sources';
import { NotifyService } from '../../../core/ui/notify.service';

/**
 * Kanban of one vacancy: a column per pipeline stage, drag & drop between columns (CDK). Moving to the reject
 * stage asks for a reason first. Stale cards (no contact for 3+ days) are marked with an icon and text.
 */
@Component({
  selector: 'app-board-page',
  imports: [NgTemplateOutlet, CdkScrollable, CdkDropList, CdkDrag, CdkDragHandle, MatButtonModule, MatIconModule, MatMenuModule, MatProgressBarModule, MatTooltipModule, RouterLink, TranslocoPipe, VacancySources],
  providers: [BoardStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (personal()) {
      <div class="personal-bar">
        <span class="muted">{{ 'recruiting.personalBoard.hint' | transloco }}</span>
        <button mat-stroked-button type="button" [matMenuTriggerFor]="setup"><mat-icon>tune</mat-icon>{{ 'recruiting.personalBoard.setup' | transloco }}</button>
        <mat-menu #setup="matMenu">
          @if (store.hiddenColumns().length > 0) {
            <p class="menu-note muted">{{ 'recruiting.personalBoard.hiddenHead' | transloco }}</p>
          }
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

    <div class="board-wrap">
      <div
        class="board"
        [class.is-dragging]="dragging()"
        cdkScrollable
        cdkDropList
        cdkDropListOrientation="horizontal"
        [cdkDropListDisabled]="!personal()"
        (cdkDropListDropped)="onLaneDrop($event)"
      >
        @for (lane of store.lanes(); track lane.key; let i = $index) {
          <section
            class="column"
            [class.own]="lane.kind === 'personal'"
            [attr.data-kind]="lane.kind === 'stage' ? lane.stage.kind : null"
            [attr.data-color]="lane.kind === 'personal' ? lane.column.color : null"
            cdkDrag
            cdkDragLockAxis="x"
            [cdkDragDisabled]="lane.kind === 'stage' || !personal()"
            [cdkDragStartDelay]="{ touch: 400, mouse: 0 }"
            [attr.aria-label]="lane.kind === 'stage' ? lane.stage.name : lane.column.title"
            [attr.data-drop]="dropHint()?.key === lane.key ? dropHint()?.kind : null"
            [cdkDragPreviewClass]="columnPreviewClass"
            (cdkDragStarted)="dragStart(null, null)"
            (cdkDragEnded)="dragEnd()"
          >
            @if (dropHint(); as hint) {
              @if (hint.key === lane.key) {
                <p class="drop-caption" aria-hidden="true">{{ hint.text }}</p>
              }
            }
            @if (personal()) {
              <div class="slot" [class.open]="adding() === i">
                @if (adding() === i) {
                  <ng-container *ngTemplateOutlet="addForm; context: { $implicit: i }" />
                } @else {
                  <button type="button" class="slot-btn" (click)="openSlot(i)" [attr.aria-label]="'recruiting.personalBoard.addHere' | transloco">+</button>
                }
              </div>
            }
            @if (lane.kind === 'stage') {
              <h2 class="col-head">
                <span class="app-station" [attr.data-kind]="lane.stage.kind" aria-hidden="true"></span>
                <span class="col-title">{{ lane.stage.name }}</span>
                @if (personal()) {
                  <mat-icon class="lock" inline [matTooltip]="'recruiting.personalBoard.stageLocked' | transloco" [attr.aria-label]="'recruiting.personalBoard.stageLocked' | transloco">lock</mat-icon>
                }
                <span class="col-count app-num">{{ lane.items.length }}</span>
              </h2>
            } @else {
              <h2 class="col-head">
                <mat-icon class="grip" cdkDragHandle [matTooltip]="'recruiting.personalBoard.drag' | transloco">drag_indicator</mat-icon>
                <span class="app-station" aria-hidden="true"></span>
                @if (renaming() === lane.column.id) {
                  <input
                    #title
                    class="col-input"
                    maxlength="40"
                    [value]="lane.column.title"
                    [attr.aria-label]="'recruiting.personalBoard.rename' | transloco"
                    (keydown.enter)="rename(lane.column, title.value)"
                    (keydown.escape)="renaming.set(null)"
                    (blur)="rename(lane.column, title.value)"
                  />
                } @else {
                  <span class="col-title">{{ lane.column.title }}</span>
                }
                <span class="col-count app-num">{{ lane.items.length }}</span>
                <button mat-icon-button type="button" class="col-menu" [matMenuTriggerFor]="colMenu" [matMenuTriggerData]="{ column: lane.column }" [attr.aria-label]="'recruiting.personalBoard.columnMenu' | transloco: { name: lane.column.title }">
                  <mat-icon>more_vert</mat-icon>
                </button>
              </h2>
            }
            <div
              class="cards"
              cdkDropList
              [id]="'cards-' + lane.key"
              [cdkDropListConnectedTo]="cardListIds()"
              [cdkDropListData]="lane.kind === 'stage' ? stageTarget(lane.stage) : personalTarget(lane.column)"
              [cdkDropListDisabled]="!canWrite() && !personal()"
              (cdkDropListDropped)="onDrop($event)"
              (cdkDropListEntered)="dragOver.set(lane.key)"
              (cdkDropListExited)="leave(lane.key)"
            >
              @for (app of lane.items; track app.id) {
                <article
                  class="card"
                  cdkDrag
                  [cdkDragData]="app"
                  cdkDragPreviewClass="board-drag-preview"
                  [cdkDragStartDelay]="{ touch: 300, mouse: 0 }"
                  (cdkDragStarted)="dragStart(lane.key, app)"
                  (cdkDragEnded)="dragEnd()"
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
                  <span class="meta muted app-num">{{ app.candidate?.phone ?? app.candidate?.email ?? '' }}</span>
                  @if (lane.kind === 'personal') {
                    <span class="stage-chip" [matTooltip]="'recruiting.personalBoard.stageChip' | transloco">{{ stageName(app.stage_id) }}</span>
                  }
                  @if (app.is_stale) {
                    <span class="stale-text"><mat-icon inline>schedule</mat-icon>{{ 'recruiting.board.stale' | transloco: { days: idle(app) } }}</span>
                  }
                  @if (canWrite() && lane.kind === 'stage' && lane.stage.is_hire) {
                    <button mat-stroked-button type="button" class="hire" (click)="hire.run(app.id)">
                      <mat-icon>badge</mat-icon>{{ 'people.hire.action' | transloco }}
                    </button>
                  }
                </article>
              } @empty {
                <p class="empty muted">—</p>
              }
            </div>
          </section>
        }
        @if (personal() && store.board()) {
          <div class="column add">
            @if (adding() === store.lanes().length) {
              <ng-container *ngTemplateOutlet="addForm; context: { $implicit: store.lanes().length }" />
            } @else {
              <button mat-button type="button" (click)="openSlot(store.lanes().length)">{{ 'recruiting.personalBoard.add' | transloco }}</button>
            }
          </div>
        }
      </div>
    </div>

    <p class="visually-hidden" aria-live="polite">{{ dropHint()?.text ?? '' }}</p>

    <ng-template #addForm let-at>
      <input
        #newTitle
        maxlength="40"
        class="col-input add-input"
        [placeholder]="'recruiting.personalBoard.newPlaceholder' | transloco"
        [attr.aria-label]="'recruiting.personalBoard.add' | transloco"
        (keydown.enter)="addColumn(newTitle.value, at)"
        (keydown.escape)="adding.set(null)"
        (blur)="adding.set(null)"
      />
    </ng-template>

    <mat-menu #colMenu="matMenu">
      <ng-template matMenuContent let-column="column">
        <button mat-menu-item type="button" (click)="renaming.set(column.id)"><mat-icon>edit</mat-icon>{{ 'recruiting.personalBoard.rename' | transloco }}</button>
        <button mat-menu-item type="button" [matMenuTriggerFor]="colors" [matMenuTriggerData]="{ column: column }"><mat-icon>palette</mat-icon>{{ 'recruiting.personalBoard.color' | transloco }}</button>
        <button mat-menu-item type="button" (click)="store.shiftColumn(id(), column, -1, toast)"><mat-icon>chevron_left</mat-icon>{{ 'recruiting.personalBoard.left' | transloco }}</button>
        <button mat-menu-item type="button" (click)="store.shiftColumn(id(), column, 1, toast)"><mat-icon>chevron_right</mat-icon>{{ 'recruiting.personalBoard.right' | transloco }}</button>
        <button mat-menu-item type="button" (click)="store.updateColumn(column, { hidden: true }, toast)"><mat-icon>visibility_off</mat-icon>{{ 'recruiting.personalBoard.hide' | transloco }}</button>
        <button mat-menu-item type="button" (click)="store.deleteColumn(column, toast)"><mat-icon>delete</mat-icon>{{ 'recruiting.personalBoard.delete' | transloco }}</button>
      </ng-template>
    </mat-menu>
    <mat-menu #colors="matMenu">
      <ng-template matMenuContent let-column="column">
        @for (c of colorKeys; track c) {
          <button mat-menu-item type="button" (click)="store.updateColumn(column, { color: c }, toast)">
            <span class="swatch" [attr.data-color]="c"></span>{{ 'recruiting.personalBoard.colors.' + c | transloco }}
          </button>
        }
      </ng-template>
    </mat-menu>

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
    /* Restyle C «Маршрут»: column heads are stations on one line that runs through the whole board (station and line
       colour = stage TYPE via data-kind, never column order); own columns are a dashed «reserve branch». No per-card
       progress dots: the column already is the stage. Tokens only (styles.scss). */
    .board-wrap { position: relative; timeline-scope: --board-x; }
    .board { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 1rem; align-items: flex-start; scroll-timeline: --board-x x; }
    /* Scroll hint: a fade + chevron on the right edge while more columns are to the right. Driven by the board's own
       scroll position (no script): hidden when nothing overflows (inactive timeline) and at the end of the route. */
    .board-wrap::after {
      content: '›' / ''; position: absolute; top: 0; right: 0; bottom: 1rem; width: 3rem; z-index: 3; pointer-events: none;
      display: flex; align-items: flex-start; justify-content: flex-end; padding: 0.15rem 0.25rem 0 0; box-sizing: border-box;
      font: 600 1.6rem/1.75rem var(--app-font-text); color: var(--mat-sys-on-surface); opacity: 0;
      background: linear-gradient(to right, transparent, var(--app-canvas) 85%);
      animation: board-hint linear both; animation-timeline: --board-x;
    }
    .board-wrap:has(.board.is-dragging)::after { visibility: hidden; }
    @keyframes board-hint { 0%, 92% { opacity: 1; } 100% { opacity: 0; } }
    .column {
      --line: var(--app-stage-new);
      --col-bg: var(--mat-sys-surface-container-low);
      flex: 0 0 15rem; background: var(--col-bg); border-radius: var(--app-radius);
      padding: 0.5rem; min-height: 8rem;
    }
    .column[data-kind='select'] { --line: var(--app-stage-select); }
    .column[data-kind='hire'] { --line: var(--app-stage-hire); }
    .column[data-kind='closed'] { --line: var(--app-stage-closed); }
    /* Drop highlight (global, styles.scss) tints the column: the head «cut-outs» follow it. */
    .column[data-drop] { --col-bg: color-mix(in srgb, var(--drop-color) 8%, var(--mat-sys-surface-container-low)); }
    .col-head {
      position: relative; display: flex; align-items: center; gap: 0; margin: 0.25rem 0.25rem 0.6rem;
      font: var(--mat-sys-title-small); font-family: var(--app-font-display);
    }
    /* One line through all heads: from this station on, through the gap, to the next station (it sits on top). */
    .col-head::after {
      content: ''; position: absolute; z-index: 1; left: 0.5rem; right: -2.25rem; top: 50%; height: 3px; translate: 0 -50%;
      border-radius: 2px; background: var(--line); pointer-events: none;
    }
    .column:last-child .col-head::after, .column:has(+ .column.add) .col-head::after { right: 0; }
    /* Head content stands on the line; title and icons «cut» it with the column's own background. */
    .col-head > * { position: relative; z-index: 2; }
    /* No flex gap: neighbours touch, so the line shows only after the last label, up to the count. */
    .col-head > .col-title, .col-head > .lock, .col-head > .grip { background: var(--col-bg); padding-right: 0.4rem; }
    .col-head > .app-station + * { margin-left: 0; padding-left: 0.4rem; }
    .col-head .app-station { width: 1rem; height: 1rem; box-shadow: 0 0 0 3px var(--col-bg); }
    .col-head .col-count {
      flex: none; margin-left: auto; margin-right: 0.15rem; min-width: 1.5rem; padding: 0 0.35rem; box-sizing: border-box; text-align: center;
      font-size: 0.75rem; line-height: 1.25rem; color: var(--mat-sys-on-surface);
      border: var(--app-border-w) solid var(--line); border-radius: var(--app-radius-pill); background: var(--app-card);
    }
    .col-head .col-menu { background: var(--col-bg); }
    .card {
      display: flex; flex-direction: column; gap: 0.125rem; padding: 0.55rem 0.75rem; margin-bottom: 0.5rem;
      background: var(--app-board-card); border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius); cursor: grab;
      box-shadow: var(--app-card-shadow); transition: border-color var(--app-fast) ease-out, translate var(--app-fast) ease-out;
    }
    .card:hover { border-color: var(--line); translate: 0 -1px; }
    .card:focus-visible { outline: 2px solid var(--app-focus-ring); outline-offset: 2px; }
    .card.stale { border-left: 4px solid var(--app-warning); }
    .card.pending { opacity: 0.6; }
    .name { color: inherit; font-weight: 600; text-decoration: none; }
    .name:hover { text-decoration: underline; }
    .meta { font-size: 0.78rem; }
    .stale-text { display: inline-flex; align-items: center; gap: 0.2rem; color: var(--app-warn-text); font-size: 0.8rem; }
    .empty { text-align: center; margin: 1rem 0; }
    .hire { margin-top: 0.25rem; align-self: flex-start; }
    /* Drag preview, landing slot, drop highlight: global (styles.scss «Board drag & drop»), the preview is outside. */
    .cdk-drag-animating, .cdk-drop-list-dragging .card:not(.cdk-drag-placeholder) { transition: transform 150ms ease-out; }
    .personal-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
    .menu-note { padding: 0 1rem; font-size: 0.85rem; }
    .col-title { flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .col-menu { margin: -0.5rem -0.25rem -0.5rem 0; }
    .col-input {
      flex: 1; min-width: 0; font: inherit; padding: 0.25rem 0.4rem; border: var(--app-border-w) solid var(--app-border); border-radius: var(--app-radius-sm);
      background: var(--mat-sys-surface); color: var(--mat-sys-on-surface);
    }
    /* Own column = «reserve branch»: dashed frame, station and line in the column's chosen colour. */
    .column.own { --line: var(--swatch, var(--mat-sys-outline)); --col-bg: var(--app-canvas); background: var(--col-bg); border: var(--app-border-w) dashed var(--app-border); }
    .column.own .app-station { --station: var(--line); border-style: dashed; }
    .column.own .col-head::after { height: 2px; background: repeating-linear-gradient(90deg, var(--line) 0 0.4rem, transparent 0.4rem 0.7rem); }
    .column.add { display: flex; flex-direction: column; gap: 0.25rem; min-height: 0; background: transparent; border: var(--app-border-w) dashed var(--app-border); }
    .column[data-color='blue'], .swatch[data-color='blue'] { --swatch: var(--mat-sys-primary); }
    .column[data-color='green'], .swatch[data-color='green'] { --swatch: var(--app-success); }
    .column[data-color='amber'], .swatch[data-color='amber'] { --swatch: var(--app-warning); }
    .column[data-color='red'], .swatch[data-color='red'] { --swatch: var(--app-danger); }
    .column[data-color='purple'], .swatch[data-color='purple'] { --swatch: var(--mat-sys-tertiary); }
    .column[data-color='grey'], .swatch[data-color='grey'] { --swatch: var(--mat-sys-outline); }
    .swatch { display: inline-block; width: 0.8rem; height: 0.8rem; border-radius: 50%; margin-right: 0.5rem; background: var(--swatch); vertical-align: middle; }
    .card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.25rem; }
    .move-btn { margin: -0.5rem -0.5rem -0.5rem 0; flex: none; }
    .stage-chip {
      align-self: flex-start; font-size: 0.75rem; padding: 0 0.5rem; border-radius: var(--app-radius-pill);
      background: var(--mat-sys-secondary-container); color: var(--mat-sys-on-secondary-container);
    }
    .column { position: relative; }
    .cards { min-height: 3em; }
    .grip { cursor: grab; color: var(--app-muted); flex: none; touch-action: none; }
    .lock { color: var(--app-muted); font-size: 0.9rem; flex: none; }
    .slot { position: absolute; top: 0; bottom: 0; left: -0.75rem; width: 0.75rem; display: flex; justify-content: center; z-index: 1; }
    .slot.open { width: 12rem; left: -6.4rem; align-items: flex-start; padding-top: 0.25rem; }
    .slot-btn {
      opacity: 0; width: 1.25em; height: 1.25em; margin-top: 0.5rem; border-radius: 50%; border: 1px solid var(--app-border);
      background: var(--mat-sys-surface); color: var(--mat-sys-primary); cursor: pointer; padding: 0;
    }
    .slot:hover .slot-btn, .slot-btn:focus-visible { opacity: 1; }
    @media (hover: none) { .slot-btn { opacity: 0.6; } }
    /* Touch: the «+» stays small to the eye, its hit area is 44px. */
    @media (pointer: coarse) {
      .slot-btn { position: relative; }
      .slot-btn::before { content: ''; position: absolute; left: 50%; top: 50%; width: 44px; height: 44px; translate: -50% -50%; }
    }
    .board.cdk-drop-list-dragging > .column:not(.cdk-drag-placeholder) { transition: transform 150ms ease-out; }
    /* The one «pop»: a card dragged over a stage column — that station gets a halo and pops once. */
    .column[data-drop='stage'] .col-head .app-station { box-shadow: 0 0 0 4px color-mix(in srgb, var(--station) 25%, transparent); }
    @media (prefers-reduced-motion: no-preference) {
      .column[data-drop='stage'] .col-head .app-station { animation: station-pop 200ms ease-out; }
    }
    @keyframes station-pop { 50% { scale: 1.35; } }
    @media (prefers-reduced-motion: reduce) {
      .card, .cdk-drag-animating, .cdk-drop-list-dragging .card:not(.cdk-drag-placeholder),
      .board.cdk-drop-list-dragging > .column:not(.cdk-drag-placeholder) { transition: none; }
      .card:hover { translate: none; }
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
  private readonly notify = inject(NotifyService);
  private readonly i18n = inject(TranslocoService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  protected readonly hire = inject(HireAction);
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  protected readonly colorKeys = PERSONAL_COLORS;
  /** CDK adds each entry with classList.add — one class per item (a space-separated string throws). */
  protected readonly columnPreviewClass = ['board-drag-preview', 'board-drag-preview--column'];
  protected readonly renaming = signal<number | null>(null);
  /** Index of the open «+» insertion slot (lanes.length = at the end). */
  protected readonly adding = signal<number | null>(null);
  protected readonly cardListIds = computed(() => this.store.lanes().map((l) => 'cards-' + l.key));
  /** Drag in progress (card or column); the source lane key and the card — null for a column drag. */
  protected readonly dragging = signal(false);
  private readonly dragFrom = signal<{ key: string; app: Application } | null>(null);
  /** Lane the dragged card is over right now (CDK cdkDropListEntered). */
  protected readonly dragOver = signal<string | null>(null);
  /** Highlight + caption of the lane under the dragged card: what the drop will do there. Also read out via aria-live. */
  protected readonly dropHint = computed<DropHint | null>(() => {
    const from = this.dragFrom();
    const lane = this.store.lanes().find((l) => l.key === this.dragOver());
    return from && lane && lane.key !== from.key ? dropHint(lane, from.app, this.canWrite(), (key, params) => this.i18n.translate(key, params)) : null;
  });
  private readonly document = inject(DOCUMENT);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  /** Error callback of the board store (shorter toast: the board is busy with drag & drop). */
  protected readonly toast = (key: string): void => {
    this.notify.show(key, { duration: 3000 });
  };

  constructor() {
    // Only id/personal drive the reload: load() reads store signals (rejectReasons) that its own answers change —
    // tracked, they re-ran load() on every answer (endless reloads when the reject-reason list is empty).
    effect(() => {
      const id = this.id();
      const personal = this.personal();
      untracked(() => this.store.load(id, personal));
    });
    inject(DestroyRef).onDestroy(() => this.document.body.classList.remove('board-dragging'));
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

  protected dragStart(key: string | null, app: Application | null): void {
    this.dragging.set(true);
    this.dragFrom.set(key !== null && app ? { key, app } : null);
    this.dragOver.set(key);
    this.document.body.classList.add('board-dragging');
  }

  /** Pointer left a lane without entering another one: no highlight, no caption. */
  protected leave(key: string): void {
    if (this.dragOver() === key) {
      this.dragOver.set(null);
    }
  }

  protected dragEnd(): void {
    this.dragging.set(false);
    this.dragFrom.set(null);
    this.dragOver.set(null);
    this.document.body.classList.remove('board-dragging');
  }

  protected onDrop(event: CdkDragDrop<BoardTarget, BoardTarget, Application>): void {
    if (event.previousContainer !== event.container) {
      this.moveTo(event.item.data, event.container.data);
    }
  }

  /** Column drag & drop: indexes among the lanes; only own columns can be picked up (stages are locked). */
  protected onLaneDrop(event: CdkDragDrop<unknown>): void {
    this.store.moveLane(this.id(), event.previousIndex, event.currentIndex, this.toast);
  }

  /**
   * Own column: personal filing only, the stage stays. Stage column: the shared move (same API and policy as the
   * vacancy board); the card leaves its own column only once the server accepted the move.
   */
  protected moveTo(app: Application, target: BoardTarget): void {
    if (target.type === 'personal') {
      this.store.file(app, target.column.id, this.toast);
      return;
    }
    const stage = target.stage;
    if (app.stage_id !== stage.id && !this.canWrite()) {
      this.toast('recruiting.errors.forbidden');
      return;
    }
    if (app.stage_id === stage.id || !this.store.needsReason(stage)) {
      this.store.moveToStage(app, stage, {}, this.toast);
      return;
    }
    this.dialog
      .open<RejectDialog, RejectDialogData, RejectDialogResult>(RejectDialog, {
        data: { candidateName: app.candidate?.full_name ?? '', reasons: this.store.rejectReasons() },
      })
      .afterClosed()
      .subscribe((result) => {
        if (result) {
          this.store.moveToStage(app, stage, result, this.toast);
        }
      });
  }

  /** «+» slot between columns (or at the end): inline title, Enter saves right there, Esc cancels. */
  protected openSlot(at: number): void {
    this.adding.set(at);
    afterNextRender(() => this.host.nativeElement.querySelector<HTMLInputElement>('.add-input')?.focus(), { injector: this.injector });
  }

  protected addColumn(value: string, at: number): void {
    const title = value.trim();
    this.adding.set(null);
    if (title) {
      this.store.addColumn(this.id(), title, this.toast, at);
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
