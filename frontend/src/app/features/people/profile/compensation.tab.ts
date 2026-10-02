import { DatePipe, DecimalPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { CURRENCIES, Compensation, PAY_PERIODS } from '../people.model';
import { PeopleService } from '../people.service';

/**
 * Compensation history. HR staff (canManage) see and add records for anyone; the employee sees own read-only
 * (self → /api/me/employee/compensation). Current = latest effective date not in the future.
 */
@Component({
  selector: 'app-compensation-tab',
  imports: [DatePipe, DecimalPipe, ReactiveFormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (data(); as d) {
      <p>
        <strong>{{ 'people.compensation.current' | transloco }}:</strong>
        @if (d.current; as c) {
          {{ +c.amount | number: '1.0-2' }} {{ c.currency }} / {{ 'people.compensation.period.' + c.period | transloco }}
        } @else {
          —
        }
      </p>
      <table class="history">
        <tbody>
          @for (r of d.history; track r.id) {
            <tr [class.current]="r.current">
              <td>{{ r.effective_on | date: 'dd.MM.yyyy' }}</td>
              <td>{{ +r.amount | number: '1.0-2' }} {{ r.currency }} / {{ 'people.compensation.period.' + r.period | transloco }}</td>
              <td class="muted">{{ r.reason }}</td>
            </tr>
          } @empty {
            <tr><td class="muted">{{ 'people.compensation.empty' | transloco }}</td></tr>
          }
        </tbody>
      </table>
    }
    @if (canManage()) {
      <form class="add" [formGroup]="form" (ngSubmit)="add()">
        <mat-form-field>
          <mat-label>{{ 'people.compensation.amount' | transloco }}</mat-label>
          <input matInput type="number" min="0" formControlName="amount" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.currency' | transloco }}</mat-label>
          <mat-select formControlName="currency">
            @for (c of currencies; track c) {
              <mat-option [value]="c">{{ c }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.periodLabel' | transloco }}</mat-label>
          <mat-select formControlName="period">
            @for (p of periods; track p) {
              <mat-option [value]="p">{{ 'people.compensation.period.' + p | transloco }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.effectiveOn' | transloco }}</mat-label>
          <input matInput type="date" formControlName="effective_on" />
        </mat-form-field>
        <mat-form-field>
          <mat-label>{{ 'people.compensation.reason' | transloco }}</mat-label>
          <input matInput formControlName="reason" maxlength="500" />
        </mat-form-field>
        <button mat-flat-button type="submit" [disabled]="form.invalid || saving()">{{ 'people.compensation.add' | transloco }}</button>
      </form>
    }
  `,
  styles: `
    .history { border-collapse: collapse; margin-bottom: 1rem; }
    .history td { padding: 0.5rem 1rem 0.5rem 0; border-bottom: var(--app-border-w) solid var(--app-track); font-variant-numeric: tabular-nums; }
    .history tr.current { font-weight: 700; }
    .add { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; }
  `,
})
export class CompensationTab implements OnInit {
  readonly employeeId = input.required<number>();
  readonly canManage = input(false);
  readonly self = input(false);

  private readonly api = inject(PeopleService);
  protected readonly data = signal<Compensation | null>(null);
  protected readonly saving = signal(false);
  protected readonly currencies = CURRENCIES;
  protected readonly periods = PAY_PERIODS;
  protected readonly form = inject(NonNullableFormBuilder).group({
    amount: [0, [Validators.required, Validators.min(0.01)]],
    currency: ['UAH', Validators.required],
    period: ['month', Validators.required],
    effective_on: ['', Validators.required],
    reason: [''],
  });

  ngOnInit(): void {
    const load: Observable<Compensation> = this.canManage() ? this.api.compensation(this.employeeId()) : this.api.myCompensation();
    load.subscribe({ next: (d) => this.data.set(d), error: () => undefined });
  }

  protected add(): void {
    const v = this.form.getRawValue();
    this.saving.set(true);
    this.api.addCompensation(this.employeeId(), { ...v, reason: v.reason.trim() || null }).subscribe({
      next: (d) => {
        this.data.set(d);
        this.saving.set(false);
        this.form.reset();
      },
      error: () => this.saving.set(false),
    });
  }
}
