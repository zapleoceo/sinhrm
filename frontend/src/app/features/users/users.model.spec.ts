import { nextRoles } from './users.model';

describe('nextRoles (users admin, several roles per user)', () => {
  it('saves the ticked roles in the standard order', () => {
    expect(nextRoles(['recruiter'], ['recruiter', 'hr_manager'])).toEqual(['hr_manager', 'recruiter']);
  });

  it('keeps superadmin on a user who has it and never gives it', () => {
    expect(nextRoles(['superadmin'], ['recruiter'])).toEqual(['superadmin', 'recruiter']);
    expect(nextRoles(['admin'], ['admin', 'superadmin'])).toBeNull();
  });

  it('returns null when nothing changed or nothing is left', () => {
    expect(nextRoles(['admin', 'recruiter'], ['recruiter', 'admin'])).toBeNull();
    expect(nextRoles(['viewer'], [])).toBeNull();
  });
});
