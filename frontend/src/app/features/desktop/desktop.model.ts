import { ADMIN_ROLES, HR_STAFF_ROLES, UserRole } from '../../core/auth/auth.model';

export const DESKTOP_WIDGET_IDS = ['summary', 'tasks', 'today', 'approvals'] as const;
export type DesktopWidgetId = (typeof DESKTOP_WIDGET_IDS)[number];
export type DesktopWidgetSize = 's' | 'm' | 'l';

export interface DesktopWidget {
  id: DesktopWidgetId;
  size: DesktopWidgetSize;
}

export interface DesktopLayout {
  version: 1;
  widgets: DesktopWidget[];
}

export type DesktopGroupId = 'personal' | 'recruiting' | 'people' | 'performance' | 'services' | 'admin';

export interface DesktopAppEntry {
  id: string;
  group: DesktopGroupId;
  label: string;
  icon: string;
  route: string;
  module: string | null;
  role?: 'hr' | 'admin' | 'superadmin';
}

/** Real routes and labels from the signed-in shell navigation; guards remain the access authority. */
export const DESKTOP_APPS: readonly DesktopAppEntry[] = [
  { id: 'overview', group: 'personal', label: 'shell.nav.overview', icon: 'dashboard', route: '/', module: null },
  { id: 'tasks', group: 'personal', label: 'shell.nav.tasks', icon: 'task_alt', route: '/tasks', module: null },
  { id: 'docs', group: 'personal', label: 'shell.nav.docs', icon: 'help_outline', route: '/docs', module: null },
  { id: 'profile', group: 'personal', label: 'shell.menu.myProfile', icon: 'badge', route: '/me', module: 'people' },
  { id: 'extension', group: 'personal', label: 'shell.menu.extension', icon: 'extension', route: '/settings/extension', module: 'recruiting' },
  { id: 'candidates', group: 'recruiting', label: 'shell.nav.candidates', icon: 'person_search', route: '/candidates', module: 'recruiting' },
  { id: 'vacancies', group: 'recruiting', label: 'shell.nav.vacancies', icon: 'work', route: '/vacancies', module: 'recruiting' },
  { id: 'inbox', group: 'recruiting', label: 'shell.nav.inbox', icon: 'inbox', route: '/inbox', module: 'recruiting' },
  { id: 'hiring-requests', group: 'recruiting', label: 'shell.nav.hiringRequests', icon: 'assignment_add', route: '/hiring-requests', module: 'hiring-requests' },
  { id: 'recruiting-reports', group: 'recruiting', label: 'shell.nav.reports', icon: 'bar_chart', route: '/reports', module: 'recruiting' },
  { id: 'people', group: 'people', label: 'shell.nav.peopleList', icon: 'groups', route: '/people', module: 'people' },
  { id: 'org-chart', group: 'people', label: 'shell.nav.orgChart', icon: 'account_tree', route: '/people/org-chart', module: 'people' },
  { id: 'timeoff', group: 'people', label: 'shell.nav.timeoff', icon: 'beach_access', route: '/timeoff', module: 'time-off' },
  { id: 'team-calendar', group: 'people', label: 'shell.nav.teamCalendar', icon: 'calendar_month', route: '/timeoff/calendar', module: 'time-off' },
  { id: 'timeoff-approvals', group: 'people', label: 'shell.nav.approvals', icon: 'event_available', route: '/timeoff/approvals', module: 'time-off' },
  { id: 'time', group: 'people', label: 'shell.nav.time', icon: 'schedule', route: '/time', module: 'time' },
  { id: 'time-approvals', group: 'people', label: 'shell.nav.timeApprovals', icon: 'fact_check', route: '/time/approvals', module: 'time' },
  { id: 'time-team', group: 'people', label: 'shell.nav.timeTeam', icon: 'groups_2', route: '/time/team', module: 'time' },
  { id: 'documents', group: 'people', label: 'shell.nav.myDocuments', icon: 'description', route: '/me/documents', module: 'documents' },
  { id: 'workflow-runs', group: 'people', label: 'shell.nav.workflowRuns', icon: 'account_tree', route: '/workflows/runs', module: 'workflows' },
  { id: 'objectives', group: 'performance', label: 'shell.nav.objectives', icon: 'flag', route: '/perform/objectives', module: 'perform' },
  { id: 'one-on-ones', group: 'performance', label: 'shell.nav.oneOnOnes', icon: 'forum', route: '/perform/one-on-ones', module: 'perform' },
  { id: 'feedback', group: 'performance', label: 'shell.nav.feedback', icon: 'rate_review', route: '/perform/feedback', module: 'perform' },
  { id: 'reviews', group: 'performance', label: 'shell.nav.reviews', icon: 'grading', route: '/perform/reviews', module: 'perform' },
  { id: 'surveys', group: 'performance', label: 'shell.nav.surveys', icon: 'poll', route: '/pulse', module: 'pulse' },
  { id: 'mood', group: 'performance', label: 'shell.nav.mood', icon: 'mood', route: '/pulse/mood', module: 'pulse' },
  { id: 'desk', group: 'services', label: 'shell.nav.desk', icon: 'support_agent', route: '/desk', module: 'desk' },
  { id: 'knowledge', group: 'services', label: 'shell.nav.knowledge', icon: 'menu_book', route: '/knowledge', module: 'knowledge' },
  { id: 'safe-speak', group: 'services', label: 'shell.nav.safeSpeak', icon: 'shield', route: '/safe-speak', module: 'safe-speak' },
  { id: 'report-catalog', group: 'services', label: 'shell.nav.reportCatalog', icon: 'analytics', route: '/reports/catalog', module: 'reports' },
  { id: 'report-builder', group: 'services', label: 'shell.nav.reportBuilder', icon: 'tune', route: '/reports/builder', module: 'reports' },
  { id: 'desk-queue', group: 'admin', label: 'shell.nav.deskQueue', icon: 'inbox', route: '/desk/queue', module: 'desk', role: 'hr' },
  { id: 'safe-speak-inbox', group: 'admin', label: 'shell.nav.safeSpeakInbox', icon: 'shield_person', route: '/safe-speak/inbox', module: 'safe-speak', role: 'hr' },
  { id: 'hiring-settings', group: 'admin', label: 'shell.nav.hiringSettings', icon: 'assignment_add', route: '/admin/hiring-requests', module: 'hiring-requests', role: 'hr' },
  { id: 'acquisition-channels', group: 'admin', label: 'shell.nav.acquisitionChannels', icon: 'campaign', route: '/admin/acquisition-channels', module: 'recruiting', role: 'admin' },
  { id: 'privacy', group: 'admin', label: 'shell.nav.privacy', icon: 'shield_person', route: '/admin/privacy', module: 'privacy', role: 'admin' },
  { id: 'workflows-admin', group: 'admin', label: 'shell.nav.workflows', icon: 'account_tree', route: '/admin/workflows', module: 'workflows', role: 'hr' },
  { id: 'review-setup', group: 'admin', label: 'shell.nav.reviewSetup', icon: 'workspace_premium', route: '/admin/perform/reviews', module: 'perform', role: 'hr' },
  { id: 'survey-builder', group: 'admin', label: 'shell.nav.surveyBuilder', icon: 'ballot', route: '/admin/pulse', module: 'pulse', role: 'hr' },
  { id: 'document-templates', group: 'admin', label: 'shell.nav.documentTemplates', icon: 'article', route: '/admin/documents/templates', module: 'documents', role: 'hr' },
  { id: 'timeoff-settings', group: 'admin', label: 'shell.nav.timeoffSettings', icon: 'event_available', route: '/admin/timeoff', module: 'time-off', role: 'hr' },
  { id: 'time-schedules', group: 'admin', label: 'shell.nav.timeSchedules', icon: 'calendar_view_week', route: '/admin/time', module: 'time', role: 'hr' },
  { id: 'assets', group: 'admin', label: 'shell.nav.assets', icon: 'devices', route: '/admin/assets', module: 'assets', role: 'hr' },
  { id: 'scripts', group: 'admin', label: 'shell.nav.scripts', icon: 'record_voice_over', route: '/admin/scripts', module: 'scripts', role: 'admin' },
  { id: 'directory', group: 'admin', label: 'shell.nav.directory', icon: 'menu_book', route: '/admin/directory', module: null, role: 'admin' },
  { id: 'status', group: 'admin', label: 'shell.nav.status', icon: 'monitor_heart', route: '/status', module: null, role: 'admin' },
  { id: 'users', group: 'admin', label: 'shell.nav.users', icon: 'group', route: '/admin/users', module: null, role: 'superadmin' },
  { id: 'modules', group: 'admin', label: 'shell.nav.modules', icon: 'toggle_on', route: '/admin/modules', module: null, role: 'superadmin' },
  { id: 'integrations', group: 'admin', label: 'shell.nav.integrations', icon: 'hub', route: '/admin/integrations', module: null, role: 'superadmin' },
  { id: 'audit', group: 'admin', label: 'shell.nav.audit', icon: 'history', route: '/admin/audit', module: 'audit', role: 'superadmin' },
  { id: 'mail', group: 'admin', label: 'shell.nav.mail', icon: 'mark_email_unread', route: '/admin/mail', module: 'mail-agent', role: 'superadmin' },
  { id: 'sheets-import', group: 'admin', label: 'shell.nav.sheetsImport', icon: 'table_chart', route: '/admin/sheets-import', module: 'google-workspace', role: 'superadmin' },
  { id: 'errors', group: 'admin', label: 'shell.nav.errors', icon: 'bug_report', route: '/admin/errors', module: null, role: 'superadmin' },
];

