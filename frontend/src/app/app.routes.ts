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
