import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { WorkflowRun, WorkflowTemplate } from '../workflows.model';
import { WorkflowsService } from '../workflows.service';
import { RunCard, StepAction } from './run-card';
import { RunsStore } from './runs.store';

/** Profile tab "Воркфлоу": runs of one employee; admins can start a workflow for them. */
@Component({
  selector: 'app-employee-runs-tab',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    TranslocoPipe,
    RunCard,
  ],
  providers: [RunsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (canStart()) {
      <form class="filters" [formGroup]="form" (ngSubmit)="start()">
        <mat-form-field class="grow" subscriptSizing="dynamic">
          <mat-label>{{ 'workflows.fields.template' | transloco }}</mat-label>
          <mat-select formControlName="template_id">
            @for (t of templates(); track t.id) {
              <mat-option [value]="t.id">{{ t.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'workflows.runs.anchor' | transloco }}</mat-label>
          <input matInput type="date" formControlName="anchor_date" />
        </mat-form-field>
        <button mat-flat-button type="submit" [disabled]="form.invalid"><mat-icon>play_arrow</mat-icon>{{ 'workflows.runs.start' | transloco }}</button>
      </form>
    }
    @if (store.loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (store.failed()) {
      <p class="muted">{{ 'common.error' | transloco }}</p>
    }
    <div class="runs">
      @for (r of store.items(); track r.id) {
        <app-run-card [run]="r" [showEmployee]="false" [busy]="store.pending()" (action)="act($event)" (cancelRequested)="cancel($event)" />
      } @empty {
        @if (!store.loading() && !store.failed()) {
          <p class="muted">{{ 'workflows.runs.emptyEmployee' | transloco }}</p>
        }
      }
    </div>
  `,
  styles: `
    :host { display: block; padding: 1rem 0; }
    .runs { display: flex; flex-direction: column; gap: 0.5rem; }
  `,
})
export class EmployeeRunsTab {
  readonly employeeId = input.required<number>();
  /** Admins may start a run (the API decides anyway). */
  readonly canStart = input(false);

  protected readonly store = inject(RunsStore);
  private readonly api = inject(WorkflowsService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  protected readonly templates = signal<WorkflowTemplate[]>([]);
  protected readonly form = inject(NonNullableFormBuilder).group({
    template_id: [0, [Validators.required, Validators.min(1)]],
    anchor_date: [new Date().toISOString().slice(0, 10)],
  });

  constructor() {
    effect(() => this.store.load({ employee_id: this.employeeId() }));
    effect(() => {
      if (this.canStart()) {
        this.api.templates().subscribe({ next: (list) => this.templates.set(list.filter((t) => t.active)), error: () => this.templates.set([]) });
      }
    });
  }

  protected start(): void {
    const v = this.form.getRawValue();
    if (this.form.invalid) {
      return;
    }
    this.store.start(v.template_id, this.employeeId(), v.anchor_date || undefined, (key) => this.toast(key));
  }

  protected act(a: StepAction): void {
    this.store.command(a.run, a.step, a.command, (key) => this.toast(key), a.reason);
  }

  protected cancel(run: WorkflowRun): void {
    this.store.cancel(run, (key) => this.toast(key));
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 4000 });
  }
}
