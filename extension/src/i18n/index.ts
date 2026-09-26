import en from './en.json';
import uk from './uk.json';
import ru from './ru.json';

export type Lang = 'uk' | 'ru' | 'en';
export type MessageKey = keyof typeof en;

export const dictionaries: Record<Lang, Record<MessageKey, string>> = { en, uk, ru };

let current: Lang = 'en';

export function pickLang(uiLanguage: string | undefined | null): Lang {
  const code = (uiLanguage ?? '').toLowerCase().split(/[-_]/)[0];
  return code === 'uk' || code === 'ru' ? code : 'en';
}

export function detectLang(saved?: string): Lang {
  if (saved === 'uk' || saved === 'ru' || saved === 'en') return saved;
  let ui: string;
  try {
    ui = chrome.i18n.getUILanguage();
  } catch {
    ui = typeof navigator !== 'undefined' ? navigator.language : '';
  }
  return pickLang(ui);
}

export function setLang(lang: Lang): void {
  current = lang;
}

export function getLang(): Lang {
  return current;
}

export function t(key: MessageKey, lang: Lang = current): string {
  return dictionaries[lang][key] ?? dictionaries.en[key] ?? key;
}

/** Fills every element that has data-i18n="key". */
export function applyI18n(root: ParentNode): void {
  root.querySelectorAll<HTMLElement>('[data-i18n]').forEach((el) => {
    el.textContent = t(el.dataset.i18n as MessageKey);
  });
}
