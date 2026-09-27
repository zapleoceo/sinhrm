import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { map } from 'rxjs';
import { Application } from '../recruiting.model';
import { recruitingErrorKey } from '../recruiting.service';

export type OfferStatus = 'draft' | 'sent' | 'accepted' | 'declined';

export interface Offer {
  id: number;
  position: string;
  salary: string;
  start_date: string | null;
  conditions: string | null;
  content_md: string;
  status: OfferStatus;
}

interface TemplateRef {
  id: number;
  name: string;
}

/**
 * Offer of an application in the offer stage (kind "hire", not terminal). Shown only to recruiting writers
 * and the hiring manager (the API answers 403 to anyone else, the salary is sensitive).
 */
@Component({
  selector: 'app-offer-panel',
  imports: [ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (inOfferStage() || offer()) {
      <section class="offer">
        <h4>{{ 'careers.offer.title' | transloco }}</h4>
        @if (offer(); as o) {
          <p>
            <strong>{{ 'careers.offer.status.' + o.status | transloco }}</strong> · {{ o.position }} · {{ o.salary }}
            @if (o.start_date) {
              · {{ o.start_date }}
            }
          </p>
          <pre class="text">{{ o.content_md }}</pre>
          @if (o.status === 'draft') {
            <button mat-flat-button type="button" [disabled]="busy()" (click)="act('send')">{{ 'careers.offer.send' | transloco }}</button>
          } @else if (o.status === 'sent') {
            <button mat-button type="button" [disabled]="busy()" (click)="act('decision', 'accepted')">{{ 'careers.offer.accept' | transloco }}</button>
            <button mat-button type="button" [disabled]="busy()" (click)="act('decision', 'declined')">{{ 'careers.offer.decline' | transloco }}</button>
          }
        } @else if (!formOpen()) {
          <button mat-stroked-button type="button" (click)="open()">{{ 'careers.offer.create' | transloco }}</button>
        } @else {
          <form [formGroup]="form" (ngSubmit)="create()" class="form">
            <mat-form-field>
              <mat-label>{{ 'careers.offer.template' | transloco }}</mat-label>
              <mat-select formControlName="template_id">
                @for (t of templates(); track t.id) {
                  <mat-option [value]="t.id">{{ t.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.offer.position' | transloco }}</mat-label>
              <input matInput formControlName="position" maxlength="255" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.offer.salary' | transloco }}</mat-label>
              <input matInput formControlName="salary" maxlength="100" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.offer.startDate' | transloco }}</mat-label>
              <input matInput type="date" formControlName="start_date" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.offer.conditions' | transloco }}</mat-label>
              <textarea matInput formControlName="conditions" rows="3" maxlength="5000"></textarea>
            </mat-form-field>
            <button mat-flat-button type="submit" [disabled]="busy()">{{ 'careers.offer.create' | transloco }}</button>
          </form>
        }
        @if (error(); as key) {
          <p class="error" role="alert">{{ key | transloco }}</p>
        }
      </section>
    }
  `,
  styles: `
    .offer { margin: 0.5rem 0; }
    .form { display: flex; flex-direction: column; max-width: 28rem; }
    .text { white-space: pre-wrap; font: inherit; }
    .error { color: var(--app-danger); margin: 0; }
  `,
})
export class OfferPanel implements OnInit {
  readonly application = input.required<Application>();

  private readonly http = inject(HttpClient);
  protected readonly offer = signal<Offer | null>(null);
  protected readonly templates = signal<TemplateRef[]>([]);
  protected readonly formOpen = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly inOfferStage = computed(() => {
    const stage = this.application().stage;
    return !!stage && stage.kind === 'hire' && !stage.is_terminal;
  });
  protected readonly form = inject(NonNullableFormBuilder).group({
    template_id: [null as number | null, Validators.required],
    position: ['', [Validators.required, Validators.maxLength(255)]],
    salary: ['', [Validators.required, Validators.maxLength(100)]],
    start_date: [''],
    conditions: [''],
  });

  private get url(): string {
    return `/api/applications/${this.application().id}/offer`;
  }

  ngOnInit(): void {
    this.http.get<{ data: Offer | null }>(this.url).pipe(map((r) => r.data)).subscribe({
      next: (o) => this.offer.set(o),
      error: () => undefined,
    });
  }

  protected open(): void {
    this.formOpen.set(true);
    this.form.patchValue({ position: this.application().vacancy?.title ?? '' });
    this.http.get<{ data: TemplateRef[] }>('/api/offer-templates').subscribe({
      next: (r) => this.templates.set(r.data),
      error: () => undefined,
    });
  }

  protected create(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.run(this.http.post<{ data: Offer }>(this.url, { ...v, start_date: v.start_date || null, conditions: v.conditions.trim() || null }));
  }

  protected act(path: 'send' | 'decision', status?: OfferStatus): void {
    this.run(this.http.post<{ data: Offer }>(`${this.url}/${path}`, status ? { status } : {}));
  }

  private run(request: ReturnType<HttpClient['post']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (r) => {
        this.offer.set((r as { data: Offer }).data);
        this.busy.set(false);
      },
      error: (e: unknown) => {
        this.error.set(recruitingErrorKey(e));
        this.busy.set(false);
      },
    });
  }
}
