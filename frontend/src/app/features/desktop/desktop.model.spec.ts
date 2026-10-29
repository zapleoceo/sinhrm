import { appAllowed, DESKTOP_APPS, DEFAULT_DESKTOP_LAYOUT, parseDesktopLayout, widgetAllowed } from './desktop.model';
import { CurrentUser } from '../../core/auth/auth.model';

const user = (roles: CurrentUser['roles'], modules?: string[]): Pick<CurrentUser, 'roles' | 'modules'> => ({ roles, modules });

describe('desktop registry permissions', () => {
  it('keeps Overview at its canonical route after Desktop takes the root path', () => {
    expect(DESKTOP_APPS.find((app) => app.id === 'overview')?.route).toBe('/overview');
  });

  it('matches the effective role and module visibility used by the signed-in navigation', () => {
    const recruiter = user(['recruiter'], ['recruiting', 'hiring-requests']);
    expect(DESKTOP_APPS.filter((app) => appAllowed(app, recruiter)).map((app) => app.id)).toContain('vacancies');
    expect(DESKTOP_APPS.filter((app) => appAllowed(app, recruiter)).map((app) => app.id)).not.toContain('people');
    expect(DESKTOP_APPS.filter((app) => appAllowed(app, recruiter)).map((app) => app.id)).not.toContain('users');
    expect(appAllowed(DESKTOP_APPS.find((app) => app.id === 'desk-queue')!, user(['hr_manager'], ['desk']))).toBe(true);
    expect(appAllowed(DESKTOP_APPS.find((app) => app.id === 'desk-queue')!, user(['employee'], ['desk']))).toBe(false);
    expect(appAllowed(DESKTOP_APPS.find((app) => app.id === 'users')!, user(['admin']))).toBe(false);
    expect(appAllowed(DESKTOP_APPS.find((app) => app.id === 'users')!, user(['superadmin']))).toBe(true);
  });

  it('limits approval widgets to users with a relevant enabled module', () => {
    expect(widgetAllowed('approvals', user(['employee'], ['people']))).toBe(false);
    expect(widgetAllowed('approvals', user(['employee'], ['time-off']))).toBe(true);
    expect(widgetAllowed('summary', user(['employee'], ['people']))).toBe(false);
    expect(widgetAllowed('summary', user(['recruiter'], ['recruiting']))).toBe(true);
    expect(widgetAllowed('tasks', null)).toBe(false);
  });
});

describe('desktop layout validation', () => {
  it('accepts only the versioned bounded cosmetic widget contract', () => {
    expect(parseDesktopLayout(DEFAULT_DESKTOP_LAYOUT)).toEqual(DEFAULT_DESKTOP_LAYOUT);
    expect(parseDesktopLayout({ version: 1, widgets: [] })).toEqual({ version: 1, widgets: [] });
    expect(parseDesktopLayout({ version: 2, widgets: [] })).toBeNull();
    expect(parseDesktopLayout({ version: '1', widgets: [] })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: [{ id: 'summary', size: 'xl' }] })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: [{ id: 'summary', size: 'm' }, { id: 'summary', size: 's' }] })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: [{ id: 'unknown', size: 'm' }] })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: [{ id: 'summary', size: 'm', data: { count: 2 } }] })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: new Array(13).fill({ id: 'summary', size: 'm' }) })).toBeNull();
    expect(parseDesktopLayout({ version: 1, widgets: [], owner: 7 })).toBeNull();
  });
});
