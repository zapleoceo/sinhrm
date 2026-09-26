import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { helpfulPercent, parseTags } from './knowledge.model';
import { KnowledgeService } from './knowledge.service';
import { audienceOf } from './editor.page';

describe('KnowledgeService', () => {
  let api: KnowledgeService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(KnowledgeService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('searches, reads, saves and votes', () => {
    api.search({ q: 'leave', category_id: 2 }).subscribe();
    http.expectOne((r) => r.url === '/api/knowledge/articles' && r.params.get('q') === 'leave' && r.params.get('category_id') === '2' && !r.params.has('tag')).flush({ data: [] });
    api.categories().subscribe();
    http.expectOne('/api/knowledge/categories').flush({ data: [] });
    api.createCategory({ name: 'Office' }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/knowledge/categories' }).flush({ data: {} });
    api.get(3).subscribe();
    http.expectOne('/api/knowledge/articles/3').flush({ data: {} });
    api.save(null, { title: 'T', body_md: 'B' }).subscribe();
    http.expectOne({ method: 'POST', url: '/api/knowledge/articles' }).flush({ data: {} });
    api.save(3, { status: 'published' }).subscribe();
    expect(http.expectOne({ method: 'PATCH', url: '/api/knowledge/articles/3' }).request.body).toEqual({ status: 'published' });
    api.versions(3).subscribe();
    http.expectOne('/api/knowledge/articles/3/versions').flush({ data: [] });
    api.vote(3, true).subscribe();
    expect(http.expectOne({ method: 'POST', url: '/api/knowledge/articles/3/vote' }).request.body).toEqual({ helpful: true });
  });
});

describe('knowledge helpers', () => {
  it('parses tags, computes helpfulness and builds the audience', () => {
    expect(parseTags(' a, b ,, c ')).toEqual(['a', 'b', 'c']);
    expect(helpfulPercent({ helpful: 0, not_helpful: 0 })).toBeNull();
    expect(helpfulPercent({ helpful: 3, not_helpful: 1 })).toBe(75);
    expect(audienceOf('all', [1], ['admin'])).toEqual({ type: 'all' });
    expect(audienceOf('branches', [1, 2], [])).toEqual({ type: 'branches', ids: [1, 2] });
    expect(audienceOf('roles', [], ['recruiter'])).toEqual({ type: 'roles', roles: ['recruiter'] });
  });
});
