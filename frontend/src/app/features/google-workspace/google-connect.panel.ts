import { ChangeDetectionStrategy, Component, OnInit, computed, inject, output, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { CONNECT_ERROR_CODES, GOOGLE_SERVICES, GoogleConnection, GoogleOAuthState, connectUrl } from './google.model';
import { GoogleService } from './google.service';
import { ChannelIcon } from '../../core/ui/channel-icon';

/**
 * Integrations → Google: state of Gmail / Calendar / Sheets and the "Connect Google" button (browser navigation to
 * the OAuth consent, separate from login). Shows the result of the callback (?connected=google / ?google_error=).
 */
@Component({
  selector: 'app-google-connect-panel',
  imports: [ChannelIcon, MatButtonModule, MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="panel" aria-live="polite">
      <div class="head">
        <mat-icon aria-hidden="true">account_circle</mat-icon>
        <div class="text">
          <strong>{{ 'google.connect.title' | transloco }}</strong>
          <span class="muted">{{ 'google.connect.explain' | transloco }}</span>
        </div>
        <a mat-flat-button role="link" [attr.href]="availability() === 'ready' ? href : null" [disabled]="availability() !== 'ready'" [attr.aria-describedby]="availability() === 'ready' ? null : 'google-availability'">
          <mat-icon>link</mat-icon>
          {{ (anyConnected() ? 'google.connect.reconnect' : 'google.connect.button') | transloco }}
        </a>
      </div>

      @if (result(); as r) {
        <p class="notice" [class.error]="r.error" role="status">{{ r.key | transloco: r.params }}</p>
      }
      @if (availability() !== 'ready') {
        <p id="google-availability" class="notice" [class.error]="availability() !== 'loading'">{{ 'google.connect.availability.' + availability() | transloco }}</p>
      }

      <ul class="services">
        @for (c of connections(); track c.service) {
          <li [attr.data-state]="state(c)">
            <app-channel-icon [key]="c.service" />
            <span class="name">{{ 'google.services.' + c.service | transloco }}</span>
            <span class="app-pill" [attr.data-tone]="stateTone[state(c)]">{{ 'google.connect.state.' + state(c) | transloco }}</span>
            @if (c.account_email) {
              <span class="muted">{{ c.account_email }}</span>
            }
            @if (availability() === 'ready' && c.service === 'gmail' && c.connected && !c.can_send) {
              <span class="notice error">{{ 'google.connect.reconnectToSend' | transloco }}</span>
            }
          </li>
        }
      </ul>
      <p class="muted small">
        @if (availability() === 'ready') {
          {{ 'google.connect.testingHint' | transloco }}
        }
        @if (redirectUri(); as uri) {
          <br />{{ 'google.connect.redirectHint' | transloco }} <code>{{ uri }}</code>
        }
      </p>
    </section>
  `,
  styles: `
    .panel { padding: 1rem 1.25rem; margin-bottom: 1rem; }
    .head { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .text { display: flex; flex-direction: column; flex: 1; min-width: 14rem; }
    .services { list-style: none; padding: 0; margin: 0.75rem 0 0; display: flex; gap: 1.5rem; flex-wrap: wrap; }
    .services li { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; min-width: 0; }
    .notice { margin: 0.5rem 0 0; }
    .notice.error { color: var(--app-warn-text); }
    .small { font-size: 0.8rem; margin-bottom: 0; }
    code { word-break: break-all; }
  `,
})
export class GoogleConnectPanel implements OnInit {
  readonly oauthState = output<GoogleOAuthState>();
  private readonly api = inject(GoogleService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly href = connectUrl(GOOGLE_SERVICES);
  protected readonly connections = signal<GoogleConnection[]>([]);
  protected readonly redirectUri = signal<string | null>(null);
  protected readonly availability = signal<GoogleOAuthState>('loading');
  protected readonly result = signal<{ key: string; params: Record<string, string>; error: boolean } | null>(null);
  protected readonly anyConnected = computed(() => this.connections().some((c) => c.connected || c.error !== null));

  ngOnInit(): void {
    this.readResult();
    this.api.status().subscribe({
      next: (s) => {
        this.connections.set(s.data);
        this.redirectUri.set(s.meta.redirect_uri);
        const state = s.meta.oauth_configured ? 'ready' : 'unconfigured';
        this.availability.set(state);
        this.oauthState.emit(state);
      },
      error: () => {
        this.connections.set([]);
        this.availability.set('error');
        this.oauthState.emit('error');
      },
    });
  }

  /** Status-pill tone per state (`.app-pill[data-tone]`: colour + marker shape). */
  protected readonly stateTone = { connected: 'good', error: 'warn', off: 'neutral' } as const;

  protected state(c: GoogleConnection): 'connected' | 'error' | 'off' {
    if (c.error === 'reconnect_required' || c.status === 'error') {
      return 'error';
    }
    return c.connected ? 'connected' : 'off';
  }

  /** Reads and then removes the callback query params, so a reload does not repeat the message. */
  private readResult(): void {
    const q = this.route.snapshot.queryParamMap;
    const error = q.get('google_error');
    if (q.get('connected') === 'google') {
      const missing = q.get('missing');
      this.result.set(
        missing
          ? { key: 'google.connect.missing', params: { services: missing }, error: true }
          : { key: 'google.connect.connected', params: {}, error: false },
      );
    } else if (error) {
      const known = (CONNECT_ERROR_CODES as readonly string[]).includes(error);
      this.result.set({ key: known ? `google.errors.${error}` : 'google.errors.generic', params: {}, error: true });
    } else {
      return;
    }
    void this.router.navigate([], { queryParams: { connected: null, missing: null, google_error: null }, queryParamsHandling: 'merge', replaceUrl: true });
  }
}
