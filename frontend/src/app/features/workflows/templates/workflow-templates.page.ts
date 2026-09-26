import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ConfirmDialog, ConfirmDialogData } from '../confirm.dialog';
import { WORKFLOW_KINDS, WORKFLOW_TRIGGERS, WorkflowKind, WorkflowTemplate, WorkflowTrigger } from '../workflows.model';
import { WorkflowsService, workflowsErrorKey } from '../workflows.service';

/** Admin → Воркфлоу: onboarding/offboarding templates, creation of a new one and deletion (only without runs). */
@Component({
  selector: 'app-workflow-templates-page',
  imports: [
    DatePipe,
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    RouterLink,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="page-head">
      <div>
        <h1>{{ 'workflows.templates.title' | transloco }}</h1>
        <p class="muted">{{ 'workflows.templates.subtitle' | transloco }}</p>
      </div>
      <a mat-stroked-button routerLink="/workflows/runs"><mat-icon>play_circle</mat-icon>{{ 'workflows.runs.title' | transloco }}</a>
    </header>

    <form class="filters" [formGroup]="form" (ngSubmit)="create()">
      <mat-form-field class="grow" subscriptSizing="dynamic">
        <mat-label>{{ 'workflows.fields.name' | transloco }}</mat-label>
        <input matInput formControlName="name" maxlength="200" autocomplete="off" />
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'workflows.fields.kind' | transloco }}</mat-label>
        <mat-select formControlName="kind">
          @for (k of kinds; track k) {
            <mat-option [value]="k">{{ 'workflows.kind.' + k | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <mat-form-field subscriptSizing="dynamic">
        <mat-label>{{ 'workflows.fields.trigger' | transloco }}</mat-label>
        <mat-select formControlName="trigger">
          @for (t of triggers; track t) {
            <mat-option [value]="t">{{ 'workflows.trigger.' + t | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      <button mat-flat-button type="submit" [disabled]="form.invalid || busy()"><mat-icon>add</mat-icon>{{ 'workflows.templates.create' | transloco }}</button>
    </form>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }
    @if (failed()) {
      <div class="state">
        <p>{{ 'common.error' | transloco }}</p>
        <button mat-stroked-button type="button" (click)="load()">{{ 'common.retry' | transloco }}</button>
      </div>
    }
    <div class="panel">
      <table>
        <thead>
          <tr>
            <th scope="col">{{ 'workflows.fields.name' | transloco }}</th>
            <th scope="col">{{ 'workflows.fields.kind' | transloco }}</th>
            <th scope="col">{{ 'workflows.fields.trigger' | transloco }}</th>
            <th scope="col">{{ 'workflows.fields.steps' | transloco }}</th>
            <th scope="col">{{ 'workflows.fields.runs' | transloco }}</th>
            <th scope="col">{{ 'workflows.fields.updated' | transloco }}</th>
            <th scope="col"><span class="visually-hidden">{{ 'workflows.templates.delete' | transloco }}</span></th>
          </tr>
        </thead>
        <tbody>
          @for (t of templates(); track t.id) {
            <tr [class.inactive]="!t.active">
              <th scope="row">
                <a [routerLink]="['/admin/workflows', t.id]">{{ t.name }}</a>
                @if (!t.active) {
                  <span class="muted"> · {{ 'workflows.fields.inactive' | transloco }}</span>
                }
              </th>
              <td>{{ 'workflows.kind.' + t.kind | transloco }}</td>
              <td>{{ 'workflows.trigger.' + t.trigger | transloco }}</td>
              <td>{{ t.steps.length }}</td>
              <td>{{ t.runs_count }}</td>
              <td>{{ t.updated_at | date: 'dd.MM.yyyy' }}</td>
              <td>
                <button mat-icon-button type="button" (click)="remove(t)" [attr.aria-label]="'workflows.templates.delete' | transloco">
                  <mat-icon>delete</mat-icon>
                </button>
              </td>
            </tr>
          } @empty {
            @if (!loading()) {
              <tr><td colspan="7" class="muted">{{ 'workflows.templates.empty' | transloco }}</td></tr>
            }
          }
        </tbody>
      </table>
    </div>
  `,
  styles: `
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--app-border); font-weight: normal; }
    thead th { color: var(--app-muted); font-size: 0.8rem; }
    th a { color: inherit; font-weight: 500; }
    tr.inactive { opacity: 0.6; }
  `,
})
export class WorkflowTemplatesPage implements OnInit {
  private readonly api = inject(WorkflowsService);
  private readonly router = inject(Router);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly dialog = inject(MatDialog);

  protected readonly kinds = WORKFLOW_KINDS;
  protected readonly triggers = WORKFLOW_TRIGGERS;
  protected readonly templates = signal<WorkflowTemplate[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);
  protected readonly busy = signal(false);
  protected readonly form = inject(NonNullableFormBuilder).group({
    name: ['', [Validators.required, Validators.maxLength(200)]],
    kind: ['onboarding' as WorkflowKind],
    trigger: ['manual' as WorkflowTrigger],
  });

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.api.templates().subscribe({
      next: (list) => {
        this.templates.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  protected create(): void {
    const v = this.form.getRawValue();
    if (this.form.invalid || v.name.trim() === '') {
      return;
    }
    this.busy.set(true);
    const probation = v.trigger === 'probation_end' ? { probation_days: 90 } : {};
    this.api.createTemplate({ name: v.name.trim(), kind: v.kind, trigger: v.trigger, active: false, ...probation, steps: [] }).subscribe({
      next: (t) => {
        this.busy.set(false);
        void this.router.navigate(['/admin/workflows', t.id]);
      },
      error: (e: unknown) => {
        this.busy.set(false);
        this.toast(workflowsErrorKey(e));
      },
    });
  }

  protected remove(t: WorkflowTemplate): void {
    const data: ConfirmDialogData = {
      message: this.i18n.translate('workflows.templates.confirmDelete', { name: t.name }),
      confirm: this.i18n.translate('common.confirm'),
      cancel: this.i18n.translate('common.cancel'),
    };
    this.dialog
      .open<ConfirmDialog, ConfirmDialogData, { reason: string } | null>(ConfirmDialog, { data })
      .afterClosed()
      .subscribe((ok) => {
        if (!ok) {
          return;
        }
        this.api.deleteTemplate(t.id).subscribe({
          next: () => this.templates.update((list) => list.filter((x) => x.id !== t.id)),
          error: (e: unknown) => this.toast(workflowsErrorKey(e)),
        });
      });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 5000 });
  }
}
