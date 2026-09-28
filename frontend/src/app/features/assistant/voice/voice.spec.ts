import { TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { TranscriptionResult } from '../assistant.model';
import { AssistantService } from '../assistant.service';
import { appendText, formatClock } from '../chat/assistant-chat';
import { AUDIO_BITRATE, AssistantRecorder, MAX_AUDIO_BYTES, MAX_RECORD_MS, RECORDER_ENV, RecorderEnv, audioFileName, pickMimeType } from './assistant-recorder';
import { AssistantVoice, TRANSCRIBE_POLL_MS, TRANSCRIBE_SLOW_MS, transcriptionErrorKey } from './assistant-voice';

/* ───────────── fakes ───────────── */

class FakeTrack {
  stopped = false;
  stop(): void {
    this.stopped = true;
  }
}

class FakeStream {
  readonly tracks = [new FakeTrack()];
  getTracks(): FakeTrack[] {
    return this.tracks;
  }
}

class FakeRecorder extends EventTarget {
  static supported: string[] = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg'];
  static last: FakeRecorder | null = null;
  static chunk = new Blob(['x'.repeat(2000)]);
  state: 'inactive' | 'recording' = 'inactive';
  readonly mimeType: string;
  constructor(
    readonly stream: FakeStream,
    readonly options: MediaRecorderOptions,
  ) {
    super();
    this.mimeType = options.mimeType ?? '';
    FakeRecorder.last = this;
  }
  static isTypeSupported(type: string): boolean {
    return FakeRecorder.supported.includes(type);
  }
  start(): void {
    this.state = 'recording';
  }
  stop(): void {
    this.state = 'inactive';
    const data = new Event('dataavailable') as Event & { data: Blob };
    data.data = FakeRecorder.chunk;
    this.dispatchEvent(data);
    this.dispatchEvent(new Event('stop'));
  }
}

function makeEnv(overrides: Partial<RecorderEnv> = {}): RecorderEnv & { stream: FakeStream } {
  const stream = new FakeStream();
  return {
    stream,
    MediaRecorder: FakeRecorder as unknown as RecorderEnv['MediaRecorder'],
    getUserMedia: () => Promise.resolve(stream as unknown as MediaStream),
    AudioContext: undefined,
    now: () => Date.now(),
    ...overrides,
  };
}

function setup(env: RecorderEnv, api: Partial<Record<'transcribe' | 'transcription', (...a: never[]) => Observable<TranscriptionResult>>> = {}) {
  TestBed.configureTestingModule({
    providers: [
      { provide: RECORDER_ENV, useValue: env },
      { provide: AssistantService, useValue: { transcribe: vi.fn(api.transcribe ?? (() => of({ state: 'done', request_id: 1, text: 'привіт' }))), transcription: vi.fn(api.transcription ?? (() => of({ state: 'done', request_id: 1, text: 'x' }))) } },
    ],
  });
  return { recorder: TestBed.inject(AssistantRecorder), voice: TestBed.inject(AssistantVoice), api: TestBed.inject(AssistantService) as unknown as Record<string, ReturnType<typeof vi.fn>> };
}

/* ───────────── recorder ───────────── */

describe('AssistantRecorder', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    FakeRecorder.supported = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg'];
  });
  afterEach(() => vi.useRealTimers());

  it('prefers webm/opus, then mp4 (Safari), then ogg', () => {
    expect(pickMimeType((t) => ['audio/webm;codecs=opus', 'audio/mp4'].includes(t))).toBe('audio/webm;codecs=opus');
    expect(pickMimeType((t) => ['audio/mp4', 'audio/ogg'].includes(t))).toBe('audio/mp4');
    expect(pickMimeType((t) => t === 'audio/ogg')).toBe('audio/ogg');
    expect(pickMimeType(() => false)).toBe('');
    expect(audioFileName('audio/mp4')).toBe('voice.m4a');
    expect(audioFileName('audio/ogg;codecs=opus')).toBe('voice.ogg');
    expect(audioFileName('audio/webm;codecs=opus')).toBe('voice.webm');
  });

  it('records mono at a low bitrate with the best supported container', async () => {
    FakeRecorder.supported = ['audio/mp4'];
    const getUserMedia = vi.fn(() => Promise.resolve(new FakeStream() as unknown as MediaStream));
    const { recorder } = setup(makeEnv({ getUserMedia }));
    await recorder.start();
    expect(getUserMedia).toHaveBeenCalledWith({ audio: expect.objectContaining({ channelCount: 1 }) });
    expect(FakeRecorder.last?.options).toEqual({ mimeType: 'audio/mp4', audioBitsPerSecond: AUDIO_BITRATE });
    expect(recorder.recording()).toBe(true);
    recorder.cancel();
  });

  it('stops by itself at 120 s and releases the microphone', async () => {
    const env = makeEnv();
    const { recorder } = setup(env);
    await recorder.start();
    const done = recorder.done;
    vi.advanceTimersByTime(MAX_RECORD_MS);
    const rec = await done;
    expect(rec?.blob.size).toBeGreaterThan(0);
    expect(rec?.ms).toBeGreaterThanOrEqual(MAX_RECORD_MS);
    expect(env.stream.tracks.every((t) => t.stopped)).toBe(true);
    expect(recorder.recording()).toBe(false);
  });

  it('cancel discards the audio and releases the tracks', async () => {
    const env = makeEnv();
    const { recorder } = setup(env);
    await recorder.start();
    vi.advanceTimersByTime(3000);
    const done = recorder.done;
    recorder.cancel();
    expect(await done).toBeNull();
    expect(env.stream.tracks[0].stopped).toBe(true);
  });

  it('ignores recordings shorter than 0.5 s', async () => {
    const { recorder } = setup(makeEnv());
    await recorder.start();
    vi.advanceTimersByTime(300);
    const done = recorder.done;
    recorder.stop();
    expect(await done).toBeNull();
  });

  it('maps a denied permission and missing APIs', async () => {
    const denied = setup(makeEnv({ getUserMedia: () => Promise.reject(Object.assign(new Error('no'), { name: 'NotAllowedError' })) }));
    await expect(denied.recorder.start()).rejects.toMatchObject({ code: 'denied' });
    TestBed.resetTestingModule();
    const none = setup(makeEnv({ MediaRecorder: undefined }));
    expect(none.recorder.supported).toBe(false);
    await expect(none.recorder.start()).rejects.toMatchObject({ code: 'unsupported' });
  });
});

