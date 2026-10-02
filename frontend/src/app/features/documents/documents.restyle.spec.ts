import { DOCUMENT_STATUSES, DOCUMENT_STATUS_TONE } from './documents.model';
import { EmployeeDocumentsTab } from './profile/employee-documents.tab';
import { css } from '../../../testing/css';

const PILL_TONES: readonly string[] = ['good', 'warn', 'bad', 'info', 'neutral'];

describe('Documents restyle', () => {
  it('gives every document status a status-pill tone (colour + marker shape)', () => {
    for (const s of DOCUMENT_STATUSES) {
      expect(PILL_TONES).toContain(DOCUMENT_STATUS_TONE[s]);
    }
  });

  it('archived documents keep their contrast (muted title, no fade); title buttons reach 44px on phones', () => {
    const style = css(EmployeeDocumentsTab);
    expect(style).not.toMatch(/opacity/);
    expect(style).toMatch(/max-width:\s*600px\)\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\{[^{}]*min-height:\s*2\.75rem/);
    expect(css(EmployeeDocumentsTab)).not.toMatch(/#[0-9a-f]{3,8}\b/i);
  });
});
