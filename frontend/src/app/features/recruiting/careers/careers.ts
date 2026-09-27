import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { AppLang } from '../../../core/auth/auth.model';
import { LanguageService } from '../../../core/i18n/language.service';
import { Logo } from '../../../core/ui/logo';

/** Public fields of a published vacancy (GET /api/public/vacancies[/{slug}]). */
export interface PublicVacancy {
  slug: string;
  title: string;
  branch?: string | null;
  position?: string | null;
  opened_at?: string | null;
  description?: string | null;
}

const LANGS: AppLang[] = ['uk', 'ru', 'en'];

/** Header of the public career pages: the logo and a language switch (no sidebar, no login). */
@Component({
  selector: 'app-careers-header',
  imports: [Logo, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header>
      <a routerLink="/jobs"><app-logo /></a>
      <nav>
        @for (l of langs; track l) {
          <button type="button" [class.on]="lang.current() === l" (click)="lang.use(l)">{{ l.toUpperCase() }}</button>
        }
      </nav>
    </header>
  `,
  styles: `
    header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0; }
    nav button { border: 0; background: none; cursor: pointer; color: inherit; padding: 0.25rem 0.5rem; }
    nav button.on { font-weight: 700; text-decoration: underline; }
  `,
})
export class CareersHeader {
  protected readonly lang = inject(LanguageService);
  protected readonly langs = LANGS;
}

/** /jobs: published open vacancies. */
@Component({
  selector: 'app-jobs-page',
  imports: [CareersHeader, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="careers">
      <app-careers-header />
      <h1>{{ 'careers.title' | transloco }}</h1>
      @for (v of vacancies(); track v.slug) {
        <a class="job" [routerLink]="['/jobs', v.slug]">
          <strong>{{ v.title }}</strong>
          @if (v.branch) {
            <span> · {{ v.branch }}</span>
          }
        </a>
      } @empty {
        <p>{{ (loaded() ? 'careers.empty' : 'careers.loading') | transloco }}</p>
      }
    </main>
  `,
  styles: `
    .careers { max-width: 48rem; margin: 0 auto; padding: 0 16px 2rem; }
    .job { display: block; padding: 1rem 0; border-bottom: 1px solid var(--mat-sys-outline-variant); color: inherit; text-decoration: none; }
  `,
})
export class JobsPage implements OnInit {
  private readonly http = inject(HttpClient);
  protected readonly vacancies = signal<PublicVacancy[]>([]);
  protected readonly loaded = signal(false);

  ngOnInit(): void {
    this.http.get<{ data: PublicVacancy[] }>('/api/public/vacancies').subscribe({
      next: (r) => {
        this.vacancies.set(r.data);
        this.loaded.set(true);
      },
      error: () => this.loaded.set(true),
    });
  }
}

/** /jobs/:slug: the vacancy and the apply form (consent to personal data processing is required). */
@Component({
  selector: 'app-job-page',
  imports: [CareersHeader, ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatInputModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="careers">
      <app-careers-header />
      <a routerLink="/jobs">← {{ 'careers.all' | transloco }}</a>
      @if (vacancy(); as v) {
        <h1>{{ v.title }}</h1>
        @if (v.branch) {
          <p class="muted">{{ v.branch }}</p>
        }
        <p class="text">{{ v.description }}</p>
        @if (sent()) {
          <p role="status">{{ 'careers.thanks' | transloco }}</p>
        } @else {
          <form [formGroup]="form" (ngSubmit)="submit()" class="form">
            <h2>{{ 'careers.apply' | transloco }}</h2>
            <mat-form-field>
              <mat-label>{{ 'careers.name' | transloco }}</mat-label>
              <input matInput formControlName="name" maxlength="255" autocomplete="name" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>E-mail</mat-label>
              <input matInput type="email" formControlName="email" maxlength="255" autocomplete="email" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.phone' | transloco }}</mat-label>
              <input matInput formControlName="phone" maxlength="32" autocomplete="tel" />
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'careers.message' | transloco }}</mat-label>
              <textarea matInput formControlName="message" rows="4" maxlength="5000"></textarea>
            </mat-form-field>
            <!-- Honeypot: hidden from people, bots fill it. -->
            <input class="hp" type="text" formControlName="website" tabindex="-1" autocomplete="off" aria-hidden="true" />
            <label class="file">
              {{ 'careers.cv' | transloco }}
              <input type="file" accept=".pdf,.doc,.docx" (change)="pick($event)" />
            </label>
            <mat-checkbox formControlName="consent">{{ 'careers.consent' | transloco }}</mat-checkbox>
            @if (error(); as key) {
              <p class="error" role="alert">{{ key | transloco }}</p>
            }
            <button mat-flat-button type="submit" [disabled]="busy()">{{ 'careers.send' | transloco }}</button>
          </form>
        }
      } @else if (missing()) {
        <p>{{ 'careers.notFound' | transloco }}</p>
      }
    </main>
  `,
  styles: `
    .careers { max-width: 48rem; margin: 0 auto; padding: 0 16px 2rem; }
    .text { white-space: pre-wrap; }
    .muted { color: var(--app-muted, inherit); }
    .form { display: flex; flex-direction: column; gap: 0.25rem; }
    .hp { position: absolute; left: -10000px; width: 1px; height: 1px; opacity: 0; }
    .file { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.5rem; }
    .error { color: var(--app-danger); }
  `,
})
export class JobPage implements OnInit {
  readonly slug = input.required<string>();

  private readonly http = inject(HttpClient);
  protected readonly vacancy = signal<PublicVacancy | null>(null);
  protected readonly missing = signal(false);
  protected readonly sent = signal(false);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  private cv: File | null = null;
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    message: [''],
    website: [''],
    consent: [false, Validators.requiredTrue],
  });

  ngOnInit(): void {
    this.http.get<{ data: PublicVacancy }>(`/api/public/vacancies/${encodeURIComponent(this.slug())}`).subscribe({
      next: (r) => this.vacancy.set(r.data),
      error: () => this.missing.set(true),
    });
  }

  protected pick(event: Event): void {
    this.cv = (event.target as HTMLInputElement).files?.[0] ?? null;
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set(this.form.controls.consent.invalid ? 'careers.consentRequired' : null);
      return;
    }
    if (this.cv && this.cv.size > 2 * 1024 * 1024) {
      this.error.set('careers.cvTooLarge');
      return;
    }
    const v = this.form.getRawValue();
    const body = new FormData();
    body.append('name', v.name.trim());
    body.append('email', v.email.trim());
    body.append('phone', v.phone.trim());
    body.append('message', v.message.trim());
    body.append('website', v.website);
    body.append('consent', '1');
    if (this.cv) {
      body.append('cv', this.cv);
    }
    this.busy.set(true);
    this.error.set(null);
    this.http.post(`/api/public/vacancies/${encodeURIComponent(this.slug())}/apply`, body).subscribe({
      next: () => this.sent.set(true),
      error: (e: { status?: number }) => {
        this.busy.set(false);
        this.error.set(e.status === 429 ? 'careers.tooMany' : 'careers.failed');
      },
    });
  }
}
