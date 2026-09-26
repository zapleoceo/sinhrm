import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import {
  Question,
  deltaTone,
  enpsAngle,
  enpsTone,
  maxOf,
  missingAnswers,
  moodEmoji,
  nextQuestionId,
  questionRange,
  toggleOption,
} from './pulse.model';
import { PulseService, pulseErrorKey } from './pulse.service';

const q = (id: string, required = true, type: Question['type'] = 'scale5'): Question => ({ id, type, text: id, required });

describe('pulse model', () => {
  it('maps eNPS to a gauge angle and a tone', () => {
    expect(enpsAngle(-100)).toBe(0);
    expect(enpsAngle(0)).toBe(90);
    expect(enpsAngle(100)).toBe(180);
    expect(enpsAngle(250)).toBe(180);
    expect(enpsAngle(null)).toBe(90);
    expect(enpsTone(-5)).toBe('danger');
    expect(enpsTone(10)).toBe('warning');
    expect(enpsTone(45)).toBe('success');
    expect(enpsTone(undefined)).toBe('none');
  });

  it('describes deltas and ranges', () => {
    expect(deltaTone(0.5)).toBe('up');
    expect(deltaTone(-1)).toBe('down');
    expect(deltaTone(0)).toBe('flat');
    expect(deltaTone(null)).toBe('none');
    expect(questionRange('scale5')).toEqual([1, 2, 3, 4, 5]);
    expect(questionRange('enps')).toHaveLength(11);
    expect(questionRange('text')).toEqual([]);
    expect(maxOf([])).toBe(1);
    expect(maxOf([2, 7])).toBe(7);
  });

  it('checks required answers and toggles options', () => {
    const questions = [q('a'), q('b'), q('c', false), q('m', true, 'multi')];
    expect(missingAnswers(questions, { a: 3, b: '', m: [] })).toEqual(['b', 'm']);
    expect(missingAnswers(questions, { a: 0, b: 'x', m: [1] })).toEqual([]);
    expect(toggleOption(undefined, 2)).toEqual([2]);
    expect(toggleOption([2, 0], 1)).toEqual([0, 1, 2]);
    expect(toggleOption([0, 1], 1)).toEqual([0]);
  });

  it('gives new question ids and mood emoji', () => {
    expect(nextQuestionId([q('q1'), q('q3')])).toBe('q4');
    expect(nextQuestionId([q('q1'), q('q2')])).toBe('q3');
    expect(moodEmoji(5)).toBe('😄');
    expect(moodEmoji(null)).toBe('—');
  });
});

describe('PulseService', () => {
  let service: PulseService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(PulseService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('answers a wave and reads results with a breakdown', () => {
    service.respond(5, { enps: 9, q1: [0, 2] }).subscribe();
    const post = http.expectOne('/api/pulse/waves/5/responses');
    expect(post.request.body).toEqual({ answers: { enps: 9, q1: [0, 2] } });
    post.flush(null, { status: 201, statusText: 'Created' });

    service.results(5, 'branch').subscribe();
    http.expectOne((r) => r.url === '/api/pulse/waves/5/results' && r.params.get('segment') === 'branch').flush({ data: {} });
    service.compare(5).subscribe();
    http.expectOne((r) => r.url === '/api/pulse/waves/5/compare' && r.params.get('segment') === 'department' && !r.params.has('with')).flush({ data: {} });
  });

  it('checks in the mood and reads the team trend', () => {
    service.checkIn(4, null).subscribe((e) => expect(e.score).toBe(4));
    const post = http.expectOne('/api/pulse/mood');
    expect(post.request.body).toEqual({ score: 4, comment: null });
    post.flush({ data: { day: '2026-10-05', score: 4, comment: null } });

    service.teamMood({ weeks: 8 }).subscribe();
    http.expectOne((r) => r.url === '/api/pulse/mood/team' && r.params.get('weeks') === '8').flush({ data: {} });
  });

  it('maps errors to i18n keys', () => {
    expect(pulseErrorKey(new HttpErrorResponse({ status: 409, error: { code: 'already_responded' } }))).toBe('pulse.errors.already_responded');
    expect(pulseErrorKey(new HttpErrorResponse({ status: 403, error: { code: 'not_in_audience' } }))).toBe('pulse.errors.not_in_audience');
    expect(pulseErrorKey(new HttpErrorResponse({ status: 403 }))).toBe('pulse.errors.forbidden');
    expect(pulseErrorKey(new HttpErrorResponse({ status: 500 }))).toBe('common.error');
  });
});
