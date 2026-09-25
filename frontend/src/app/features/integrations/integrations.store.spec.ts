import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Integration, IntegrationsList } from './integrations.model';
import { IntegrationsService } from './integrations.service';
import { IntegrationsStore } from './integrations.store';

const item = (key: string, group: Integration['group'], status: Integration['status'] = 'off'): Integration => ({
  key,
  group,
  status,
  supports_check: false,
  last_checked_at: null,
  last_error: null,
  updated_at: null,
  fields: [],
});

class FakeApi {
  list$: Observable<IntegrationsList> = of({ data: [], ai_policy: { enabled: false } });
  status$ = new Subject<Integration>();
  ai$ = new Subject<{ enabled: boolean }>();
  list = () => this.list$;
  setStatus = () => this.status$;
  setAiPolicy = () => this.ai$;
  update = (key: string) => of(item(key, 'ai', 'demo'));
  check = () => throwError(() => new HttpErrorResponse({ status: 422, error: { code: 'check_not_supported' } }));
}

describe('IntegrationsStore', () => {
  let store: IntegrationsStore;
  let api: FakeApi;

  beforeEach(() => {
    api = new FakeApi();
    TestBed.configureTestingModule({ providers: [IntegrationsStore, { provide: IntegrationsService, useValue: api }] });
    store = TestBed.inject(IntegrationsStore);
  });

  it('loads and groups in the fixed group order, skipping empty groups', () => {
    api.list$ = of({ data: [item('viber', 'messengers'), item('ai_broker', 'ai'), item('djinni', 'sources')], ai_policy: { enabled: true } });
    store.load();

    expect(store.loading()).toBe(false);
    expect(store.aiEnabled()).toBe(true);
    expect(store.groups().map((g) => g.group)).toEqual(['ai', 'messengers', 'sources']);
  });

  it('flags a load error', () => {
    api.list$ = throwError(() => new Error('down'));
    store.load();
    expect(store.failed()).toBe(true);
    expect(store.loading()).toBe(false);
  });

  it('applies a status change at once and keeps the server answer', () => {
    api.list$ = of({ data: [item('viber', 'messengers')], ai_policy: { enabled: false } });
    store.load();

    store.setStatus(store.items()[0], 'demo', () => undefined);
    expect(store.items()[0].status).toBe('demo');
    expect(store.pending().has('viber')).toBe(true);

    api.status$.next(item('viber', 'messengers', 'demo'));
    expect(store.pending().has('viber')).toBe(false);
    expect(store.items()[0].status).toBe('demo');
  });

  it('rolls a status change back and reports the error key', () => {
    api.list$ = of({ data: [item('viber', 'messengers')], ai_policy: { enabled: false } });
    store.load();
    let reported = '';

    store.setStatus(store.items()[0], 'demo', (key) => (reported = key));
    api.status$.error(new HttpErrorResponse({ status: 500 }));

    expect(store.items()[0].status).toBe('off');
    expect(store.pending().size).toBe(0);
    expect(reported).toBe('integrations.errors.generic');
  });

  it('toggles AI optimistically and rolls back on failure', () => {
    let reported = '';
    store.setAi(true, (key) => (reported = key));
    expect(store.aiEnabled()).toBe(true);

    api.ai$.error(new HttpErrorResponse({ status: 403 }));
    expect(store.aiEnabled()).toBe(false);
    expect(reported).toBe('integrations.errors.generic');
  });

  it('save replaces the item and clears pending; failed check clears pending too', () => {
    api.list$ = of({ data: [item('ai_broker', 'ai')], ai_policy: { enabled: false } });
    store.load();

    store.save('ai_broker', { settings: {} }).subscribe();
    expect(store.items()[0].status).toBe('demo');
    expect(store.pending().size).toBe(0);

    let failed = false;
    store.check('ai_broker').subscribe({ error: () => (failed = true) });
    expect(failed).toBe(true);
    expect(store.pending().size).toBe(0);
  });
});
