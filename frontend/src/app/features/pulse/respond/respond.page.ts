import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatRadioModule } from '@angular/material/radio';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AnswerValue, MyWave, Question, missingAnswers, questionRange, toggleOption } from '../pulse.model';
import { PulseService, pulseErrorKey } from '../pulse.service';

/**
 * Answering a survey (/pulse/waves/:id): one column, large tap targets (works on a phone). Anonymous waves say so
 * up front; the server keeps no link between the person and the answers.
 */
@Component({
  selector: 'app-respond-page',
  imports: [FormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatRadioModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (error(); as key) {
      <div class="state">
        <p>{{ key | transloco }}</p>
        <a mat-stroked-button routerLink="/pulse">{{ 'pulse.my.title' | transloco }}</a>
      </div>
    } @else if (done()) {
      <div class="state">
        <mat-icon class="big">task_alt</mat-icon>
        <p>{{ 'pulse.respond.thanks' | transloco }}</p>
        <a mat-stroked-button routerLink="/pulse">{{ 'pulse.my.title' | transloco }}</a>
      </div>
    } @else if (wave(); as w) {
      <form class="wrap" (ngSubmit)="submit(w)">
        <h1>{{ w.title }}</h1>
        @if (w.description) {
          <p class="muted">{{ w.description }}</p>
        }
        <p class="note" [class.anon]="w.anonymous">
          <mat-icon inline>{{ w.anonymous ? 'visibility_off' : 'visibility' }}</mat-icon>
          {{ (w.anonymous ? 'pulse.respond.anonymous' : 'pulse.respond.named') | transloco }}
        </p>
        @for (q of w.questions ?? []; track q.id) {
          <fieldset [class.missing]="missing().includes(q.id)">
            <legend>{{ q.text }}@if (q.required) { <span aria-hidden="true">*</span> }</legend>
            @switch (q.type) {
              @case ('text') {
                <mat-form-field class="full" subscriptSizing="dynamic">
                  <mat-label>{{ 'pulse.respond.yourAnswer' | transloco }}</mat-label>
                  <textarea matInput rows="3" [name]="q.id" [ngModel]="answers[q.id]" (ngModelChange)="set(q, $event)"></textarea>
                </mat-form-field>
              }
              @case ('single') {
                <mat-radio-group class="options" [name]="q.id" [ngModel]="answers[q.id]" (ngModelChange)="set(q, $event)" [attr.aria-label]="q.text">
                  @for (o of q.options ?? []; track $index) {
                    <mat-radio-button [value]="$index">{{ o }}</mat-radio-button>
                  }
                </mat-radio-group>
              }
              @case ('multi') {
                <div class="options">
                  @for (o of q.options ?? []; track $index) {
                    <mat-checkbox [checked]="isPicked(q, $index)" (change)="pick(q, $index)">{{ o }}</mat-checkbox>
                  }
                </div>
              }
              @default {
                <div class="scale" role="radiogroup" [attr.aria-label]="q.text">
                  @for (v of range(q); track v) {
                    <button type="button" role="radio" class="pt" [attr.aria-checked]="answers[q.id] === v" [class.on]="answers[q.id] === v" (click)="set(q, v)">{{ v }}</button>
                  }
                </div>
                @if (q.type === 'enps') {
                  <p class="ends muted"><span>{{ 'pulse.respond.unlikely' | transloco }}</span><span>{{ 'pulse.respond.likely' | transloco }}</span></p>
                }
              }
            }
          </fieldset>
        }
        @if (missing().length) {
          <p class="err">{{ 'pulse.respond.fillRequired' | transloco }}</p>
        }
        <button mat-flat-button type="submit" class="send" [disabled]="busy()">{{ 'pulse.respond.send' | transloco }}</button>
      </form>
    } @else {
      <mat-progress-bar mode="indeterminate" />
    }
  `,
  styles: `
    .wrap { max-width: 40rem; margin: 0 auto; display: flex; flex-direction: column; gap: 0.75rem; }
    h1 { font: var(--mat-sys-headline-small); margin: 0; }
    .note { padding: 0.5rem 0.75rem; border-radius: 8px; background: var(--mat-sys-surface-container); margin: 0; }
    .note.anon { background: color-mix(in srgb, var(--app-success) 12%, transparent); }
    fieldset { border: 1px solid var(--app-border); border-radius: var(--app-radius); padding: 0.75rem 1rem; margin: 0; }
    fieldset.missing { border-color: var(--app-danger); }
    legend { font-weight: 500; padding: 0 0.25rem; }
    .scale { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .pt { min-width: 2.75rem; min-height: 2.75rem; border-radius: 8px; border: 1px solid var(--app-border); background: none; color: inherit; font: inherit; cursor: pointer; }
    .pt.on { background: var(--mat-sys-primary); color: var(--mat-sys-on-primary); border-color: var(--mat-sys-primary); }
    .ends { display: flex; justify-content: space-between; font-size: 0.8rem; margin: 0.25rem 0 0; }
    .options { display: flex; flex-direction: column; gap: 0.25rem; }
    .full { width: 100%; }
    .err { color: var(--app-danger); margin: 0; }
    .send { align-self: stretch; min-height: 3rem; }
    .big { font-size: 3rem; width: 3rem; height: 3rem; color: var(--app-success); }
  `,
})
export class RespondPage {
  /** Route param :id. */
  readonly id = input.required<string>();
  private readonly api = inject(PulseService);
  protected readonly wave = signal<MyWave | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly missing = signal<string[]>([]);
  protected readonly busy = signal(false);
  protected readonly done = signal(false);
  protected answers: Record<string, AnswerValue> = {};
  protected readonly range = (q: Question): number[] => questionRange(q.type);

  constructor() {
    effect(() => {
      this.api.form(Number(this.id())).subscribe({
        next: (w) => {
          this.wave.set(w);
          this.done.set(w.responded);
        },
        error: (e: unknown) => this.error.set(pulseErrorKey(e)),
      });
    });
  }

  protected set(q: Question, value: AnswerValue): void {
    this.answers = { ...this.answers, [q.id]: value };
    this.missing.update((m) => m.filter((id) => id !== q.id));
  }

  protected isPicked(q: Question, index: number): boolean {
    const a = this.answers[q.id];
    return Array.isArray(a) && a.includes(index);
  }

  protected pick(q: Question, index: number): void {
    this.set(q, toggleOption(this.answers[q.id], index));
  }

  protected submit(w: MyWave): void {
    const missing = missingAnswers(w.questions ?? [], this.answers);
    this.missing.set(missing);
    if (missing.length) {
      return;
    }
    this.busy.set(true);
    this.api.respond(w.id, this.answers).subscribe({
      next: () => {
        this.busy.set(false);
        this.done.set(true);
      },
      error: (e: unknown) => {
        this.busy.set(false);
        this.error.set(pulseErrorKey(e));
      },
    });
  }
}
