import { TestBed } from '@angular/core/testing';
import { Observable, Subject } from 'rxjs';
import { DataEnvelope } from '../../core/api/api.model';
import { EmployeeDirectoryPreview, EmployeeDirectoryStatus, EmployeeDirectorySyntheticPreview } from './integrations.model';
import { IntegrationsService } from './integrations.service';
import { IntegrationsDirectoryStore } from './integrations-directory.store';

describe('IntegrationsDirectoryStore', () => {
  let statusRequest: Subject<DataEnvelope<EmployeeDirectoryStatus>>;
  let previewRequest: Subject<EmployeeDirectorySyntheticPreview>;
  let refreshRequest: Subject<EmployeeDirectorySyntheticPreview>;
  let previewCalls: number;
  let store: IntegrationsDirectoryStore;

  beforeEach(() => {
    statusRequest = new Subject<DataEnvelope<EmployeeDirectoryStatus>>();
    previewRequest = new Subject<EmployeeDirectorySyntheticPreview>();
    refreshRequest = new Subject<EmployeeDirectorySyntheticPreview>();
    previewCalls = 0;
    const api = {
      employeeDirectoryStatus: (): Observable<DataEnvelope<EmployeeDirectoryStatus>> => statusRequest,
      employeeDirectorySyntheticPreview: (): Observable<EmployeeDirectorySyntheticPreview> => previewCalls++ === 0 ? previewRequest : refreshRequest,
    };
    TestBed.configureTestingModule({ providers: [IntegrationsDirectoryStore, { provide: IntegrationsService, useValue: api }] });
    store = TestBed.inject(IntegrationsDirectoryStore);
  });

  it('keeps a preview error visible when the slower status request succeeds', () => {
    store.loadStatus();
    store.loadSyntheticPreview();
    previewRequest.error(new Error('synthetic preview unavailable'));
    statusRequest.next({ data: { status: 'dependency_pending', missing_inputs: [], scope_configured: false, writes_enabled: false } });

    expect(store.statusError()).toBe(false);
    expect(store.previewError()).toBe(true);
    expect(store.preview()).toBeNull();
  });

  it('keeps a status error visible when the later synthetic preview succeeds', () => {
    store.loadStatus();
    store.loadSyntheticPreview();
    statusRequest.error(new Error('status unavailable'));
    const preview: EmployeeDirectoryPreview = {
      status: 'preview_only', namespace: 'synthetic-demo', profiles: [], duplicate_count: 0, conflicts: [],
    };
    previewRequest.next({ data: preview, synthetic: true });

    expect(store.statusError()).toBe(true);
    expect(store.previewError()).toBe(false);
    expect(store.preview()).toEqual(preview);
  });

  it('clears the previous preview when a refresh fails', () => {
    const preview: EmployeeDirectoryPreview = {
      status: 'preview_only', namespace: 'synthetic-demo', profiles: [], duplicate_count: 0, conflicts: [],
    };
    store.loadSyntheticPreview();
    previewRequest.next({ data: preview, synthetic: true });
    expect(store.preview()).toEqual(preview);

    store.loadSyntheticPreview();
    expect(store.preview()).toBeNull();
    refreshRequest.error(new Error('preview refresh failed'));

    expect(store.previewError()).toBe(true);
    expect(store.preview()).toBeNull();
  });
});
