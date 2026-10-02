import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { CASE_STATUS_TONE, DeskCase, DeskCategory } from './desk.model';
import { DeskService, deskErrorKey } from './desk.service';
import { SlaBadge } from './sla-badge';
import { NotifyService } from '../../core/ui/notify.service';

/** "Мої звернення" (/desk): own helpdesk cases and a form to open a new one. */
@Component({
  selector: 'app-my-cases-page',
  imports: [DatePipe, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatSelectModule, RouterLink, TranslocoPipe, SlaBadge],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'desk.my.title' | transloco }}</h1>
        <p class="muted">{{ 'desk.my.subtitle' | transloco }}</p>
      </div>
      <button mat-flat-button type="button" (click)="formOpen.set(!formOpen())"><mat-icon>add</mat-icon>{{ 'desk.my.new' | transloco }}</button>
    </header>

    @if (formOpen()) {
      <form class="panel form" (submit)="$event.preventDefault(); submit()">
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'desk.category' | transloco }}</mat-label>
          <mat-select [value]="categoryId()" (valueChange)="categoryId.set($event)" required>
            @for (c of categories(); track c.id) {
              <mat-option [value]="c.id">{{ c.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'desk.subject' | transloco }}</mat-label>
          <input matInput maxlength="200" required [value]="subject()" (input)="subject.set(val($event))" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'desk.body' | transloco }}</mat-label>
          <textarea matInput rows="5" maxlength="10000" required [value]="body()" (input)="body.set(val($event))"></textarea>
        </mat-form-field>
        <div class="actions">
          <button mat-flat-button type="submit" [disabled]="saving() || !valid()">{{ 'desk.my.send' | transloco }}</button>
        </div>
      </form>
    }

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="cases panel">
      @for (c of items(); track c.id) {
        <li>
          <a [routerLink]="['/desk/cases', c.id]" class="main">
            <strong>#{{ c.id }} {{ c.subject }}</strong>
            <span class="muted small">{{ c.category.name }} · <span class="app-num">{{ c.created_at | date: 'dd.MM.yyyy HH:mm' }}</span></span>
          </a>
          <app-sla-badge [c]="c" />
          <span class="status app-pill" [attr.data-status]="c.status" [attr.data-tone]="statusTone[c.status]">{{ 'desk.status.' + c.status | transloco }}</span>
        </li>
      } @empty {
        @if (!loading()) {
          <li class="app-empty">{{ 'desk.my.empty' | transloco }}</li>
        }
      }
    </ul>
  `,
  styles: `
    .form { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem 1.25rem; margin-bottom: var(--app-gap); }
    .actions { display: flex; justify-content: flex-end; }
    .cases { list-style: none; margin: 0; padding: 0; }
    .cases li { display: flex; gap: 1rem; align-items: center; padding: 0.6rem 0.75rem; border-bottom: var(--app-border-w) solid var(--app-track); flex-wrap: wrap; }
    .cases li:last-child { border-bottom: 0; }
    .cases li:hover:not(.app-empty) { background: var(--app-row-hover); }
    .cases li.app-empty { display: block; }
    .main { flex: 1 1 16rem; display: flex; flex-direction: column; color: inherit; text-decoration: none; min-width: 0; }
    .small { font-size: 0.8rem; }
  `,
})
export class MyCasesPage implements OnInit {
  private readonly api = inject(DeskService);
  private readonly router = inject(Router);
  private readonly notify = inject(NotifyService);
  protected readonly statusTone = CASE_STATUS_TONE;
  protected readonly items = signal<DeskCase[]>([]);
  protected readonly categories = signal<DeskCategory[]>([]);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly formOpen = signal(false);
  protected readonly categoryId = signal<number | null>(null);
  protected readonly subject = signal('');
  protected readonly body = signal('');

  ngOnInit(): void {
    this.loading.set(true);
    this.api.mine().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.loading.set(false);
        this.notify.show(deskErrorKey(e));
      },
    });
    this.api.categories().subscribe({ next: (list) => this.categories.set(list), error: () => this.categories.set([]) });
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected valid(): boolean {
    return this.categoryId() !== null && this.subject().trim() !== '' && this.body().trim() !== '';
  }

  protected submit(): void {
    const categoryId = this.categoryId();
    if (categoryId === null || !this.valid()) {
      return;
    }
    this.saving.set(true);
    this.api.open({ category_id: categoryId, subject: this.subject().trim(), body: this.body() }).subscribe({
      next: (c) => {
        this.saving.set(false);
        void this.router.navigate(['/desk/cases', c.id]);
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.notify.show(deskErrorKey(e));
      },
    });
  }
}
