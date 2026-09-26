import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { withCurrent } from '../hiring-team';
import { Application, Ref } from '../recruiting.model';
import { RecruitingService, recruitingErrorKey } from '../recruiting.service';

/**
 * Interviewers of one application in the candidate card (contextual role: they see only this candidate). Writers get a
 * multi-select of active users (loaded on first open); everyone else sees the names. The API decides the final right.
 */
@Component({
  selector: 'app-interviewers-panel',
  imports: [MatFormFieldModule, MatIconModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (canEdit()) {
      <mat-form-field class="picker" subscriptSizing="dynamic">
        <mat-label>{{ 'recruiting.card.interviewers.label' | transloco }}</mat-label>
        <mat-select multiple [value]="selectedIds()" [disabled]="saving()" (openedChange)="$event && loadPeople()" (selectionChange)="save($event.value)">
          @for (u of options(); track u.id) {
            <mat-option [value]="u.id">{{ u.name }}</mat-option>
          }
        </mat-select>
        <mat-hint>{{ 'recruiting.card.interviewers.hint' | transloco }}</mat-hint>
      </mat-form-field>
    } @else {
      <p class="muted">
        <mat-icon inline aria-hidden="true">groups</mat-icon>
        {{ 'recruiting.card.interviewers.label' | transloco }}: {{ names() || ('recruiting.card.interviewers.none' | transloco) }}
      </p>
    }
  `,
  styles: `
    .picker { width: min(24rem, 100%); margin: 0.5rem 0; }
    .muted { color: var(--app-muted, inherit); margin: 0.25rem 0; }
  `,
})
export class InterviewersPanel {
  readonly application = input.required<Application>();
  readonly canEdit = input(false);
  readonly changed = output<void>();

  private readonly api = inject(RecruitingService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly people = signal<Ref[] | null>(null);
  protected readonly saving = signal(false);
  protected readonly current = computed(() => this.application().interviewers ?? []);
  protected readonly selectedIds = computed(() => this.current().map((u) => u.id));
  protected readonly names = computed(() => this.current().map((u) => u.name).join(', '));
  protected readonly options = computed(() => withCurrent(this.people() ?? [], ...this.current()));

  protected loadPeople(): void {
    if (this.people() !== null) {
      return;
    }
    this.people.set([]);
    this.api.assignableUsers().subscribe({ next: (list) => this.people.set(list), error: () => this.people.set(null) });
  }

  protected save(ids: number[]): void {
    this.saving.set(true);
    this.api.setInterviewers(this.application().id, ids).subscribe({
      next: () => {
        this.saving.set(false);
        this.toast('recruiting.card.interviewers.saved');
        this.changed.emit();
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.toast(recruitingErrorKey(e));
      },
    });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
