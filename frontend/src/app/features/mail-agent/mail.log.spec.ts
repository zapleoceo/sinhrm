import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { of } from 'rxjs';
import { TablePage, clickTitle, header, openTablePage, sortCount } from '../../../testing/table-page';
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

// As the API sends them: newest first (the page keeps that order until a title click).
const MESSAGES = [mail(2, '2026-10-02T08:00:00Z', null, 'unknown'), mail(1, '2026-10-01T08:00:00Z', 'b@work.ua', 'application'), mail(3, '2026-09-30T08:00:00Z', 'a@djinni.co', 'skipped')];

describe('MailPage «Журнал»: sortable / filterable headers bound to the URL', () => {
  let page: TablePage<MailPage>;
  const table = (sel = 'table') => page.el.querySelector(sel)!;
  const sort = async (title: string, sel = 'table') => {
    clickTitle(table(sel), title);
    await page.settle();
  };
  const params = () => new URL(page.router.url, 'http://x').searchParams;
  const cells = (index: number, sel = 'table', inner?: string) =>
    [...table(sel).querySelectorAll('tbody tr')].map((tr) => (inner ? tr.children[index]?.querySelector(inner) : tr.children[index])?.textContent?.trim() ?? '');

  const openLog = async (url: string): Promise<void> => {
    const api = {
      status: () => of({ connection: { connected: true, account_email: 'hr@example.com', error: null }, last_sync: null, counts: { rules: 0, unknown: 0, processed_24h: 0 } }),
      rules: () => of([]),
      unknown: () => of([]),
      messages: () => of(MESSAGES),
    };
    page = await openTablePage(MailPage, url, [
      { provide: MailService, useValue: api },
      { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
    ]);
    (page.el.querySelectorAll<HTMLElement>('[role="tab"]')[2]).click();
    await page.settle();
    await page.settle();
  };

  it('newest first by default (arrow on «received»), a title click sorts by sender with empty senders last', async () => {
    await openLog('/');
    expect(header(table(), 'mail.log.received').getAttribute('aria-sort')).toBe('descending');
    expect(cells(2, 'table.log')).toEqual(['Тема 2', 'Тема 1', 'Тема 3']);
    await sort('mail.log.sender');
    expect(params().get('sort')).toBe('sender');
    expect(cells(1, 'table.log')).toEqual(['a@djinni.co', 'b@work.ua', '—']);
  });

  it('outcome and date filters from the URL narrow the log', async () => {
    await openLog('/?outcome=application&received_from=2026-10-01');
    expect(cells(2, 'table.log')).toEqual(['Тема 1']);
    expect(sortCount(page.fixture)).toBe(1); // what an open header filter announces
    expect(header(table(), 'mail.log.outcome').querySelector('.dot')).not.toBeNull();
  });
  it('the open tab lives in the URL: a link with the log sort opens the log, a tab click writes ?tab=', async () => {
    page = await openTablePage(MailPage, '/?sort=sender&dir=asc', [
      { provide: MailService, useValue: { status: () => of({ connection: { connected: true, account_email: 'hr@example.com', error: null }, last_sync: null, counts: { rules: 0, unknown: 0, processed_24h: 0 } }), rules: () => of([]), unknown: () => of([]), messages: () => of(MESSAGES) } },
      { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
    ]);
    await page.settle();
    const tabs = () => [...page.el.querySelectorAll<HTMLElement>('[role="tab"]')];
    expect(tabs()[2].getAttribute('aria-selected')).toBe('true');
    expect(cells(1, 'table.log')).toEqual(['a@djinni.co', 'b@work.ua', '—']);
    tabs()[1].click();
    await page.settle();
    expect(params().get('tab')).toBe('rules');
    tabs()[0].click();
    await page.settle();
    expect(params().get('tab')).toBe('unknown'); // named, or the leftover ?sort= would bring the log back
    expect(tabs()[0].getAttribute('aria-selected')).toBe('true');
    await page.router.navigateByUrl('/?tab=log');
    await page.settle();
    expect(tabs()[2].getAttribute('aria-selected')).toBe('true');
  });
});
