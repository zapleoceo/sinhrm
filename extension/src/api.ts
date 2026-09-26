/** SinHRM clipper API client. All requests are token-authenticated, never cookie-based. */
import type { Profile } from './types';

export interface ClipperUser {
  id: number;
  name: string;
  email: string;
}

export interface Vacancy {
  id: number;
  title: string;
  branch: string | null;
}

export interface MeData {
  user: ClipperUser;
  vacancies: Vacancy[];
}

export interface CandidateResult {
  candidate_id: number;
  url: string;
  created: boolean;
}

export type ApiErrorKind =
  | 'unauthorized'
  | 'restricted'
  | 'conflict'
  | 'validation'
  | 'forbidden'
  | 'rate_limited'
  | 'network'
  | 'server';

export type ApiResult<T> =
  | { ok: true; status: number; data: T }
  | {
      ok: false;
      status: number;
      kind: ApiErrorKind;
      code?: string;
      message?: string;
      errors?: Record<string, string[]>;
    };

export type CandidatePayload = Omit<Profile, 'summary'> & { summary?: string; vacancy_id?: number };

type FetchLike = typeof fetch;

function kindForStatus(status: number, body: Record<string, unknown>): ApiErrorKind {
  if (status === 401) return 'unauthorized';
  if (status === 403) return 'forbidden';
  if (status === 409) return body.restricted === true ? 'restricted' : 'conflict';
  if (status === 422) return 'validation';
  if (status === 429) return 'rate_limited';
  return 'server';
}

async function request<T>(
  fetchImpl: FetchLike,
  base: string,
  token: string,
  path: string,
  init: { method: 'GET' | 'POST'; body?: unknown },
): Promise<ApiResult<T>> {
  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
  if (init.body !== undefined) headers['Content-Type'] = 'application/json';
  let res: Response;
  try {
    res = await fetchImpl(base + path, {
      method: init.method,
      headers,
      credentials: 'omit',
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });
  } catch (e) {
    return { ok: false, status: 0, kind: 'network', message: e instanceof Error ? e.message : String(e) };
  }
  let body: Record<string, unknown> = {};
  try {
    const parsed: unknown = await res.json();
    if (parsed && typeof parsed === 'object') body = parsed as Record<string, unknown>;
  } catch {
    /* non-JSON body */
  }
  if (res.ok) return { ok: true, status: res.status, data: body.data as T };
  return {
    ok: false,
    status: res.status,
    kind: kindForStatus(res.status, body),
    code: typeof body.code === 'string' ? body.code : undefined,
    message: typeof body.message === 'string' ? body.message : undefined,
    errors: body.errors && typeof body.errors === 'object' ? (body.errors as Record<string, string[]>) : undefined,
  };
}

/** Drops empty optional fields so the API never receives "" for them. */
export function buildPayload(profile: Profile, vacancyId?: number | null): CandidatePayload {
  const payload: CandidatePayload = {
    full_name: profile.full_name.trim(),
    source_site: profile.source_site,
    profile_url: profile.profile_url,
    headline: profile.headline.trim(),
    location: profile.location.trim(),
  };
  for (const key of ['phone', 'email', 'telegram', 'summary'] as const) {
    const v = profile[key]?.trim();
    if (v) payload[key] = v;
  }
  if (vacancyId) payload.vacancy_id = vacancyId;
  return payload;
}

export function createClient(base: string, token: string, fetchImpl: FetchLike = (...a) => fetch(...a)) {
  return {
    me: () => request<MeData>(fetchImpl, base, token, '/api/clipper/me', { method: 'GET' }),
    createCandidate: (payload: CandidatePayload) =>
      request<CandidateResult>(fetchImpl, base, token, '/api/clipper/candidates', { method: 'POST', body: payload }),
  };
}
