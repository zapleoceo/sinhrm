/**
 * Popup flow: toolbar click -> read active tab URL -> detect site -> inject build/extract.js
 * into the CURRENT tab only (activeTab + scripting) -> show editable fields -> POST on click.
 */
import { buildPayload, createClient, type ApiResult, type CandidateResult } from './api';
import { detectSite } from './detect';
import { applyI18n, detectLang, setLang, t, type MessageKey } from './i18n';
import { loadSettings } from './settings';
import type { ExtractResult, Profile } from './types';

const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;

const FIELDS = ['full_name', 'headline', 'location', 'phone', 'email', 'telegram', 'profile_url', 'summary'] as const;

function show(sectionId: 'loading' | 'message' | 'form'): void {
  for (const id of ['loading', 'message', 'form']) $(id).hidden = id !== sectionId;
}

function showMessage(key: MessageKey, withOptionsLink = false): void {
  $('message-text').textContent = t(key);
  $('open-options').hidden = !withOptionsLink;
  show('message');
}

function setStatus(kind: 'ok' | 'error' | 'info', text: string, link?: string, details?: string[]): void {
  const box = $('status');
  box.hidden = false;
  box.className = `status status-${kind}`;
  box.replaceChildren();
  const p = document.createElement('p');
  p.textContent = text;
  box.append(p);
  if (details?.length) {
    const ul = document.createElement('ul');
    for (const d of details) {
      const li = document.createElement('li');
      li.textContent = d;
      ul.append(li);
    }
    box.append(ul);
  }
  if (link) {
    const a = document.createElement('a');
    a.href = link;
    a.textContent = t('openCandidate');
    a.addEventListener('click', (e) => {
      e.preventDefault();
      void chrome.tabs.create({ url: link });
    });
    box.append(a);
  }
}

function fillForm(profile: Profile): void {
  for (const f of FIELDS) {
    ($(`f-${f}`) as HTMLInputElement | HTMLTextAreaElement).value = profile[f] ?? '';
  }
}

function readForm(base: Profile): Profile {
  const v = (f: (typeof FIELDS)[number]) => ($(`f-${f}`) as HTMLInputElement | HTMLTextAreaElement).value;
  return {
    ...base,
    full_name: v('full_name'),
    headline: v('headline'),
    location: v('location'),
    phone: v('phone'),
    email: v('email'),
    telegram: v('telegram'),
    summary: v('summary').slice(0, 2000),
  };
}

function renderResult(res: ApiResult<CandidateResult>): void {
  if (res.ok) {
    setStatus('ok', t(res.data.created ? 'created' : 'matched'), res.data.url);
    return;
  }
  const map: Record<typeof res.kind, MessageKey> = {
    unauthorized: 'errUnauthorized',
    restricted: 'errRestricted',
    conflict: 'errConflict',
    validation: 'errValidation',
    forbidden: 'errForbidden',
    rate_limited: 'errRateLimited',
    network: 'errNetwork',
    server: 'errServer',
  };
  const details =
    res.kind === 'validation'
      ? Object.entries(res.errors ?? {}).flatMap(([field, msgs]) => msgs.map((m) => `${field}: ${m}`))
      : undefined;
  if (res.kind === 'validation' && !details?.length && res.message) details?.push(res.message);
  setStatus('error', t(map[res.kind]), undefined, details);
  if (res.kind === 'unauthorized') $('open-options-inline').hidden = false;
}

async function init(): Promise<void> {
  const settings = await loadSettings();
  setLang(detectLang(settings.lang));
  applyI18n(document);
  $('open-options').addEventListener('click', () => void chrome.runtime.openOptionsPage());
  $('open-options-inline').addEventListener('click', () => void chrome.runtime.openOptionsPage());

  if (!settings.token) {
    showMessage('noToken', true);
    return;
  }

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id || !tab.url || !detectSite(tab.url)) {
    showMessage('unsupported');
    return;
  }

  let result: ExtractResult | undefined;
  try {
    const [injection] = await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['extract.js'] });
    result = injection?.result as ExtractResult | undefined;
  } catch {
    result = undefined;
  }
  if (!result) {
    showMessage('extractFailed');
    return;
  }
  if (!result.ok) {
    showMessage('unsupported');
    return;
  }
  const profile = result.profile;
  fillForm(profile);
  show('form');

  const client = createClient(settings.baseUrl, settings.token);
  const select = $('f-vacancy') as HTMLSelectElement;
  void client.me().then((me) => {
    if (me.ok) {
      for (const v of me.data.vacancies ?? []) {
        const opt = document.createElement('option');
        opt.value = String(v.id);
        opt.textContent = v.branch ? `${v.title} (${v.branch})` : v.title;
        select.append(opt);
      }
    } else if (me.kind === 'unauthorized') {
      renderResult(me as ApiResult<CandidateResult>);
    }
  });

  $('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const edited = readForm(profile);
    if (!edited.full_name.trim()) {
      setStatus('error', t('nameRequired'));
      return;
    }
    const button = $('submit') as HTMLButtonElement;
    button.disabled = true;
    setStatus('info', t('sending'));
    const res = await client.createCandidate(buildPayload(edited, Number(select.value) || null));
    button.disabled = false;
    renderResult(res);
  });
}

void init();
