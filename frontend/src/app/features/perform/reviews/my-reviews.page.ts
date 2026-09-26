import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatRadioModule } from '@angular/material/radio';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Assignment } from '../perform.model';
import { PerformService, performErrorKey } from '../perform.service';

/**
 * "My reviews" (/perform/reviews): the forms the user fills as a reviewer (self, manager, peer, upward) and the
 * competency form. Peer/upward answers are never shown to the subject individually.
 */
@Component({
  selector: 'app-my-reviews-page',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, MatRadioModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'perform.reviews.mine' | transloco }}</h1>
        <p class="muted">{{ 'perform.reviews.mineSubtitle' | transloco }}</p>
      </div>
    </header>
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    <ul class="list">
      @for (a of items(); track a.id) {
        <li>
          <button type="button" class="row" [class.active]="a.id === open()?.id" (click)="select(a)">
            <strong>{{ a.subject.full_name }}</strong>
            <span class="muted">{{ a.cycle.name }} · {{ 'perform.reviewType.' + a.type | transloco }}@if (a.cycle.deadline) { · {{ 'perform.reviews.until' | transloco: { date: a.cycle.deadline } }}}</span>
            <span class="status" [attr.data-status]="a.status">{{ 'perform.assignmentStatus.' + a.status | transloco }}</span>
          </button>
        </li>
      } @empty {
        @if (!loading()) {
          <li class="muted state">{{ 'perform.reviews.none' | transloco }}</li>
        }
      }
    </ul>

    @if (open(); as a) {
      <form class="panel form" (ngSubmit)="submit(a)">
        <h2>{{ a.subject.full_name }} — {{ 'perform.reviewType.' + a.type | transloco }}</h2>
        @if (a.type === 'peer' || a.type === 'upward') {
          <p class="muted"><mat-icon inline>visibility_off</mat-icon> {{ 'perform.reviews.anonymousHint' | transloco }}</p>
        }
        @for (c of a.competencies ?? []; track c.id) {
          <fieldset>
            <legend>{{ c.name }}</legend>
            @if (c.description) {
              <p class="muted">{{ c.description }}</p>
            }
            <mat-radio-group [name]="'r' + c.id" [(ngModel)]="ratings[c.id]" [disabled]="a.status === 'submitted'" [attr.aria-label]="c.name">
              @for (l of c.levels; track l.value) {
                <mat-radio-button [value]="l.value">{{ l.value }} · {{ l.label }}</mat-radio-button>
              }
            </mat-radio-group>
            <mat-form-field subscriptSizing="dynamic" class="full">
              <mat-label>{{ 'perform.reviews.comment' | transloco }}</mat-label>
              <input matInput [name]="'c' + c.id" [(ngModel)]="comments[c.id]" [disabled]="a.status === 'submitted'" />
            </mat-form-field>
          </fieldset>
        }
        @if (a.status === 'pending' && a.cycle.status === 'active') {
          <button mat-flat-button type="submit" [disabled]="!complete(a)">{{ 'perform.reviews.submit' | transloco }}</button>
        }
      </form>
    }
  `,
  styles: `
    .list { list-style: none; padding: 0; margin: 0 0 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
    .row { width: 100%; display: flex; gap: 0.75rem; align-items: baseline; flex-wrap: wrap; padding: 0.6rem 0.75rem; border: 1px solid var(--app-border); border-radius: 8px; background: none; color: inherit; font: inherit; cursor: pointer; text-align: left; }
    .row.active { border-color: var(--mat-sys-primary); }
    .status { margin-left: auto; }
    .status[data-status='submitted'] { color: var(--app-success); }
    .form { padding: 1rem; }
    .form h2 { margin: 0 0 0.5rem; font: var(--mat-sys-title-medium); }
    fieldset { border: none; border-bottom: 1px solid var(--app-border); padding: 0.5rem 0 0.75rem; margin: 0 0 0.5rem; }
    legend { font-weight: 500; }
    mat-radio-group { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; }
    .full { width: 100%; margin-top: 0.5rem; }
  `,
})
export class MyReviewsPage implements OnInit {
  private readonly api = inject(PerformService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly items = signal<Assignment[]>([]);
  protected readonly open = signal<Assignment | null>(null);
  protected readonly loading = signal(false);
  protected ratings: Record<number, number> = {};
  protected comments: Record<number, string> = {};

  ngOnInit(): void {
    this.loading.set(true);
    this.api.myAssignments().subscribe({
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

  protected select(a: Assignment): void {
    this.api.assignment(a.id).subscribe({
      next: (full) => {
        this.ratings = Object.fromEntries((full.answers ?? []).map((x) => [x.competency_id, x.rating]));
        this.comments = Object.fromEntries((full.answers ?? []).map((x) => [x.competency_id, x.comment ?? '']));
        this.open.set(full);
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  protected complete(a: Assignment): boolean {
    return (a.competencies ?? []).every((c) => this.ratings[c.id] !== undefined);
  }

  protected submit(a: Assignment): void {
    const answers = (a.competencies ?? []).map((c) => ({ competency_id: c.id, rating: this.ratings[c.id], comment: this.comments[c.id] || null }));
    this.api.submitReview(a.id, answers).subscribe({
      next: (saved) => {
        this.open.set(saved);
        this.items.update((list) => list.map((x) => (x.id === saved.id ? { ...x, status: saved.status } : x)));
        this.toast('perform.reviews.submitted');
      },
      error: (e: unknown) => this.toast(performErrorKey(e)),
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
