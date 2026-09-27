import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { BulkResult, Candidate, CandidateBulkAction, CandidateBulkBody } from '../recruiting.model';
import { RecruitingService } from '../recruiting.service';

/** Bulk action over the selected candidates: move / reject (within one vacancy), add a tag, assign a recruiter. */
@Component({
  selector: 'app-candidate-bulk-dialog',
  imports: [FormsModule, MatButtonModule, MatDialogModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'bulk.title' | transloco: { n: data.length } }}</h2>
    <mat-dialog-content>
      <mat-form-field>
        <mat-label>{{ 'bulk.action' | transloco }}</mat-label>
        <mat-select [(ngModel)]="action">
          @for (a of actions; track a) {
            <mat-option [value]="a">{{ 'bulk.candidates.' + a | transloco }}</mat-option>
          }
        </mat-select>
      </mat-form-field>
      @if (action() === 'move' || action() === 'reject') {
        <mat-form-field>
          <mat-label>{{ 'bulk.vacancy' | transloco }}</mat-label>
          <mat-select [(ngModel)]="vacancyId">
            @for (v of vacancies(); track v.id) {
              <mat-option [value]="v.id">{{ v.title }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
      @if (action() === 'move') {
        <mat-form-field>
          <mat-label>{{ 'bulk.stage' | transloco }}</mat-label>
          <mat-select [(ngModel)]="stageId">
            @for (s of stages(); track s.id) {
              <mat-option [value]="s.id">{{ s.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
      @if (action() === 'reject') {
        <mat-form-field>
          <mat-label>{{ 'bulk.rejectReason' | transloco }}</mat-label>
          <mat-select [(ngModel)]="rejectReasonId">
            @for (r of reasons(); track r.id) {
              <mat-option [value]="r.id">{{ r.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
      @if (action() === 'tag') {
        <mat-form-field>
          <mat-label>{{ 'bulk.tag' | transloco }}</mat-label>
          <input matInput [(ngModel)]="tag" maxlength="40" />
        </mat-form-field>
      }
      @if (action() === 'assign') {
        <mat-form-field>
          <mat-label>{{ 'bulk.recruiter' | transloco }}</mat-label>
          <mat-select [(ngModel)]="ownerId">
            @for (u of users(); track u.id) {
              <mat-option [value]="u.id">{{ u.name }}</mat-option>
            }
          </mat-select>
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" mat-dialog-close>{{ 'common.cancel' | transloco }}</button>
      <button mat-flat-button type="button" [disabled]="!ready() || busy()" (click)="run()">{{ 'bulk.apply' | transloco }}</button>
    </mat-dialog-actions>
  `,
  styles: `mat-form-field { width: 100%; }`,
})
export class CandidateBulkDialog {
  protected readonly data = inject<Candidate[]>(MAT_DIALOG_DATA);
  private readonly api = inject(RecruitingService);
  private readonly ref = inject<MatDialogRef<CandidateBulkDialog, BulkResult[]>>(MatDialogRef);
  protected readonly actions: CandidateBulkAction[] = ['move', 'reject', 'tag', 'assign'];
  protected readonly action = signal<CandidateBulkAction>('move');
  protected readonly vacancyId = signal<number | undefined>(undefined);
  protected readonly stageId = signal<number | undefined>(undefined);
  protected readonly rejectReasonId = signal<number | undefined>(undefined);
  protected readonly tag = signal('');
  protected readonly ownerId = signal<number | undefined>(undefined);
  protected readonly busy = signal(false);
  private readonly pipelines = toSignal(this.api.pipelines(), { initialValue: [] });
  protected readonly reasons = toSignal(this.api.rejectReasons(), { initialValue: [] });
  protected readonly users = toSignal(this.api.assignableUsers(), { initialValue: [] });
  private readonly applications = this.data.flatMap((c) => c.applications);
  protected readonly vacancies = computed(() => {
    const seen = new Map<number, { id: number; title: string }>();
    for (const a of this.applications) {
      if (a.vacancy) seen.set(a.vacancy.id, { id: a.vacancy.id, title: a.vacancy.title });
    }
    return [...seen.values()];
  });
  protected readonly stages = computed(() => {
    const app = this.applications.find((a) => a.vacancy_id === this.vacancyId());
    return this.pipelines().find((p) => p.stages.some((s) => s.id === app?.stage_id))?.stages.filter((s) => !s.is_reject) ?? [];
  });
  protected readonly ready = computed(() => {
    switch (this.action()) {
      case 'move':
        return !!this.vacancyId() && !!this.stageId();
      case 'reject':
        return !!this.vacancyId() && !!this.rejectReasonId();
      case 'tag':
        return this.tag().trim() !== '';
      default:
        return !!this.ownerId();
    }
  });

  protected run(): void {
    const body: CandidateBulkBody = {
      action: this.action(),
      ids: this.data.map((c) => c.id),
      vacancy_id: this.vacancyId(),
      stage_id: this.stageId(),
      reject_reason_id: this.rejectReasonId(),
      tag: this.tag().trim() || undefined,
      owner_id: this.ownerId(),
    };
    this.busy.set(true);
    this.api.bulkCandidates(body).subscribe({
      next: (r) => this.ref.close(r),
      error: () => this.busy.set(false),
    });
  }
}
