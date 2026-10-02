import { provideHttpClient, HttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { DataEnvelope, Paged } from './api.model';
import { unwrapData } from './unwrap-data';

describe('unwrapData', () => {
  it('returns the content of the { data } wrapper', async () => {
    expect(await firstValueFrom(of<DataEnvelope<{ id: number }>>({ data: { id: 7 } }).pipe(unwrapData()))).toEqual({ id: 7 });
  });

  it('works on a real HttpClient answer and keeps a page as is when not unwrapped', async () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const http = TestBed.inject(HttpClient);
    const backend = TestBed.inject(HttpTestingController);

    const one = firstValueFrom(http.get<DataEnvelope<string[]>>('/api/x').pipe(unwrapData()));
    backend.expectOne('/api/x').flush({ data: ['a', 'b'] });
    expect(await one).toEqual(['a', 'b']);

    const page = firstValueFrom(http.get<Paged<string>>('/api/list'));
    const meta = { current_page: 1, per_page: 20, total: 1, last_page: 1 };
    backend.expectOne('/api/list').flush({ data: ['a'], meta });
    expect(await page).toEqual({ data: ['a'], meta });
    backend.verify();
  });
});
