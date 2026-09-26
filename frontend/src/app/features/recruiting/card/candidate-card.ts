import { Clipboard } from '@angular/cdk/clipboard';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal, viewChild } from '@angular/core';
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
import { SendMessage } from '../../channels/channels.model';
import { channelErrorCode, channelErrorKey } from '../../channels/channels.service';
import { GoogleService } from '../../google-workspace/google.service';
import { MeetingDialog, MeetingDialogData } from '../../google-workspace/meeting.dialog';
import { HireAction } from '../../people/hire.action';
import { EvaluationBadge } from '../../scripts/evaluation/evaluation-badge';
import { TasksWidget } from '../../scripts/tasks/tasks-widget';
import { RejectDialog, RejectDialogData, RejectDialogResult } from '../board/reject.dialog';
import { canWriteRecruiting } from '../recruiting.access';
import { formatDuration } from '../recruiting.format';
import { Application, CHANNELS, LogTouch, STAGE_FILTER, Stage, TimelineFilter } from '../recruiting.model';
import { recruitingErrorKey } from '../recruiting.service';
import { CandidateCardStore } from './candidate-card.store';
import { ScreeningPanel } from './screening-panel';
import { AuditHistory } from '../../audit/audit-history';
import { AuditLoader } from '../../audit/audit.model';
import { AuditService } from '../../audit/audit.service';
import { InterviewersPanel } from './interviewers-panel';
import { TouchComposer } from './touch-composer';
import { ChannelIcon } from '../../../core/ui/channel-icon';
import { hasChannelIcon } from '../../../core/ui/channel-icons';

/**
 * The candidate card: contacts and source/UTM chips, the ROUTE per vacancy (stages with time spent), a stage
 * control, and the merged timeline of every touch (made in SinHRM or captured outside) with channel filters.
 * Evaluated calls/chat messages carry a script score badge; the candidate's open tasks are listed above the timeline;
 * the AI screening per application (advisory, "Оцінка ШІ, рішення за людиною") sits above the tasks.
 */
@Component({
  selector: 'app-candidate-card',
  imports: [InterviewersPanel,
    DatePipe,
    MatButtonModule,
    ChannelIcon,
    MatChipsModule,
    MatIconModule,
    MatMenuModule,
    MatProgressBarModule,
    MatTooltipModule,
    TranslocoPipe,
    TouchComposer,
    EvaluationBadge,
    TasksWidget,
    ScreeningPanel,
    AuditHistory,
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
  private readonly google = inject(GoogleService);
  private readonly clipboard = inject(Clipboard);
  protected readonly hire = inject(HireAction);

  protected readonly hasIcon = hasChannelIcon;
  protected readonly filterChips: readonly TimelineFilter[] = [...CHANNELS.filter((c) => c !== 'system'), STAGE_FILTER];
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  private readonly audit = inject(AuditService);
  /** Change history: shown to everyone who can open the card (the API applies the same recruiting scope). */
  /** Loaded lazily, on the first expand of the section. */
  protected readonly historyOpen = signal(false);
  protected readonly historyLoader = computed<AuditLoader>(() => {
    const id = this.candidateId();
    return (paging) => this.audit.candidateHistory(id, paging);
  });
  protected readonly utm = computed(() => Object.entries(this.store.candidate()?.utm ?? {}));
  protected readonly duration = formatDuration;
  /** "Schedule a meeting" works only with a connected Google Calendar. */
  protected readonly calendarConnected = signal(false);

  constructor() {
    effect(() => this.store.open(this.candidateId()));
    this.google.calendarConnected().subscribe({ next: (on) => this.calendarConnected.set(on), error: () => this.calendarConnected.set(false) });
  }

  protected scheduleMeeting(): void {
    const c = this.store.candidate();
    if (!c) {
      return;
    }
    this.dialog
      .open<MeetingDialog, MeetingDialogData, boolean>(MeetingDialog, {
        data: { candidateId: c.id, candidateName: c.full_name, candidateEmail: c.email },
      })
      .afterClosed()
      .subscribe((created) => {
        if (created) {
          this.store.refresh();
        }
      });
  }

  protected copy(link: string): void {
    this.clipboard.copy(link);
    this.toast('google.meeting.copied');
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

  protected send(body: SendMessage): void {
    this.store.sendMessage(body).subscribe({
      next: (touch) => {
        this.composer()?.reset();
        this.toast(touch.meta.demo === true ? 'channels.composer.sentDemo' : 'channels.composer.sent');
      },
      error: (e: unknown) => {
        if (channelErrorCode(e) === 'channel_not_connected') {
          this.composer()?.offerManual();
        } else {
          this.composer()?.setBusy(false);
        }
        this.toast(channelErrorKey(e));
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
