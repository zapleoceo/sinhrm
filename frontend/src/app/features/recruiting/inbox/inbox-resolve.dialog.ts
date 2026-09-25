import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatListModule } from '@angular/material/list';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable, Subject, debounceTime, distinctUntilChanged, switchMap } from 'rxjs';
import { Candidate, Touchpoint, Vacancy } from '../recruiting.model';
import { RecruitingService, duplicateOf, recruitingErrorKey } from '../recruiting.service';
import { InboxStore } from './inbox.store';

export interface InboxResolveData {
  message: Touchpoint;
  store: InboxStore;
}

/** Resolve an unmatched message: link it to an existing candidate (search) or create a new candidate from it. */
@Component({
  selector: 'app-inbox-resolve-dialog',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatListModule,
    MatSelectModule,
    MatTabsModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'recruiting.inbox.resolve' | transloco }} · {{ data.message.meta.contact ?? '—' }}</h2>
    <mat-dialog-content>
      <mat-tab-group mat-stretch-tabs="false">
        <mat-tab [label]="'recruiting.inbox.link' | transloco">
          <mat-form-field class="full" subscriptSizing="dynamic">
            <mat-label>{{ 'recruiting.candidates.search' | transloco }}</mat-label>
            <input matInput #q (input)="search$.next(q.value)" cdkFocusInitial />
          </mat-form-field>
          <mat-selection-list [multiple]="false" (selectionChange)="picked.set($event.options[0].value)">
            @for (c of found(); track c.id) {
              <mat-list-option [value]="c.id">{{ c.full_name }} · {{ c.phone ?? c.email ?? c.telegram_username }}</mat-list-option>
            }
          </mat-selection-list>
          <div class="actions">
            <button mat-flat-button type="button" [disabled]="picked() === null || busy()" (click)="link()">{{ 'recruiting.inbox.link' | transloco }}</button>
          </div>
        </mat-tab>
        <mat-tab [label]="'recruiting.inbox.create' | transloco">
          <form [formGroup]="form" (ngSubmit)="create(false)" class="create">
            <mat-form-field>
              <mat-label>{{ 'recruiting.candidates.fields.fullName' | transloco }}</mat-label>
              <input matInput formControlName="full_name" required maxlength="255" />
              <mat-error>{{ 'recruiting.required' | transloco }}</mat-error>
            </mat-form-field>
            <mat-form-field>
              <mat-label>{{ 'recruiting.inbox.vacancy' | transloco }}</mat-label>
              <mat-select formControlName="vacancy_id">
                <mat-option [value]="null">—</mat-option>
                @for (v of vacancies(); track v.id) {
                  <mat-option [value]="v.id">{{ v.title }} · {{ v.branch?.name }}</mat-option>
                }
              </mat-select>
            </mat-form-field>
            @if (duplicateId(); as dupId) {
              <p class="warn" role="alert">{{ 'recruiting.inbox.duplicate' | transloco }}</p>
              <div class="actions">
                <button mat-stroked-button type="button" (click)="picked.set(dupId); link()">{{ 'recruiting.inbox.linkExisting' | transloco }}</button>
                <button mat-button type="button" (click)="create(true)">{{ 'recruiting.candidates.createAnyway' | transloco }}</button>
              </div>
            }
            <div class="actions">
              <button mat-flat-button type="submit" [disabled]="busy()">{{ 'recruiting.inbox.create' | transloco }}</button>
            </div>
          </form>
        </mat-tab>
      </mat-tab-group>
      @if (error(); as key) {
        <p class="error" role="alert">{{ key | transloco }}</p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" mat-dialog-close>{{ 'common.close' | transloco }}</button>
    </mat-dialog-actions>
  `,
  styles: `
    mat-dialog-content { min-width: min(32rem, 86vw); }
    .full { width: 100%; margin-top: 0.75rem; }
    .create { display: flex; flex-direction: column; margin-top: 0.75rem; }
    .actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem; }
    .warn { color: var(--app-warning); margin: 0; }
    .error { color: var(--app-danger); }
  `,
})
export class InboxResolveDialog implements OnInit {
  protected readonly data = inject<InboxResolveData>(MAT_DIALOG_DATA);
  private readonly api = inject(RecruitingService);
  private readonly ref = inject<MatDialogRef<InboxResolveDialog, boolean>>(MatDialogRef);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly search$ = new Subject<string>();
  protected readonly found = signal<Candidate[]>([]);
  protected readonly vacancies = signal<Vacancy[]>([]);
  protected readonly picked = signal<number | null>(null);
  protected readonly duplicateId = signal<number | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly form = inject(NonNullableFormBuilder).group({
    full_name: ['', [Validators.required, Validators.minLength(2)]],
    vacancy_id: [null as number | null],
  });

  ngOnInit(): void {
    this.search$
      .pipe(
        debounceTime(250),
        distinctUntilChanged(),
        switchMap((q) => this.api.candidates({ q: q.trim() || undefined, perPage: 10 })),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({ next: (page) => this.found.set(page.data), error: () => this.found.set([]) });
    this.api.vacancies({ status: 'open', perPage: 100 }).subscribe({ next: (p) => this.vacancies.set(p.data), error: () => undefined });
  }

  protected link(): void {
    const id = this.picked();
    if (id === null) {
      return;
    }
    this.run(this.data.store.link(this.data.message, id));
  }

  protected create(forceNew: boolean): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const v = this.form.getRawValue();
    this.run(
      this.data.store.createCandidate(this.data.message, {
        full_name: v.full_name.trim(),
        vacancy_id: v.vacancy_id ?? undefined,
        force_new: forceNew || undefined,
      }),
    );
  }

  private run(call: Observable<unknown>): void {
    this.busy.set(true);
    this.error.set(null);
    call.subscribe({
      next: () => this.ref.close(true),
      error: (e: unknown) => {
        this.busy.set(false);
        const dup = duplicateOf(e);
        this.duplicateId.set(dup?.existing_id ?? null);
        this.error.set(dup ? null : recruitingErrorKey(e));
      },
    });
  }
}
