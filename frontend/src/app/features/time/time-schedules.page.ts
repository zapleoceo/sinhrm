import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { DictionaryItem } from '../directory/directory.model';
import { DirectoryService } from '../directory/directory.service';
import { WorkScheduleRow } from './time.model';
import { TimeService, timeErrorKey } from './time.service';

/** Admin: work schedules — the company default and branch overrides (an employee's own People schedule wins). */
@Component({
  selector: 'app-time-schedules-page',
  imports: [MatButtonModule, MatCheckboxModule, MatFormFieldModule, MatIconModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'time.schedules.title' | transloco }}</h1>
        <p class="muted">{{ 'time.schedules.subtitle' | transloco }}</p>
      </div>
    </header>
    @for (s of rows(); track s.id) {
      <section class="panel box">
        <h2>{{ s.branch?.name ?? ('time.schedules.company' | transloco) }}</h2>
        <div class="row">
          @for (d of weekdays; track d) {
            <mat-checkbox [checked]="s.days.includes(d)" (change)="toggleDay(s, d, $event.checked)">{{ 'time.weekday.' + d | transloco }}</mat-checkbox>
          }
          <mat-form-field subscriptSizing="dynamic" class="xs"><mat-label>{{ 'time.schedules.hours' | transloco }}</mat-label>
            <input matInput type="number" min="0" max="24" step="0.5" [value]="s.hours_per_day" #h /></mat-form-field>
          <button mat-stroked-button type="button" (click)="save(s.branch?.id ?? null, s.days, h.value)"><mat-icon>save</mat-icon>{{ 'common.save' | transloco }}</button>
          @if (s.branch) {
            <button mat-icon-button type="button" (click)="remove(s.branch.id)" [attr.aria-label]="'common.delete' | transloco"><mat-icon>delete</mat-icon></button>
          }
        </div>
      </section>
    }
    <section class="panel box">
      <h2>{{ 'time.schedules.add' | transloco }}</h2>
      <div class="row">
        <mat-form-field subscriptSizing="dynamic"><mat-label>{{ 'time.schedules.branch' | transloco }}</mat-label>
          <mat-select [value]="newBranch()" (valueChange)="newBranch.set($event)">
            @for (b of branches(); track b.id) {
              <mat-option [value]="b.id">{{ b.name }}</mat-option>
            }
          </mat-select></mat-form-field>
        <button mat-flat-button type="button" [disabled]="newBranch() === null" (click)="save(newBranch(), [1, 2, 3, 4, 5], '8')"><mat-icon>add</mat-icon>{{ 'time.schedules.add' | transloco }}</button>
      </div>
    </section>
  `,
  styles: `
    .box { padding: 1rem; margin-bottom: var(--app-gap); }
    h2 { font: var(--mat-sys-title-medium); margin: 0 0 0.5rem; }
    .row { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
    .xs { width: 7rem; }
  `,
})
export class TimeSchedulesPage implements OnInit {
  private readonly api = inject(TimeService);
  private readonly directory = inject(DirectoryService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly weekdays = [1, 2, 3, 4, 5, 6, 7];
  protected readonly rows = signal<WorkScheduleRow[]>([]);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly newBranch = signal<number | null>(null);

  ngOnInit(): void {
    this.load();
    this.directory.active('branches').subscribe({ next: (l) => this.branches.set(l) });
  }

  protected toggleDay(s: WorkScheduleRow, day: number, on: boolean): void {
    const days = on ? [...new Set([...s.days, day])].sort((a, b) => a - b) : s.days.filter((d) => d !== day);
    this.rows.update((list) => list.map((r) => (r.id === s.id ? { ...r, days } : r)));
  }

  protected save(branchId: number | null, days: number[], hours: string): void {
    this.api.saveSchedule(branchId, days, Number(hours) || 0).subscribe({
      next: () => {
        this.newBranch.set(null);
        this.load();
        this.snack.open(this.i18n.translate('time.schedules.saved'), undefined, { duration: 3000 });
      },
      error: (e: unknown) => this.snack.open(this.i18n.translate(timeErrorKey(e)), undefined, { duration: 4000 }),
    });
  }

  protected remove(branchId: number): void {
    this.api.deleteSchedule(branchId).subscribe({ next: () => this.load() });
  }

  private load(): void {
    this.api.schedules().subscribe({ next: (list) => this.rows.set(list) });
  }
}
