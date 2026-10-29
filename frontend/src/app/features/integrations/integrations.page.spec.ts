import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { IntegrationsDirectoryStore } from './integrations-directory.store';
import { IntegrationsPage } from './integrations.page';
import { IntegrationsStore } from './integrations.store';
import { NotifyService } from '../../core/ui/notify.service';
import { TABLE_QUERY_ADAPTER } from '../../core/ui/table/table-query-adapter';
import { windowQueryAdapter } from '../../../testing/window-query-adapter';

describe('IntegrationsPage window deep link', () => {
  it('keeps the selected integration in the window query, separate from the canonical primary query', () => {
    const query = windowQueryAdapter({ integration: 'google_gmail' });
    TestBed.configureTestingModule({
      providers: [
        { provide: TABLE_QUERY_ADAPTER, useValue: query.adapter },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({}), queryParamMap: convertToParamMap({ integration: 'ai_broker' }) } } },
        { provide: IntegrationsStore, useValue: {} },
        { provide: IntegrationsDirectoryStore, useValue: {} },
        { provide: MatDialog, useValue: {} },
        { provide: NotifyService, useValue: {} },
      ],
    });
    const page = TestBed.runInInjectionContext(() => new IntegrationsPage()) as unknown as { focusedIntegration: () => string | null };
    expect(page.focusedIntegration()).toBe('google_gmail');

    query.set({ integration: 'ai_broker' });
    expect(page.focusedIntegration()).toBe('ai_broker');
  });
});
