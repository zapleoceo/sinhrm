import { Clipboard } from '@angular/cdk/clipboard';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { EXTENSION_DOCS_URL, ExtensionTokenStatus } from './extension.model';
import { ExtensionService } from './extension.service';
import { ChannelIcon } from '../../core/ui/channel-icon';

const INACTIVE: ExtensionTokenStatus = { active: false, created_at: null, last_used_at: null, expires_at: null };

/**
 * Settings → Browser extension (SinHRM Clipper): the personal token the extension uses for /api/clipper/*.
 * The plaintext is shown once, right after it is created; creating a new one revokes the previous.
 */
@Component({
  selector: 'app-extension-page',
  imports: [ChannelIcon, DatePipe, MatButtonModule, MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1>{{ 'extension.title' | transloco }}</h1>
    <p class="muted">{{ 'extension.explain' | transloco }}</p>
    <ul class="sites" [attr.aria-label]="'extension.sites' | transloco">
      @for (s of sites; track s) {
        <li><app-channel-icon [key]="s" />{{ 'recruiting.source.' + s | transloco }}</li>
      }
    </ul>

    <section class="panel" aria-live="polite">
      @if (status(); as s) {
        @if (s.active) {
          <p>
            <mat-icon class="ok" aria-hidden="true">check_circle</mat-icon>
            {{ 'extension.active' | transloco }}
          </p>
          <dl>
            <dt>{{ 'extension.createdAt' | transloco }}</dt>
            <dd>{{ s.created_at | date: 'dd.MM.yyyy HH:mm' }}</dd>
            <dt>{{ 'extension.lastUsedAt' | transloco }}</dt>
            <dd>{{ s.last_used_at ? (s.last_used_at | date: 'dd.MM.yyyy HH:mm') : ('extension.never' | transloco) }}</dd>
            <dt>{{ 'extension.expiresAt' | transloco }}</dt>
            <dd>{{ s.expires_at | date: 'dd.MM.yyyy' }}</dd>
          </dl>
        } @else {
          <p>{{ 'extension.inactive' | transloco }}</p>
        }
      }

      @if (plain(); as token) {
        <div class="once" role="status">
          <strong>{{ 'extension.onceTitle' | transloco }}</strong>
          <span class="muted">{{ 'extension.onceHint' | transloco }}</span>
          <code data-testid="token">{{ token }}</code>
          <button mat-stroked-button type="button" (click)="copy(token)">
            <mat-icon>content_copy</mat-icon>{{ (copied() ? 'extension.copied' : 'extension.copy') | transloco }}
          </button>
        </div>
      }

      @if (error()) {
        <p class="error" role="alert">{{ 'extension.error' | transloco }}</p>
      }

      <div class="actions">
        <button mat-flat-button type="button" data-testid="issue" [disabled]="busy()" (click)="issue()">
          <mat-icon>key</mat-icon>
          {{ (status()?.active ? 'extension.regenerate' : 'extension.create') | transloco }}
        </button>
        @if (status()?.active) {
          <button mat-button type="button" data-testid="revoke" [disabled]="busy()" (click)="revoke()">
            <mat-icon>block</mat-icon>{{ 'extension.revoke' | transloco }}
          </button>
        }
      </div>
    </section>

    <section class="panel">
      <h2>{{ 'extension.installTitle' | transloco }}</h2>
      <ol>
        <li>{{ 'extension.install.step1' | transloco }}</li>
        <li>{{ 'extension.install.step2' | transloco }}</li>
        <li>{{ 'extension.install.step3' | transloco }}</li>
        <li>{{ 'extension.install.step4' | transloco }}</li>
      </ol>
      <a mat-button [href]="docsUrl" target="_blank" rel="noopener">
        <mat-icon>open_in_new</mat-icon>{{ 'extension.docs' | transloco }}
      </a>
      <p class="muted small">{{ 'extension.privacy' | transloco }}</p>
    </section>
  `,
  styles: `
    :host { display: block; max-width: 48rem; }
    .sites { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; list-style: none; padding: 0; margin: 0 0 1rem; }
    .sites li { display: inline-flex; align-items: center; gap: 0.35rem; }
    .panel { border: 1px solid var(--app-border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; margin: 0.5rem 0; }
    dd { margin: 0; }
    .ok { color: var(--app-success); vertical-align: middle; }
    .once { display: flex; flex-direction: column; gap: 0.5rem; padding: 0.75rem; border-radius: 8px; border: 1px dashed var(--app-warning); margin: 0.75rem 0; }
    code { word-break: break-all; user-select: all; }
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.75rem; }
    .error { color: var(--app-warning); }
    .small { font-size: 0.8rem; }
  `,
})
export class ExtensionPage implements OnInit {
  /** Sites the Clipper reads profiles from (extension/src/extractors). */
  protected readonly sites = ['linkedin', 'work_ua', 'djinni', 'dou'] as const;
  private readonly api = inject(ExtensionService);
  private readonly clipboard = inject(Clipboard);

  protected readonly docsUrl = EXTENSION_DOCS_URL;
  protected readonly status = signal<ExtensionTokenStatus | null>(null);
  /** The plaintext of a token created during this visit; kept only in memory. */
  protected readonly plain = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal(false);
  protected readonly copied = signal(false);

  ngOnInit(): void {
    this.api.status().subscribe({ next: (s) => this.status.set(s), error: () => this.error.set(true) });
  }

  protected issue(): void {
    this.start();
    this.api.issue().subscribe({
      next: ({ token, ...status }) => {
        this.plain.set(token ?? null);
        this.status.set(status);
        this.busy.set(false);
      },
      error: () => this.fail(),
    });
  }

  protected revoke(): void {
    this.start();
    this.api.revoke().subscribe({
      next: () => {
        this.plain.set(null);
        this.status.set(INACTIVE);
        this.busy.set(false);
      },
      error: () => this.fail(),
    });
  }

  protected copy(token: string): void {
    this.copied.set(this.clipboard.copy(token));
  }

  private start(): void {
    this.busy.set(true);
    this.error.set(false);
    this.copied.set(false);
  }

  private fail(): void {
    this.busy.set(false);
    this.error.set(true);
  }
}
