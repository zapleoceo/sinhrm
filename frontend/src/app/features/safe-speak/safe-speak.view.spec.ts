import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { REPORT_STATUSES, ReportMessage, reportStatusTone } from './safe-speak.model';
import { SafeSpeakThread } from './thread';

describe('reportStatusTone', () => {
  it('gives every status its own pill tone (marker shape + text, never colour alone)', () => {
    expect(REPORT_STATUSES.map(reportStatusTone)).toEqual(['info', 'warn', 'neutral']);
  });
});

describe('SafeSpeakThread', () => {
  it('draws each message as a station on one line, author and date in the text', async () => {
    TestBed.configureTestingModule({
      imports: [
        SafeSpeakThread,
        TranslocoTestingModule.forRoot({
          langs: { uk: { safeSpeak: { author: { reporter: 'Ви', handler: 'Відповідальний' } } } },
          translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' },
        }),
      ],
    });
    const fixture = TestBed.createComponent(SafeSpeakThread);
    const messages: ReportMessage[] = [
      { author: 'reporter', body: 'Питання', created_on: '2026-10-01' },
      { author: 'handler', body: 'Відповідь', created_on: '2026-10-02' },
    ];
    fixture.componentRef.setInput('messages', messages);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const el = fixture.nativeElement as HTMLElement;
    const items = Array.from(el.querySelectorAll('article.msg'));
    expect(items.map((m) => m.getAttribute('data-author'))).toEqual(['reporter', 'handler']);
    expect(items[1].querySelector('.who')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Відповідальний · 02.10.2026');
    expect(items[1].querySelector('.who .mono')?.textContent?.trim()).toBe('02.10.2026');
    expect(items[0].querySelector('.text')?.textContent).toBe('Питання');
  });
});
