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
        <p class="muted">{{ 'errors.empty' | transloco }}</p>
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
    h1 { font: var(--mat-sys-headline-small); margin: 0 0 0.5rem; }
    .groups { list-style: none; padding: 0; margin: 1rem 0 0; display: grid; gap: 0.5rem; }
    li { border: 1px solid var(--mat-sys-outline-variant); border-radius: 8px; padding: 0.5rem 0.75rem; }
    li.resolved { opacity: 0.6; }
    summary { display: flex; gap: 0.75rem; align-items: baseline; cursor: pointer; flex-wrap: wrap; }
    .msg { flex: 1 1 16rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .count { font-weight: 500; }
    .source { font: var(--mat-sys-label-small); text-transform: uppercase; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; margin: 0.75rem 0; }
    dd { margin: 0; overflow-wrap: anywhere; }
    .pre { white-space: pre-wrap; }
    .fail { color: var(--app-danger); }
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
