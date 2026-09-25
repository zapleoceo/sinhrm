import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/auth/auth.guards';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/login.page').then((m) => m.LoginPage),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./features/shell/shell.layout').then((m) => m.ShellLayout),
    children: [
      { path: '', loadComponent: () => import('./features/core/status.page').then((m) => m.StatusPage) },
      // Recruiting: every active role; the API scopes data by branch and blocks viewers from writing.
      { path: 'vacancies', loadComponent: () => import('./features/recruiting/vacancies/vacancies.page').then((m) => m.VacanciesPage) },
      { path: 'vacancies/:id', loadComponent: () => import('./features/recruiting/board/board.page').then((m) => m.BoardPage) },
      { path: 'candidates', loadComponent: () => import('./features/recruiting/candidates/candidates.page').then((m) => m.CandidatesPage) },
      { path: 'candidates/:id', loadComponent: () => import('./features/recruiting/candidates/candidates.page').then((m) => m.CandidatesPage) },
      { path: 'inbox', loadComponent: () => import('./features/recruiting/inbox/inbox.page').then((m) => m.InboxPage) },
      { path: 'reports', loadComponent: () => import('./features/recruiting/reports/reports.page').then((m) => m.ReportsPage) },
      {
        path: 'admin/users',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/users/users.page').then((m) => m.UsersPage),
      },
      {
        path: 'admin/directory',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/directory/directory.page').then((m) => m.DirectoryPage),
      },
      {
        path: 'admin/integrations',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/integrations/integrations.page').then((m) => m.IntegrationsPage),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
