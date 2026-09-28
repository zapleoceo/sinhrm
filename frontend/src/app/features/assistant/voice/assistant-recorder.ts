import { Injectable, InjectionToken, inject, signal } from '@angular/core';

/** Preferred containers: Opus in WebM (Chrome/Firefox), MP4 (Safari), then Ogg. */
export const MIME_PREFERENCE: readonly string[] = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg'];
export const MAX_RECORD_MS = 120_000;
export const MIN_RECORD_MS = 500;
export const MAX_AUDIO_BYTES = 4 * 1024 * 1024;
export const AUDIO_BITRATE = 24_000;
export const BAR_COUNT = 20;

export type RecorderError = 'denied' | 'no_mic' | 'unsupported' | 'error';

export class RecorderFailure extends Error {
  constructor(readonly code: RecorderError) {
    super(code);
  }
}

export interface Recording {
  blob: Blob;
  mime: string;
  ms: number;
}

type RecorderCtor = new (stream: MediaStream, options?: MediaRecorderOptions) => MediaRecorder;

/** Browser audio APIs (replaced in tests). */
export interface RecorderEnv {
  MediaRecorder: (RecorderCtor & { isTypeSupported(type: string): boolean }) | undefined;
  getUserMedia: ((constraints: MediaStreamConstraints) => Promise<MediaStream>) | undefined;
  AudioContext: (new () => AudioContext) | undefined;
  now: () => number;
}

export const RECORDER_ENV = new InjectionToken<RecorderEnv>('RECORDER_ENV', {
  providedIn: 'root',
  factory: (): RecorderEnv => {
    const g = globalThis as unknown as {
      MediaRecorder?: RecorderEnv['MediaRecorder'];
      AudioContext?: new () => AudioContext;
      webkitAudioContext?: new () => AudioContext;
      navigator?: { mediaDevices?: MediaDevices };
    };
    const devices = g.navigator?.mediaDevices;
    return {
      MediaRecorder: g.MediaRecorder,
      getUserMedia: devices?.getUserMedia ? (c) => devices.getUserMedia(c) : undefined,
      AudioContext: g.AudioContext ?? g.webkitAudioContext,
      now: () => Date.now(),
    };
  },
});

/** First container the browser can record ('' — let the browser choose). */
export function pickMimeType(isSupported: (type: string) => boolean): string {
  return MIME_PREFERENCE.find((t) => isSupported(t)) ?? '';
}

/** Upload file name by container (the backend sniffs the type, the extension helps Whisper). */
export function audioFileName(mime: string): string {
  if (mime.includes('mp4') || mime.includes('m4a') || mime.includes('aac')) {
    return 'voice.m4a';
  }
  return mime.includes('ogg') ? 'voice.ogg' : 'voice.webm';
}

function failureOf(e: unknown): RecorderFailure {
  const name = e instanceof Error || (typeof e === 'object' && e !== null && 'name' in e) ? String((e as { name: unknown }).name) : '';
  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return new RecorderFailure('denied');
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError') {
    return new RecorderFailure('no_mic');
  }
  return new RecorderFailure('error');
}

/**
 * Microphone recording for voice dictation: mono, low bitrate, hard 120 s limit, the mic is released after every
 * recording. `done` resolves with the recording (null — cancelled or shorter than 0.5 s).
 */
@Injectable({ providedIn: 'root' })
export class AssistantRecorder {
  private readonly env = inject(RECORDER_ENV);
  private recorder: MediaRecorder | null = null;
  private stream: MediaStream | null = null;
  private chunks: Blob[] = [];
  private startedAt = 0;
  private limitTimer: ReturnType<typeof setTimeout> | null = null;
  private cancelled = false;
  private resolveDone: ((r: Recording | null) => void) | null = null;
  private ctx: AudioContext | null = null;
  private analyser: AnalyserNode | null = null;
  private samples: Uint8Array<ArrayBuffer> | null = null;
  private freq: Uint8Array<ArrayBuffer> | null = null;
  private readonly active = signal(false);

  readonly recording = this.active.asReadonly();
  /** Resolves when the current recording ends. */
  done: Promise<Recording | null> = Promise.resolve(null);
  mimeType = '';

  get supported(): boolean {
    return !!this.env.MediaRecorder && !!this.env.getUserMedia;
  }

