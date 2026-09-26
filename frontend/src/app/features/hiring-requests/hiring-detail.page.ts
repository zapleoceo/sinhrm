import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Vacancy } from '../recruiting/recruiting.model';
import { RecruitingService } from '../recruiting/recruiting.service';
import { HiringRequest, salaryRange, statusTone, stepIcon } from './hiring-requests.model';
import { HiringRequestsService, hiringErrorKey } from './hiring-requests.service';

/**
 * Request card: fields, the approval route as a timeline (who, when, comment, SLA / overdue), the linked vacancy with
 * hiring progress, and the actions the API allows the user (approve/reject, submit/edit draft, cancel, HR: vacancy).
 */
@Component({
  selector: 'app-hiring-detail-page',
  imports: [DatePipe, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <a mat-button routerLink="/hiring-requests"><mat-icon>arrow_back</mat-icon>{{ 'hiring.list.title' | transloco }}</a>
    @if (request(); as r) {
      <header class="page-head">
        <div>
          <h1>{{ r.title }} <span class="muted">× {{ r.headcount }}</span></h1>
          <p class="muted">
            <span class="chip" [attr.data-tone]="tone(r.status)">{{ 'hiring.status.' + r.status | transloco }}</span>
            · {{ r.branch.name }}{{ r.department ? ' · ' + r.department.name : '' }} · {{ 'hiring.priority.' + r.priority | transloco }}
            · {{ 'hiring.fields.requester' | transloco }}: {{ r.requester?.name ?? '—' }}
          </p>
        </div>
        <div class="row">
          @if (r.can.edit) {
            <a mat-stroked-button [routerLink]="['/hiring-requests', r.id, 'edit']"><mat-icon>edit</mat-icon>{{ 'hiring.detail.edit' | transloco }}</a>
            <button mat-flat-button type="button" (click)="act(api.submit(r.id))"><mat-icon>send</mat-icon>{{ 'hiring.wizard.submit' | transloco }}</button>
          }
          @if (r.can.cancel) {
            <button mat-button type="button" (click)="act(api.cancel(r.id))"><mat-icon>block</mat-icon>{{ 'hiring.detail.cancel' | transloco }}</button>
          }
          @if (r.can.manage && (r.status === 'approved' || r.status === 'in_progress')) {
            <button mat-button type="button" (click)="act(api.close(r.id))"><mat-icon>done_all</mat-icon>{{ 'hiring.detail.close' | transloco }}</button>
          }
        </div>
      </header>
      @if (busy()) {
        <mat-progress-bar mode="indeterminate" />
      }

      <div class="cols">
        <section class="panel box">
          <h2>{{ 'hiring.detail.details' | transloco }}</h2>
          <dl class="kv">
            <dt>{{ 'hiring.fields.reason' | transloco }}</dt>
            <dd>{{ 'hiring.reason.' + r.reason | transloco }}{{ r.replaced_employee ? ': ' + r.replaced_employee.full_name : '' }}</dd>
            <dt>{{ 'hiring.fields.position' | transloco }}</dt><dd>{{ r.position?.name ?? '—' }}</dd>
            <dt>{{ 'hiring.fields.startDate' | transloco }}</dt><dd>{{ r.desired_start_date ? (r.desired_start_date | date: 'dd.MM.yyyy') : '—' }}</dd>
            <dt>{{ 'hiring.fields.salary' | transloco }}</dt><dd>{{ salary(r) || '—' }}</dd>
            @for (e of extras(); track e[0]) {
              <dt>{{ e[0] }}</dt><dd>{{ e[1] }}</dd>
            }
          </dl>
          @if (r.requirements) {
            <h3>{{ 'hiring.fields.requirements' | transloco }}</h3>
            <p class="pre">{{ r.requirements }}</p>
          }
        </section>

        <section class="panel box">
          <h2>{{ 'hiring.detail.route' | transloco }}</h2>
          <ol class="timeline">
            @for (a of r.approvals; track a.id) {
              <li [attr.data-status]="a.status" [class.overdue]="a.overdue">
                <mat-icon>{{ icon(a) }}</mat-icon>
                <div>
                  <strong>{{ a.name }}</strong>
                  <span class="muted"> · {{ a.approver?.name ?? (a.role ? ('roles.' + a.role | transloco) : '—') }} · {{ 'hiring.approval.' + a.status | transloco }}</span>
                  @if (a.due_at && a.status === 'pending') {
                    <div class="small" [class.warn]="a.overdue">{{ 'hiring.detail.due' | transloco: { date: (a.due_at | date: 'dd.MM HH:mm') } }}</div>
                  }
                  @if (a.decided_at) {
                    <div class="small">{{ a.decided_by?.name }} · {{ a.decided_at | date: 'dd.MM.yyyy HH:mm' }}</div>
                  }
                  @if (a.comment) {
                    <blockquote>{{ a.comment }}</blockquote>
                  }
                </div>
              </li>
            } @empty {
              <li class="muted">{{ 'hiring.detail.noRoute' | transloco }}</li>
            }
          </ol>
          @if (r.can.decide) {
            <div class="decide">
              <mat-form-field class="wide" subscriptSizing="dynamic">
                <mat-label>{{ 'hiring.detail.comment' | transloco }}</mat-label>
                <textarea matInput rows="2" #comment maxlength="2000"></textarea>
              </mat-form-field>
              @if (r.can.manage && isLastStep()) {
                <mat-form-field class="wide" subscriptSizing="dynamic">
                  <mat-label>{{ 'hiring.detail.recruiter' | transloco }}</mat-label>
                  <mat-select [value]="recruiterId()" (valueChange)="recruiterId.set($event)">
                    @for (u of users(); track u.id) {
                      <mat-option [value]="u.id">{{ u.name }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
              }
              <div class="row">
                <button mat-flat-button type="button" (click)="decide(true, comment.value)"><mat-icon>check</mat-icon>{{ 'hiring.detail.approve' | transloco }}</button>
                <button mat-stroked-button type="button" (click)="decide(false, comment.value)"><mat-icon>close</mat-icon>{{ 'hiring.detail.reject' | transloco }}</button>
              </div>
            </div>
          }
        </section>

        <section class="panel box">
          <h2>{{ 'hiring.detail.vacancy' | transloco }}</h2>
          @if (r.vacancy; as v) {
            <p><a [routerLink]="['/vacancies', v.id]">{{ v.title }}</a> · {{ 'recruiting.vacancyStatus.' + v.status | transloco }}</p>
            @if (r.progress; as p) {
              <div class="bar" role="progressbar" [attr.aria-valuenow]="p.percent" aria-valuemin="0" aria-valuemax="100">
                <span [style.width.%]="p.percent"></span>
              </div>
              <p class="muted">{{ 'hiring.detail.hired' | transloco: { hired: p.hired, headcount: p.headcount } }}</p>
            }
            <p class="muted">{{ 'hiring.fields.recruiter' | transloco }}: {{ r.recruiter?.name ?? '—' }}</p>
          } @else if (r.status === 'approved' && r.can.manage) {
            <p class="muted">{{ 'hiring.detail.noVacancy' | transloco }}</p>
            <div class="row">
              <button mat-flat-button type="button" (click)="act(api.createVacancy(r.id, recruiterId()))"><mat-icon>work</mat-icon>{{ 'hiring.detail.openVacancy' | transloco }}</button>
            </div>
            <mat-form-field class="wide" subscriptSizing="dynamic">
              <mat-label>{{ 'hiring.detail.linkVacancy' | transloco }}</mat-label>
              <mat-select (valueChange)="link($event)">
                @for (v of vacancies(); track v.id) {
                  <mat-option [value]="v.id">{{ v.title }} · {{ v.branch?.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
          } @else {
            <p class="muted">{{ 'hiring.detail.vacancyAfterApproval' | transloco }}</p>
          }
        </section>
      </div>
    }
  `,
  styles: `
    .row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
    .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: var(--app-gap); }
    .box { padding: 1rem; }
    h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.5rem; }
    h3 { font: var(--mat-sys-title-small); margin: 0.75rem 0 0.25rem; }
    .kv { display: grid; grid-template-columns: max-content 1fr; gap: 0.3rem 1rem; margin: 0; }
    .kv dt { color: var(--app-muted); }
    .kv dd { margin: 0; }
    .pre { white-space: pre-wrap; }
    .timeline { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem; }
    .timeline li { display: flex; gap: 0.5rem; }
    .timeline li[data-status='approved'] mat-icon { color: #2e7d32; }
    .timeline li[data-status='rejected'] mat-icon, .timeline li.overdue mat-icon { color: var(--app-danger); }
    .timeline li[data-status='waiting'], .timeline li[data-status='skipped'] { color: var(--app-muted); }
    blockquote { margin: 0.25rem 0 0; padding-left: 0.5rem; border-left: 2px solid var(--app-border); }
    .small { font-size: 0.8rem; }
    .warn { color: var(--app-danger); }
    .wide { width: 100%; }
    .decide { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
    .bar { height: 8px; border-radius: 4px; background: var(--app-border); overflow: hidden; }
    .bar span { display: block; height: 100%; background: var(--mat-sys-primary); }
    .chip { padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.8rem; background: var(--app-border); }
    .chip[data-tone='danger'] { background: color-mix(in srgb, var(--app-danger) 18%, transparent); }
  `,
})
export class HiringDetailPage {
  readonly id = input.required({ transform: numberAttribute });
  protected readonly api = inject(HiringRequestsService);
  private readonly recruiting = inject(RecruitingService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly request = signal<HiringRequest | null>(null);
  protected readonly busy = signal(false);
  protected readonly users = signal<{ id: number; name: string }[]>([]);
  protected readonly vacancies = signal<Vacancy[]>([]);
  protected readonly recruiterId = signal<number | null>(null);
  protected readonly tone = statusTone;
  protected readonly icon = stepIcon;
  protected readonly salary = salaryRange;
  protected readonly extras = computed(() => Object.entries(this.request()?.extra ?? {}).map(([k, v]) => [k, String(v)] as const));
  protected readonly isLastStep = computed(() => {
    const r = this.request();
    const current = r?.current_step?.position;
    return r !== null && current !== undefined && !r.approvals.some((a) => a.position > current && a.status === 'waiting');
  });

  constructor() {
    effect(() => this.load(this.id()));
  }

  protected decide(approve: boolean, comment: string): void {
    const r = this.request();
    if (r === null) {
      return;
    }
    this.act(this.api.decide(r.id, approve, comment.trim() || null, this.recruiterId()));
  }

  protected link(vacancyId: number): void {
    const r = this.request();
    if (r !== null) {
      this.act(this.api.linkVacancy(r.id, vacancyId));
    }
  }

  protected act(call: ReturnType<HiringRequestsService['get']>): void {
    this.busy.set(true);
    call.subscribe({
      next: (r) => {
        this.busy.set(false);
        this.apply(r);
      },
      error: (e: unknown) => {
        this.busy.set(false);
        this.snack.open(this.i18n.translate(hiringErrorKey(e)), undefined, { duration: 4000 });
      },
    });
  }

  private load(id: number): void {
    this.act(this.api.get(id));
  }

  private apply(r: HiringRequest): void {
    this.request.set(r);
    if (r.can.manage && this.users().length === 0) {
      this.api.settings().subscribe({ next: (s) => this.users.set(s.users) });
    }
    if (r.can.manage && r.status === 'approved' && r.vacancy === null) {
      this.recruiting.vacancies({ status: 'open', branch_id: r.branch.id, perPage: 100 }).subscribe({ next: (page) => this.vacancies.set(page.data) });
    }
  }
}
