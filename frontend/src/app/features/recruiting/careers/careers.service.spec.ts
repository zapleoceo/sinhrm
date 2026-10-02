import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PublicCareersService, PublicVacancy } from './careers.service';

describe('PublicCareersService', () => {
  let api: PublicCareersService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(PublicCareersService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('lists published vacancies and reads one by an encoded slug', () => {
    const v: PublicVacancy = { slug: 'sales manager', title: 'Sales' };
    let list: PublicVacancy[] = [];
    api.vacancies().subscribe((l) => (list = l));
    http.expectOne({ method: 'GET', url: '/api/public/vacancies' }).flush({ data: [v] });
    expect(list).toEqual([v]);

    let one: PublicVacancy | undefined;
    api.vacancy('sales manager').subscribe((x) => (one = x));
    http.expectOne({ method: 'GET', url: '/api/public/vacancies/sales%20manager' }).flush({ data: v });
    expect(one).toEqual(v);
  });

  it('applies with the multipart body as is', () => {
    const body = new FormData();
    body.append('name', 'Ann');
    api.apply('sales', body).subscribe();
    const req = http.expectOne({ method: 'POST', url: '/api/public/vacancies/sales/apply' });
    expect(req.request.body).toBe(body);
    req.flush({ ok: true });
  });
});
