import { nextRoles } from './users.model';

describe('nextRoles (users admin, several roles per user)', () => {
  it('saves the ticked roles in the standard order', () => {
    expect(nextRoles(['recruiter'], ['recruiter', 'hr_manager'])).toEqual(['hr_manager', 'recruiter']);
  });

  it('gives and takes superadmin like any other role (HRM-84)', () => {
    expect(nextRoles(['admin'], ['admin', 'superadmin'])).toEqual(['superadmin', 'admin']);
    expect(nextRoles(['superadmin', 'recruiter'], ['recruiter'])).toEqual(['recruiter']);
    expect(nextRoles(['superadmin'], ['superadmin'])).toBeNull();
  });

  it('returns null when nothing changed or nothing is left', () => {
    expect(nextRoles(['admin', 'recruiter'], ['recruiter', 'admin'])).toBeNull();
    expect(nextRoles(['viewer'], [])).toBeNull();
  });
});
