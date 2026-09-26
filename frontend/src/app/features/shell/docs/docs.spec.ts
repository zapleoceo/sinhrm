import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { UserRole } from '../../../core/auth/auth.model';
import { AuthService } from '../../../core/auth/auth.service';
import { DocPage, groupDocs, searchDocs, visibleDocs } from './docs.model';
import { DocsPage } from './docs.page';
import { visibleMap } from './docs-map';

const doc = (slug: string, group: DocPage['group'], audience: DocPage['audience'], text: string, html = `<p>${text}</p>`): DocPage => ({
  slug,
  title: slug.toUpperCase(),
  group,
  audience,
  html,
  text,
});

const DOCS: DocPage[] = [
  doc('pulse', 'perform', 'all', 'Опросы и eNPS, анонимность'),
  doc('integrations', 'admin', 'admin', 'Ключи и токены интеграций'),
  doc('desk', 'services', 'all', 'Обращения в HR', '<p>Desk</p><img src="x" onerror="alert(1)"><script>alert(1)</script><a href="javascript:alert(1)">x</a>'),
];

describe('docs helpers', () => {
  it('hides admin-only pages from employees', () => {
    expect(visibleDocs(DOCS, ['viewer']).map((d) => d.slug)).toEqual(['pulse', 'desk']);
    expect(visibleDocs(DOCS, ['recruiter']).map((d) => d.slug)).toEqual(['pulse', 'desk']);
    expect(visibleDocs(DOCS, ['admin']).map((d) => d.slug)).toEqual(['pulse', 'integrations', 'desk']);
    expect(visibleDocs(DOCS, ['superadmin'])).toHaveLength(3);
    expect(visibleDocs(DOCS, [])).toHaveLength(2);
  });

  it('groups by module group in a fixed order, skipping empty groups', () => {
    expect(groupDocs(DOCS).map((g) => g.group)).toEqual(['perform', 'services', 'admin']);
  });

  it('searches case-insensitively over title and text, all words must match', () => {
    expect(searchDocs(DOCS, '')).toEqual([]);
    expect(searchDocs(DOCS, 'enps').map((h) => h.doc.slug)).toEqual(['pulse']);
    expect(searchDocs(DOCS, 'опросы анонимность').map((h) => h.doc.slug)).toEqual(['pulse']);
    expect(searchDocs(DOCS, 'опросы токены')).toEqual([]);
    expect(searchDocs(DOCS, 'desk').map((h) => h.doc.slug)).toEqual(['desk']);
    expect(searchDocs(DOCS, 'enps')[0].snippet).toContain('eNPS');
  });
});

describe('docs map', () => {
  it('keeps only blocks whose doc page is visible, falling back to the next allowed page', () => {
    const pages = [doc('recruiting', 'recruiting', 'all', 'r'), doc('mail-agent', 'admin', 'admin', 'm'), doc('pulse', 'perform', 'all', 'p')];
    const employee = visibleMap(visibleDocs(pages, ['viewer']));
    expect(employee.sources.map((b) => [b.id, b.slug])).toEqual([['mail', 'recruiting'], ['sheets', 'recruiting'], ['manual', 'recruiting']]);
    expect(employee.helpers).toEqual([]);
    const admin = visibleMap(visibleDocs(pages, ['admin']));
    expect(admin.sources.map((b) => [b.id, b.slug])).toContainEqual(['jobSites', 'mail-agent']);
    expect(admin.people.map((b) => b.id)).toEqual(['pulse']);
  });
});

describe('DocsPage', () => {
  async function setup(roles: UserRole[], slug?: string): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      imports: [DocsPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: { user: signal({ id: 1, name: 'U', email: 'u@e', avatar_url: null, locale: 'uk', roles, status: 'active' }) } },
      ],
    });
    const fixture = TestBed.createComponent(DocsPage);
    if (slug) fixture.componentRef.setInput('slug', slug);
    fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/docs/index.json').flush(DOCS);
    fixture.detectChanges();
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  it('lists only the pages the role may see', async () => {
    const el = await setup(['viewer']);
    const links = Array.from(el.querySelectorAll('.toc a')).map((a) => a.textContent?.trim());
    expect(links).toEqual(['PULSE', 'DESK']);
  });

  it('shows admin pages to admins', async () => {
    const el = await setup(['admin']);
    expect(el.querySelectorAll('.toc a')).toHaveLength(3);
  });

  it('does not open an admin page by direct link for an employee', async () => {
    const el = await setup(['viewer'], 'integrations');
    expect(el.querySelector('.doc-body')).toBeNull();
    expect(el.textContent).toContain('docs.notFound');
  });

  it('renders a page with scripts, handlers and javascript: links stripped', async () => {
    const el = await setup(['viewer'], 'desk');
    const body = el.querySelector('.doc-body') as HTMLElement;
    expect(body.textContent).toContain('Desk');
    expect(body.querySelector('script')).toBeNull();
    expect(body.innerHTML).not.toContain('onerror');
    expect(body.querySelector('a')?.getAttribute('href')).toMatch(/^unsafe:/); // Angular neutralizes javascript: URLs
  });

  it('shows the overview map when no section is selected', async () => {
    const el = await setup(['viewer']);
    expect(el.querySelector('app-docs-overview')).not.toBeNull();
    expect(el.textContent).toContain('docs.map.intro');
    expect(el.querySelector('.crumbs')).toBeNull();
  });

  it('hides the overview and shows a way back when a section is open', async () => {
    const el = await setup(['viewer'], 'desk');
    expect(el.querySelector('app-docs-overview')).toBeNull();
    expect(el.querySelector('.crumbs a')?.getAttribute('href')).toBe('/docs');
  });

  it('links each map block to its doc section', async () => {
    const el = await setup(['viewer']);
    expect(el.querySelector('[data-block="pulse"]')?.getAttribute('href')).toBe('/docs/pulse');
    expect(el.querySelector('[data-block="desk"]')?.getAttribute('href')).toBe('/docs/desk');
  });

  it('hides map blocks of modules the role cannot see', async () => {
    const viewer = await setup(['viewer']);
    expect(viewer.querySelector('[data-block="integrations"]')).toBeNull();
    expect(viewer.querySelector('[data-zone="helpers"]')).toBeNull();
    TestBed.resetTestingModule();
    const admin = await setup(['admin']);
    expect(admin.querySelector('[data-block="integrations"]')?.getAttribute('href')).toBe('/docs/integrations');
  });

  it('filters the list by search', async () => {
    const el = await setup(['viewer']);
    const input = el.querySelector('input') as HTMLInputElement;
    input.value = 'enps';
    input.dispatchEvent(new Event('input'));
    TestBed.tick();
    expect(Array.from(el.querySelectorAll('.hits a')).map((a) => a.textContent?.trim())).toEqual(['PULSE']);
  });
});
