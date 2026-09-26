import { canCompleteTask, isInternalLink, splitLink } from '../scripts/scripts.model';
import { myTasksQuery } from './my-tasks.page';

describe('unified tasks', () => {
  it('lets recruiting writers and the assignee complete a task, never a document task', () => {
    expect(canCompleteTask({ type: 'followup', assignee_id: 5 }, 9, true)).toBe(true);
    expect(canCompleteTask({ type: 'workflow', assignee_id: 5 }, 5, false)).toBe(true);
    expect(canCompleteTask({ type: 'workflow', assignee_id: 5 }, 9, false)).toBe(false);
    expect(canCompleteTask({ type: 'manual', assignee_id: 5 }, null, false)).toBe(false);
    expect(canCompleteTask({ type: 'document', assignee_id: 5 }, 5, true)).toBe(false);
  });

  it('tells in-app links from external ones and splits query params', () => {
    expect(isInternalLink('/people/12?tab=documents')).toBe(true);
    expect(isInternalLink('https://forms.example.test/x')).toBe(false);
    expect(isInternalLink('//evil.example.test')).toBe(false);
    expect(splitLink('/people/12?tab=documents')).toEqual({ path: '/people/12', query: { tab: 'documents' } });
    expect(splitLink('/me/documents')).toEqual({ path: '/me/documents', query: {} });
  });

  it('builds the "my tasks" query from the filters', () => {
    expect(myTasksQuery('all', 'all', false)).toEqual({ mine: true });
    expect(myTasksQuery('workflows', 'overdue', true)).toEqual({ mine: true, source: 'workflows', due: 'overdue', done: true });
  });
});
