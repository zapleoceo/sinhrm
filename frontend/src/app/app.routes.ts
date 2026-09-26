import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/auth/auth.guards';
import { HR_STAFF_ROLES } from './core/auth/auth.model';

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
      // Hiring requests (tz2): every active role; the API decides who creates, sees and approves.
      { path: 'hiring-requests', title: 'titles.hiringRequests', loadComponent: () => import('./features/hiring-requests/hiring-list.page').then((m) => m.HiringListPage) },
      {
        path: 'hiring-requests/inbox',
        title: 'titles.hiringInbox',
        data: { view: 'inbox' },
        loadComponent: () => import('./features/hiring-requests/hiring-list.page').then((m) => m.HiringListPage),
      },
      { path: 'hiring-requests/new', title: 'titles.hiringNew', loadComponent: () => import('./features/hiring-requests/hiring-wizard.page').then((m) => m.HiringWizardPage) },
      { path: 'hiring-requests/:id', title: 'titles.hiringRequest', loadComponent: () => import('./features/hiring-requests/hiring-detail.page').then((m) => m.HiringDetailPage) },
      { path: 'hiring-requests/:id/edit', title: 'titles.hiringNew', loadComponent: () => import('./features/hiring-requests/hiring-wizard.page').then((m) => m.HiringWizardPage) },
      // Time: own week for everyone; approvals and team for managers (the API scopes by People).
      { path: 'time', title: 'titles.time', loadComponent: () => import('./features/time/my-week.page').then((m) => m.MyWeekPage) },
      { path: 'time/approvals', title: 'titles.timeApprovals', loadComponent: () => import('./features/time/time-approvals.page').then((m) => m.TimeApprovalsPage) },
      { path: 'time/team', title: 'titles.timeTeam', loadComponent: () => import('./features/time/time-team.page').then((m) => m.TimeTeamPage) },
      { path: 'reports', title: 'titles.reports', loadComponent: () => import('./features/recruiting/reports/reports.page').then((m) => m.ReportsPage) },
      // People and time off: every active role; the API decides what each user sees (directory / job / PII tiers).
      { path: 'people', title: 'titles.people', loadComponent: () => import('./features/people/directory/people.page').then((m) => m.PeoplePage) },
      { path: 'people/org-chart', title: 'titles.orgChart', loadComponent: () => import('./features/people/org-chart/org-chart.page').then((m) => m.OrgChartPage) },
      { path: 'people/:id', title: 'titles.profile', loadComponent: () => import('./features/people/profile/profile.page').then((m) => m.ProfilePage) },
      { path: 'me', title: 'titles.myProfile', loadComponent: () => import('./features/people/profile/profile.page').then((m) => m.ProfilePage) },
      { path: 'timeoff', title: 'titles.timeoff', loadComponent: () => import('./features/timeoff/my/my-timeoff.page').then((m) => m.MyTimeOffPage) },
      { path: 'timeoff/calendar', title: 'titles.teamCalendar', loadComponent: () => import('./features/timeoff/calendar/calendar.page').then((m) => m.CalendarPage) },
      // Unified tasks, own documents and the workflow board: every active role; the API scopes the data.
      { path: 'tasks', title: 'titles.tasks', loadComponent: () => import('./features/tasks/my-tasks.page').then((m) => m.MyTasksPage) },
      { path: 'me/documents', title: 'titles.myDocuments', loadComponent: () => import('./features/documents/my/my-documents.page').then((m) => m.MyDocumentsPage) },
      { path: 'workflows/runs', title: 'titles.workflowRuns', loadComponent: () => import('./features/workflows/runs/workflow-runs.page').then((m) => m.WorkflowRunsPage) },
      // Perform and Pulse: every active role; the API scopes the data (admin all, managers their people, own items).
      { path: 'perform/one-on-ones', title: 'titles.oneOnOnes', loadComponent: () => import('./features/perform/one-on-ones/one-on-ones.page').then((m) => m.OneOnOnesPage) },
      { path: 'perform/objectives', title: 'titles.objectives', loadComponent: () => import('./features/perform/objectives/objectives.page').then((m) => m.ObjectivesPage) },
      { path: 'perform/feedback', title: 'titles.feedback', loadComponent: () => import('./features/perform/feedback/feedback.page').then((m) => m.FeedbackPage) },
      { path: 'perform/reviews', title: 'titles.myReviews', loadComponent: () => import('./features/perform/reviews/my-reviews.page').then((m) => m.MyReviewsPage) },
      { path: 'pulse', title: 'titles.mySurveys', loadComponent: () => import('./features/pulse/my/my-surveys.page').then((m) => m.MySurveysPage) },
      { path: 'pulse/mood', title: 'titles.mood', loadComponent: () => import('./features/pulse/mood/mood.page').then((m) => m.MoodPage) },
      { path: 'pulse/waves/:id', title: 'titles.respond', loadComponent: () => import('./features/pulse/respond/respond.page').then((m) => m.RespondPage) },
      { path: 'pulse/waves/:id/results', title: 'titles.surveyResults', loadComponent: () => import('./features/pulse/results/wave-results.page').then((m) => m.WaveResultsPage) },
      // Desk, Safe Speak, Knowledge, Reports: every active role; the API decides what each user sees.
      { path: 'desk', title: 'titles.desk', loadComponent: () => import('./features/desk/my-cases.page').then((m) => m.MyCasesPage) },
      { path: 'desk/cases/:id', title: 'titles.deskCase', loadComponent: () => import('./features/desk/case.page').then((m) => m.CasePage) },
      { path: 'safe-speak', title: 'titles.safeSpeak', loadComponent: () => import('./features/safe-speak/report.page').then((m) => m.SafeSpeakPage) },
      { path: 'knowledge', title: 'titles.knowledge', loadComponent: () => import('./features/knowledge/knowledge.page').then((m) => m.KnowledgePage) },
      { path: 'knowledge/:id', title: 'titles.article', loadComponent: () => import('./features/knowledge/article.page').then((m) => m.ArticlePage) },
      { path: 'reports/catalog', title: 'titles.reportCatalog', loadComponent: () => import('./features/reports/catalog.page').then((m) => m.ReportCatalogPage) },
      { path: 'reports/catalog/:key', title: 'titles.reportCatalog', loadComponent: () => import('./features/reports/report-view.page').then((m) => m.ReportViewPage) },
      { path: 'reports/builder', title: 'titles.reportBuilder', loadComponent: () => import('./features/reports/builder.page').then((m) => m.ReportBuilderPage) },
      { path: 'timeoff/approvals', title: 'titles.approvals', loadComponent: () => import('./features/timeoff/approvals/approvals.page').then((m) => m.ApprovalsPage) },
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
        path: 'admin/workflows',
        title: 'titles.workflows',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/workflows/templates/workflow-templates.page').then((m) => m.WorkflowTemplatesPage),
      },
      {
        path: 'admin/workflows/:id',
        title: 'titles.workflowEditor',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/workflows/editor/workflow-editor.page').then((m) => m.WorkflowEditorPage),
      },
      {
        path: 'admin/perform/reviews',
        title: 'titles.reviewSetup',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/perform/reviews/review-admin.page').then((m) => m.ReviewAdminPage),
      },
      {
        path: 'admin/pulse',
        title: 'titles.surveys',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/pulse/surveys/surveys.page').then((m) => m.SurveysPage),
      },
      {
        path: 'admin/documents/templates',
        title: 'titles.documentTemplates',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/documents/templates/document-templates.page').then((m) => m.DocumentTemplatesPage),
      },
      {
        path: 'admin/hiring-requests',
        title: 'titles.hiringSettings',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/hiring-requests/hiring-settings.page').then((m) => m.HiringSettingsPage),
      },
      {
        path: 'admin/acquisition-channels',
        title: 'titles.acquisitionChannels',
        canActivate: [roleGuard('superadmin', 'admin')],
        loadComponent: () => import('./features/recruiting/channels/acquisition-channels.page').then((m) => m.AcquisitionChannelsPage),
      },
      {
        path: 'admin/time',
        title: 'titles.timeSchedules',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/time/time-schedules.page').then((m) => m.TimeSchedulesPage),
      },
      {
        path: 'desk/queue',
        title: 'titles.deskQueue',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/desk/queue.page').then((m) => m.DeskQueuePage),
      },
      {
        // Admins only; the API additionally requires the explicit Safe Speak handler flag.
        path: 'safe-speak/inbox',
        title: 'titles.safeSpeakInbox',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/safe-speak/inbox.page').then((m) => m.SafeSpeakInboxPage),
      },
      {
        path: 'admin/knowledge/:id',
        title: 'titles.articleEditor',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/knowledge/editor.page').then((m) => m.KnowledgeEditorPage),
      },
      {
        path: 'admin/assets',
        title: 'titles.assets',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/assets/assets.page').then((m) => m.AssetsPage),
      },
      {
        path: 'admin/users',
        title: 'titles.users',
        canActivate: [roleGuard('superadmin')],
        loadComponent: () => import('./features/users/users.page').then((m) => m.UsersPage),
      },
      {
        path: 'admin/timeoff',
        title: 'titles.timeoffSettings',
        canActivate: [roleGuard(...HR_STAFF_ROLES)],
        loadComponent: () => import('./features/timeoff/settings/timeoff-settings.page').then((m) => m.TimeOffSettingsPage),
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
