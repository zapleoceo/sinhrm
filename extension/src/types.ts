export type SourceSite = 'linkedin' | 'work_ua' | 'djinni' | 'dou' | 'robota_ua';

export interface Profile {
  full_name: string;
  headline: string;
  location: string;
  phone?: string;
  email?: string;
  telegram?: string;
  profile_url: string;
  summary: string;
  source_site: SourceSite;
}

export type ExtractResult = { ok: true; profile: Profile } | { ok: false; error: 'unsupported_site' };
