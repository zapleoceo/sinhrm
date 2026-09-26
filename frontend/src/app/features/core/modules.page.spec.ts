import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { AuthService } from '../../core/auth/auth.service';
import { ModulesPage } from './modules.page';
import { ModuleSetting } from './modules.service';

const ALL = ['superadmin', 'admin', 'hr_manager', 'recruiter', 'employee', 'viewer'] as const;
const ROWS: ModuleSetting[] = [
  { key: 'users', name_key: 'modules.names.users', icon: 'group', group: 'core', core: true, enabled: true, roles: [...ALL], default_roles: [...ALL] },
  { key: 'pulse', name_key: 'modules.names.pulse', icon: 'poll', group: 'perform', core: false, enabled: true, roles: [...ALL], default_roles: [...ALL] },
];

async function setup(): Promise<{ el: HTMLElement; http: HttpTestingController; detect: () => Promise<void>; reload: ReturnType<typeof vi.fn> }> {
  const reload = vi.fn().mockResolvedValue(undefined);
  TestBed.configureTestingModule({
    imports: [ModulesPage, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
    providers: [provideHttpClient(), provideHttpClientTesting(), { provide: AuthService, useValue: { reload } }],
  });
  const fixture = TestBed.createComponent(ModulesPage);
  const http = TestBed.inject(HttpTestingController);
  const detect = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  fixture.detectChanges();
  http.expectOne('/api/modules').flush({ data: ROWS });
  await detect();
  return { el: fixture.nativeElement as HTMLElement, http, detect, reload };
}

describe('ModulesPage', () => {
  it('locks core modules', async () => {
    const { el } = await setup();
    const rows = el.querySelectorAll('tbody tr');
    expect(rows.length).toBe(2);
    expect(rows[0].querySelector('button[role="switch"]')?.hasAttribute('disabled')).toBe(true);
    expect(rows[1].querySelector('button[role="switch"]')?.hasAttribute('disabled')).toBe(false);
  });

  it('asks for confirmation before switching a module off, then saves', async () => {
    const { el, http, detect, reload } = await setup();
    const row = el.querySelectorAll('tbody tr')[1];
    (row.querySelector('button[role="switch"]') as HTMLButtonElement).click();
    await detect();

    (row.querySelector('.actions button') as HTMLButtonElement).click();
    await detect();
    http.expectNone('/api/modules/pulse');
    expect(row.querySelector('.warn')).not.toBeNull();

    (row.querySelector('.actions button') as HTMLButtonElement).click();
    const req = http.expectOne('/api/modules/pulse');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body.enabled).toBe(false);
    req.flush({ data: { ...ROWS[1], enabled: false } });
    await detect();
    expect(reload).toHaveBeenCalled();
    http.verify();
  });
});
