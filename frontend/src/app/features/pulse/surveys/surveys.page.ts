import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { parseIds } from '../../perform/perform.model';
import {
  LIFECYCLE_TRIGGERS,
  LifecycleTrigger,
  QUESTION_TYPES,
  Question,
  QuestionType,
  SURVEY_TYPES,
  Survey,
  SurveyTemplate,
  SurveyType,
  WAVE_SCHEDULES,
  Wave,
  WaveSchedule,
  nextQuestionId,
} from '../pulse.model';
import { PulseService, pulseErrorKey } from '../pulse.service';
import { toIsoDate, today } from '../../../core/date/iso-date';

/**
 * Surveys (/admin/pulse, admins): the builder (from a template or from scratch; questions: scales 1–5 / 1–10,
 * eNPS 0–10, single/multiple choice, text), waves of the selected survey (schedule, audience, anonymity with a
 * minimum group of 5) and links to results. Questions freeze once a survey has answers.
 */
@Component({
  selector: 'app-surveys-page',
  imports: [DatePipe, FormsModule, MatButtonModule, MatCheckboxModule, MatDatepickerModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'pulse.surveys.title' | transloco }}</h1>
        <p class="muted">{{ 'pulse.surveys.subtitle' | transloco }}</p>
      </div>
      <div class="filters">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'pulse.surveys.fromTemplate' | transloco }}</mat-label>
          <mat-select (selectionChange)="fromTemplate($event.value)">
            @for (t of templates(); track t.key) {
              <mat-option [value]="t">{{ t.title }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <button mat-flat-button type="button" (click)="startNew()"><mat-icon>add</mat-icon>{{ 'pulse.surveys.new' | transloco }}</button>
      </div>
    </header>

    <div class="layout">
      <nav class="list" [attr.aria-label]="'pulse.surveys.title' | transloco">
        @for (s of surveys(); track s.id) {
          <button type="button" class="row" [class.active]="s.id === editingId()" (click)="edit(s)">
            <strong>{{ s.title }}</strong>
            <span class="muted">{{ 'pulse.surveyType.' + s.type | transloco }} · {{ 'pulse.surveys.waves' | transloco: { n: s.waves_count } }}@if (!s.active) { · {{ 'pulse.surveys.inactive' | transloco }}}</span>
          </button>
        } @empty {
          <p class="muted">{{ 'pulse.surveys.empty' | transloco }}</p>
        }
      </nav>

      @if (draft(); as d) {
        <div class="main">
          <form class="panel box" (ngSubmit)="save()">
            <div class="filters">
              <mat-form-field subscriptSizing="dynamic" class="grow">
                <mat-label>{{ 'pulse.fields.title' | transloco }}</mat-label>
                <input matInput name="title" [(ngModel)]="d.title" required />
              </mat-form-field>
              <mat-form-field subscriptSizing="dynamic">
                <mat-label>{{ 'pulse.fields.type' | transloco }}</mat-label>
                <mat-select name="type" [(ngModel)]="d.type">
                  @for (t of surveyTypes; track t) {
                    <mat-option [value]="t">{{ 'pulse.surveyType.' + t | transloco }}</mat-option>
                  }
                </mat-select>
              </mat-form-field>
              @if (d.type === 'lifecycle') {
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.fields.trigger' | transloco }}</mat-label>
                  <mat-select name="trigger" [(ngModel)]="d.lifecycle_trigger">
                    @for (t of triggers; track t) {
                      <mat-option [value]="t">{{ 'pulse.trigger.' + t | transloco }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
              }
              <mat-checkbox name="active" [(ngModel)]="d.active">{{ 'pulse.fields.active' | transloco }}</mat-checkbox>
            </div>
            <mat-form-field class="full" subscriptSizing="dynamic">
              <mat-label>{{ 'pulse.fields.description' | transloco }}</mat-label>
              <textarea matInput name="description" rows="2" [(ngModel)]="d.description"></textarea>
            </mat-form-field>

            <h2>{{ 'pulse.surveys.questions' | transloco }}</h2>
            @for (q of d.questions; track $index; let i = $index) {
              <div class="question">
                <span class="qid">{{ q.id }}</span>
                <mat-form-field subscriptSizing="dynamic" class="grow">
                  <mat-label>{{ 'pulse.fields.question' | transloco }}</mat-label>
                  <input matInput [name]="'qt' + i" [(ngModel)]="q.text" required />
                </mat-form-field>
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.fields.questionType' | transloco }}</mat-label>
                  <mat-select [name]="'qy' + i" [(ngModel)]="q.type" (selectionChange)="typeChanged(q, $event.value)">
                    @for (t of questionTypes; track t) {
                      <mat-option [value]="t">{{ 'pulse.questionType.' + t | transloco }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
                @if (q.type === 'single' || q.type === 'multi') {
                  <mat-form-field subscriptSizing="dynamic" class="grow">
                    <mat-label>{{ 'pulse.fields.options' | transloco }}</mat-label>
                    <input matInput [name]="'qo' + i" [ngModel]="(q.options ?? []).join('; ')" (ngModelChange)="setOptions(q, $event)" />
                  </mat-form-field>
                }
                <mat-checkbox [name]="'qr' + i" [(ngModel)]="q.required">{{ 'pulse.fields.required' | transloco }}</mat-checkbox>
                <button mat-icon-button type="button" (click)="removeQuestion(i)" [attr.aria-label]="'pulse.surveys.removeQuestion' | transloco"><mat-icon>delete</mat-icon></button>
              </div>
            }
            <button mat-button type="button" (click)="addQuestion()"><mat-icon>add</mat-icon>{{ 'pulse.surveys.addQuestion' | transloco }}</button>
            <button mat-flat-button type="submit" [disabled]="!d.title.trim() || !d.questions.length">{{ 'pulse.save' | transloco }}</button>
          </form>

          @if (editingId(); as surveyId) {
            <section class="panel box">
              <h2>{{ 'pulse.waves.title' | transloco }}</h2>
              <form class="filters" (ngSubmit)="createWave(surveyId)">
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.waves.starts' | transloco }}</mat-label>
                  <input matInput [matDatepicker]="dp1" name="starts" [(ngModel)]="startsAt" required /><mat-datepicker-toggle matIconSuffix [for]="dp1" /><mat-datepicker #dp1 />
                </mat-form-field>
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.waves.ends' | transloco }}</mat-label>
                  <input matInput [matDatepicker]="dp2" name="ends" [(ngModel)]="endsAt" required /><mat-datepicker-toggle matIconSuffix [for]="dp2" /><mat-datepicker #dp2 />
                </mat-form-field>
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.waves.schedule' | transloco }}</mat-label>
                  <mat-select name="schedule" [(ngModel)]="schedule">
                    @for (s of schedules; track s) {
                      <mat-option [value]="s">{{ 'pulse.schedule.' + s | transloco }}</mat-option>
                    }
                  </mat-select>
                </mat-form-field>
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.waves.departments' | transloco }}</mat-label>
                  <input matInput name="departments" [(ngModel)]="departmentIds" />
                </mat-form-field>
                <mat-form-field subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.waves.branches' | transloco }}</mat-label>
                  <input matInput name="branches" [(ngModel)]="branchIds" />
                </mat-form-field>
                <mat-checkbox name="anonymous" [(ngModel)]="anonymous">{{ 'pulse.waves.anonymous' | transloco }}</mat-checkbox>
                <mat-form-field subscriptSizing="dynamic" class="num">
                  <mat-label>{{ 'pulse.waves.minGroup' | transloco }}</mat-label>
                  <input matInput type="number" name="min" [min]="anonymous ? 5 : 1" [(ngModel)]="minGroup" />
                </mat-form-field>
                <button mat-stroked-button type="submit" [disabled]="!startsAt || !endsAt">{{ 'pulse.waves.launch' | transloco }}</button>
              </form>
              <p class="muted">{{ 'pulse.waves.anonymityHint' | transloco }}</p>
              <table class="waves">
                <tbody>
                  @for (w of waves(); track w.id) {
                    <tr>
                      <td>{{ w.starts_at | date: 'dd.MM.yyyy' }} — {{ w.ends_at | date: 'dd.MM.yyyy' }}</td>
                      <td>{{ 'pulse.schedule.' + w.schedule | transloco }}</td>
                      <td>{{ 'pulse.waveStatus.' + w.status | transloco }}</td>
                      <td>@if (w.anonymous) { <mat-icon inline [attr.aria-label]="'pulse.waves.anonymous' | transloco">visibility_off</mat-icon> } {{ 'pulse.waves.responses' | transloco: { n: w.responses_count } }}</td>
                      <td>
                        <a mat-button [routerLink]="['/pulse/waves', w.id, 'results']">{{ 'pulse.results.open' | transloco }}</a>
                        @if (w.status !== 'closed') {
                          <button mat-button type="button" (click)="closeWave(w)">{{ 'pulse.waves.close' | transloco }}</button>
                        }
                      </td>
                    </tr>
                  } @empty {
                    <tr><td class="muted">{{ 'pulse.waves.empty' | transloco }}</td></tr>
                  }
                </tbody>
              </table>
            </section>
          }
        </div>
      }
    </div>
  `,
  styles: `
    .layout { display: grid; grid-template-columns: minmax(14rem, 18rem) 1fr; gap: 1rem; }
    @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    .list { display: flex; flex-direction: column; gap: 0.25rem; }
    .row { display: flex; flex-direction: column; align-items: flex-start; padding: 0.5rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: none; color: inherit; font: inherit; cursor: pointer; text-align: left; }
    .row.active { border-color: var(--mat-sys-primary); background: var(--mat-sys-secondary-container); }
    .main { display: flex; flex-direction: column; gap: 1rem; }
    .box { padding: 1rem; }
    .box h2 { font: var(--mat-sys-title-medium); margin: 0.75rem 0 0.5rem; }
    .full { width: 100%; }
    .question { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; padding: 0.25rem 0; border-bottom: 1px dashed var(--app-border); }
    .qid { font-family: monospace; color: var(--app-muted); min-width: 2.5rem; }
    .grow { flex: 1 1 14rem; }
    .num { width: 7rem; }
    .waves { width: 100%; border-collapse: collapse; }
    .waves td { padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--app-border); }
  `,
})
export class SurveysPage implements OnInit {
  private readonly api = inject(PulseService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly surveyTypes = SURVEY_TYPES;
  protected readonly questionTypes = QUESTION_TYPES;
  protected readonly triggers = LIFECYCLE_TRIGGERS;
  protected readonly schedules = WAVE_SCHEDULES;
  protected readonly surveys = signal<Survey[]>([]);
  protected readonly templates = signal<SurveyTemplate[]>([]);
  protected readonly waves = signal<Wave[]>([]);
  protected readonly editingId = signal<number | null>(null);
  protected readonly draft = signal<{ title: string; type: SurveyType; description: string | null; lifecycle_trigger: LifecycleTrigger | null; active: boolean; questions: Question[] } | null>(null);
  protected startsAt: Date | null = today();
  protected endsAt: Date | null = null;
  protected schedule: WaveSchedule = 'once';
  protected departmentIds = '';
  protected branchIds = '';
  protected anonymous = true;
  protected minGroup = 5;

  ngOnInit(): void {
    this.api.surveys().subscribe({ next: (s) => this.surveys.set(s), error: (e: unknown) => this.toast(pulseErrorKey(e)) });
    this.api.templates().subscribe({ next: (t) => this.templates.set(t), error: () => this.templates.set([]) });
  }

  protected startNew(): void {
    this.editingId.set(null);
    this.draft.set({ title: '', type: 'custom', description: null, lifecycle_trigger: null, active: true, questions: [{ id: 'q1', type: 'scale5', text: '', required: true }] });
  }

  protected fromTemplate(t: SurveyTemplate): void {
    this.editingId.set(null);
    this.draft.set({ title: t.title, type: t.type, description: null, lifecycle_trigger: t.lifecycle_trigger, active: true, questions: t.questions.map((q) => ({ ...q, options: q.options ? [...q.options] : undefined })) });
  }

  protected edit(s: Survey): void {
    this.editingId.set(s.id);
    this.draft.set({ title: s.title, type: s.type, description: s.description, lifecycle_trigger: s.lifecycle_trigger, active: s.active, questions: s.questions.map((q) => ({ ...q })) });
    this.api.waves(s.id).subscribe({ next: (w) => this.waves.set(w), error: () => this.waves.set([]) });
  }

  protected addQuestion(): void {
    this.draft.update((d) => (d ? { ...d, questions: [...d.questions, { id: nextQuestionId(d.questions), type: 'scale5', text: '', required: false }] } : d));
  }

  protected removeQuestion(index: number): void {
    this.draft.update((d) => (d ? { ...d, questions: d.questions.filter((_, i) => i !== index) } : d));
  }

  protected typeChanged(q: Question, type: QuestionType): void {
    q.options = type === 'single' || type === 'multi' ? (q.options ?? ['', '']) : undefined;
  }

  protected setOptions(q: Question, text: string): void {
    q.options = text.split(';').map((o) => o.trim());
  }

  protected save(): void {
    const d = this.draft();
    if (!d) {
      return;
    }
    const questions = d.questions.map((q) => ({ ...q, options: q.options?.filter(Boolean) }));
    const body = { ...d, questions, lifecycle_trigger: d.type === 'lifecycle' ? d.lifecycle_trigger : null };
    this.api.saveSurvey(body, this.editingId() ?? undefined).subscribe({
      next: (s) => {
        this.surveys.update((list) => (list.some((x) => x.id === s.id) ? list.map((x) => (x.id === s.id ? s : x)) : [s, ...list]));
        this.edit(s);
        this.toast('pulse.saved');
      },
      error: (e: unknown) => this.toast(pulseErrorKey(e)),
    });
  }

  protected createWave(surveyId: number): void {
    this.api
      .createWave(surveyId, {
        starts_at: toIsoDate(this.startsAt),
        ends_at: toIsoDate(this.endsAt),
        schedule: this.schedule,
        audience: { branch_ids: parseIds(this.branchIds), department_ids: parseIds(this.departmentIds) },
        anonymous: this.anonymous,
        min_group_size: Number(this.minGroup),
      })
      .subscribe({
        next: (w) => {
          this.waves.update((list) => [w, ...list]);
          this.toast('pulse.waves.launched');
        },
        error: (e: unknown) => this.toast(pulseErrorKey(e)),
      });
  }

  protected closeWave(w: Wave): void {
    this.api.closeWave(w.id).subscribe({
      next: (saved) => this.waves.update((list) => list.map((x) => (x.id === saved.id ? saved : x))),
      error: (e: unknown) => this.toast(pulseErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
