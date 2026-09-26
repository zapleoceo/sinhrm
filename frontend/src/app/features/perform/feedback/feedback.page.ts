import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { FEEDBACK_BOXES, FEEDBACK_TYPES, FEEDBACK_VISIBILITIES, Feedback, FeedbackBox, FeedbackType, FeedbackVisibility } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';

/**
 * Continuous feedback (/perform/feedback): give or ask for feedback; boxes received / given / requests to me /
 * my team (managers: manager + public feedback of people below; admins: all) / public; answer a request.
 */
@Component({
  selector: 'app-feedback-page',
  imports: [DatePipe, FormsModule, MatButtonModule, MatButtonToggleModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'perform.feedback.title' | transloco }}</h1>
        <p class="muted">{{ 'perform.feedback.subtitle' | transloco }}</p>
      </div>
    </header>

    <form class="panel give" (ngSubmit)="send()">
      <h2>{{ (answering() ? 'perform.feedback.answer' : 'perform.feedback.give') | transloco }}</h2>
      @if (answering(); as r) {
        <p class="muted">«{{ r.text }}» — {{ r.from.full_name }}</p>
      }
      <div class="filters">
        @if (!answering()) {
          <mat-form-field subscriptSizing="dynamic" class="narrow">
            <mat-label>{{ 'perform.fields.employeeId' | transloco }}</mat-label>
            <input matInput type="number" min="1" name="to" [(ngModel)]="to" required />
          </mat-form-field>
        }
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'perform.fields.type' | transloco }}</mat-label>
          <mat-select name="type" [(ngModel)]="type">
            @for (t of types; track t) {
              @if (!answering() || t !== 'request') {
                <mat-option [value]="t">{{ 'perform.feedbackType.' + t | transloco }}</mat-option>
              }
            }
          </mat-select>
        </mat-form-field>
        @if (type !== 'request') {
          <mat-form-field subscriptSizing="dynamic">
            <mat-label>{{ 'perform.fields.visibility' | transloco }}</mat-label>
            <mat-select name="visibility" [(ngModel)]="visibility">
              @for (v of visibilities; track v) {
                <mat-option [value]="v">{{ 'perform.feedbackVisibility.' + v | transloco }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        }
      </div>
      <mat-form-field class="full">
        <mat-label>{{ 'perform.fields.text' | transloco }}</mat-label>
        <textarea matInput rows="3" name="text" [(ngModel)]="text" required></textarea>
      </mat-form-field>
      <button mat-flat-button type="submit" [disabled]="!text.trim() || (!answering() && !to)"><mat-icon>send</mat-icon>{{ 'perform.feedback.send' | transloco }}</button>
      @if (answering()) {
        <button mat-button type="button" (click)="answering.set(null)">{{ 'common.cancel' | transloco }}</button>
      }
    </form>

    <mat-button-toggle-group [value]="box()" (change)="setBox($event.value)" [attr.aria-label]="'perform.feedback.title' | transloco">
      @for (b of boxes; track b) {
        <mat-button-toggle [value]="b">{{ 'perform.feedbackBox.' + b | transloco }}</mat-button-toggle>
      }
    </mat-button-toggle-group>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="list">
      @for (f of items(); track f.id) {
        <li class="panel" [attr.data-type]="f.type">
          <div class="meta">
            <span class="badge">{{ 'perform.feedbackType.' + f.type | transloco }}</span>
            <span>{{ f.from.full_name }} → {{ f.to.full_name }}</span>
            <span class="muted">{{ f.created_at | date: 'dd.MM.yyyy' }} · {{ 'perform.feedbackVisibility.' + f.visibility | transloco }}</span>
          </div>
          <p>{{ f.text }}</p>
          @if (f.can_answer) {
            <button mat-stroked-button type="button" (click)="answer(f)"><mat-icon>reply</mat-icon>{{ 'perform.feedback.answer' | transloco }}</button>
          }
        </li>
      } @empty {
        @if (!loading()) {
          <li class="muted state">{{ 'perform.feedback.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .give { padding: 1rem; margin-bottom: 1rem; }
    .give h2 { margin: 0 0 0.5rem; font: var(--mat-sys-title-medium); }
    .full { width: 100%; }
    .narrow { width: 9rem; }
    .list { list-style: none; padding: 0; margin: 1rem 0 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .list li { padding: 0.75rem 1rem; }
    .list p { margin: 0.5rem 0 0; white-space: pre-line; }
    .meta { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: baseline; }
    .badge { font-size: 0.8rem; padding: 0.1rem 0.5rem; border-radius: 999px; background: var(--mat-sys-secondary-container); }
    [data-type='praise'] .badge { background: color-mix(in srgb, var(--app-success) 18%, transparent); }
    [data-type='constructive'] .badge { background: color-mix(in srgb, var(--app-warning) 18%, transparent); }
  `,
})
export class FeedbackPage implements OnInit {
  private readonly api = inject(PerformService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly boxes = FEEDBACK_BOXES;
  protected readonly types = FEEDBACK_TYPES;
  protected readonly visibilities = FEEDBACK_VISIBILITIES;
  protected readonly box = signal<FeedbackBox>('received');
  protected readonly items = signal<Feedback[]>([]);
  protected readonly loading = signal(false);
  protected readonly answering = signal<Feedback | null>(null);
  protected to: number | null = null;
  protected type: FeedbackType = 'praise';
  protected visibility: FeedbackVisibility = 'private_to_recipient';
  protected text = '';

  ngOnInit(): void {
    this.load();
  }

  protected setBox(box: FeedbackBox): void {
    this.box.set(box);
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.api.feedback(this.box()).subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.toast(performErrorKey(e));
      },
    });
  }

  protected answer(request: Feedback): void {
    this.answering.set(request);
    this.type = 'praise';
  }

  protected send(): void {
    const request = this.answering();
    const body = request
      ? { request_id: request.id, type: this.type, text: this.text, visibility: this.visibility }
      : { to_employee_id: Number(this.to), type: this.type, text: this.text, ...(this.type === 'request' ? {} : { visibility: this.visibility }) };
    this.api.giveFeedback(body).subscribe({
      next: () => {
        this.text = '';
        this.answering.set(null);
        this.toast(this.type === 'request' ? 'perform.feedback.requested' : 'perform.feedback.sent');
        this.load();
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
