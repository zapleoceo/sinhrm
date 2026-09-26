import { DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { AI_PURPOSES, AiStatus, AiTestResult, usagePercent } from './ai.model';
import { AiService, aiCodeKey, aiErrorKey } from './ai.service';

/**
 * Integrations → AI: whether AI can run per purpose (and why not), the broker capability of each, today's usage against
 * the daily caps, and the "Test prompt" button (a tiny fixed prompt without personal data). Settings themselves are
 * edited in the AI Broker card below.
 */
@Component({
  selector: 'app-ai-panel',
  imports: [DecimalPipe, MatButtonModule, MatIconModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="panel" aria-live="polite">
      <div class="head">
        <mat-icon aria-hidden="true">smart_toy</mat-icon>
        <div class="text">
          <strong>{{ 'ai.panel.title' | transloco }}</strong>
          <span class="muted">{{ 'ai.panel.explain' | transloco }}</span>
        </div>
        <button mat-flat-button type="button" [disabled]="testing() || !status()?.available" (click)="runTest()">
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
            · {{ 'ai.panel.tokens' | transloco: { in: r.tokens_in, out: r.tokens_out, cached: r.tokens_cached } }} · \${{ r.cost_usd | number: '1.0-4' }}
          </span>
        </p>
      }

      @if (status(); as s) {
        <p class="state" [class.off]="!s.available">
          {{ (s.available ? 'ai.panel.available' : (reasonKey(s) ?? 'ai.errors.generic')) | transloco }}
          · {{ 'ai.panel.model' | transloco }}: {{ s.model ?? ('ai.panel.modelAuto' | transloco) }}
        </p>
        <ul class="purposes">
          @for (p of purposes; track p) {
            <li [class.off]="s.purposes[p] !== null">
              <span class="name">{{ 'ai.purposes.' + p | transloco }}</span>
              <code>{{ s.capabilities[p] ?? s.capability }}</code>
              <span class="muted">{{ (s.purposes[p] === null ? 'ai.panel.on' : 'ai.errors.' + s.purposes[p]) | transloco }}</span>
            </li>
          }
        </ul>
        <div class="usage">
          <div class="meter">
            {{ 'ai.panel.requests' | transloco: { used: s.usage.requests, limit: s.limits.requests } }}
            <mat-progress-bar mode="determinate" [value]="percent(s.usage.requests, s.limits.requests)" />
          </div>
          <div class="meter">
            {{ 'ai.panel.cost' | transloco: { used: (s.usage.cost_usd | number: '1.0-4'), limit: s.limits.cost_usd } }}
            <mat-progress-bar mode="determinate" [value]="percent(s.usage.cost_usd, s.limits.cost_usd)" />
          </div>
          <span class="muted small">{{ 'ai.panel.cached' | transloco: { cached: s.usage.tokens_cached, input: s.usage.tokens_in } }}</span>
        </div>
      } @else if (loadFailed()) {
        <p class="muted">{{ 'ai.errors.generic' | transloco }}</p>
      }
    </section>
  `,
  styles: `
    .panel { border: 1px solid var(--app-border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
    .head { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .text { display: flex; flex-direction: column; flex: 1 1 16rem; }
    .notice { margin: 0.5rem 0 0; }
    .notice.error, .state.off { color: var(--app-danger); }
    .state { margin: 0.75rem 0 0.25rem; }
    .purposes { list-style: none; padding: 0; margin: 0; display: grid; gap: 0.25rem; }
    .purposes li { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; }
    .purposes li.off .name { color: var(--app-muted); }
    .usage { display: grid; gap: 0.5rem; margin-top: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
    .meter { display: flex; flex-direction: column; gap: 0.25rem; }
    .small { font-size: 0.8rem; }
  `,
})
export class AiPanel implements OnInit {
  private readonly api = inject(AiService);

  protected readonly purposes = AI_PURPOSES;
  protected readonly status = signal<AiStatus | null>(null);
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
  }
}
