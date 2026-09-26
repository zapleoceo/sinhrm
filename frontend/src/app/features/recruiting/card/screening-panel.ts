import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { aiCodeKey, aiErrorKey } from '../../ai/ai.service';
import { Application, Screening } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

interface ScreeningRow {
  application: Application;
  screening: Screening | null;
}

/**
 * "AI-скрининг" in the candidate card (tz6): per application, the latest AI assessment of the candidate against the
 * vacancy requirements — score, verdict, summary, strengths, gaps and interview questions — always labelled
 * "Оцінка ШІ, рішення за людиною" (AI Act transparency). The button starts a screening; a slow answer shows as
 * "in progress" and is picked up on refresh.
 */
@Component({
  selector: 'app-screening-panel',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <p class="label"><mat-icon inline aria-hidden="true">smart_toy</mat-icon> {{ 'recruiting.screening.label' | transloco }}</p>
    @if (error(); as key) {
      <p class="error" role="status">{{ key | transloco }}</p>
    }
    @for (row of rows(); track row.application.id) {
      <article class="row">
        <header>
          <strong>{{ row.application.vacancy?.title }}</strong>
          @if (row.screening?.status === 'done' && row.screening; as s) {
            <span class="score" [attr.data-verdict]="s.verdict">{{ s.score }}/100 · {{ 'recruiting.screening.verdict.' + s.verdict | transloco }}</span>
          }
          @if (canWrite()) {
            <button mat-stroked-button type="button" [disabled]="busy() === row.application.id || row.screening?.status === 'pending'" (click)="screen(row.application)">
              <mat-icon>psychology</mat-icon>
              {{ (row.screening ? 'recruiting.screening.again' : 'recruiting.screening.run') | transloco }}
            </button>
          }
        </header>
        @if (busy() === row.application.id) {
          <mat-progress-bar mode="indeterminate" />
        }
        @if (row.screening; as s) {
          @switch (s.status) {
            @case ('pending') {
              <p class="muted">
                {{ 'recruiting.screening.pending' | transloco }}
                <button mat-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
              </p>
            }
            @case ('failed') {
              <p class="error">{{ failKey(s) | transloco }}</p>
            }
            @default {
              @if (s.summary) {
                <p class="summary">{{ s.summary }}</p>
              }
              <div class="lists">
                @if (s.strengths.length) {
                  <div>
                    <h4>{{ 'recruiting.screening.strengths' | transloco }}</h4>
                    <ul>
                      @for (x of s.strengths; track $index) {
                        <li>{{ x }}</li>
                      }
                    </ul>
                  </div>
                }
                @if (s.gaps.length) {
                  <div>
                    <h4>{{ 'recruiting.screening.gaps' | transloco }}</h4>
                    <ul>
                      @for (x of s.gaps; track $index) {
                        <li>{{ x }}</li>
                      }
                    </ul>
                  </div>
                }
                @if (s.questions.length) {
                  <div>
                    <h4>{{ 'recruiting.screening.questions' | transloco }}</h4>
                    <ul>
                      @for (x of s.questions; track $index) {
                        <li>{{ x }}</li>
                      }
                    </ul>
                  </div>
                }
              </div>
            }
          }
        }
      </article>
    } @empty {
      <p class="muted">{{ 'recruiting.card.noApplications' | transloco }}</p>
    }
  `,
  styles: `
    :host { display: block; }
    .label { display: flex; align-items: center; gap: 0.25rem; font-size: 0.8rem; color: var(--app-muted); margin: 0 0 0.5rem; }
    .row { border: 1px solid var(--app-border); border-radius: var(--app-radius); padding: 0.75rem; margin-bottom: 0.5rem; }
    header { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
    header button { margin-left: auto; }
    .score { font-weight: 500; padding: 0 0.5rem; border-radius: 999px; border: 1px solid currentColor; }
    .score[data-verdict='fit'] { color: var(--app-success); }
    .score[data-verdict='maybe'] { color: var(--app-warning); }
    .score[data-verdict='no'] { color: var(--app-danger); }
    .summary { margin: 0.5rem 0 0; }
    .lists { display: grid; gap: 0.5rem; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); }
    h4 { margin: 0.5rem 0 0.25rem; font-size: 0.85rem; }
    ul { margin: 0; padding-left: 1.1rem; }
    .error { color: var(--app-danger); }
  `,
})
export class ScreeningPanel {
  readonly candidateId = input.required<number>();
  readonly applications = input.required<readonly Application[]>();
  readonly canWrite = input(false);

  private readonly api = inject(RecruitingService);
  private readonly screenings = signal<Screening[]>([]);
  protected readonly busy = signal<number | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly rows = computed<ScreeningRow[]>(() => {
    const byApp = new Map(this.screenings().map((s) => [s.application_id, s]));
    return this.applications().map((application) => ({ application, screening: byApp.get(application.id) ?? null }));
  });

  constructor() {
    effect(() => {
      this.candidateId();
      this.load();
    });
  }

  load(): void {
    this.api.screenings(this.candidateId()).subscribe({
      next: (list) => this.screenings.set(list),
      error: (e: unknown) => this.error.set(aiErrorKey(e)),
    });
  }

  protected screen(application: Application): void {
    this.busy.set(application.id);
    this.error.set(null);
    this.api.screen(application.id).subscribe({
      next: (s) => {
        this.screenings.update((list) => [s, ...list.filter((x) => x.application_id !== s.application_id)]);
        this.busy.set(null);
      },
      error: (e: unknown) => {
        this.error.set(aiErrorKey(e));
        this.busy.set(null);
      },
    });
  }

  protected failKey(s: Screening): string {
    return aiCodeKey(s.error) ?? 'ai.errors.generic';
  }
}
