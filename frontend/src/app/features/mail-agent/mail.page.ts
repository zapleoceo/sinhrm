import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { MAIL_OUTCOMES, PARSER_KEYS, ParserKey, SENDER_KINDS, SenderKind, SenderRule, UnknownSender, isSenderPattern } from './mail.model';
import { MailStore } from './mail.store';

/** Choice in the unknown-senders row before "Assign". */
interface Draft {
  kind: SenderKind;
  parser: ParserKey | null;
  domain: boolean;
}

/**
 * Admin → Mail (superadmin): Gmail connection and last sync, "Sync now", sender rules CRUD, the unknown-senders
 * queue (one click turns a sender into a rule; AI suggestions are shown with the label "ШІ", confident ones become
 * rules by themselves and are marked in the rules list) and the recent processed-mail log.
 */
@Component({
  selector: 'app-mail-page',
  imports: [
    DatePipe,
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTabsModule,
    RouterLink,
    TranslocoPipe,
  ],
  providers: [MailStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './mail.page.html',
  styleUrl: './mail.page.scss',
})
export class MailPage implements OnInit {
  protected readonly store = inject(MailStore);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly kinds = SENDER_KINDS;
  protected readonly parsers = PARSER_KEYS;
  protected readonly newPattern = signal('');
  protected readonly newKind = signal<SenderKind>('job_board');
  protected readonly newParser = signal<ParserKey>('generic');
  protected readonly drafts = signal<Record<number, Draft>>({});
  protected readonly isPattern = isSenderPattern;
  /** Outcome counters of the last manual sync, for the notice. */
  protected readonly syncSummary = computed(() => {
    const counts = this.store.lastSync() ?? {};
    return MAIL_OUTCOMES.filter((o) => o !== 'skipped').map((outcome) => ({ outcome, n: counts[outcome] ?? 0 }));
  });
  protected readonly toast = (key: string): void => {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  };

  ngOnInit(): void {
    this.store.load();
  }

  protected sync(): void {
    this.store.sync(this.toast);
  }

  protected addRule(): void {
    const pattern = this.newPattern().trim().toLowerCase();
    if (!isSenderPattern(pattern)) {
      return;
    }
    const kind = this.newKind();
    this.store.createRule(
      { pattern, kind, parser: kind === 'job_board' ? this.newParser() : null },
      () => {
        this.newPattern.set('');
        this.toast('mail.rules.created');
      },
      this.toast,
    );
  }

  protected changeKind(rule: SenderRule, kind: SenderKind): void {
    this.store.updateRule(rule, { kind }, this.toast);
  }

  protected changeParser(rule: SenderRule, parser: ParserKey): void {
    this.store.updateRule(rule, { parser }, this.toast);
  }

  protected remove(rule: SenderRule): void {
    this.store.deleteRule(rule, this.toast);
  }

  /** Rules list filter: all, or only those created automatically by AI. */
  protected readonly aiOnly = signal(false);
  protected readonly visibleRules = computed(() => (this.aiOnly() ? this.store.rules().filter((r) => r.source === 'ai') : this.store.rules()));

  /** The AI suggestion (when done) pre-selects the kind; otherwise the rule-based guess. */
  protected draft(sender: UnknownSender): Draft {
    const ai = sender.ai?.status === 'done' ? sender.ai : null;
    const kind = ai?.kind ?? sender.suggested_kind ?? 'ignore';
    return this.drafts()[sender.id] ?? {
      kind,
      parser: ai?.parser ?? sender.suggested_parser,
      domain: kind === 'job_board' || kind === 'newsletter',
    };
  }

  protected confidence(value: number | null | undefined): number {
    return Math.round((value ?? 0) * 100);
  }

  protected patchDraft(sender: UnknownSender, patch: Partial<Draft>): void {
    this.drafts.update((all) => ({ ...all, [sender.id]: { ...this.draft(sender), ...patch } }));
  }

  protected assign(sender: UnknownSender): void {
    const d = this.draft(sender);
    this.store.assign(
      sender,
      { kind: d.kind, parser: d.kind === 'job_board' ? (d.parser ?? 'generic') : null, scope: d.domain ? 'domain' : 'email' },
      () => this.toast('mail.unknown.assigned'),
      this.toast,
    );
  }

  protected dismiss(sender: UnknownSender): void {
    this.store.dismiss(sender, this.toast);
  }
}
