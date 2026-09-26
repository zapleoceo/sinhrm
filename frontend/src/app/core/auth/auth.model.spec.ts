import { HR_STAFF_ROLES, INVITABLE_ROLES, USER_ROLES, isHrStaff } from './auth.model';

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
});
