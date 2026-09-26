import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AiPromptDialog } from './ai-prompt.dialog';
import {
  AI_PURPOSES,
  AI_STATS_PERIODS,
  AiPurpose,
  AiPurposeStats,
  AiStats,
  AiStatsPeriod,
  AiStatus,
  AiTestResult,
  errorsTooltip,
  sparklinePath,
  usagePercent,
} from './ai.model';
import { AiService, aiCodeKey, aiErrorKey } from './ai.service';

/**
 * Integrations → AI: whether AI can run per purpose (and why not), the broker capability and prompt version of each,
 * compact stats per purpose for a period (one toggle for the panel: today / 7 / 30 days; details in a tooltip), the
 * prompt editor per purpose, today's usage against the daily caps, and the "Test prompt" button. Other settings are
 * edited in the AI Broker card below.
 */
@Component({
  selector: 'app-ai-panel',
  imports: [
    DecimalPipe,
    MatButtonModule,
    MatButtonToggleModule,
    MatIconModule,
    MatProgressBarModule,
    MatTooltipModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="panel" aria-live="polite">
      <div class="head">
        <mat-icon aria-hidden="true">smart_toy</mat-icon>
        <div class="text">
          <strong>{{ 'ai.panel.title' | transloco }}</strong>
          <span class="muted">{{ 'ai.panel.explain' | transloco }}</span>
        </div>
        <button
          mat-flat-button
          type="button"
          [disabled]="testing() || !status()?.available"
          (click)="runTest()"
        >
          <mat-icon>play_arrow</mat-icon>
          {{ 'ai.panel.test' | transloco }}
        </button>
      </div>

      @if (testing()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (error(); as key) {
        <p class="notice error" role="status">{{ key | transloco }}</p>
      }
      @if (result(); as r) {
        <p class="notice" [class.error]="r.status !== 'done'" role="status">
          @if (r.status === 'done') {
            {{ 'ai.panel.testOk' | transloco: { reply: r.reply ?? '' } }}
          } @else if (r.status === 'deferred') {
            {{ 'ai.panel.testDeferred' | transloco }}
          } @else {
            {{ resultErrorKey(r) | transloco }}
          }
          <span class="muted">
            ·
            {{
              'ai.panel.tokens'
                | transloco: { in: r.tokens_in, out: r.tokens_out, cached: r.tokens_cached }
            }}
            · \${{ r.cost_usd | number: '1.0-4' }}
          </span>
        </p>
      }

      @if (status(); as s) {
        <div class="state-row">
          <p class="state" [class.off]="!s.available">
            {{
              (s.available ? 'ai.panel.available' : (reasonKey(s) ?? 'ai.errors.generic'))
                | transloco
            }}
            · {{ 'ai.panel.model' | transloco }}:
            {{ s.model ?? ('ai.panel.modelAuto' | transloco) }}
          </p>
          <mat-button-toggle-group
            class="period"
            hideSingleSelectionIndicator
            [value]="period()"
            (change)="setPeriod($event.value)"
            [attr.aria-label]="'ai.stats.period' | transloco"
          >
            @for (p of periods; track p) {
              <mat-button-toggle [value]="p">{{
                'ai.stats.periods.' + p | transloco
              }}</mat-button-toggle>
            }
          </mat-button-toggle-group>
        </div>
        <ul class="purposes">
          @for (p of purposes; track p) {
            <li [class.off]="s.purposes[p] !== null">
              <span class="name">{{ 'ai.purposes.' + p | transloco }}</span>
              <code>{{ s.capabilities[p] ?? s.capability }}</code>
              @if (s.prompt_versions?.[p]; as v) {
                <code class="custom" [matTooltip]="'ai.prompt.customActive' | transloco">{{
                  v
                }}</code>
              }
              <span class="muted">{{
                (s.purposes[p] === null ? 'ai.panel.on' : 'ai.errors.' + s.purposes[p]) | transloco
              }}</span>
              <span class="spacer"></span>
              @if (statsOf(p); as st) {
                <span class="figures muted" [matTooltip]="tooltip(st)" tabindex="0">
                  @if (spark(st); as d) {
                    <svg class="spark" viewBox="0 0 60 16" aria-hidden="true">
                      <path [attr.d]="d" />
                    </svg>
                  }
                  {{
                    'ai.stats.inline'
                      | transloco: { requests: st.requests, success: st.success_pct ?? '—' }
                  }}
                  @if (st.failed > 0) {
                    <span class="err">· {{ 'ai.stats.errors' | transloco: { n: st.failed } }}</span>
                  }
                  · \${{ st.cost_usd | number: '1.0-3' }}
                </span>
              } @else {
                <span class="figures muted">{{ 'ai.stats.none' | transloco }}</span>
              }
              <button
                mat-icon-button
                type="button"
                class="prompt-btn"
                [matTooltip]="'ai.prompt.open' | transloco"
                [attr.aria-label]="'ai.prompt.open' | transloco"
                (click)="openPrompt(p)"
              >
                <mat-icon>edit_note</mat-icon>
              </button>
            </li>
          }
        </ul>
        <div class="usage">
          <div class="meter">
            {{
              'ai.panel.requests' | transloco: { used: s.usage.requests, limit: s.limits.requests }
            }}
            <mat-progress-bar
              mode="determinate"
              [value]="percent(s.usage.requests, s.limits.requests)"
            />
          </div>
          <div class="meter">
            {{
              'ai.panel.cost'
                | transloco
                  : { used: (s.usage.cost_usd | number: '1.0-4'), limit: s.limits.cost_usd }
            }}
            <mat-progress-bar
              mode="determinate"
              [value]="percent(s.usage.cost_usd, s.limits.cost_usd)"
            />
          </div>
          <span class="muted small">{{
            'ai.panel.cached'
              | transloco: { cached: s.usage.tokens_cached, input: s.usage.tokens_in }
          }}</span>
        </div>
      } @else if (loadFailed()) {
        <p class="muted">{{ 'ai.errors.generic' | transloco }}</p>
      }
    </section>
  `,
  styles: `
    .panel {
      border: 1px solid var(--app-border);
      border-radius: 12px;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    .head {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .text {
      display: flex;
      flex-direction: column;
      flex: 1 1 16rem;
    }
    .notice {
      margin: 0.5rem 0 0;
    }
    .notice.error,
    .state.off {
      color: var(--app-danger);
    }
    .state-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      align-items: center;
      justify-content: space-between;
      margin: 0.75rem 0 0.25rem;
    }
    .state {
      margin: 0;
    }
    .period {
      --mat-button-toggle-height: 28px;
      font-size: 0.8rem;
    }
    .purposes {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 0.125rem;
    }
    .purposes li {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      align-items: center;
      min-height: 2rem;
    }
    .purposes li.off .name {
      color: var(--app-muted);
    }
    .custom {
      color: var(--app-accent, inherit);
    }
    .spacer {
      flex: 1 1 auto;
    }
    .figures {
      font-size: 0.8rem;
      display: inline-flex;
      gap: 0.25rem;
      align-items: center;
    }
    .figures .err {
      color: var(--app-danger);
    }
    .spark {
      width: 60px;
      height: 16px;
    }
    .spark path {
      fill: none;
      stroke: currentColor;
      stroke-width: 1.2;
    }
    .usage {
      display: grid;
      gap: 0.5rem;
      margin-top: 0.75rem;
      grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    }
    .meter {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
    }
    .small {
      font-size: 0.8rem;
    }
  `,
})
export class AiPanel implements OnInit {
  private readonly api = inject(AiService);
  private readonly dialog = inject(MatDialog);
  private readonly i18n = inject(TranslocoService);

  protected readonly purposes = AI_PURPOSES;
  protected readonly periods = AI_STATS_PERIODS;
  protected readonly status = signal<AiStatus | null>(null);
  protected readonly stats = signal<AiStats | null>(null);
  protected readonly period = signal<AiStatsPeriod>('today');
  protected readonly loadFailed = signal(false);
  protected readonly testing = signal(false);
  protected readonly result = signal<AiTestResult | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly percent = usagePercent;

  ngOnInit(): void {
    this.load();
  }

  protected runTest(): void {
    this.testing.set(true);
    this.error.set(null);
    this.result.set(null);
    this.api.test().subscribe({
      next: (r) => {
        this.result.set(r);
        this.testing.set(false);
        this.load();
      },
      error: (e: unknown) => {
        this.error.set(aiErrorKey(e));
        this.testing.set(false);
      },
    });
  }

  protected setPeriod(period: AiStatsPeriod): void {
    this.period.set(period);
    this.loadStats();
  }

  protected statsOf(purpose: AiPurpose): AiPurposeStats | null {
    return this.stats()?.purposes[purpose] ?? null;
  }

  protected spark(st: AiPurposeStats): string {
    return sparklinePath(st.series);
  }

  /** Details that do not fit the row: outcomes, latency, tokens, errors by code. */
  protected tooltip(st: AiPurposeStats): string {
    const parts = [
      this.i18n.translate('ai.stats.outcomes', {
        done: st.done,
        failed: st.failed,
        pending: st.pending,
      }),
      this.i18n.translate('ai.stats.latency', { s: st.avg_latency_s ?? '—' }),
      this.i18n.translate('ai.panel.tokens', {
        in: st.tokens_in,
        out: st.tokens_out,
        cached: st.tokens_cached,
      }),
    ];
    const errors = errorsTooltip(st.errors);
    if (errors) {
      parts.push(errors);
    }
    return parts.join(' · ');
  }

  protected openPrompt(purpose: AiPurpose): void {
    this.dialog
      .open(AiPromptDialog, { data: purpose, width: '56rem', maxWidth: '95vw', autoFocus: false })
      .afterClosed()
      .subscribe((changed: unknown) => {
        if (changed === true) {
          this.load();
        }
      });
  }

  protected reasonKey(s: AiStatus): string | null {
    return s.configured ? 'ai.errors.ai_disabled' : 'ai.errors.ai_not_configured';
  }

  protected resultErrorKey(r: AiTestResult): string {
    return aiCodeKey(r.error) ?? 'ai.errors.generic';
  }

  private load(): void {
    this.api.status().subscribe({
      next: (s) => {
        this.status.set(s);
        this.loadFailed.set(false);
      },
      error: () => this.loadFailed.set(true),
    });
    this.loadStats();
  }

  private loadStats(): void {
    this.api.stats(this.period()).subscribe({
      next: (st) => this.stats.set(st),
      error: () => this.stats.set(null),
    });
  }
}
