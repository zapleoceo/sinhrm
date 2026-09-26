import type { Profile, SourceSite } from '../types';

export const SUMMARY_MAX = 2000;

export interface SiteSelectors {
  name: string[];
  headline: string[];
  location: string[];
  summary: string[];
}

interface PersonLd {
  name: string;
  jobTitle: string;
  address: string;
  email: string;
  telephone: string;
  description: string;
  url: string;
}

export function clean(value: unknown): string {
  return typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : '';
}

export function truncate(text: string, max = SUMMARY_MAX): string {
  const t = text.trim();
  return t.length > max ? t.slice(0, max).trimEnd() : t;
}

/** Strips query string and hash. */
export function stripUrl(raw: string): string {
  try {
    const u = new URL(raw);
    u.search = '';
    u.hash = '';
    return u.toString();
  } catch {
    return raw;
  }
}

function isPerson(node: Record<string, unknown>): boolean {
  const type = node['@type'];
  return type === 'Person' || (Array.isArray(type) && type.includes('Person'));
}

function flatten(node: unknown, out: Record<string, unknown>[]): void {
  if (Array.isArray(node)) {
    node.forEach((n) => flatten(n, out));
  } else if (node && typeof node === 'object') {
    const obj = node as Record<string, unknown>;
    out.push(obj);
    if (obj['@graph']) flatten(obj['@graph'], out);
    if (obj['mainEntity']) flatten(obj['mainEntity'], out);
  }
}

function addressToString(address: unknown): string {
  if (typeof address === 'string') return clean(address);
  if (Array.isArray(address)) return addressToString(address[0]);
  if (address && typeof address === 'object') {
    const a = address as Record<string, unknown>;
    const country =
      a.addressCountry && typeof a.addressCountry === 'object'
        ? (a.addressCountry as Record<string, unknown>).name
        : a.addressCountry;
    return [a.addressLocality, a.addressRegion, country].map(clean).filter(Boolean).join(', ');
  }
  return '';
}

export function readPersonLd(doc: Document): PersonLd | null {
  const nodes: Record<string, unknown>[] = [];
  doc.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
    try {
      flatten(JSON.parse(s.textContent ?? ''), nodes);
    } catch {
      /* malformed JSON-LD is ignored; the next fallback applies */
    }
  });
  const p = nodes.find(isPerson);
  if (!p) return null;
  return {
    name: clean(p.name),
    jobTitle: clean(p.jobTitle),
    address: addressToString(p.address),
    email: clean(p.email).replace(/^mailto:/i, ''),
    telephone: clean(p.telephone).replace(/^tel:/i, ''),
    description: clean(p.description),
    url: clean(p.url),
  };
}

export function meta(doc: Document, property: string): string {
  const el = doc.querySelector(`meta[property="${property}"], meta[name="${property}"]`);
  return clean(el?.getAttribute('content'));
}

export function firstText(doc: Document, selectors: string[]): string {
  for (const sel of selectors) {
    const text = clean(doc.querySelector(sel)?.textContent);
    if (text) return text;
  }
  return '';
}

/** "Ivan Testenko - Senior Dev | LinkedIn" -> ["Ivan Testenko", "Senior Dev", "LinkedIn"] */
export function splitTitle(title: string): string[] {
  return title.split(/\s+[-|–—]\s+/).map(clean).filter(Boolean);
}

export function findEmail(doc: Document): string {
  const href = doc.querySelector('a[href^="mailto:"]')?.getAttribute('href');
  if (!href) return '';
  try {
    return clean(decodeURIComponent(href.slice(7).split('?')[0] ?? ''));
  } catch {
    return '';
  }
}

export function findPhone(doc: Document): string {
  const href = doc.querySelector('a[href^="tel:"]')?.getAttribute('href');
  return href ? clean(href.slice(4)) : '';
}

const TELEGRAM_RE = /^(?:https?:\/\/)?(?:www\.)?(?:t\.me|telegram\.me)\/([A-Za-z0-9_]{4,32})\/?(?:[?#].*)?$/i;

const TELEGRAM_RESERVED = new Set(['joinchat', 'share', 'addstickers', 'proxy', 'socks', 'iv']);

/** Returns "@handle" from the first visible t.me / telegram.me link. */
export function findTelegram(doc: Document): string {
  for (const a of Array.from(doc.querySelectorAll('a[href]'))) {
    const m = TELEGRAM_RE.exec(a.getAttribute('href') ?? '');
    if (m?.[1] && !TELEGRAM_RESERVED.has(m[1].toLowerCase())) return '@' + m[1];
  }
  return '';
}

/** canonical link -> og:url -> current location, always without query/hash. */
export function canonicalUrl(doc: Document, locationUrl: string): string {
  const canonical = doc.querySelector('link[rel="canonical"]')?.getAttribute('href');
  for (const candidate of [canonical, meta(doc, 'og:url')]) {
    if (candidate && /^https:\/\//i.test(candidate)) return stripUrl(candidate);
  }
  return stripUrl(locationUrl);
}

/**
 * Field-by-field fallback chain: JSON-LD Person -> og: meta -> site DOM selectors.
 * Reads only the passed document; never fetches or navigates.
 */
export function extractCommon(doc: Document, url: string, site: SourceSite, sel: SiteSelectors): Profile {
  const ld = readPersonLd(doc);
  const og = splitTitle(meta(doc, 'og:title'));

  const profile: Profile = {
    full_name: ld?.name || og[0] || firstText(doc, sel.name),
    // og:title "Name - Headline | Site" carries a headline only when it has 3+ parts
    headline: ld?.jobTitle || (og.length > 2 ? (og[1] ?? '') : '') || firstText(doc, sel.headline),
    location: ld?.address || firstText(doc, sel.location),
    profile_url: canonicalUrl(doc, url),
    summary: truncate(ld?.description || meta(doc, 'og:description') || firstText(doc, sel.summary)),
    source_site: site,
  };
  const email = ld?.email || findEmail(doc);
  const phone = ld?.telephone || findPhone(doc);
  const telegram = findTelegram(doc);
  if (email) profile.email = email;
  if (phone) profile.phone = phone;
  if (telegram) profile.telegram = telegram;
  return profile;
}
