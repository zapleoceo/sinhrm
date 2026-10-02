import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { HandledReport, REPORT_STATUSES, ReportStatus, reportStatusTone } from './safe-speak.model';
import { SafeSpeakService, safeSpeakErrorKey } from './safe-speak.service';
import { SafeSpeakThread } from './thread';
import { NotifyService } from '../../core/ui/notify.service';

/** Handler inbox (/safe-speak/inbox): anonymous reports, the thread, answers, status. Admins with the handler flag. */
@Component({
  selector: 'app-safe-speak-inbox-page',
  imports: [DatePipe, MatButtonModule, MatButtonToggleModule, MatFormFieldModule, MatInputModule, MatProgressBarModule, MatSelectModule, TranslocoPipe, SafeSpeakThread],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'safeSpeak.inbox.title' | transloco }}</h1>
        <p class="muted">{{ 'safeSpeak.inbox.subtitle' | transloco }}</p>
      </div>
    </header>
    <mat-button-toggle-group [value]="status() ?? 'all'" (change)="filter($event.value)" hideSingleSelectionIndicator>
      <mat-button-toggle value="all">{{ 'common.all' | transloco }}</mat-button-toggle>
      @for (s of statuses; track s) {
        <mat-button-toggle [value]="s">{{ 'safeSpeak.status.' + s | transloco }}</mat-button-toggle>
      }
    </mat-button-toggle-group>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <div class="split">
      <ul class="panel list">
        @for (r of items(); track r.id) {
          <li>
            <button type="button" [class.active]="selected()?.id === r.id" (click)="select(r.id)">
              <strong>#{{ r.id }} {{ r.subject }}</strong>
              <span class="meta">{{ 'safeSpeak.categories.' + r.category | transloco }} · <span class="mono">{{ r.updated_on | date: 'dd.MM.yyyy' }}</span> · <span class="app-pill" [attr.data-tone]="tone(r.status)">{{ 'safeSpeak.status.' + r.status | transloco }}</span></span>
            </button>
          </li>
        } @empty {
          <li class="app-empty">{{ 'safeSpeak.inbox.empty' | transloco }}</li>
        }
      </ul>
      @if (selected(); as r) {
        <section class="panel view">
          <div class="head">
            <h2>#{{ r.id }} {{ r.subject }}</h2>
            <mat-form-field subscriptSizing="dynamic">
              <mat-label>{{ 'safeSpeak.statusLabel' | transloco }}</mat-label>
              <mat-select [value]="r.status" (valueChange)="setStatus($event)">
                @for (s of statuses; track s) {
                  <mat-option [value]="s">{{ 'safeSpeak.status.' + s | transloco }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          </div>
          <app-safe-speak-thread [messages]="r.messages ?? []" />
          @if (r.status !== 'closed') {
            <form class="reply" (submit)="$event.preventDefault(); answer()">
              <mat-form-field subscriptSizing="dynamic">
                <mat-label>{{ 'safeSpeak.reply' | transloco }}</mat-label>
                <textarea matInput rows="3" maxlength="10000" [value]="text()" (input)="text.set(val($event))"></textarea>
              </mat-form-field>
              <div class="actions"><button mat-flat-button type="submit" [disabled]="text().trim() === ''">{{ 'safeSpeak.send' | transloco }}</button></div>
            </form>
          }
        </section>
      }
    </div>
  `,
  styles: `
    mat-button-toggle-group { margin-bottom: 1rem; max-width: 100%; overflow-x: auto; }
    .split { display: grid; grid-template-columns: minmax(16rem, 1fr) 2fr; gap: var(--app-gap); align-items: start; }
    .list { list-style: none; margin: 0; padding: 0; }
    .list li + li { border-top: var(--app-border-w) solid var(--app-track); }
    .list button {
      display: flex; flex-direction: column; gap: 0.25rem; width: 100%; min-height: 44px; box-sizing: border-box; text-align: left;
      border: 0; background: none; color: inherit; font: inherit; padding: 0.65rem 0.875rem; cursor: pointer;
      transition: background-color var(--app-fast) ease;
    }
    .list button:hover { background: var(--app-row-hover); }
    /* Selected report: row tint + a brand bar on the left (position, not colour alone). */
    .list button.active { background: var(--app-row-selected); box-shadow: inset 4px 0 0 var(--mat-sys-primary); }
    .list strong { font: var(--mat-sys-title-small); overflow-wrap: anywhere; }
    .meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; color: var(--app-muted); font: var(--mat-sys-body-small); }
    .view { display: flex; flex-direction: column; gap: 0.75rem; padding: 1.25rem; }
    .head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center; }
    .head h2 { font: var(--mat-sys-title-medium); margin: 0; overflow-wrap: anywhere; }
    .reply { display: flex; flex-direction: column; gap: 0.5rem; padding-top: 0.75rem; border-top: var(--app-border-w) dashed var(--app-track); }
    .actions { display: flex; justify-content: flex-end; }
    @media (max-width: 900px) { .split { grid-template-columns: 1fr; } }
    @media (max-width: 600px) { .view { padding: 1rem; } }
    @media (prefers-reduced-motion: reduce) { .list button { transition: none; } }
  `,
})
export class SafeSpeakInboxPage implements OnInit {
  private readonly api = inject(SafeSpeakService);
  private readonly notify = inject(NotifyService);
  protected readonly statuses = REPORT_STATUSES;
  protected readonly status = signal<ReportStatus | undefined>(undefined);
  protected readonly items = signal<HandledReport[]>([]);
  protected readonly selected = signal<HandledReport | null>(null);
  protected readonly loading = signal(false);
  protected readonly text = signal('');
  protected readonly tone = reportStatusTone;

  ngOnInit(): void {
    this.load();
  }

  protected val(event: Event): string {
    return (event.target as HTMLTextAreaElement).value;
  }

  protected filter(value: ReportStatus | 'all'): void {
    this.status.set(value === 'all' ? undefined : value);
    this.load();
  }

  protected select(id: number): void {
    this.apply(this.api.get(id));
  }

  protected answer(): void {
    const r = this.selected();
    if (r !== null) {
      this.apply(this.api.answer(r.id, this.text()), () => this.text.set(''));
    }
  }

  protected setStatus(status: ReportStatus): void {
    const r = this.selected();
    if (r !== null) {
      this.apply(this.api.setStatus(r.id, status));
    }
  }

  private apply(call: Observable<HandledReport>, done?: () => void): void {
    call.subscribe({
      next: (r) => {
        this.selected.set(r);
        this.items.update((list) => list.map((x) => (x.id === r.id ? { ...x, status: r.status, updated_on: r.updated_on } : x)));
        done?.();
      },
      error: (e: unknown) => this.notify.show(safeSpeakErrorKey(e)),
    });
  }

  private load(): void {
    this.loading.set(true);
    this.api.inbox(this.status()).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.notify.show(safeSpeakErrorKey(e));
      },
    });
  }
}
