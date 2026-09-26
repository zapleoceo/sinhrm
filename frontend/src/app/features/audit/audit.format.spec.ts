import { auditActionKey, auditChangeLines, auditEntityKey, auditEntityLink, auditValue } from './audit.format';

const e = (entity_type: string, entity_id = 7, meta: Record<string, unknown> | null = null) => ({ entity_type, entity_id, meta });

describe('auditEntityLink', () => {
  it('links entities that have a page', () => {
    expect(auditEntityLink(e('employee'))).toBe('/people/7');
    expect(auditEntityLink(e('candidate'))).toBe('/candidates/7');
    expect(auditEntityLink(e('vacancy'))).toBe('/vacancies/7');
    expect(auditEntityLink(e('user'))).toBe('/admin/users');
    expect(auditEntityLink(e('integration'))).toBe('/admin/integrations');
  });

  it('links applications to their candidate only when meta has a valid id', () => {
    expect(auditEntityLink(e('application', 3, { candidate_id: 11 }))).toBe('/candidates/11');
    expect(auditEntityLink(e('application', 3, { candidate_id: '12' }))).toBe('/candidates/12');
    expect(auditEntityLink(e('application', 3, { candidate_id: '1/../x' }))).toBeNull();
    expect(auditEntityLink(e('application', 3))).toBeNull();
  });

  it('returns null for entities without a page', () => {
    for (const t of ['ai_prompt_version', 'leave_request', 'document', 'hiring_request', 'hiring_approval', 'workflow_template', 'x']) {
      expect(auditEntityLink(e(t))).toBeNull();
    }
  });
});

describe('audit formatting', () => {
  it('renders field: from → to lines', () => {
    expect(auditChangeLines({ status: { from: 'active', to: 'blocked' }, token: { from: null, to: '***' } })).toEqual([
      'status: active → blocked',
      'token: — → ***',
    ]);
    expect(auditChangeLines(null)).toEqual([]);
  });

  it('prints values', () => {
    expect(auditValue({ a: 1 })).toBe('{"a":1}');
    expect(auditValue(false)).toBe('false');
    expect(auditValue('')).toBe('—');
  });

  it('maps known actions and entity types to i18n keys', () => {
    expect(auditActionKey('stage_changed')).toBe('audit.actions.stage_changed');
    expect(auditActionKey('weird')).toBeNull();
    expect(auditEntityKey('employee')).toBe('audit.entities.employee');
    expect(auditEntityKey('weird')).toBeNull();
  });
});
