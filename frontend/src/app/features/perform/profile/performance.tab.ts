import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { DevelopmentPlan, Kpi, Objective, OneOnOne, ReviewResult, progressTone } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';
import { ReviewResults } from '../reviews/review-results';
import { toIsoDate } from '../../../core/date/iso-date';

/**
 * Profile tab "Performance": objectives, KPIs, development plans (managers/admins add a plan and KPIs; the
 * employee ticks plan actions), 1:1s and review results the viewer may see. The API scopes every list.
 */
@Component({
  selector: 'app-performance-tab',
  imports: [DatePipe, FormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, RouterLink, TranslocoPipe, ReviewResults],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="grid">
      <section class="panel box">
        <h3>{{ 'perform.objectives.title' | transloco }}</h3>
        @for (o of objectives(); track o.id) {
          <div class="line">
            <span>{{ o.title }} <span class="muted">· {{ o.period }}</span></span>
            <span class="bar"><span [style.width.%]="o.progress" [attr.data-tone]="tone(o.progress)"></span></span>
            <span>{{ o.progress }}%</span>
          </div>
        } @empty {
          <p class="muted">{{ 'perform.objectives.empty' | transloco }}</p>
        }
        <a mat-button routerLink="/perform/objectives">{{ 'perform.open' | transloco }}<mat-icon iconPositionEnd>arrow_forward</mat-icon></a>
      </section>

      <section class="panel box">
        <h3>{{ 'perform.kpis.title' | transloco }}</h3>
        <table class="kpis">
          <tbody>
            @for (k of kpis(); track k.id) {
              <tr>
                <th scope="row">{{ k.metric }}</th>
                <td class="muted">{{ k.period }}</td>
                <td>{{ k.actual ?? '—' }} / {{ k.target }} {{ k.unit ?? '' }}</td>
                <td>{{ k.attainment === null ? '—' : k.attainment + '%' }}</td>
              </tr>
            } @empty {
              <tr><td class="muted">{{ 'perform.kpis.empty' | transloco }}</td></tr>
            }
          </tbody>
        </table>
        @if (canManage()) {
          <form class="filters" (ngSubmit)="addKpi()">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.kpis.metric' | transloco }}</mat-label>
              <input matInput name="metric" [(ngModel)]="metric" required />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="num">
              <mat-label>{{ 'perform.fields.period' | transloco }}</mat-label>
              <input matInput name="period" [(ngModel)]="period" placeholder="2026-10" required />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="num">
              <mat-label>{{ 'perform.objectives.target' | transloco }}</mat-label>
              <input matInput type="number" name="target" [(ngModel)]="target" required />
            </mat-form-field>
            <button mat-stroked-button type="submit">{{ 'perform.add' | transloco }}</button>
          </form>
        }
      </section>

      <section class="panel box">
        <h3>{{ 'perform.plans.title' | transloco }}</h3>
        @for (p of plans(); track p.id) {
          <div class="plan">
            <strong>{{ p.title }}</strong>
            <span class="muted"> · {{ p.progress.done }}/{{ p.progress.total }}@if (p.due_on) { · {{ p.due_on | date: 'dd.MM.yyyy' }}}</span>
            <ul>
              @for (g of p.goals; track g.id) {
                <li class="muted">{{ g.text }}</li>
              }
            </ul>
            @for (a of p.actions; track a.id) {
              <mat-checkbox [checked]="a.done" (change)="toggle(p, a.id, !a.done)">{{ a.text }}@if (a.due_on) { <span class="muted">· {{ a.due_on | date: 'dd.MM' }}</span>}</mat-checkbox>
            }
          </div>
        } @empty {
          <p class="muted">{{ 'perform.plans.empty' | transloco }}</p>
        }
        @if (canManage()) {
          <form class="filters" (ngSubmit)="addPlan()">
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.fields.title' | transloco }}</mat-label>
              <input matInput name="planTitle" [(ngModel)]="planTitle" required />
            </mat-form-field>
            <mat-form-field subscriptSizing="dynamic" class="grow">
              <mat-label>{{ 'perform.plans.actions' | transloco }}</mat-label>
              <input matInput name="planActions" [(ngModel)]="planActions" [placeholder]="'perform.plans.actionsHint' | transloco" />
            </mat-form-field>
            <button mat-stroked-button type="submit">{{ 'perform.add' | transloco }}</button>
          </form>
        }
      </section>

      <section class="panel box">
        <h3>{{ 'perform.oneOnOnes.title' | transloco }}</h3>
        <ul class="rows">
          @for (m of meetings(); track m.id) {
            <li>{{ m.scheduled_at | date: 'dd.MM.yyyy' }} · {{ m.manager.full_name }} <span class="muted">· {{ 'perform.oneOnOneStatus.' + m.status | transloco }}</span></li>
          } @empty {
            <li class="muted">{{ 'perform.oneOnOnes.none' | transloco }}</li>
          }
        </ul>
      </section>
    </div>

    <section class="panel box">
      <h3>{{ 'perform.reviews.results' | transloco }}</h3>
      @for (r of results(); track r.cycle.id) {
        <app-review-results [result]="r" />
      } @empty {
        <p class="muted">{{ 'perform.reviews.noResults' | transloco }}</p>
      }
    </section>
  `,
  styles: `
    :host { display: block; padding: 1rem 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; margin-bottom: 1rem; }
    .box { padding: 1rem; }
    .box h3 { margin: 0 0 0.5rem; font: var(--mat-sys-title-small); }
    .line { display: grid; grid-template-columns: 1fr 6rem 3rem; gap: 0.5rem; align-items: center; margin-bottom: 0.35rem; }
    .bar { display: block; height: 8px; border-radius: 4px; background: var(--mat-sys-surface-container-highest); overflow: hidden; }
    .bar span { display: block; height: 100%; background: var(--app-success); }
    .bar span[data-tone='danger'] { background: var(--app-danger); }
    .bar span[data-tone='warning'] { background: var(--app-warning); }
    .kpis { width: 100%; border-collapse: collapse; }
    .kpis th, .kpis td { text-align: left; padding: 0.25rem 0.5rem 0.25rem 0; }
    .plan { margin-bottom: 0.75rem; display: flex; flex-direction: column; }
    .plan ul { margin: 0.25rem 0; padding-left: 1.25rem; }
    .num { width: 7rem; }
    .rows { list-style: none; padding: 0; margin: 0; }
  `,
})
export class PerformanceTab {
  readonly employeeId = input.required<number>();
  /** Admins and managers above the employee (the API decides anyway). */
  readonly canManage = input(false);

  private readonly api = inject(PerformService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly objectives = signal<Objective[]>([]);
  protected readonly kpis = signal<Kpi[]>([]);
  protected readonly plans = signal<DevelopmentPlan[]>([]);
  protected readonly meetings = signal<OneOnOne[]>([]);
  protected readonly results = signal<ReviewResult[]>([]);
  protected readonly tone = progressTone;
  protected metric = '';
  protected period = toIsoDate(new Date()).slice(0, 7);
  protected target: number | null = null;
  protected planTitle = '';
  protected planActions = '';

  constructor() {
    effect(() => {
      const id = this.employeeId();
      this.api.objectives({ owner_employee_id: id }).subscribe({ next: (x) => this.objectives.set(x), error: () => this.objectives.set([]) });
      this.api.kpis({ employee_id: id }).subscribe({ next: (x) => this.kpis.set(x), error: () => this.kpis.set([]) });
      this.api.plans({ employee_id: id }).subscribe({ next: (x) => this.plans.set(x), error: () => this.plans.set([]) });
      this.api.oneOnOnes({ employee_id: id }).subscribe({ next: (x) => this.meetings.set(x), error: () => this.meetings.set([]) });
      this.api.employeeResults(id).subscribe({ next: (x) => this.results.set(x), error: () => this.results.set([]) });
    });
  }

  protected addKpi(): void {
    if (!this.metric.trim() || this.target === null) {
      return;
    }
    this.api.saveKpi({ employee_id: this.employeeId(), metric: this.metric.trim(), period: this.period, target: Number(this.target) }).subscribe({
      next: (k) => {
        this.kpis.update((list) => [k, ...list]);
        this.metric = '';
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected addPlan(): void {
    const actions = this.planActions
      .split(';')
      .map((t) => t.trim())
      .filter(Boolean)
      .map((text) => ({ text }));
    this.api.savePlan({ employee_id: this.employeeId(), title: this.planTitle, goals: [], actions }).subscribe({
      next: (p) => {
        this.plans.update((list) => [p, ...list]);
        this.planTitle = '';
        this.planActions = '';
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected toggle(plan: DevelopmentPlan, actionId: string, done: boolean): void {
    this.api.togglePlanAction(plan.id, actionId, done).subscribe({
      next: (saved) => this.plans.update((list) => list.map((p) => (p.id === saved.id ? saved : p))),
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
