import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { Subject, catchError, debounceTime, of, switchMap } from 'rxjs';
import { estimateDays, toIso } from '../timeoff.dates';
import { HALF_DAYS, HalfDay, LeavePreview, LeaveRequest, LeaveType, NewLeaveRequest } from '../timeoff.model';
import { TimeOffService, timeoffErrorKey } from '../timeoff.service';

/**
 * New leave request: type, date range (native date inputs), half day, comment. The number of working days comes
 * from the server preview (/api/timeoff/requests/preview: weekends, holidays of the employee's branch, balance);
 * a local estimate is shown until it arrives.
 */
@Component({
  selector: 'app-leave-request-form',
  imports: [ReactiveFormsModule, MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form [formGroup]="form" (ngSubmit)="submit()" class="form">
      <mat-form-field>
        <mat-label>{{ 'timeoff.fields.type' | transloco }}</mat-label>
        <mat-select formControlName="leave_type_id" required>
          @for (t of types(); track t.id) {
            <mat-option [value]="t.id">{{ t.name }}</mat-option>
          }
        </mat-select>
        <mat-error>{{ 'people.required' | transloco }}</mat-error>
      </mat-form-field>
      <mat-form-field>
        <mat-label>{{ 'timeoff.fields.from' | transloco }}</mat-label>
        <input matInput type="date" formControlName="starts_on" required />
      </mat-form-field>
      <mat-form-field>
        <mat-label>{{ 'timeoff.fields.to' | transloco }}</mat-label>
        <input matInput type="date" formControlName="ends_on" required [min]="form.controls.starts_on.value" />
      </mat-form-field>
      <mat-form-field>
        <mat-label>{{ 'timeoff.fields.halfDay' | transloco }}</mat-label>
        <mat-select formControlName="half_day">
          @for (h of halfDays; track h) {
            <mat-option [value]="h">{{ 'timeoff.halfDay.' + h | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field class="full">
        <mat-label>{{ 'timeoff.fields.comment' | transloco }}</mat-label>
        <input matInput formControlName="comment" maxlength="2000" />
      </mat-form-field>
      @if (allowOverride()) {
        <mat-checkbox formControlName="override_balance" class="full">{{ 'timeoff.fields.override' | transloco }}</mat-checkbox>
      }

      <p class="preview full" aria-live="polite">
        @if (preview(); as p) {
          <strong>{{ 'timeoff.daysCount' | transloco: { n: p.days } }}</strong>
          @if (p.tracked) {
            · {{ 'timeoff.preview.available' | transloco: { n: p.available } }}
          }
          @for (h of p.holidays; track h.date) {
            <span class="muted"> · {{ h.name }} ({{ h.date }})</span>
          }
          @if (!p.sufficient) {
            <span class="warn"> · {{ 'timeoff.errors.insufficient_balance' | transloco }}</span>
          }
          @if (p.overlap) {
            <span class="warn"> · {{ 'timeoff.errors.overlap' | transloco }}</span>
          }
        } @else {
          <span class="muted">{{ 'timeoff.daysCount' | transloco: { n: estimate() } }}</span>
        }
      </p>
      @if (error(); as key) {
        <p class="error full" role="alert">{{ key | transloco }}</p>
      }
      <div class="full actions">
        <button mat-flat-button type="submit" [disabled]="saving()">{{ 'timeoff.actions.submit' | transloco }}</button>
      </div>
    </form>
  `,
  styles: `
    .form { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0 1rem; align-items: start; }
    .full { grid-column: 1 / -1; }
    .preview { margin: 0 0 0.5rem; }
    .warn { color: var(--app-warning); }
    .error { color: var(--app-danger); margin: 0; }
    .actions { display: flex; justify-content: flex-end; }
    @media (max-width: 800px) { .form { grid-template-columns: 1fr 1fr; } }
  `,
})
export class LeaveRequestForm implements OnInit {
  /** Employee the request is for; omitted = the signed-in user's own employee. */
  readonly employeeId = input<number | undefined>(undefined);
  /** Admins may push a balance below zero. */
  readonly allowOverride = input(false);
  readonly created = output<LeaveRequest>();

  private readonly api = inject(TimeOffService);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly halfDays = HALF_DAYS;
  protected readonly types = signal<LeaveType[]>([]);
  protected readonly preview = signal<LeavePreview | null>(null);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly today = toIso(new Date());
  protected readonly form = inject(NonNullableFormBuilder).group({
    leave_type_id: [null as number | null, Validators.required],
    starts_on: [this.today, Validators.required],
    ends_on: [this.today, Validators.required],
    half_day: ['none' as HalfDay],
    comment: [''],
    override_balance: [false],
  });
  private readonly value = toSignal(this.form.valueChanges, { initialValue: this.form.getRawValue() });
  protected readonly estimate = computed(() => {
    const v = this.value();
    return estimateDays(v.starts_on ?? '', v.ends_on ?? '', v.half_day ?? 'none');
  });
  private readonly preview$ = new Subject<NewLeaveRequest | null>();

  ngOnInit(): void {
    this.api.types().subscribe({
      next: (types) => {
        this.types.set(types);
        if (types.length > 0 && this.form.controls.leave_type_id.value === null) {
          this.form.controls.leave_type_id.setValue(types[0].id);
        }
      },
      error: () => undefined,
    });
    this.preview$
      .pipe(
        debounceTime(300),
        switchMap((body) => (body === null ? of(null) : this.api.preview(body).pipe(catchError(() => of(null))))),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((p) => this.preview.set(p));
    this.form.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      this.preview.set(null);
      this.preview$.next(this.body());
    });
  }

  protected submit(): void {
    const body = this.body();
    if (this.form.invalid || body === null) {
      this.form.markAllAsTouched();
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.api.create(body).subscribe({
      next: (created) => {
        this.saving.set(false);
        this.form.patchValue({ comment: '', override_balance: false });
        this.created.emit(created);
      },
      error: (e: unknown) => {
        this.error.set(timeoffErrorKey(e));
        this.saving.set(false);
      },
    });
  }

  /** The request body, or null while the form is incomplete / the range is reversed. */
  private body(): NewLeaveRequest | null {
    const v = this.form.getRawValue();
    if (v.leave_type_id === null || !v.starts_on || !v.ends_on || v.ends_on < v.starts_on) {
      return null;
    }
    return {
      leave_type_id: v.leave_type_id,
      starts_on: v.starts_on,
      ends_on: v.ends_on,
      half_day: v.half_day,
      comment: v.comment.trim() || null,
      ...(this.employeeId() !== undefined ? { employee_id: this.employeeId() } : {}),
      ...(v.override_balance ? { override_balance: true } : {}),
    };
  }
}
