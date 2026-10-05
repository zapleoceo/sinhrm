import assert from 'node:assert/strict';
import test from 'node:test';
import { parseInventory } from './inventory.ts';

test('retains quoted options and their quoted links, URLs and state flags', () => {
  const inventory = parseInventory(`- listbox "Candidates":
  - 'option "Synthetic Candidate Role · New Score: 0" [selected]':
    - 'link "Synthetic Candidate Role · New Score: 0"':
      - /url: /candidates/150
  - 'option "Synthetic Candidate Role · New Score: —"':
    - 'link "Synthetic Candidate Role · New Score: —"':
      - /url: /candidates/149`);
  assert.equal(inventory.counts.option, 2);
  assert.deepEqual(inventory.interactive, [
    'link "Synthetic Candidate Role · New Score: 0" -> /candidates/150',
    'link "Synthetic Candidate Role · New Score: —" -> /candidates/149',
    'listbox "Candidates"',
    'option "Synthetic Candidate Role · New Score: 0" [selected]',
    'option "Synthetic Candidate Role · New Score: —"',
  ]);
});

test('decodes YAML apostrophes without dropping flags or mistaking name brackets for state', () => {
  const inventory = parseInventory(`- 'button "Candidate''s score: 0 [TEST]" [disabled] [pressed]'
- 'checkbox "Candidate''s consent: checked" [checked=mixed]'`);
  assert.deepEqual(inventory.interactive, [
    'button "Candidate\'s score: 0 [TEST]" [disabled,pressed]',
    'checkbox "Candidate\'s consent: checked" [checked=mixed]',
  ]);
});

test('single and double YAML wrappers preserve the full unquoted inventory', () => {
  const roles = [
    'heading "Candidates: review" [level=1]',
    'region "Recruiting: selection"',
    'columnheader "Score: descending"',
    'button "Say \\"yes\\": continue" [expanded] [disabled]',
    'option "Score: zero" [selected]',
    'article',
  ];
  const plain = roles.map((role) => `- ${role}`).join('\n');
  const single = roles.map((role) => `- '${role.replaceAll("'", "''")}'`).join('\n');
  const double = roles.map((role) => `- ${JSON.stringify(role)}`).join('\n');
  assert.deepEqual(parseInventory(single), parseInventory(plain));
  assert.deepEqual(parseInventory(single.replaceAll('\n', '\r\n')), parseInventory(plain));
  assert.deepEqual(parseInventory(double), parseInventory(plain));
});

test('preserves link indentation checks and ignores unknown or malformed rows', () => {
  const inventory = parseInventory(`  - 'link "Score: details"':
  - /url: /not-a-child
- 'notarole "Score: details"'
- 'button "Unclosed wrapper"
- "button \\q"
- 'text: score: 82'`);
  assert.deepEqual(inventory.interactive, ['link "Score: details"']);
  assert.deepEqual(inventory.counts, {});
});

test('still detects a lost interactive role or a disabled control', () => {
  const enabled = parseInventory(`- 'option "Score: 82"'
- 'button "Review: candidate"'`);
  assert.notDeepEqual(parseInventory(`- 'button "Review: candidate"'`), enabled);
  assert.notDeepEqual(parseInventory(`- 'option "Score: 82"'
- 'button "Review: candidate" [disabled]'`), enabled);
});
