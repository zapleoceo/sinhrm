import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { ActivatedRoute, ParamMap, RouterLink, convertToParamMap } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { ClientColumn, ClientTable, DATE_RANGE, TEXT_FILTER, translatedSelect } from '../../core/ui/table/client-table';
import { ColumnHeader } from '../../core/ui/table/column-header';
import { TableSortDirective } from '../../core/ui/table/table-sort.directive';
import { oneOfParam } from '../../core/ui/table/table-state';
import { TableUrlState } from '../../core/ui/table/table-url-state';
import { MAIL_OUTCOMES, PARSER_KEYS, ParserKey, ProcessedMail, SENDER_KINDS, SenderKind, SenderRule, UnknownSender, isSenderPattern } from './mail.model';
import { MailStore } from './mail.store';
import { NotifyService } from '../../core/ui/notify.service';

/** Columns of the processed-mail log (all rows are on the page: sorted and filtered here, state in the URL). */
const MAIL_LOG_COLUMNS: readonly ClientColumn<ProcessedMail>[] = [
  { key: 'received', value: (m) => m.received_at, filter: 'date' },
  { key: 'sender', value: (m) => m.sender, filter: 'text' },
  { key: 'subject', value: (m) => m.subject, filter: 'text' },
  { key: 'outcome', value: (m) => MAIL_OUTCOMES.indexOf(m.outcome), filter: 'select', filterValue: (m) => m.outcome },
];

/** Tabs of the page in their order; the URL keeps the open one (`?tab=rules`, none = the first). */
const MAIL_TABS = ['unknown', 'rules', 'log'] as const;
type MailTab = (typeof MAIL_TABS)[number];

/**
 * Open tab of the URL. Without `tab`, a link carrying the log table state (`?sort=sender`, `?outcome=…`) opens the log:
 * those params mean nothing on the other tabs.
 */
function mailTabFromParams(params: ParamMap): MailTab {
  const tab = oneOfParam(params, 'tab', MAIL_TABS);
  if (tab) return tab;
  const logParam = (name: string): boolean =>
    name === 'sort' || name === 'dir' || MAIL_LOG_COLUMNS.some((c) => name === c.key || name === `${c.key}_from` || name === `${c.key}_to`);
  return params.keys.some(logParam) ? 'log' : 'unknown';
}

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
    TableSortDirective,
    ColumnHeader,
  ],
  providers: [MailStore, TableUrlState],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './mail.page.html',
  styleUrl: './mail.page.scss',
})
export class MailPage implements OnInit {
  protected readonly store = inject(MailStore);
  private readonly notify = inject(NotifyService);
  private readonly url = inject(TableUrlState);
  private readonly params = toSignal(inject(ActivatedRoute).queryParamMap, { initialValue: convertToParamMap({}) });
  /** Open tab (in the URL, so a shared link or «back» lands on the same tab). */
  protected readonly tabIndex = computed(() => MAIL_TABS.indexOf(mailTabFromParams(this.params())));

  protected readonly kinds = SENDER_KINDS;
  protected readonly parsers = PARSER_KEYS;
  protected readonly newPattern = signal('');
  protected readonly newKind = signal<SenderKind>('job_board');
  protected readonly newParser = signal<ParserKey>('generic');
  protected readonly drafts = signal<Record<number, Draft>>({});
  protected readonly isPattern = isSenderPattern;
  /** «Журнал» tab: newest first by default; header sort / filters in the URL. */
  protected readonly log = new ClientTable({ rows: this.store.messages, columns: MAIL_LOG_COLUMNS, defaultSort: { key: 'received', dir: 'desc' } });
  protected readonly textFilter = TEXT_FILTER;
  protected readonly dateFilter = DATE_RANGE;
  protected readonly outcomeFilter = translatedSelect(() => MAIL_OUTCOMES, (o) => 'mail.outcomes.' + o);
  /** Outcome counters of the last manual sync, for the notice. */
  protected readonly syncSummary = computed(() => {
    const counts = this.store.lastSync() ?? {};
    return MAIL_OUTCOMES.filter((o) => o !== 'skipped').map((outcome) => ({ outcome, n: counts[outcome] ?? 0 }));
  });
  protected readonly toast = (key: string): void => {
    this.notify.show(key, { duration: 3000 });
  };

  ngOnInit(): void {
    this.store.load();
  }

  protected setTab(index: number): void {
    // Always named: without it the log params left in the URL (`?sort=…`) would bring the log back.
    if (index !== this.tabIndex()) this.url.update({ tab: MAIL_TABS[index] });
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
