import { TestBed } from '@angular/core/testing';
import en from '../../../../public/i18n/en.json';
import ru from '../../../../public/i18n/ru.json';
import uk from '../../../../public/i18n/uk.json';
import { ENABLED_KEY, AssistantSettings } from './assistant-settings';
import { splitLink, toSegments } from './assistant-text';
import { claudeCodeCommand, claudeDesktopConfig } from './chat/assistant-mcp-panel';
import { SUGGESTION_COUNT } from './chat/assistant-chat';
import { GREETING_COUNT, QUIP_COUNTS, QuipGroup, pickQuip, quipGroupForUrl } from './quips';

type Tree = Record<string, unknown>;

function keys(tree: Tree, prefix = ''): string[] {
  return Object.entries(tree).flatMap(([k, v]) => (v && typeof v === 'object' ? keys(v as Tree, `${prefix}${k}.`) : [`${prefix}${k}`]));
}

describe('assistant text rendering', () => {
  it('splits lines and turns SPA paths into links (no HTML)', () => {
    expect(toSegments('Дивись /candidates/12, там усе.\nАбо /timeoff?tab=my')).toEqual([
      { kind: 'text', text: 'Дивись ' },
      { kind: 'link', text: '/candidates/12', path: '/candidates/12' },
      { kind: 'text', text: ', там усе.' },
      { kind: 'break' },
      { kind: 'text', text: 'Або ' },
      { kind: 'link', text: '/timeoff?tab=my', path: '/timeoff?tab=my' },
    ]);
    expect(toSegments('<b>x</b> 1/2 https://x.com/a')).toEqual([{ kind: 'text', text: '<b>x</b> 1/2 https://x.com/a' }]);
    expect(splitLink('/timeoff?tab=my&y=2')).toEqual({ route: '/timeoff', query: { tab: 'my', y: '2' } });
  });
});

describe('quips', () => {
  it('maps routes to quip groups', () => {
    expect(quipGroupForUrl('/')).toBe('overview');
    expect(quipGroupForUrl('/candidates/5?x=1')).toBe('recruiting');
    expect(quipGroupForUrl('/reports')).toBe('recruiting');
    expect(quipGroupForUrl('/reports/catalog/x')).toBe('reports');
    expect(quipGroupForUrl('/timeoff/calendar')).toBe('timeoff');
    expect(quipGroupForUrl('/time/team')).toBe('timeoff');
    expect(quipGroupForUrl('/admin/users')).toBe('admin');
    expect(quipGroupForUrl('/unknown')).toBe('generic');
    expect(pickQuip('/people', () => 0.1)).toBe('assistant.quips.people.0');
    expect(pickQuip('/people', () => 0.9)).toMatch(/^assistant\.quips\.generic\.\d$/);
  });

  it.each([
    ['uk', uk],
    ['ru', ru],
    ['en', en],
  ])('%s has every quip, greeting and suggestion', (_lang, file) => {
    const a = (file as Tree)['assistant'] as Tree;
    const quips = a['quips'] as Record<QuipGroup, Tree>;
    for (const [group, count] of Object.entries(QUIP_COUNTS) as [QuipGroup, number][]) {
      for (let i = 0; i < count; i++) {
        expect(typeof quips[group][String(i)]).toBe('string');
      }
    }
    expect(Object.keys(a['greetings'] as Tree)).toHaveLength(GREETING_COUNT);
    expect(Object.keys(a['suggestions'] as Tree)).toHaveLength(SUGGESTION_COUNT);
  });

  it('uk, ru and en have the same assistant keys', () => {
    const base = keys((uk as Tree)['assistant'] as Tree).sort();
    expect(keys((ru as Tree)['assistant'] as Tree).sort()).toEqual(base);
    expect(keys((en as Tree)['assistant'] as Tree).sort()).toEqual(base);
  });
});

describe('AssistantSettings (on/off)', () => {
  beforeEach(() => localStorage.clear());

  it('is on by default and remembers switching off/on', () => {
    const s = TestBed.inject(AssistantSettings);
    expect(s.enabled()).toBe(true);
    s.toggleEnabled();
    expect(s.enabled()).toBe(false);
    expect(localStorage.getItem(ENABLED_KEY)).toBe('0');
    TestBed.resetTestingModule();
    expect(TestBed.inject(AssistantSettings).enabled()).toBe(false);
    TestBed.inject(AssistantSettings).setEnabled(true);
    expect(localStorage.getItem(ENABLED_KEY)).toBe('1');
  });

  it('survives storage that throws', () => {
    const get = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const set = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const s = TestBed.inject(AssistantSettings);
    expect(s.enabled()).toBe(true);
    expect(() => s.setEnabled(false)).not.toThrow();
    expect(s.enabled()).toBe(false);
    get.mockRestore();
    set.mockRestore();
  });

  it('opens the chat on a panel', () => {
    const s = TestBed.inject(AssistantSettings);
    s.openChat('mcp');
    expect(s.chatOpen()).toBe(true);
    expect(s.panel()).toBe('mcp');
    s.closeChat();
    expect(s.chatOpen()).toBe(false);
  });
});

describe('MCP connection snippets', () => {
  it('builds the Claude Code command and the Claude Desktop config', () => {
    expect(claudeCodeCommand('https://h/mcp', 'T')).toBe('claude mcp add --transport http sinhrm https://h/mcp --header "Authorization: Bearer T"');
    const cfg = JSON.parse(claudeDesktopConfig('https://h/mcp', 'T')) as { mcpServers: { sinhrm: { args: string[] } } };
    expect(cfg.mcpServers.sinhrm.args).toEqual(['-y', 'mcp-remote', 'https://h/mcp', '--header', 'Authorization: Bearer T']);
  });
});
