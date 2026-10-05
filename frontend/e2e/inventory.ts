// Inventory of a rendered page: what a user can see and operate, independent of looks and order.
// Source: Playwright's accessibility snapshot (roles and accessible names computed by the browser engine; hidden,
// aria-hidden and display:none elements are not in it). Lists are sorted, so moving things around keeps the
// inventory equal; losing, renaming, hiding or disabling something changes it.
import type { Page } from '@playwright/test';

export interface Inventory {
  headings: string[];
  landmarks: string[];
  interactive: string[];
  columns: string[];
  counts: Record<string, number>;
}

const INTERACTIVE = new Set([
  'button', 'link', 'textbox', 'searchbox', 'combobox', 'listbox', 'option', 'checkbox', 'radio', 'switch', 'tab',
  'menuitem', 'menuitemradio', 'menuitemcheckbox', 'slider', 'spinbutton', 'treeitem',
]);
const LANDMARKS = new Set(['banner', 'main', 'navigation', 'complementary', 'contentinfo', 'region', 'search', 'form', 'dialog', 'alertdialog', 'menu', 'tablist']);
const COUNTED = new Set(['row', 'listitem', 'article', 'tab', 'option', 'cell', 'img']);
// State flags that describe what the user can do; [level], [active] and the like are presentation.
const KEPT_FLAGS = new Set(['disabled', 'checked', 'checked=mixed', 'expanded', 'pressed', 'selected']);

// The theme toggle offers the OTHER theme, so its name follows the theme; the inventory must not.
const THEME_NAMES: Record<string, string> = { 'Світла тема': '<theme toggle>', 'Темна тема': '<theme toggle>' };

const LINE =/^(\s*)- ([a-z]+)(?: "((?:[^"\\]|\\.)*)")?((?: \[[^\]]+\])*)(:?)(.*)$/;
const URL_LINE = /^\s*- \/url: (.*)$/;

const QUOTED_LINE = /^(\s*- )('(?:[^']|'')*'|"(?:[^"\\]|\\.)*")(:?)(.*)$/;

/** Playwright quotes YAML role/name keys containing punctuation such as `: `. */
function unquoteRoleLine(line: string): string {
  const match = QUOTED_LINE.exec(line);
  if (!match) return line;
  const [, prefix, scalar, colon, suffix] = match;
  try {
    const role = scalar.startsWith("'") ? scalar.slice(1, -1).replace(/''/g, "'") : JSON.parse(scalar) as string;
    return `${prefix}${role}${colon}${suffix}`;
  } catch {
    return line;
  }
}
/** Parses `locator.ariaSnapshot()` YAML into a sorted, order-free inventory. */
export function parseInventory(yaml: string): Inventory {
  const inv: Inventory = { headings: [], landmarks: [], interactive: [], columns: [], counts: {} };
  const lines = yaml.split(/\r?\n/).map(unquoteRoleLine);
  for (let i = 0; i < lines.length; i++) {
    const m = LINE.exec(lines[i]);
    if (!m) continue;
    const [, indent, role, rawName = '', rawFlags] = m;
    const plain = rawName.replace(/\\"/g, '"').replace(/\s+/g, ' ').trim();
    const name = THEME_NAMES[plain] ?? plain;
    const flags = (rawFlags.match(/\[([^\]]+)\]/g) ?? []).map((f) => f.slice(1, -1)).filter((f) => KEPT_FLAGS.has(f));
    const tail = flags.length ? ` [${flags.join(',')}]` : '';
    if (COUNTED.has(role)) inv.counts[role] = (inv.counts[role] ?? 0) + 1;
    if (role === 'heading') inv.headings.push(name);
    else if (role === 'columnheader') inv.columns.push(name);
    else if (LANDMARKS.has(role)) inv.landmarks.push(`${role} "${name}"`);
    if (INTERACTIVE.has(role)) {
      let href = '';
      if (role === 'link') {
        // The /url child line follows directly, one level deeper.
        const u = URL_LINE.exec(lines[i + 1] ?? '');
        if (u && (lines[i + 1].length - lines[i + 1].trimStart().length) > indent.length) href = ` -> ${u[1].replace(/^"|"$/g, '')}`;
      }
      inv.interactive.push(`${role} "${name}"${tail}${href}`);
    }
  }
  inv.headings.sort();
  inv.landmarks.sort();
  inv.interactive.sort();
  inv.columns.sort();
  inv.counts = Object.fromEntries(Object.entries(inv.counts).sort(([a], [b]) => a.localeCompare(b)));
  return inv;
}

/** @param volatile CSS of content to leave out (aria-hidden while the snapshot is taken, then restored). */
export async function inventoryOf(page: Page, volatile?: string): Promise<Inventory> {
  const mark = (on: boolean) =>
    page.evaluate(
      ([css, hide]) => {
        for (const el of Array.from(document.querySelectorAll(css))) {
          if (hide) el.setAttribute('data-ui-parity-hidden', el.getAttribute('aria-hidden') ?? '');
          if (hide) el.setAttribute('aria-hidden', 'true');
          else {
            const was = el.getAttribute('data-ui-parity-hidden');
            el.removeAttribute('data-ui-parity-hidden');
            if (was) el.setAttribute('aria-hidden', was);
            else el.removeAttribute('aria-hidden');
          }
        }
      },
      [volatile ?? '', on] as const,
    );
  if (volatile) await mark(true);
  try {
    return parseInventory(await page.locator('body').ariaSnapshot());
  } finally {
    if (volatile) await mark(false);
  }
}

