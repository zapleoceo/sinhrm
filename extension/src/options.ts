import { createClient } from './api';
import { applyI18n, detectLang, setLang, t, type Lang } from './i18n';
import { loadSettings, normalizeBaseUrl, saveSettings } from './settings';

const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;

function status(kind: 'ok' | 'error', text: string): void {
  const el = $('status');
  el.hidden = false;
  el.className = `status status-${kind}`;
  el.textContent = text;
}

async function init(): Promise<void> {
  const settings = await loadSettings();
  const lang = detectLang(settings.lang);
  setLang(lang);
  applyI18n(document);

  const baseInput = $<HTMLInputElement>('base-url');
  const tokenInput = $<HTMLInputElement>('token');
  const langSelect = $<HTMLSelectElement>('lang');
  baseInput.value = settings.baseUrl;
  tokenInput.value = settings.token;
  langSelect.value = lang;

  langSelect.addEventListener('change', async () => {
    await saveSettings({ lang: langSelect.value });
    setLang(langSelect.value as Lang);
    applyI18n(document);
  });

  const persist = async (): Promise<{ baseUrl: string; token: string } | null> => {
    const baseUrl = normalizeBaseUrl(baseInput.value);
    if (!baseUrl) {
      status('error', t('invalidBaseUrl'));
      return null;
    }
    const token = tokenInput.value.trim();
    baseInput.value = baseUrl;
    await saveSettings({ baseUrl, token });
    return { baseUrl, token };
  };

  $('options-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (await persist()) status('ok', t('saved'));
  });

  $('check').addEventListener('click', async () => {
    const s = await persist();
    if (!s) return;
    const res = await createClient(s.baseUrl, s.token).me();
    if (res.ok) {
      status('ok', `${t('connectedAs')}: ${res.data.user.name}`);
    } else {
      const key = res.kind === 'unauthorized' ? 'errUnauthorized' : res.kind === 'network' ? 'errNetwork' : 'errServer';
      status('error', t(key));
    }
  });
}

void init();
