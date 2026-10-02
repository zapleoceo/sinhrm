import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatIconModule } from '@angular/material/icon';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { ErrorGroup, ErrorStatusFilter, ErrorsService } from './errors.service';

/** Адміністрування → Помилки (superadmin): grouped server and web errors, newest first; expand for details. */
@Component({
  selector: 'app-errors-page',
  imports: [DatePipe, MatButtonToggleModule, MatIconModule, MatSlideToggleModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ 'errors.title' | transloco }}</h1>
    <p class="muted">{{ 'errors.hint' | transloco }}</p>
    <mat-button-toggle-group [value]="status()" (change)="setStatus($event.value)" [attr.aria-label]="'errors.filter' | transloco">
      <mat-button-toggle value="open">{{ 'errors.open' | transloco }}</mat-button-toggle>
      <mat-button-toggle value="resolved">{{ 'errors.resolved' | transloco }}</mat-button-toggle>
      <mat-button-toggle value="all">{{ 'errors.all' | transloco }}</mat-button-toggle>
    </mat-button-toggle-group>

    @if (failed()) {
      <p class="fail">{{ 'common.error' | transloco }}</p>
    } @else if (groups(); as list) {
      @if (list.length === 0) {
        <p class="panel app-empty">{{ 'errors.empty' | transloco }}</p>
      }
      <ul class="groups">
        @for (g of list; track g.id) {
          <li [class.resolved]="g.resolved">
            <details>
              <summary>
                <span class="count" [attr.aria-label]="'errors.count' | transloco">×{{ g.count }}</span>
                <span class="source">{{ g.source }}</span>
                <strong>{{ g.exception_class }}</strong>
                <span class="msg">{{ g.message }}</span>
                <span class="muted when">{{ g.last_seen_at | date: 'dd.MM.yyyy HH:mm' }}</span>
              </summary>
              <dl>
                <dt>{{ 'errors.message' | transloco }}</dt><dd class="pre">{{ g.message }}</dd>
                <dt>{{ 'errors.location' | transloco }}</dt><dd>{{ g.file ?? '—' }}{{ g.line ? ':' + g.line : '' }}</dd>
                <dt>{{ 'errors.route' | transloco }}</dt><dd>{{ g.route ?? '—' }}</dd>
                <dt>{{ 'errors.user' | transloco }}</dt><dd>{{ g.last_user_id ?? '—' }}</dd>
                <dt>{{ 'errors.firstSeen' | transloco }}</dt><dd>{{ g.first_seen_at | date: 'dd.MM.yyyy HH:mm' }}</dd>
                <dt>{{ 'errors.lastSeen' | transloco }}</dt><dd>{{ g.last_seen_at | date: 'dd.MM.yyyy HH:mm' }}</dd>
              </dl>
              <mat-slide-toggle [checked]="g.resolved" (change)="toggle(g, $event.checked)">{{ 'errors.markResolved' | transloco }}</mat-slide-toggle>
            </details>
          </li>
        }
      </ul>
    } @else {
      <p class="muted">{{ 'common.loading' | transloco }}</p>
    }
  `,
  styles: `
    h1 { font: var(--mat-sys-headline-small); letter-spacing: var(--mat-sys-headline-small-tracking); margin: 0 0 0.5rem; }
    .groups { list-style: none; padding: 0; margin: 1rem 0 0; display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.5rem; }
    li { border: var(--app-border-w) solid var(--app-border); border-left: 4px solid var(--app-danger); border-radius: var(--app-radius); padding: 0.5rem 1rem; background: var(--app-card); }
    /* Resolved = dashed line + neutral rail (not only a fade), text keeps its contrast. */
    li.resolved { border-style: dashed; border-left-style: solid; border-left-color: var(--app-border); }
    summary { display: flex; gap: 0.5rem 0.75rem; align-items: center; cursor: pointer; flex-wrap: wrap; min-height: 2.25rem; }
    summary strong { min-width: 0; overflow-wrap: anywhere; } /* long exception class names wrap on phones */
    .msg { flex: 1 1 16rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .count {
      font: 600 0.75rem var(--app-font-mono); padding: 0.05rem 0.5rem; border-radius: var(--app-radius-pill);
      color: var(--app-bad-text); background: var(--app-bad-bg);
    }
    li.resolved .count { color: var(--app-muted); background: var(--app-card-2); }
    .source { font: var(--mat-sys-label-small); text-transform: uppercase; letter-spacing: 0.04em; color: var(--app-muted); }
    .when { font-family: var(--app-font-mono); font-size: 0.75rem; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; margin: 0.75rem 0; padding-top: 0.75rem; border-top: var(--app-border-w) solid var(--app-track); }
    dt { color: var(--app-muted); }
    dd { margin: 0; overflow-wrap: anywhere; }
    .pre { white-space: pre-wrap; }
    .fail { color: var(--app-bad-text); }
    @media (max-width: 600px) { summary { min-height: 2.75rem; } }
  `,
})
export class ErrorsPage {
  private readonly api = inject(ErrorsService);
  protected readonly status = signal<ErrorStatusFilter>('open');
  protected readonly groups = signal<ErrorGroup[] | null>(null);
  protected readonly failed = signal(false);

  constructor() {
    this.load();
  }

  protected setStatus(status: ErrorStatusFilter): void {
    this.status.set(status);
    this.load();
  }

  protected toggle(group: ErrorGroup, resolved: boolean): void {
    this.api.setResolved(group.id, resolved).subscribe({
      next: (updated) => this.groups.update((list) => list?.map((g) => (g.id === updated.id ? updated : g)) ?? null),
      error: () => this.failed.set(true),
    });
  }

  private load(): void {
    this.groups.set(null);
    this.failed.set(false);
    this.api.list(this.status()).subscribe({
      next: (list) => this.groups.set(list),
      error: () => this.failed.set(true),
    });
  }
}
