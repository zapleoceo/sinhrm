import { Injectable, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { AssignSender, MailStatus, ProcessedMail, SaveSenderRule, SenderRule, SyncCounts, UnknownSender } from './mail.model';
import { MailService, mailErrorKey } from './mail.service';

/**
 * State of Admin → Mail (provided per page): status, rules, unknown senders, processed log.
 * Every action reports failures through onError(i18n key); lists are reloaded after changes to keep server order.
 */
@Injectable()
export class MailStore {
  private readonly api = inject(MailService);

  readonly status = signal<MailStatus | null>(null);
  readonly rules = signal<SenderRule[]>([]);
  readonly unknown = signal<UnknownSender[]>([]);
  readonly messages = signal<ProcessedMail[]>([]);
  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly syncing = signal(false);
  readonly lastSync = signal<SyncCounts | null>(null);

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    forkJoin({
      status: this.api.status(),
      rules: this.api.rules(),
      unknown: this.api.unknown(),
      messages: this.api.messages(),
    }).subscribe({
      next: (r) => {
        this.status.set(r.status);
        this.rules.set(r.rules);
        this.unknown.set(r.unknown);
        this.messages.set(r.messages);
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  sync(onError: (key: string) => void): void {
    this.syncing.set(true);
    this.api.sync().subscribe({
      next: (counts) => {
        this.lastSync.set(counts);
        this.syncing.set(false);
        this.load();
      },
      error: (e: unknown) => {
        this.syncing.set(false);
        onError(mailErrorKey(e));
        this.load();
      },
    });
  }

  createRule(body: SaveSenderRule, done: () => void, onError: (key: string) => void): void {
    this.api.createRule(body).subscribe({
      next: () => {
        done();
        this.reloadRules();
      },
      error: (e: unknown) => onError(mailErrorKey(e)),
    });
  }

  updateRule(rule: SenderRule, body: SaveSenderRule, onError: (key: string) => void): void {
    this.api.updateRule(rule.id, body).subscribe({
      next: (saved) => this.rules.update((list) => list.map((r) => (r.id === saved.id ? saved : r))),
      error: (e: unknown) => onError(mailErrorKey(e)),
    });
  }

  deleteRule(rule: SenderRule, onError: (key: string) => void): void {
    const before = this.rules();
    this.rules.update((list) => list.filter((r) => r.id !== rule.id));
    this.api.deleteRule(rule.id).subscribe({
      error: (e: unknown) => {
        this.rules.set(before);
        onError(mailErrorKey(e));
      },
    });
  }

  assign(sender: UnknownSender, body: AssignSender, done: () => void, onError: (key: string) => void): void {
    this.api.assign(sender.id, body).subscribe({
      next: () => {
        done();
        this.reloadRules();
        this.api.unknown().subscribe((list) => this.unknown.set(list));
      },
      error: (e: unknown) => onError(mailErrorKey(e)),
    });
  }

  dismiss(sender: UnknownSender, onError: (key: string) => void): void {
    const before = this.unknown();
    this.unknown.update((list) => list.filter((s) => s.id !== sender.id));
    this.api.dismiss(sender.id).subscribe({
      error: (e: unknown) => {
        this.unknown.set(before);
        onError(mailErrorKey(e));
      },
    });
  }

  private reloadRules(): void {
    this.api.rules().subscribe((list) => this.rules.set(list));
  }
}
