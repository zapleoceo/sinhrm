import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/auth/auth.guards';

// `title` is an i18n key (TranslatedTitleStrategy → "SinHRM · <page>").
export const routes: Routes = [
  {
    path: 'login',
    title: 'titles.login',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/login.page').then((m) => m.LoginPage),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./features/shell/shell.layout').then((m) => m.ShellLayout),
    children: [
      { path: '', title: 'titles.overview', loadComponent: () => import('./features/overview/dashboard.page').then((m) => m.DashboardPage) },
      // Recruiting: every active role; the API scopes data by branch and blocks viewers from writing.
      { path: 'vacancies', title: 'titles.vacancies', loadComponent: () => import('./features/recruiting/vacancies/vacancies.page').then((m) => m.VacanciesPage) },
      { path: 'vacancies/:id', title: 'titles.board', loadComponent: () => import('./features/recruiting/board/board.page').then((m) => m.BoardPage) },
      { path: 'candidates', title: 'titles.candidates', loadComponent: () => import('./features/recruiting/candidates/candidates.page').then((m) => m.CandidatesPage) },
      { path: 'candidates/:id', title: 'titles.candidates', loadComponent: () => import('./features/recruiting/candidates/candidates.page').then((m) => m.CandidatesPage) },
      { path: 'inbox', title: 'titles.inbox', loadComponent: () => import('./features/recruiting/inbox/inbox.page').then((m) => m.InboxPage) },
      { path: 'settings/extension', title: 'titles.extension', loadComponent: () => import('./features/extension/extension.page').then((m) => m.ExtensionPage) },
      { path: 'reports', title: 'titles.reports', loadComponent: () => import('./features/recruiting/reports/reports.page').then((m) => m.ReportsPage) },
      {
        path: 'status',
        title: 'titles.status',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/core/status.page').then((m) => m.StatusPage),
      },
      {
        path: 'admin/scripts',
        title: 'titles.scripts',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/scripts/list/scripts.page').then((m) => m.ScriptsPage),
      },
      {
        path: 'admin/scripts/:id',
        title: 'titles.scriptEditor',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/scripts/editor/script-editor.page').then((m) => m.ScriptEditorPage),
      },
      {
        path: 'admin/users',
        title: 'titles.users',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/users/users.page').then((m) => m.UsersPage),
      },
      {
        path: 'admin/directory',
        title: 'titles.directory',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/directory/directory.page').then((m) => m.DirectoryPage),
      },
      {
        path: 'admin/mail',
        title: 'titles.mail',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/mail-agent/mail.page').then((m) => m.MailPage),
      },
      {
        path: 'admin/sheets-import',
        title: 'titles.sheetsImport',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/google-workspace/sheets-import.page').then((m) => m.SheetsImportPage),
      },
      {
        path: 'admin/integrations',
        title: 'titles.integrations',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/integrations/integrations.page').then((m) => m.IntegrationsPage),
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