  async start(): Promise<void> {
    const Recorder = this.env.MediaRecorder;
    const getUserMedia = this.env.getUserMedia;
    if (!Recorder || !getUserMedia) {
      throw new RecorderFailure('unsupported');
    }
    if (this.recorder) {
      return;
    }
    let stream: MediaStream;
    try {
      stream = await getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
    } catch (e: unknown) {
      throw failureOf(e);
    }
    this.mimeType = pickMimeType((t) => Recorder.isTypeSupported(t));
    let recorder: MediaRecorder;
    try {
      recorder = new Recorder(stream, { ...(this.mimeType ? { mimeType: this.mimeType } : {}), audioBitsPerSecond: AUDIO_BITRATE });
    } catch {
      stream.getTracks().forEach((t) => t.stop());
      throw new RecorderFailure('error');
    }
    this.stream = stream;
    this.recorder = recorder;
    this.chunks = [];
    this.cancelled = false;
    this.done = new Promise<Recording | null>((resolve) => (this.resolveDone = resolve));
    recorder.addEventListener('dataavailable', (e: BlobEvent) => {
      if (e.data && e.data.size > 0) {
        this.chunks.push(e.data);
      }
    });
    recorder.addEventListener('stop', () => this.finish());
    recorder.start(1000);
    this.startedAt = this.env.now();
    this.limitTimer = setTimeout(() => this.stop(), MAX_RECORD_MS);
    this.attachAnalyser(stream);
    this.active.set(true);
  }

  /** Stops and delivers the recording through `done`. */
  stop(): void {
    this.clearLimit();
    if (this.recorder && this.recorder.state !== 'inactive') {
      this.recorder.stop();
    } else {
      this.finish();
    }
  }

  /** Stops and throws the audio away. */
  cancel(): void {
    this.cancelled = true;
    this.stop();
  }

  elapsedMs(): number {
    return this.active() ? this.env.now() - this.startedAt : 0;
  }

  /** Voice loudness 0..1 (RMS of the waveform). */
  level(): number {
    const a = this.analyser;
    const buf = this.samples;
    if (!a || !buf) {
      return 0;
    }
    a.getByteTimeDomainData(buf);
    let sum = 0;
    for (const sample of buf) {
      const v = (sample - 128) / 128;
      sum += v * v;
    }
    return Math.min(1, Math.sqrt(sum / buf.length) * 3.2);
  }

  /** Fills `out` (BAR_COUNT values 0..1) from the spectrum — the live level bars. */
  fillBars(out: Float32Array): void {
    const a = this.analyser;
    const buf = this.freq;
    if (!a || !buf) {
      out.fill(0);
      return;
    }
    a.getByteFrequencyData(buf);
    const per = Math.max(1, Math.floor(buf.length / out.length));
    for (let i = 0; i < out.length; i++) {
      let m = 0;
      for (let k = 0; k < per; k++) {
        m = Math.max(m, buf[i * per + k] ?? 0);
      }
      out[i] = m / 255;
    }
  }

  private attachAnalyser(stream: MediaStream): void {
    const Ctx = this.env.AudioContext;
    if (!Ctx) {
      return;
    }
    try {
      this.ctx = new Ctx();
      const source = this.ctx.createMediaStreamSource(stream);
      this.analyser = this.ctx.createAnalyser();
      this.analyser.fftSize = 256;
      source.connect(this.analyser);
      this.samples = new Uint8Array(new ArrayBuffer(this.analyser.fftSize));
      this.freq = new Uint8Array(new ArrayBuffer(this.analyser.frequencyBinCount));
    } catch {
      this.analyser = null;
    }
  }

  private finish(): void {
    const ms = this.env.now() - this.startedAt;
    const mime = this.recorder?.mimeType || this.mimeType || 'audio/webm';
    const blob = new Blob(this.chunks, { type: mime });
    const resolve = this.resolveDone;
    const keep = !this.cancelled && ms >= MIN_RECORD_MS && blob.size > 0;
    this.release();
    resolve?.(keep ? { blob, mime, ms } : null);
  }

  /** Releases the microphone (the browser's recording indicator goes away). */
  private release(): void {
    this.clearLimit();
    this.stream?.getTracks().forEach((t) => t.stop());
    this.stream = null;
    this.recorder = null;
    this.resolveDone = null;
    this.chunks = [];
    this.analyser = null;
    this.samples = null;
    this.freq = null;
    void this.ctx?.close().catch(() => undefined);
    this.ctx = null;
    this.active.set(false);
  }

  private clearLimit(): void {
    if (this.limitTimer !== null) {
      clearTimeout(this.limitTimer);
      this.limitTimer = null;
    }
  }
}