/* ───────────── dictation ───────────── */

describe('AssistantVoice', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('record → upload → pending → poll → text for the input (never sent)', async () => {
    const polls: TranscriptionResult[] = [
      { state: 'pending', request_id: 9 },
      { state: 'done', request_id: 9, text: '  Скільки відпусток лишилось?  ' },
    ];
    const { voice, api } = setup(makeEnv(), {
      transcribe: () => of({ state: 'pending', request_id: 9 }),
      transcription: () => of(polls.shift()!),
    });
    await voice.toggle();
    expect(voice.state()).toBe('recording');
    vi.advanceTimersByTime(2000);
    await voice.toggle();
    await vi.advanceTimersByTimeAsync(0);
    expect(voice.state()).toBe('transcribing');
    expect(api['transcribe']).toHaveBeenCalledWith(expect.any(Blob), 'voice.webm');
    await vi.advanceTimersByTimeAsync(TRANSCRIBE_POLL_MS);
    expect(api['transcription']).toHaveBeenCalledWith(9);
    await vi.advanceTimersByTimeAsync(TRANSCRIBE_POLL_MS);
    expect(voice.state()).toBe('idle');
    expect(voice.dictated()).toEqual({ text: 'Скільки відпусток лишилось?', seq: 1 });
    expect(voice.errorKey()).toBeNull();
  });

  it('shows «ще слухаю» after 20 s of transcription', async () => {
    const { voice } = setup(makeEnv(), { transcribe: () => of({ state: 'pending', request_id: 3 }), transcription: () => of({ state: 'pending', request_id: 3 }) });
    await voice.toggle();
    vi.advanceTimersByTime(1500);
    await voice.toggle();
    await vi.advanceTimersByTimeAsync(TRANSCRIBE_SLOW_MS + TRANSCRIBE_POLL_MS);
    expect(voice.slow()).toBe(true);
    voice.cancel();
  });

  it('empty transcript and too-big audio give friendly messages', async () => {
    const empty = setup(makeEnv(), { transcribe: () => of({ state: 'failed', request_id: 1, error: 'empty_transcript' }) });
    await empty.voice.toggle();
    vi.advanceTimersByTime(1000);
    await empty.voice.toggle();
    await vi.advanceTimersByTimeAsync(0);
    expect(empty.voice.errorKey()).toBe('assistant.voice.empty');
    TestBed.resetTestingModule();

    FakeRecorder.chunk = new Blob([new Uint8Array(MAX_AUDIO_BYTES + 1)]);
    const big = setup(makeEnv());
    await big.voice.toggle();
    vi.advanceTimersByTime(1000);
    await big.voice.toggle();
    await vi.advanceTimersByTimeAsync(0);
    expect(big.voice.errorKey()).toBe('assistant.voice.tooBig');
    expect(big.api['transcribe']).not.toHaveBeenCalled();
    FakeRecorder.chunk = new Blob(['x'.repeat(2000)]);
  });

  it('permission denied → how to allow the microphone', async () => {
    const { voice } = setup(makeEnv({ getUserMedia: () => Promise.reject(Object.assign(new Error('no'), { name: 'NotAllowedError' })) }));
    await voice.toggle();
    expect(voice.state()).toBe('idle');
    expect(voice.errorKey()).toBe('assistant.voice.denied');
  });

  it('error codes reuse the chat messages', () => {
    expect(transcriptionErrorKey('ai_budget_exceeded')).toBe('assistant.errors.ai_budget_exceeded');
    expect(transcriptionErrorKey('ai_provider_http_502')).toBe('assistant.errors.ai_provider');
    expect(transcriptionErrorKey('empty_transcript')).toBe('assistant.voice.empty');
  });

  it('dictated text is appended to the draft with a space; the clock is mm:ss', () => {
    expect(appendText('', 'привіт')).toBe('привіт');
    expect(appendText('Скажи  ', 'привіт')).toBe('Скажи привіт');
    expect(formatClock(0)).toBe('00:00');
    expect(formatClock(65_400)).toBe('01:05');
  });
});
