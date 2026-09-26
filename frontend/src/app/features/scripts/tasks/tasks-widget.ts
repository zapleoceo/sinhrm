import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { canWriteRecruiting } from '../../recruiting/recruiting.access';
import { Task, TaskQuery, canCompleteTask, isInternalLink, splitLink } from '../scripts.model';
import { TasksStore } from './tasks.store';

/**
 * Unified tasks (recruiting follow-ups, workflow steps, documents to acknowledge) with a "done" checkbox.
 * Dashboard: `[query]="{mine: true, due: 'today'}"`; candidate card: `[query]="{candidate_id: id}"`.
 */
@Component({
  selector: 'app-tasks-widget',
  imports: [DatePipe, MatButtonModule, MatCheckboxModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  providers: [TasksStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (store.loading() && store.items().length === 0) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.failed()) {
      <p class="muted">{{ 'scripts.tasks.loadError' | transloco }}</p>
    }
    <ul class="tasks">
      @for (t of store.items(); track t.id) {
        <li [class.done]="t.done_at" [class.overdue]="t.is_overdue && !t.done_at">
          @if (t.type === 'document') {
            <mat-icon class="doc-icon" aria-hidden="true">description</mat-icon>
          } @else {
            <mat-checkbox
              [checked]="!!t.done_at"
              [disabled]="!canComplete(t) || store.pending().has(t.id)"
              (change)="store.toggleDone(t, toast)"
              [attr.aria-label]="t.title"
            />
          }
          <div class="body">
            <span class="title">{{ t.type === 'new_applicant' ? ('scripts.tasks.newApplicantTitle' | transloco) : t.title }}</span>
            <span class="meta muted">
              @if (t.employee) {
                <a [routerLink]="['/people', t.employee.id]">{{ t.employee.name }}</a> ·
              }
              @if (showCandidate() && t.candidate) {
                <a [routerLink]="['/candidates', t.candidate.id]">{{ t.candidate.name }}</a> ·
              }
              @if (t.vacancy) {
                {{ t.vacancy.title }} ·
              }
              @if (t.is_overdue && !t.done_at) {
                <mat-icon inline>schedule</mat-icon>{{ 'scripts.tasks.overdue' | transloco }}
              }
              <time [attr.datetime]="t.due_at">{{ t.due_at | date: 'dd.MM HH:mm' }}</time>
              · {{ 'scripts.tasks.type.' + t.type | transloco }}
            </span>
          </div>
          @if (t.type === 'document') {
            <a mat-button class="open" routerLink="/me/documents">{{ 'scripts.tasks.open' | transloco }}</a>
          } @else if (t.link; as link) {
            @if (internal(link)) {
              <a mat-button class="open" [routerLink]="split(link).path" [queryParams]="split(link).query">{{ 'scripts.tasks.open' | transloco }}</a>
            } @else {
              <a mat-button class="open" [href]="link" target="_blank" rel="noopener noreferrer">{{ 'scripts.tasks.open' | transloco }}<mat-icon iconPositionEnd>open_in_new</mat-icon></a>
            }
          }
        </li>
      } @empty {
        @if (!store.loading() && !store.failed()) {
          <li class="muted empty">{{ 'scripts.tasks.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    :host { display: block; }
    .tasks { list-style: none; margin: 0; padding: 0; }
    li { display: flex; align-items: flex-start; gap: 0.25rem; padding: 0.25rem 0; border-bottom: 1px solid var(--app-border); }
    li:last-child { border-bottom: 0; }
    li.done .title { text-decoration: line-through; color: var(--app-muted); }
    li.overdue .meta mat-icon { color: var(--app-warning); }
    .body { display: flex; flex-direction: column; padding-top: 0.6rem; min-width: 0; flex: 1; }
    .doc-icon { margin: 0.6rem 0.7rem 0; color: var(--app-muted); }
    .open { flex: none; margin-top: 0.2rem; }
    .meta { font-size: 0.8rem; }
    .meta a { color: inherit; }
    .empty { padding: 0.5rem 0; border: 0; }
  `,
})
export class TasksWidget {
  readonly query = input.required<TaskQuery>();

  protected readonly store = inject(TasksStore);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  private readonly userId = computed(() => this.auth.user()?.id ?? null);
  /** In the candidate card the candidate is obvious; elsewhere it links to the card. */
  protected readonly showCandidate = computed(() => this.query().candidate_id === undefined);
  protected readonly toast = (key: string): void => {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  };

  constructor() {
    effect(() => this.store.load(this.query()));
  }

  protected canComplete(task: Task): boolean {
    return canCompleteTask(task, this.userId(), this.canWrite());
  }

  protected internal(link: string): boolean {
    return isInternalLink(link);
  }

  protected split(link: string): { path: string; query: Record<string, string> } {
    return splitLink(link);
  }
}
