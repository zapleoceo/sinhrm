import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, viewChild } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { RejectDialog, RejectDialogData, RejectDialogResult } from '../board/reject.dialog';
import { canWriteRecruiting } from '../recruiting.access';
import { formatDuration } from '../recruiting.format';
import { Application, CHANNEL_ICONS, CHANNELS, LogTouch, STAGE_FILTER, Stage, TimelineFilter } from '../recruiting.model';
import { recruitingErrorKey } from '../recruiting.service';
import { CandidateCardStore } from './candidate-card.store';
import { TouchComposer } from './touch-composer';

/**
 * The candidate card: contacts and source/UTM chips, the ROUTE per vacancy (stages with time spent), a stage
 * control, and the merged timeline of every touch (made in SinHRM or captured outside) with channel filters.
 */
@Component({
  selector: 'app-candidate-card',
  imports: [
    DatePipe,
    MatButtonModule,
    MatChipsModule,
    MatIconModule,
    MatMenuModule,
    MatProgressBarModule,
    MatTooltipModule,
    TranslocoPipe,
    TouchComposer,
  ],
  providers: [CandidateCardStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './candidate-card.html',
  styleUrl: './candidate-card.scss',
})
export class CandidateCard {
  readonly candidateId = input.required<number>();

  protected readonly store = inject(CandidateCardStore);
  private readonly dialog = inject(MatDialog);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);
  private readonly auth = inject(AuthService);
  private readonly composer = viewChild(TouchComposer);

  protected readonly icons = CHANNEL_ICONS;
  protected readonly filterChips: readonly TimelineFilter[] = [...CHANNELS.filter((c) => c !== 'system'), STAGE_FILTER];
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  protected readonly utm = computed(() => Object.entries(this.store.candidate()?.utm ?? {}));
  protected readonly duration = formatDuration;

  constructor() {
    effect(() => this.store.open(this.candidateId()));
  }

  protected isOn(filter: TimelineFilter): boolean {
    return this.store.filters().includes(filter);
  }

  protected otherStages(app: Application): Stage[] {
    return (app.stages ?? []).filter((s) => s.id !== app.stage_id);
  }

  protected moveTo(app: Application, stage: Stage): void {
    if (!stage.is_reject) {
      this.doMove(app, { stage_id: stage.id });
      return;
    }
    this.dialog
      .open<RejectDialog, RejectDialogData, RejectDialogResult>(RejectDialog, {
        data: { candidateName: this.store.candidate()?.full_name ?? '', reasons: this.store.rejectReasons() },
      })
      .afterClosed()
      .subscribe((r) => {
        if (r) {
          this.doMove(app, { stage_id: stage.id, ...r });
        }
      });
  }

  protected log(body: LogTouch): void {
    this.store.logTouch(body).subscribe({
      next: () => {
        this.composer()?.reset();
        this.toast('recruiting.composer.saved');
      },
      error: (e: unknown) => {
        this.composer()?.setBusy(false);
        this.toast(recruitingErrorKey(e));
      },
    });
  }

  private doMove(app: Application, body: { stage_id: number; reject_reason_id?: number; reason?: string }): void {
    this.store.move(app, body).subscribe({ error: (e: unknown) => this.toast(recruitingErrorKey(e)) });
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
