import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject } from 'rxjs';
import { NAV_BADGES_INTERVAL_MS, NavBadge, NavBadgesService, badgeText, groupBadgeSum } from './nav-badges';

describe('nav badges helpers', () => {
  it('hides 0 and caps at 99+', () => {
    expect(badgeText(0)).toBe('');
    expect(badgeText(-1)).toBe('');
    expect(badgeText(1)).toBe('1');
    expect(badgeText(99)).toBe('99');
    expect(badgeText(100)).toBe('99+');
  });

  it('sums the counters of a group, ignoring missing keys', () => {
    expect(groupBadgeSum({ timeoff_approvals: 2, time_approvals: 3, my_documents: 1, tasks: 7 }, 'people')).toBe(6);
    expect(groupBadgeSum({ desk_queue: 4 }, 'admin')).toBe(4);
    expect(groupBadgeSum({}, 'recruiting')).toBe(0);
  });
});

@Component({ imports: [NavBadge], template: '<app-nav-badge [count]="count()" />' })
class Host {
  readonly count = signal(0);
}

describe('NavBadge', () => {
  function render(count: number): HTMLElement {
    TestBed.configureTestingModule({
      imports: [Host, TranslocoTestingModule.forRoot({ langs: { uk: { shell: { badge: { new: '{{count}} нових' } } } }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    });
    const fixture = TestBed.createComponent(Host);
    fixture.componentInstance.count.set(count);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders the number with an accessible label', () => {
    const badge = render(3).querySelector('.nav-badge');
    expect(badge?.textContent?.trim()).toBe('3');
    expect(badge?.getAttribute('aria-label')).toBe('3 нових');
  });

  it('is hidden at 0', () => {
    expect(render(0).querySelector('.nav-badge')).toBeNull();
  });

  it('shows 99+ for big numbers but says the exact count', () => {
    const badge = render(150).querySelector('.nav-badge');
    expect(badge?.textContent?.trim()).toBe('99+');
    expect(badge?.getAttribute('aria-label')).toBe('150 нових');
  });
});

describe('NavBadgesService', () => {
  let http: HttpTestingController;
  let service: NavBadgesService;
  let visibility: DocumentVisibilityState;

  const setVisibility = (state: DocumentVisibilityState): void => {
    visibility = state;
    document.dispatchEvent(new Event('visibilitychange'));
  };

  beforeEach(() => {
    vi.useFakeTimers();
    visibility = 'visible';
    vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visibility);
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    http = TestBed.inject(HttpTestingController);
    service = TestBed.inject(NavBadgesService);
  });

  afterEach(() => {
    http.verify();
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('loads on start, every minute while visible and after navigation', () => {
    const navigations = new Subject<void>();
    const sub = service.watch(navigations);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/nav/badges').flush({ data: { tasks: 1 } });
    expect(service.badges()).toEqual({ tasks: 1 });

    vi.advanceTimersByTime(NAV_BADGES_INTERVAL_MS);
    http.expectOne('/api/nav/badges').flush({ data: { tasks: 2 } });
    expect(service.badges()).toEqual({ tasks: 2 });

    navigations.next();
    http.expectOne('/api/nav/badges').flush({ data: {} });
    expect(service.badges()).toEqual({});
    sub.unsubscribe();
  });

  it('does not poll while the tab is hidden and refreshes when it comes back', () => {
    const navigations = new Subject<void>();
    const sub = service.watch(navigations);
    vi.advanceTimersByTime(0);
    http.expectOne('/api/nav/badges').flush({ data: { inbox: 1 } });

    setVisibility('hidden');
    vi.advanceTimersByTime(NAV_BADGES_INTERVAL_MS * 3);
    navigations.next();
    http.expectNone('/api/nav/badges');

    setVisibility('visible');
    vi.advanceTimersByTime(0);
    http.expectOne('/api/nav/badges').flush({ data: { inbox: 4 } });
    expect(service.badges()).toEqual({ inbox: 4 });
    sub.unsubscribe();
  });

  it('keeps the last counters when a request fails', () => {
    const sub = service.watch(new Subject<void>());
    vi.advanceTimersByTime(0);
    http.expectOne('/api/nav/badges').flush({ data: { tasks: 5 } });
    vi.advanceTimersByTime(NAV_BADGES_INTERVAL_MS);
    http.expectOne('/api/nav/badges').flush('down', { status: 503, statusText: 'Service Unavailable' });
    expect(service.badges()).toEqual({ tasks: 5 });
    sub.unsubscribe();
  });
});
