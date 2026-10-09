import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { firstValueFrom, of } from 'rxjs';
import { USER_ROLES } from '../../core/auth/auth.model';
import { ConfirmDialog } from '../workflows/confirm.dialog';
import { SuperadminConfirm, superadminChange } from './superadmin-confirm';
import en from '../../../../public/i18n/en.json';
import ru from '../../../../public/i18n/ru.json';
import uk from '../../../../public/i18n/uk.json';

describe('superadminChange (HRM-84)', () => {
  it('tells a grant from a revoke and ignores other role changes', () => {
    expect(superadminChange(['admin'], ['superadmin', 'admin'])).toBe('grant');
    expect(superadminChange(['superadmin', 'recruiter'], ['recruiter'])).toBe('revoke');
    expect(superadminChange(['superadmin'], ['superadmin', 'viewer'])).toBeNull();
    expect(superadminChange(['viewer'], ['hr_manager'])).toBeNull();
  });

  it('superadmin is offered among the global roles', () => {
    expect(USER_ROLES).toContain('superadmin');
  });
});

describe('SuperadminConfirm', () => {
  const setup = (answer: { reason: string } | null) => {
    const open = vi.fn().mockReturnValue({ afterClosed: () => of(answer) });
    TestBed.configureTestingModule({
      providers: [
        { provide: MatDialog, useValue: { open } },
        { provide: TranslocoService, useValue: { translate: (key: string, p?: { name?: string }) => (p?.name ? `${key}|${p.name}` : key) } },
      ],
    });
    return { open, confirm: TestBed.inject(SuperadminConfirm) };
  };

  it('warns with the grant texts and the person name; true only when confirmed', async () => {
    const { open, confirm } = setup({ reason: '' });

    expect(await firstValueFrom(confirm.ask('grant', 'Ann'))).toBe(true);
    expect(open).toHaveBeenCalledWith(ConfirmDialog, {
      data: { message: 'users.superadmin.grant.body|Ann', confirm: 'users.superadmin.grant.confirm|Ann', cancel: 'common.cancel' },
      ariaLabel: 'users.superadmin.grant.title|Ann',
    });
  });

  it('cancel = false (nothing is saved)', async () => {
    const { open, confirm } = setup(null);

    expect(await firstValueFrom(confirm.ask('revoke', 'Bob'))).toBe(false);
    expect(open.mock.calls[0][1].data.message).toBe('users.superadmin.revoke.body|Bob');
  });

  it('has the warning texts and the error in ru, uk and en', () => {
    for (const lang of [en, ru, uk]) {
      for (const change of ['grant', 'revoke'] as const) {
        const t = lang.users.superadmin[change];
        expect(t.title && t.confirm).toBeTruthy();
        expect(t.body).toContain('{{name}}');
      }
      expect(lang.users.errors.superadmin_forbidden).toBeTruthy();
      expect('superadmin_not_assignable' in lang.users.errors).toBe(false);
    }
  });
});
