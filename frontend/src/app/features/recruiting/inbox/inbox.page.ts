import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { canWriteRecruiting } from '../recruiting.access';
import { Touchpoint } from '../recruiting.model';
import { InboxResolveDialog, InboxResolveData } from './inbox-resolve.dialog';
import { InboxStore } from './inbox.store';
import { ChannelIcon } from '../../../core/ui/channel-icon';

/** Messages captured from outside that matched no candidate. Triage: link or create; resolved ones disappear. */
@Component({
  selector: 'app-inbox-page',
  imports: [ChannelIcon, DatePipe, MatButtonModule, MatIconModule, MatProgressBarModule, TranslocoPipe],
  providers: [InboxStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'recruiting.inbox.title' | transloco }}</h1>
        <p class="muted">{{ 'recruiting.inbox.subtitle' | transloco }}</p>
      </div>
      <span class="count">{{ store.total() }}</span>
    </header>
    <section class="panel" aria-live="polite">
      @if (store.loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (store.failed()) {
        <div class="state">
          <p>{{ 'recruiting.loadError' | transloco }}</p>
          <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
        </div>
      } @else if (!store.loading() && store.items().length === 0) {
        <p class="state muted"><mat-icon inline>inbox</mat-icon> {{ 'recruiting.inbox.empty' | transloco }}</p>
      }
      <ul class="rows">
        @for (m of store.items(); track m.id) {
          <li class="row">
            <app-channel-icon class="icon" [key]="m.channel" />
            <div class="body">
              <div class="line">
                <strong>{{ m.meta.contact ?? '—' }}</strong>
                <span class="muted">{{ 'recruiting.channel.' + m.channel | transloco }}</span>
                <time class="muted" [attr.datetime]="m.occurred_at">{{ m.occurred_at | date: 'dd.MM HH:mm' }}</time>
              </div>
              @if (m.body) {
                <p class="text">{{ m.body }}</p>
              }
            </div>
            @if (canWrite()) {
              <button mat-stroked-button type="button" (click)="resolve(m)">{{ 'recruiting.inbox.resolve' | transloco }}</button>
            }
          </li>
        }
      </ul>
    </section>
  `,
  styles: `
    .count { font: var(--mat-sys-headline-small); font-variant-numeric: tabular-nums; }
    .rows { list-style: none; margin: 0; padding: 0; }
    .row { display: flex; gap: 0.75rem; align-items: center; padding: 0.75rem 1rem; border-bottom: 1px solid var(--app-border); }
    .icon { color: var(--app-muted); }
    .body { flex: 1; min-width: 0; }
    .line { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; }
    .text { margin: 0.25rem 0 0; overflow-wrap: anywhere; }
  `,
})
export class InboxPage implements OnInit {
  protected readonly store = inject(InboxStore);
  private readonly dialog = inject(MatDialog);
  private readonly auth = inject(AuthService);
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));

  ngOnInit(): void {
    this.store.load();
  }

  protected resolve(message: Touchpoint): void {
    this.dialog.open<InboxResolveDialog, InboxResolveData>(InboxResolveDialog, { data: { message, store: this.store } });
  }
}
