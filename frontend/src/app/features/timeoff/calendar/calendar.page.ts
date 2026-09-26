import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { isWeekend } from '../timeoff.dates';
import { CalendarStore } from './calendar.store';
import { toIsoDate } from '../../../core/date/iso-date';

/**
 * Team calendar: month grid (CSS grid, no calendar library), a row per absent colleague, cells coloured by leave
 * type (pending — hatched). Weekends and holidays are shaded. Scope comes from the API (self, team, reports; admin — all).
 */
@Component({
  selector: 'app-calendar-page',
  imports: [DatePipe, MatButtonModule, MatFormFieldModule, MatIconModule, MatProgressBarModule, MatSelectModule, MatTooltipModule, RouterLink, TranslocoPipe],
  providers: [CalendarStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'timeoff.calendar.title' | transloco }}</h1>
        <p class="muted">{{ 'timeoff.calendar.subtitle' | transloco }}</p>
      </div>
      <a mat-stroked-button routerLink="/timeoff"><mat-icon>beach_access</mat-icon>{{ 'timeoff.my.title' | transloco }}</a>
    </header>

    <div class="filters">
      <button mat-icon-button type="button" (click)="store.shift(-1)" [attr.aria-label]="'timeoff.calendar.prev' | transloco"><mat-icon>chevron_left</mat-icon></button>
      <strong class="month">{{ store.month() | date: 'LLLL yyyy' }}</strong>
      <button mat-icon-button type="button" (click)="store.shift(1)" [attr.aria-label]="'timeoff.calendar.next' | transloco"><mat-icon>chevron_right</mat-icon></button>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'people.fields.branch' | transloco }}</mat-label>
        <mat-select [value]="store.branchId()" (valueChange)="store.setBranch($event)">
          <mat-option [value]="undefined">{{ 'common.all' | transloco }}</mat-option>
          @for (b of branches(); track b.id) {
            <mat-option [value]="b.id">{{ b.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
    </div>

    <section class="panel" aria-live="polite">
      @if (store.loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (store.failed()) {
        <div class="state">
          <p>{{ 'timeoff.loadError' | transloco }}</p>
          <button mat-stroked-button type="button" (click)="store.load()">{{ 'common.retry' | transloco }}</button>
        </div>
      } @else {
        <div class="scroll">
          <div class="grid" [style.grid-template-columns]="'12rem repeat(' + store.days().length + ', minmax(1.75rem, 1fr))'" role="table">
            <div class="head name" role="columnheader"></div>
            @for (d of store.days(); track d) {
              <div
                class="head"
                role="columnheader"
                [class.weekend]="weekend(d)"
                [class.holiday]="store.holidays().has(d)"
                [class.today]="d === today"
                [matTooltip]="store.holidays().get(d) ?? ''"
              >
                {{ d | date: 'd' }}
              </div>
            }
            @for (row of store.rows(); track row.employee.id) {
              <a class="name" role="rowheader" [routerLink]="['/people', row.employee.id]">{{ row.employee.full_name }}</a>
              @for (d of store.days(); track d) {
                @if (row.cells[d]; as a) {
                  <div
                    class="cell on"
                    role="cell"
                    [class.pending]="a.status === 'pending'"
                    [class.half]="a.half_day !== 'none' && ((a.half_day === 'start' && d === a.starts_on) || (a.half_day === 'end' && d === a.ends_on))"
                    [style.--c]="a.leave_type.color"
                    [matTooltip]="a.leave_type.name + ' · ' + ('timeoff.status.' + a.status | transloco)"
                  ></div>
                } @else {
                  <div class="cell" role="cell" [class.weekend]="weekend(d)" [class.holiday]="store.holidays().has(d)"></div>
                }
              }
            } @empty {
              <p class="muted empty">{{ 'timeoff.calendar.empty' | transloco }}</p>
            }
          </div>
        </div>
      }
    </section>
  `,
  styles: `
    .month { min-width: 10rem; text-align: center; text-transform: capitalize; }
    .scroll { overflow-x: auto; }
    .grid { display: grid; min-width: 60rem; }
    .head { font-size: 0.75rem; text-align: center; padding: 0.25rem 0; color: var(--app-muted); border-bottom: 1px solid var(--app-border); }
    .head.today { color: var(--mat-sys-primary); font-weight: 600; }
    .name {
      padding: 0.25rem 0.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: inherit;
      text-decoration: none; border-bottom: 1px solid var(--app-border);
    }
    .cell { min-height: 2rem; border-bottom: 1px solid var(--app-border); border-left: 1px solid var(--app-border); }
    .weekend { background: var(--mat-sys-surface-container); }
    .holiday { background: color-mix(in srgb, var(--app-warning) 18%, transparent); }
    .cell.on { background: var(--c); }
    .cell.on.pending { background: repeating-linear-gradient(45deg, var(--c) 0 4px, transparent 4px 8px); }
    .cell.on.half { background: linear-gradient(to right, var(--c) 50%, transparent 50%); }
    .empty { grid-column: 1 / -1; padding: 1rem; }
  `,
})
export class CalendarPage implements OnInit {
  protected readonly store = inject(CalendarStore);
  private readonly directory = inject(DirectoryService);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly today = toIsoDate(new Date());
  protected readonly weekend = isWeekend;

  ngOnInit(): void {
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l), error: () => undefined });
    this.store.load();
  }
}
