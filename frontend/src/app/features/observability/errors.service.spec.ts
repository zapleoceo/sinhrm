import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ErrorGroup, ErrorsService } from './errors.service';

describe('ErrorsService', () => {
  it('lists groups by status and resolves one, unwrapping { data }', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const api = TestBed.inject(ErrorsService);
    const http = TestBed.inject(HttpTestingController);
    const group = { id: 4 } as ErrorGroup;

    let list: ErrorGroup[] | undefined;
    api.list('open').subscribe((r) => (list = r));
    const get = http.expectOne((r) => r.method === 'GET' && r.params.get('status') === 'open');
    get.flush({ data: [group] });
    expect(list).toEqual([group]);

    let saved: ErrorGroup | undefined;
    api.setResolved(4, true).subscribe((r) => (saved = r));
    const patch = http.expectOne((r) => r.method === 'PATCH' && r.url.endsWith('/4'));
    expect(patch.request.body).toEqual({ resolved: true });
    patch.flush({ data: group });
    expect(saved).toEqual(group);
    http.verify();
  });
});
