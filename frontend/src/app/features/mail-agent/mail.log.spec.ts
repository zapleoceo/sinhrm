import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { of } from 'rxjs';
import { TablePage } from '../../../testing/table';
import { MailOutcome, ProcessedMail } from './mail.model';
import { MailPage } from './mail.page';
import { MailService } from './mail.service';

const mail = (id: number, received: string, sender: string | null, outcome: MailOutcome): ProcessedMail => ({
  id,
  received_at: received,
  sender,
  subject: `Тема ${id}`,
  kind: null,
  parser: null,
  outcome,
  error: null,
  candidate_id: null,
  touchpoint_id: null,
});

const MESSAGES = [mail(1, '2026-10-01T08:00:00Z', 'b@work.ua', 'application'), mail(2, '2026-10-02T08:00:00Z', null, 'unknown'), mail(3, '2026-09-30T08:00:00Z', 'a@djinni.co', 'skipped')];

describe('MailPage «Журнал»: sortable / filterable headers bound to the URL', () => {
  let page: TablePage;

  const openLog = async (url: string): Promise<void> => {
    const api = {
      status: () => of({ connection: { connected: true, account_email: 'hr@example.com', error: null }, last_sync: null, counts: { rules: 0, unknown: 0, processed_24h: 0 } }),
      rules: () => of([]),
      unknown: () => of([]),
      messages: () => of(MESSAGES),
    };
    page = await TablePage.open('admin/mail', MailPage, url, [
      { provide: MailService, useValue: api },
      { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
    ]);
    (page.root.querySelectorAll<HTMLElement>('[role="tab"]')[2]).click();
    await page.settle();
    await page.settle();
  };

  it('newest first by default (arrow on «received»), a title click sorts by sender with empty senders last', async () => {
    await openLog('/admin/mail');
    expect(page.th('mail.log.received').getAttribute('aria-sort')).toBe('descending');
    expect(page.column(2, 'table.log')).toEqual(['Тема 2', 'Тема 1', 'Тема 3']);
    await page.sort('mail.log.sender');
    expect(page.params.get('sort')).toBe('sender');
    expect(page.column(1, 'table.log')).toEqual(['a@djinni.co', 'b@work.ua', '—']);
  });

  it('outcome and date filters from the URL narrow the log', async () => {
    await openLog('/admin/mail?outcome=application&received_from=2026-10-01');
    expect(page.column(2, 'table.log')).toEqual(['Тема 1']);
    expect(page.th('mail.log.outcome').querySelector('.dot')).not.toBeNull();
  });
});
