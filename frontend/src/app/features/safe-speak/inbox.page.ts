import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { HandledReport, REPORT_STATUSES, ReportStatus } from './safe-speak.model';
import { SafeSpeakService, safeSpeakErrorKey } from './safe-speak.service';
import { SafeSpeakThread } from './thread';

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
              <span class="muted small">{{ 'safeSpeak.categories.' + r.category | transloco }} · {{ r.updated_on | date: 'dd.MM.yyyy' }} · {{ 'safeSpeak.status.' + r.status | transloco }}</span>
            </button>
          </li>
        } @empty {
          <li class="muted pad">{{ 'safeSpeak.inbox.empty' | transloco }}</li>
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
    mat-button-toggle-group { margin-bottom: 1rem; }
    .split { display: grid; grid-template-columns: minmax(16rem, 1fr) 2fr; gap: var(--app-gap); align-items: start; }
    .list { list-style: none; margin: 0; padding: 0; }
    .list button { display: flex; flex-direction: column; gap: 0.15rem; width: 100%; text-align: left; border: 0; border-bottom: 1px solid var(--app-border); background: none; color: inherit; font: inherit; padding: 0.6rem 0.75rem; cursor: pointer; }
    .list button.active { background: color-mix(in srgb, var(--mat-sys-primary) 8%, transparent); }
    .pad { padding: 0.75rem; }
    .view { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; }
    .head { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center; }
    .head h2 { font: var(--mat-sys-title-medium); margin: 0; }
    .reply { display: flex; flex-direction: column; gap: 0.5rem; }
    .actions { display: flex; justify-content: flex-end; }
    .small { font-size: 0.8rem; }
    @media (max-width: 900px) { .split { grid-template-columns: 1fr; } }
  `,
})
export class SafeSpeakInboxPage implements OnInit {
  private readonly api = inject(SafeSpeakService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly statuses = REPORT_STATUSES;
  protected readonly status = signal<ReportStatus | undefined>(undefined);
  protected readonly items = signal<HandledReport[]>([]);
  protected readonly selected = signal<HandledReport | null>(null);
  protected readonly loading = signal(false);
  protected readonly text = signal('');

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
      error: (e: unknown) => this.toast(safeSpeakErrorKey(e)),
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
        this.toast(safeSpeakErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
