import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { DictionaryItem } from '../../directory/directory.model';
import { BulkEmployeeAction, EmployeeBulkResult } from '../people.model';
import { PeopleService } from '../people.service';

export interface EmployeeBulkData {
  ids: number[];
  departments: DictionaryItem[];
  positions: DictionaryItem[];
  managers: { id: number; name: string }[];
}

type ChangeAction = Exclude<BulkEmployeeAction, 'export'>;

/** Bulk change of department / position / manager for the selected employees (HR staff). */
@Component({
  selector: 'app-employee-bulk-dialog',
  imports: [FormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'bulk.title' | transloco: { n: data.ids.length } }}</h2>
    <mat-dialog-content>
      <mat-form-field>
        <mat-label>{{ 'bulk.action' | transloco }}</mat-label>
        <mat-select [(ngModel)]="action">
          @for (a of actions; track a) {
            <mat-option [value]="a">{{ 'bulk.people.' + a | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field>
        <mat-label>{{ 'bulk.value' | transloco }}</mat-label>
        <mat-select [(ngModel)]="value">
          @for (o of options(); track o.id) {
            <mat-option [value]="o.id">{{ o.name }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
      <button mat-flat-button type="button" [disabled]="!value() || busy()" (click)="run()">{{ 'bulk.apply' | transloco }}</button>
    </mat-dialog-actions>
  `,
  styles: `mat-form-field { width: 100%; }`,
})
export class EmployeeBulkDialog {
  protected readonly data = inject<EmployeeBulkData>(MAT_DIALOG_DATA);
  private readonly api = inject(PeopleService);
  private readonly ref = inject<MatDialogRef<EmployeeBulkDialog, EmployeeBulkResult[]>>(MatDialogRef);
  protected readonly actions: ChangeAction[] = ['department', 'position', 'manager'];
  protected readonly action = signal<ChangeAction>('department');
  protected readonly value = signal<number | undefined>(undefined);
  protected readonly busy = signal(false);
  protected readonly options = computed(() => {
    const a = this.action();
    return a === 'department' ? this.data.departments : a === 'position' ? this.data.positions : this.data.managers;
  });

  protected run(): void {
    const value = this.value();
    if (!value) return;
    this.busy.set(true);
    this.api.bulk(this.action(), this.data.ids, value).subscribe({
      next: (r) => this.ref.close(r),
      error: () => this.busy.set(false),
    });
  }
}
