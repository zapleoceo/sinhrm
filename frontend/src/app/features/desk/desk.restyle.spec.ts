import { Type } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { CASE_STATUSES, CASE_STATUS_TONE, DeskCase } from './desk.model';
import { SlaBadge } from './sla-badge';

/** Compiled component CSS — restyle C «Маршрут» contract: theme tokens only (no hex), 1.5px lines, no fades. */
function css(component: Type<unknown>): string {
  // %NS% is the compiler's placeholder inside custom property names; it is empty at runtime.
  return (component as unknown as { ɵcmp: { styles: string[] } }).ɵcmp.styles.join('\n').replaceAll('%NS%', '');
}

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Desk restyle', () => {
  it('gives every case status a status-pill tone (colour + marker shape)', () => {
    for (const s of CASE_STATUSES) {
      expect(PILL_TONES).toContain(CASE_STATUS_TONE[s]);
    }
  });

  it('SLA badge is a status pill: breached = bad (square marker), met = neutral', () => {
    TestBed.configureTestingModule({
      imports: [SlaBadge, TranslocoTestingModule.forRoot({ langs: { uk: {} }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    });
    const fixture = TestBed.createComponent(SlaBadge);
    const sla = { first_response_due: null, resolve_due: null, first_response_breached: true, resolve_breached: false };
    fixture.componentRef.setInput('c', { sla, status: 'new', first_response_at: null } as unknown as DeskCase);
    fixture.detectChanges();
    const pill = (fixture.nativeElement as HTMLElement).querySelector('.app-pill');
    expect(pill?.getAttribute('data-tone')).toBe('bad');

    fixture.componentRef.setInput('c', { sla: { ...sla, first_response_breached: false }, status: 'closed', first_response_at: null } as unknown as DeskCase);
    fixture.detectChanges();
    expect(pill?.getAttribute('data-tone')).toBe('neutral');
    expect(css(SlaBadge)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
