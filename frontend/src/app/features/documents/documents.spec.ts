import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { fileSize, insertVariable, isEditable } from './documents.model';
import { DocumentsService, documentsErrorKey, unknownVariables } from './documents.service';
import { createBody } from './profile/document-create.dialog';

describe('DocumentsService', () => {
  let api: DocumentsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(DocumentsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('manages templates and previews', () => {
    api.templates().subscribe();
    http.expectOne((r) => r.url === '/api/documents/templates' && !r.params.has('archived')).flush({ data: [] });
    api.templates(true).subscribe();
    http.expectOne((r) => r.url === '/api/documents/templates' && r.params.get('archived') === '1').flush({ data: [] });

    api.createTemplate({ name: 'Order', body: 'Hi {ПІБ}', category: null }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents/templates' }).request.body).toEqual({ name: 'Order', body: 'Hi {ПІБ}', category: null });
    api.updateTemplate(2, { archived: true }).subscribe();
    expect(http.expectOne({ method: 'PATCH', url: '/api/documents/templates/2' }).request.body).toEqual({ archived: true });

    api.preview('Hi').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents/templates/preview' }).request.body).toEqual({ body: 'Hi' });
    api.preview('Hi', 5).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents/templates/preview' }).request.body).toEqual({ body: 'Hi', employee_id: 5 });
  });

  it('lists, creates and moves documents through their lifecycle', () => {
    api.list({ employee_id: 5, status: 'sent' }).subscribe();
    http.expectOne((r) => r.url === '/api/documents' && r.params.get('employee_id') === '5' && r.params.get('status') === 'sent').flush({ data: [] });
    api.mine().subscribe();
    http.expectOne('/api/me/documents').flush({ data: [] });
    api.get(9).subscribe();
    http.expectOne('/api/documents/9').flush({ data: {} });
    expect(api.fileUrl(9)).toBe('/api/documents/9/file');

    api.create({ employee_id: 5, template_id: 2 }).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents' }).request.body).toEqual({ employee_id: 5, template_id: 2 });
    api.update(9, { status: 'archived' }).subscribe();
    expect(http.expectOne({ method: 'PATCH', url: '/api/documents/9' }).request.body).toEqual({ status: 'archived' });

    api.upload(9, new File(['x'], 'a.pdf', { type: 'application/pdf' })).subscribe();
    const upload = http.expectOne({ method: 'POST', url: '/api/documents/9/file' });
    expect(upload.request.body instanceof FormData && upload.request.body.has('file')).toBe(true);

    api.send(9).subscribe();
    http.expectOne({ method: 'POST', url: '/api/documents/9/send' }).flush({ data: {} });
    api.acknowledge(9).subscribe();
    http.expectOne({ method: 'POST', url: '/api/documents/9/acknowledge' }).flush({ data: {} });
    api.reject(9, 'typo').subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents/9/reject' }).request.body).toEqual({ reason: 'typo' });
    api.reject(9, null).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/documents/9/reject' }).request.body).toEqual({});
  });

  it('maps errors to i18n keys and reads unknown variables', () => {
    const unknown = new HttpErrorResponse({ status: 422, error: { code: 'unknown_variables', variables: ['Зарплата', 3] } });
    expect(documentsErrorKey(unknown)).toBe('documents.errors.unknown_variables');
    expect(unknownVariables(unknown)).toEqual(['Зарплата']);
    expect(documentsErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'already_signed' } }))).toBe('documents.errors.already_signed');
    expect(documentsErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('documents.errors.forbidden');
    expect(documentsErrorKey(new HttpErrorResponse({ status: 422, error: {} }))).toBe('documents.errors.validation');
    expect(documentsErrorKey(new Error('x'))).toBe('common.error');
    expect(unknownVariables(new Error('x'))).toEqual([]);
  });
});

describe('document helpers', () => {
  it('inserts a variable at the caret or over the selection', () => {
    expect(insertVariable('Hello !', 6, 6, 'ПІБ')).toEqual({ text: 'Hello {ПІБ}!', caret: 11 });
    expect(insertVariable('Hello NAME', 6, 10, "Ім'я")).toEqual({ text: "Hello {Ім'я}", caret: 12 });
    expect(insertVariable('ab', 99, 99, 'X')).toEqual({ text: 'ab{X}', caret: 5 });
  });

  it('formats sizes and knows editable statuses', () => {
    expect(fileSize(512)).toBe('512 B');
    expect(fileSize(12 * 1024)).toBe('12 KB');
    expect(fileSize(1.5 * 1024 * 1024)).toBe('1.5 MB');
    expect(isEditable({ status: 'draft' })).toBe(true);
    expect(isEditable({ status: 'rejected' })).toBe(true);
    expect(isEditable({ status: 'sent' })).toBe(false);
  });

  it('builds the create body per mode', () => {
    const base = { template_id: null, title: '', category: '', content_md: '' };
    expect(createBody(5, { ...base, mode: 'template' })).toBeNull();
    expect(createBody(5, { ...base, mode: 'template', template_id: 2, title: ' Order ' })).toEqual({ employee_id: 5, template_id: 2, title: 'Order' });
    expect(createBody(5, { ...base, mode: 'manual' })).toBeNull();
    expect(createBody(5, { ...base, mode: 'manual', title: 'Memo', category: 'HR', content_md: '# Hi', template_id: 2 })).toEqual({
      employee_id: 5,
      title: 'Memo',
      category: 'HR',
      content_md: '# Hi',
    });
  });
});
