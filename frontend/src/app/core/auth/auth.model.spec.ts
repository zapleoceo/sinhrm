import { ADMIN_ROLES, HR_STAFF_ROLES, INVITABLE_ROLES, SUPERADMIN_ROLE, USER_ROLES, isAdmin, isHrStaff } from './auth.model';

describe('auth.model roles', () => {
  it('lists the standard global roles (mirrors backend UserRole)', () => {
    expect(USER_ROLES).toEqual(['superadmin', 'admin', 'hr_manager', 'recruiter', 'employee', 'viewer']);
    expect(INVITABLE_ROLES).not.toContain('superadmin');
    expect(HR_STAFF_ROLES).toEqual(['superadmin', 'admin', 'hr_manager']);
  });

  it('knows who acts as HR', () => {
    expect(isHrStaff(['hr_manager'])).toBe(true);
    expect(isHrStaff(['employee', 'viewer', 'recruiter'])).toBe(false);
    expect(isHrStaff([])).toBe(false);
  });

  it('knows the administration level (superadmin + admin) and nothing below it', () => {
    expect(ADMIN_ROLES).toEqual(['superadmin', 'admin']);
    expect(SUPERADMIN_ROLE).toBe('superadmin');
    expect(isAdmin(['admin'])).toBe(true);
    expect(isAdmin(['employee', 'superadmin'])).toBe(true);
    expect(isAdmin(['hr_manager', 'recruiter', 'employee', 'viewer'])).toBe(false);
    expect(isAdmin([])).toBe(false);
  });
});
