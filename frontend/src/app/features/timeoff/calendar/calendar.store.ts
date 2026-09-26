import { Injectable, computed, inject, signal } from '@angular/core';
import { calendarRows, monthDays, monthRange, shiftMonth, toIso } from '../timeoff.dates';
import { CalendarData } from '../timeoff.model';
import { TimeOffService } from '../timeoff.service';

/** Team calendar state: the month shown, optional branch filter, absences laid out per employee × day. */
@Injectable()
export class CalendarStore {
  private readonly api = inject(TimeOffService);
  private seq = 0;

  readonly month = signal(shiftMonth(toIso(new Date()), 0));
  readonly branchId = signal<number | undefined>(undefined);
  readonly data = signal<CalendarData>({ absences: [], holidays: [] });
  readonly loading = signal(false);
  readonly failed = signal(false);

  readonly days = computed(() => monthDays(this.month()));
  readonly rows = computed(() => calendarRows(this.data().absences, this.days()));
  readonly holidays = computed(() => new Map(this.data().holidays.map((h) => [h.date, h.name])));

  load(): void {
    const seq = ++this.seq;
    const { from, to } = monthRange(this.month());
    this.loading.set(true);
    this.failed.set(false);
    this.api.calendar(from, to, this.branchId()).subscribe({
      next: (d) => {
        if (seq === this.seq) {
          this.data.set(d);
          this.loading.set(false);
        }
      },
      error: () => {
        if (seq === this.seq) {
          this.failed.set(true);
          this.loading.set(false);
        }
      },
    });
  }

  shift(delta: number): void {
    this.month.set(shiftMonth(this.month(), delta));
    this.load();
  }

  setBranch(id: number | undefined): void {
    this.branchId.set(id);
    this.load();
  }
}
