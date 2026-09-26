import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslocoPipe } from '@jsverse/transloco';
import { Balance } from '../timeoff.model';
import { TimeOffService, timeoffErrorKey } from '../timeoff.service';

/** Leave balances of one employee (own when employeeId is not given): available / pending / used this year. */
@Component({
  selector: 'app-balances-panel',
  imports: [MatIconModule, MatProgressBarModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (error(); as key) {
      <p class="muted">{{ key | transloco }}</p>
    }
    <ul class="balances">
      @for (b of balances(); track b.leave_type.id) {
        <li class="balance" [style.border-left-color]="b.leave_type.color">
          <span class="name">{{ b.leave_type.name }}</span>
          @if (b.tracked) {
            <strong class="value">{{ b.available }}</strong>
            <span class="muted">{{ 'timeoff.balance.available' | transloco }}</span>
            <span class="muted small">
              {{ 'timeoff.balance.details' | transloco: { balance: b.balance, pending: b.pending, used: b.used_this_year } }}
            </span>
          } @else {
            <strong class="value">{{ b.used_this_year }}</strong>
            <span class="muted">{{ 'timeoff.balance.usedUnlimited' | transloco }}</span>
          }
        </li>
      }
    </ul>
  `,
  styles: `
    .balances { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); }
    .balance {
      display: flex; flex-direction: column; padding: 0.75rem 1rem; border: 1px solid var(--app-border);
      border-left-width: 4px; border-radius: var(--app-radius);
    }
    .name { font: var(--mat-sys-title-small); }
    .value { font: var(--mat-sys-headline-medium); font-variant-numeric: tabular-nums; }
    .small { font-size: 0.8rem; }
  `,
})
export class BalancesPanel {
  readonly employeeId = input<number | undefined>(undefined);
  /** Bump to reload (e.g. after a request was created). */
  readonly version = input(0);

  private readonly api = inject(TimeOffService);
  protected readonly balances = signal<Balance[]>([]);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
    effect(() => {
      this.version();
      this.load(this.employeeId());
    });
  }

  private load(employeeId: number | undefined): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.balances(employeeId).subscribe({
      next: (rows) => {
        this.balances.set(rows);
        this.loading.set(false);
      },
      error: (e: unknown) => {
        this.error.set(timeoffErrorKey(e));
        this.loading.set(false);
      },
    });
  }
}
