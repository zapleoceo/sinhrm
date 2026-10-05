import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { of } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { AssistantSettings, ENABLED_KEY } from '../assistant-settings';
import { AssistantService } from '../assistant.service';
import { AssistantMascot } from './assistant-mascot';
import { FrameClock, MASCOT_FRAME_CLOCK } from './mascot-loop';

class IdleClock implements FrameClock {
  requests = 0;
  readonly pending = new Set<number>();
  request(): number {
    const id = ++this.requests;
    this.pending.add(id);
    return id;
  }
  cancel(handle: number): void {
    this.pending.delete(handle);
  }
}

describe('AssistantMascot compact presentation', () => {
  let clock: IdleClock;

  beforeEach(() => {
    localStorage.clear();
    vi.useFakeTimers();
    clock = new IdleClock();
  });
  afterEach(() => vi.useRealTimers());

  async function mount(compact: boolean): Promise<ComponentFixture<AssistantMascot>> {
    TestBed.configureTestingModule({
      imports: [AssistantMascot, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        { provide: MASCOT_FRAME_CLOCK, useValue: clock },
        { provide: AuthService, useValue: { user: signal({ id: 1 }) } },
        { provide: AssistantService, useValue: { status: () => of({ available: true, reason: null, mcp_url: '' }), quips: () => of({ jokes: [], source: 'none' }) } },
      ],
    });
    const fixture = TestBed.createComponent(AssistantMascot);
    fixture.componentRef.setInput('compact', compact);
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(10);
    fixture.detectChanges();
    return fixture;
  }

  it.each([true, false])('narrow screen: no figure/orb or animation loop with enabled=%s', async (enabled) => {
    localStorage.setItem(ENABLED_KEY, enabled ? '1' : '0');
    const fixture = await mount(true);
    await vi.advanceTimersByTimeAsync(5000);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.mascot-stage')?.classList.contains('off')).toBe(true);
    expect(el.querySelector('.mascot-orb')).toBeNull();
    expect(clock.requests).toBe(0);
    expect(TestBed.inject(AssistantSettings).enabled()).toBe(enabled);
    expect(localStorage.getItem(ENABLED_KEY)).toBe(enabled ? '1' : '0');
  });

  it('chat remains available in compact mode and does not start animation', async () => {
    const fixture = await mount(true);
    TestBed.inject(AssistantSettings).openChat();
    fixture.detectChanges();
    await vi.advanceTimersByTimeAsync(100);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.assistant-panel')).not.toBeNull();
    expect((fixture.nativeElement as HTMLElement).querySelector('.mascot-stage')?.classList.contains('off')).toBe(true);
    expect(clock.requests).toBe(0);
  });

  it('resizing stops pending frames and restores desktop animation without changing the saved preference', async () => {
    const fixture = await mount(false);
    await vi.advanceTimersByTimeAsync(5000);
    expect(clock.requests).toBeGreaterThan(0);
    fixture.componentRef.setInput('compact', true);
    fixture.detectChanges();
    const count = clock.requests;
    expect(clock.pending.size).toBe(0);
    await vi.advanceTimersByTimeAsync(5000);
    expect(clock.requests).toBe(count);
    fixture.componentRef.setInput('compact', false);
    fixture.detectChanges();
    expect(clock.requests).toBeGreaterThan(count);
    expect(TestBed.inject(AssistantSettings).enabled()).toBe(true);
    expect(localStorage.getItem(ENABLED_KEY)).toBeNull();
  });
});