export interface DesktopUserAccess {
  modules?: readonly string[];
  roles: readonly UserRole[];
}

export function appAllowed(app: DesktopAppEntry, user: DesktopUserAccess | null): boolean {
  if (!user) return false;
  const moduleAllowed = user.modules === undefined || app.module === null || user.modules.includes(app.module);
  if (!moduleAllowed) return false;
  if (app.role === 'hr') return user.roles.some((role) => HR_STAFF_ROLES.includes(role));
  if (app.role === 'admin') return user.roles.some((role) => ADMIN_ROLES.includes(role));
  if (app.role === 'superadmin') return user.roles.includes('superadmin');
  return true;
}

export const DESKTOP_GROUPS: readonly { id: DesktopGroupId; label: string }[] = [
  { id: 'personal', label: 'desktop.groups.personal' },
  { id: 'recruiting', label: 'shell.nav.recruiting' },
  { id: 'people', label: 'shell.nav.people' },
  { id: 'performance', label: 'shell.nav.perform' },
  { id: 'services', label: 'shell.nav.services' },
  { id: 'admin', label: 'shell.nav.admin' },
];

export const DESKTOP_WIDGETS: readonly { id: DesktopWidgetId; title: string; icon: string; module: string | null }[] = [
  { id: 'summary', title: 'desktop.widgets.summary.title', icon: 'insights', module: 'recruiting' },
  { id: 'tasks', title: 'desktop.widgets.tasks.title', icon: 'task_alt', module: null },
  { id: 'today', title: 'desktop.widgets.today.title', icon: 'today', module: null },
  { id: 'approvals', title: 'desktop.widgets.approvals.title', icon: 'fact_check', module: null },
];

