import { describe, expect, it, vi } from 'vitest';
import { buildPayload, createClient } from '../src/api';
import type { Profile } from '../src/types';

const BASE = 'https://sinhrm.example.com';
const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

const profile: Profile = {
  full_name: ' Ivan Testenko ',
  headline: 'QA',
  location: 'Testograd',
  phone: '',
  email: 'ivan@example.com',
  telegram: '  ',
  profile_url: 'https://www.linkedin.com/in/ivan-testenko/',
  summary: '',
  source_site: 'linkedin',
};

describe('buildPayload', () => {
  it('omits empty optional fields and adds vacancy_id', () => {
    expect(buildPayload(profile, 3)).toEqual({
      full_name: 'Ivan Testenko',
      source_site: 'linkedin',
      profile_url: 'https://www.linkedin.com/in/ivan-testenko/',
      headline: 'QA',
      location: 'Testograd',
      email: 'ivan@example.com',
      vacancy_id: 3,
    });
    expect(buildPayload(profile, null)).not.toHaveProperty('vacancy_id');
  });
});

describe('api client', () => {
  it('GET /me sends bearer token, accept header and omits credentials', async () => {
    const data = { user: { id: 1, name: 'Test User', email: 'u@example.com' }, vacancies: [{ id: 3, title: 'QA', branch: null }] };
    const f = vi.fn().mockResolvedValue(json(200, { data }));
    const res = await createClient(BASE, 'tok', f).me();
    expect(res).toEqual({ ok: true, status: 200, data });
    const [url, init] = f.mock.calls[0]!;
    expect(url).toBe(`${BASE}/api/clipper/me`);
    expect(init).toMatchObject({
      method: 'GET',
      credentials: 'omit',
      headers: { Authorization: 'Bearer tok', Accept: 'application/json' },
    });
  });

  it('POST /candidates sends JSON body; 201 created and 200 matched', async () => {
    const created = { candidate_id: 12, url: 'https://sinhrm.example.com/candidates/12', created: true };
    const f = vi
      .fn()
      .mockResolvedValueOnce(json(201, { data: created }))
      .mockResolvedValueOnce(json(200, { data: { ...created, created: false } }));
    const client = createClient(BASE, 'tok', f);
    const payload = buildPayload(profile);
    expect(await client.createCandidate(payload)).toEqual({ ok: true, status: 201, data: created });
    const r2 = await client.createCandidate(payload);
    expect(r2.ok && r2.data.created).toBe(false);
    const [url, init] = f.mock.calls[0]!;
    expect(url).toBe(`${BASE}/api/clipper/candidates`);
    expect(init.method).toBe('POST');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(JSON.parse(init.body)).toEqual(payload);
  });

  it.each([
    [401, { message: 'Unauthenticated.' }, 'unauthorized'],
    [403, { code: 'vacancy_out_of_scope' }, 'forbidden'],
    [409, { code: 'duplicate_candidate', restricted: true }, 'restricted'],
    [409, { code: 'duplicate_candidate' }, 'conflict'],
    [422, { message: 'Invalid', errors: { email: ['The email must be valid.'] } }, 'validation'],
    [429, {}, 'rate_limited'],
    [500, {}, 'server'],
  ])('maps HTTP %i to %s', async (status, body, kind) => {
    const f = vi.fn().mockResolvedValue(json(status, body));
    const res = await createClient(BASE, 'tok', f).createCandidate(buildPayload(profile));
    expect(res.ok).toBe(false);
    if (!res.ok) {
      expect(res.kind).toBe(kind);
      expect(res.status).toBe(status);
      if (status === 422) expect(res.errors).toEqual({ email: ['The email must be valid.'] });
      if (status === 403) expect(res.code).toBe('vacancy_out_of_scope');
    }
  });

  it('maps a thrown fetch to network error', async () => {
    const f = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
    const res = await createClient(BASE, 'tok', f).me();
    expect(res).toMatchObject({ ok: false, status: 0, kind: 'network' });
  });

  it('handles non-JSON error body', async () => {
    const f = vi.fn().mockResolvedValue(new Response('oops', { status: 502 }));
    const res = await createClient(BASE, 'tok', f).me();
    expect(res).toMatchObject({ ok: false, kind: 'server', status: 502 });
  });
});
