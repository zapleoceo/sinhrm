import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { MatDatepickerModule } from '@angular/material/datepicker';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslocoPipe } from '@jsverse/transloco';
import { fromIsoDate, toIsoDate } from '../../core/date/iso-date';
import { addDays, mondayOf } from './time.model';

/** Dropdown calendar to jump to any week: picking a day emits that week's Monday ('YYYY-MM-DD'). */
@Component({
  selector: 'app-week-picker',
  imports: [MatDatepickerModule, MatFormFieldModule, MatInputModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-form-field subscriptSizing="dynamic" class="week">
      <mat-label>{{ 'time.week.pick' | transloco }}</mat-label>
      <input matInput [matDatepicker]="picker" [value]="monday()" (dateChange)="pick($event.value)" />
      <mat-datepicker-toggle matIconSuffix [for]="picker" />
      <mat-datepicker #picker [dateClass]="inWeek" />
    </mat-form-field>
  `,
  styles: `
    .week { width: 11rem; }
  `,
})
export class WeekPicker {
  /** Monday of the shown week, 'YYYY-MM-DD'. */
  readonly weekStart = input.required<string>();
  readonly weekChange = output<string>();

  protected readonly monday = computed(() => fromIsoDate(this.weekStart()));
  private readonly sunday = computed(() => addDays(this.weekStart(), 6));

  /** Highlights the days of the shown week in the calendar (global class `.app-week-day`, styles.scss: the popup is an overlay). */
  protected readonly inWeek = (date: Date, view: string): string => {
    const iso = toIsoDate(date);
    return view === 'month' && iso >= this.weekStart() && iso <= this.sunday() ? 'app-week-day' : '';
  };

  protected pick(date: Date | null): void {
    const iso = toIsoDate(date);
    if (iso !== '' && mondayOf(iso) !== this.weekStart()) {
      this.weekChange.emit(mondayOf(iso));
    }
  }
}
