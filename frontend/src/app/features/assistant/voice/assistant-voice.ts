import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { turnErrorKey } from '../assistant-conversation';
import { TranscriptionResult } from '../assistant.model';
import { AssistantService } from '../assistant.service';
import { AssistantRecorder, MAX_AUDIO_BYTES, RecorderFailure, Recording, audioFileName } from './assistant-recorder';

export const TRANSCRIBE_POLL_MS = 2000;
export const TRANSCRIBE_TIMEOUT_MS = 90_000;
/** After this the input says «ще слухаю…» (the broker's slow fallback can take minutes). */
export const TRANSCRIBE_SLOW_MS = 20_000;

export type VoiceState = 'idle' | 'recording' | 'transcribing';

/** Dictated text waiting to be put into the input; seq makes the same text twice a new event. */
export interface Dictation {
  text: string;
  seq: number;
}

/** i18n key of a failed transcription. */
export function transcriptionErrorKey(code: string | null | undefined): string {
  if (code === 'empty_transcript') {
    return 'assistant.voice.empty';
  }
  return turnErrorKey(code);
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Voice dictation: record → upload → poll the transcription → hand the text to the chat input.
 * Never sends the message by itself — the user edits and sends.
 */
@Injectable({ providedIn: 'root' })
export class AssistantVoice {
  private readonly api = inject(AssistantService);
  private readonly recorder = inject(AssistantRecorder);
  private readonly stateSignal = signal<VoiceState>('idle');
  private readonly error = signal<string | null>(null);
  private readonly slowSignal = signal(false);
  private readonly dictation = signal<Dictation | null>(null);

  readonly state = this.stateSignal.asReadonly();
  readonly errorKey = this.error.asReadonly();
  /** Transcription takes long: show «ще слухаю…». */
  readonly slow = this.slowSignal.asReadonly();
  /** Latest dictated text for the input. */
  readonly dictated = this.dictation.asReadonly();

  get supported(): boolean {
    return this.recorder.supported;
  }

  /** Microphone loudness 0..1 (read per frame by the level bars and the mascot). */
  level(): number {
    return this.stateSignal() === 'recording' ? this.recorder.level() : 0;
  }

  fillBars(out: Float32Array): void {
    this.recorder.fillBars(out);
  }

  elapsedMs(): number {
    return this.recorder.elapsedMs();
  }

  /** Mic button: start, or stop and transcribe. */
  async toggle(): Promise<void> {
    if (this.stateSignal() === 'recording') {
      this.recorder.stop();
    } else if (this.stateSignal() === 'idle') {
      await this.start();
    }
  }

  cancel(): void {
    if (this.stateSignal() === 'recording') {
      this.stateSignal.set('idle');
      this.recorder.cancel();
    }
  }

  clearError(): void {
    this.error.set(null);
  }

  private async start(): Promise<void> {
    this.error.set(null);
    try {
      await this.recorder.start();
    } catch (e: unknown) {
      const code = e instanceof RecorderFailure ? e.code : 'error';
      this.error.set(code === 'denied' ? 'assistant.voice.denied' : code === 'no_mic' ? 'assistant.voice.noMic' : 'assistant.voice.error');
      return;
    }
    this.stateSignal.set('recording');
    void this.recorder.done.then((rec) => this.recorded(rec));
  }

  private async recorded(rec: Recording | null): Promise<void> {
    if (this.stateSignal() !== 'recording') {
      return;
    }
    if (!rec) {
      this.stateSignal.set('idle');
      return;
    }
    if (rec.blob.size > MAX_AUDIO_BYTES) {
      this.error.set('assistant.voice.tooBig');
      this.stateSignal.set('idle');
      return;
    }
    this.stateSignal.set('transcribing');
    this.slowSignal.set(false);
    try {
      const result = await this.transcribe(rec);
      const text = result.text?.trim() ?? '';
      if (result.state === 'done' && text) {
        this.dictation.update((d) => ({ text, seq: (d?.seq ?? 0) + 1 }));
      } else {
        this.error.set(transcriptionErrorKey(result.state === 'pending' ? 'ai_timeout' : result.state === 'done' ? 'empty_transcript' : result.error));
      }
    } catch (e: unknown) {
      this.error.set(e instanceof HttpErrorResponse && e.status === 429 ? 'assistant.errors.throttled' : this.httpKey(e));
    } finally {
      this.slowSignal.set(false);
      this.stateSignal.set('idle');
    }
  }

  private async transcribe(rec: Recording): Promise<TranscriptionResult> {
    const started = Date.now();
    let result = await firstValueFrom(this.api.transcribe(rec.blob, audioFileName(rec.mime)));
    while (result.state === 'pending' && Date.now() - started < TRANSCRIBE_TIMEOUT_MS) {
      await sleep(TRANSCRIBE_POLL_MS);
      if (Date.now() - started >= TRANSCRIBE_SLOW_MS) {
        this.slowSignal.set(true);
      }
      result = await firstValueFrom(this.api.transcription(result.request_id));
    }
    return result;
  }

  private httpKey(e: unknown): string {
    if (e instanceof HttpErrorResponse) {
      const code: unknown = (e.error as { code?: unknown } | null)?.code;
      if (typeof code === 'string') {
        return transcriptionErrorKey(code);
      }
    }
    return 'assistant.voice.error';
  }
}