export function widgetAllowed(id: DesktopWidgetId, user: DesktopUserAccess | null): boolean {
  if (!user) return false;
  if (id === 'summary') return user.modules === undefined || user.modules.includes('recruiting');
  if (id === 'approvals') return user.modules === undefined || ['time-off', 'time', 'hiring-requests'].some((module) => user.modules?.includes(module));
  return true;
}

export const DEFAULT_DESKTOP_LAYOUT: DesktopLayout = {
  version: 1,
  widgets: [
    { id: 'summary', size: 'm' },
    { id: 'tasks', size: 's' },
    { id: 'today', size: 'm' },
  ],
};

const WIDGET_ID_SET = new Set<string>(DESKTOP_WIDGET_IDS);
const WIDGET_SIZES = new Set<string>(['s', 'm', 'l']);
const MAX_WIDGETS = 12;

/** Reject malformed, stale or expanded storage records rather than partially trusting them. */
export function parseDesktopLayout(value: unknown): DesktopLayout | null {
  if (!isRecord(value) || !hasOnlyKeys(value, ['version', 'widgets']) || value['version'] !== 1 || !Number.isInteger(value['version'])) return null;
  const widgets = value['widgets'];
  if (!Array.isArray(widgets) || widgets.length > MAX_WIDGETS) return null;
  const seen = new Set<string>();
  const parsed: DesktopWidget[] = [];
  for (const item of widgets) {
    if (!isRecord(item) || !hasOnlyKeys(item, ['id', 'size'])) return null;
    const id = item['id'];
    const size = item['size'];
    if (typeof id !== 'string' || !WIDGET_ID_SET.has(id) || seen.has(id) || typeof size !== 'string' || !WIDGET_SIZES.has(size)) return null;
    seen.add(id);
    parsed.push({ id: id as DesktopWidgetId, size: size as DesktopWidgetSize });
  }
  return { version: 1, widgets: parsed };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasOnlyKeys(value: Record<string, unknown>, keys: readonly string[]): boolean {
  return Object.keys(value).length === keys.length && Object.keys(value).every((key) => keys.includes(key));
}
