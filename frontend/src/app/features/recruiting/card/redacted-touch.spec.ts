import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import uk from '../../../../../public/i18n/uk.json';
import { Touchpoint } from '../recruiting.model';
import { TouchBody } from './touch-body';

const touch = (over: Partial<Touchpoint>): Touchpoint =>
  ({
    id: 1,
    candidate_id: 5,
    application_id: 9,
    channel: 'email',
    direction: 'out',
    author: null,
    occurred_at: '2026-10-01T10:00:00+03:00',
    body: null,
    meta: {},
    via_product: true,
    integration_key: null,
    ...over,
  }) as Touchpoint;

function setup(value: Touchpoint): HTMLElement {
  TestBed.configureTestingModule({
    imports: [TouchBody, TranslocoTestingModule.forRoot({ langs: { uk }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' }, preloadLangs: true })],
  });
  const fixture = TestBed.createComponent(TouchBody);
  fixture.componentRef.setInput('touch', value);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('TouchBody — a touch the viewer may not read', () => {
  it('shows the placeholder instead of an empty bubble when the touch is redacted', () => {
    const el = setup(touch({ redacted: true, body: null }));
    expect(el.querySelector('.text.redacted')?.textContent).toContain('Текст приховано');
  });

  it('shows the text of a touch that is not redacted', () => {
    const el = setup(touch({ body: 'Звичайний лист' }));
    expect(el.querySelector('.text')?.textContent).toContain('Звичайний лист');
    expect(el.querySelector('.redacted')).toBeNull();
  });

  it('renders nothing for a touch without a text', () => {
    expect(setup(touch({ body: null })).querySelector('p')).toBeNull();
  });
});
