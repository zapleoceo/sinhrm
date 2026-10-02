import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { JobPage } from './careers';
import { PublicVacancy } from './careers.service';

function render(data: PublicVacancy): HTMLElement {
  TestBed.configureTestingModule({
    imports: [JobPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
  });
  const fixture = TestBed.createComponent(JobPage);
  fixture.componentRef.setInput('slug', 'sales');
  fixture.detectChanges();
  TestBed.inject(HttpTestingController).expectOne('/api/public/vacancies/sales').flush({ data });
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('JobPage (public /jobs/:slug)', () => {
  it('renders the sections through the sanitizer: <script> and onerror are stripped', () => {
    const el = render({
      slug: 'sales',
      title: 'Sales',
      requirements_html: '<ul><li><strong>CRM</strong></li></ul><script>alert(1)</script><img src="x" onerror="alert(2)">',
      responsibilities_html: '<p>Calls</p>',
      additional_info_html: null,
    });

    const req = el.querySelector('[data-testid="requirements_html"]') as HTMLElement;
    expect(req.querySelector('strong')?.textContent).toBe('CRM');
    expect(req.innerHTML).not.toContain('<script');
    expect(req.innerHTML).not.toContain('onerror');
    expect(req.querySelector('img')?.getAttribute('onerror')).toBeNull();
    expect(el.querySelector('[data-testid="responsibilities_html"]')?.textContent).toBe('Calls');
    expect(el.querySelector('[data-testid="additional_info_html"]')).toBeNull();
  });

  it('shows the salary only when the API sends it, plus city / employment / format chips', () => {
    const withSalary = render({
      slug: 'sales',
      title: 'Sales',
      city: 'Kyiv',
      employment_type: 'full_time',
      work_format: 'remote',
      salary: { min: 1000, max: 2000, currency: 'EUR' },
    });
    expect(withSalary.querySelector('[data-testid="job-salary"]')?.textContent).toContain('EUR');
    expect(withSalary.querySelectorAll('[data-testid="job-chips"] li').length).toBe(3);

    TestBed.resetTestingModule();
    const hidden = render({ slug: 'sales', title: 'Sales', salary: null });
    expect(hidden.querySelector('[data-testid="job-salary"]')).toBeNull();
    expect(hidden.querySelector('[data-testid="job-chips"]')).toBeNull();
  });
});
