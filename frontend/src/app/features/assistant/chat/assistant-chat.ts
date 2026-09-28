import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, NgZone, afterNextRender, computed, effect, inject, signal, untracked, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AssistantConversation, unavailableKey } from '../assistant-conversation';
import { AssistantSettings } from '../assistant-settings';
import { TextSegment, splitLink, toSegments } from '../assistant-text';
import { AssistantConfirm } from './assistant-confirm';
import { AssistantMcpPanel } from './assistant-mcp-panel';
import { BAR_COUNT } from '../voice/assistant-recorder';
import { AssistantVoice } from '../voice/assistant-voice';

interface RenderedMessage {
  role: 'user' | 'assistant';
  segments: (TextSegment & { route?: string; query?: Record<string, string> })[];
}

export const SUGGESTION_COUNT = 4;

/** Dictated text goes after what is already typed, separated by a space. */
export function appendText(draft: string, text: string): string {
  const head = draft.replace(/\s+$/, '');
  return head ? `${head} ${text}` : text;
}

/** mm:ss of a duration. */
export function formatClock(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/** Chat card of «Стік» (bottom-right; a bottom sheet on narrow screens). */
@Component({
  selector: 'app-assistant-chat',
  imports: [
    FormsModule,
    RouterLink,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    MatProgressSpinnerModule,
    TranslocoPipe,
    AssistantConfirm,
    AssistantMcpPanel,
  ],
  host: { '(keydown.escape)': 'onEscape()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './assistant-chat.html',
  styleUrl: './assistant-chat.scss',
})
export class AssistantChat {
  protected readonly conversation = inject(AssistantConversation);
  protected readonly settings = inject(AssistantSettings);
  protected readonly voice = inject(AssistantVoice);
  private readonly i18n = inject(TranslocoService);
  private readonly zone = inject(NgZone);
  private readonly list = viewChild<ElementRef<HTMLElement>>('list');
  private readonly inputRef = viewChild<ElementRef<HTMLTextAreaElement>>('input');
  private readonly barsRef = viewChild<ElementRef<SVGSVGElement>>('bars');

  protected readonly draft = signal('');
  protected readonly loading = signal(true);
  protected readonly suggestions = Array.from({ length: SUGGESTION_COUNT }, (_, i) => `assistant.suggestions.${i}`);
  protected readonly status = this.conversation.status;
  protected readonly unavailable = computed(() => {
    const s = this.status();
    return s && !s.available ? unavailableKey(s.reason) : null;
  });
  protected readonly messages = computed<RenderedMessage[]>(() =>
    this.conversation.transcript().map((m) => ({
      role: m.role,
      segments: m.role === 'user' ? [{ kind: 'text', text: m.text }] : toSegments(m.text).map((s) => (s.kind === 'link' ? { ...s, ...splitLink(s.path) } : s)),
    })),
  );
  protected readonly recording = computed(() => this.voice.state() === 'recording');
  protected readonly transcribing = computed(() => this.voice.state() === 'transcribing');
  /** The mic is offered only when the browser can record and the assistant is available. */
  protected readonly micAvailable = computed(() => this.voice.supported && this.status()?.available === true);
  protected readonly placeholder = computed(() =>
    this.transcribing() ? (this.voice.slow() ? 'assistant.voice.stillListening' : 'assistant.voice.transcribing') : 'assistant.input.placeholder',
  );
  protected readonly clock = signal('00:00');
  /** x positions of the level bars (ink strokes in a 100x24 box). */
  protected readonly barSlots = Array.from({ length: BAR_COUNT }, (_, i) => 3 + i * (94 / (BAR_COUNT - 1)));
  private readonly barLevels = new Float32Array(BAR_COUNT);
  private barFrame: number | null = null;
  private clockTimer: ReturnType<typeof setInterval> | null = null;
  protected readonly canSend = computed(() => !this.conversation.busy() && !this.conversation.pendingWrite() && this.draft().trim().length > 0);

  constructor() {
    void this.conversation.loadStatus().finally(() => this.loading.set(false));
    inject(DestroyRef).onDestroy(() => {
      this.stopMeters();
      this.voice.cancel();
    });
    // Recording: mm:ss clock and live level bars (drawn outside change detection).
    effect(() => {
      const on = this.recording();
      untracked(() => (on ? this.startMeters() : this.stopMeters()));
    });
    // Dictated text lands in the input and is never sent automatically.
    effect(() => {
      const d = this.voice.dictated();
      if (d) {
        untracked(() => this.insertDictation(d.text));
      }
    });
    afterNextRender(() => this.inputRef()?.nativeElement.focus());
    // Keep the newest message in view.
    effect(() => {
      this.messages();
      this.conversation.busy();
      this.conversation.pendingWrite();
      untracked(() => queueMicrotask(() => this.scrollDown()));
    });
  }

  protected send(text?: string): void {
    const value = (text ?? this.draft()).trim();
    if (!value || this.conversation.busy() || this.conversation.pendingWrite()) {
      return;
    }
    this.draft.set('');
    void this.conversation.send(value);
  }

  /** Quick suggestion chip: sends its text in the current language. */
  protected ask(key: string): void {
    this.send(this.i18n.translate(key));
  }

  protected onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      this.send();
    }
  }

  protected toggleMic(): void {
    void this.voice.toggle();
  }

  protected onEscape(): void {
    if (this.recording()) {
      this.voice.cancel();
    } else {
      this.close();
    }
  }

  protected decide(ok: boolean): void {
    this.conversation.confirmWrite(ok);
  }

  protected close(): void {
    this.settings.closeChat();
  }

  protected newChat(): void {
    this.conversation.reset();
    this.settings.showPanel('chat');
  }

  protected showMcp(): void {
    this.settings.showPanel('mcp');
  }

  protected toggleEnabled(): void {
    this.settings.toggleEnabled();
  }

  private insertDictation(text: string): void {
    this.draft.update((d) => appendText(d, text));
    queueMicrotask(() => {
      const el = this.inputRef()?.nativeElement;
      if (el) {
        el.focus();
        el.setSelectionRange(el.value.length, el.value.length);
      }
    });
  }

  private startMeters(): void {
    this.clock.set('00:00');
    this.clockTimer ??= setInterval(() => this.clock.set(formatClock(this.voice.elapsedMs())), 250);
    const raf = globalThis.requestAnimationFrame;
    if (typeof raf !== 'function' || this.barFrame !== null) {
      return;
    }
    this.zone.runOutsideAngular(() => {
      const draw = (): void => {
        const lines = this.barsRef()?.nativeElement.querySelectorAll('line');
        if (lines) {
          this.voice.fillBars(this.barLevels);
          lines.forEach((line, i) => {
            const h = 1.5 + this.barLevels[i] * 10;
            line.setAttribute('y1', (12 - h).toFixed(1));
            line.setAttribute('y2', (12 + h).toFixed(1));
          });
        }
        this.barFrame = this.recording() ? raf(draw) : null;
      };
      this.barFrame = raf(draw);
    });
  }

  private stopMeters(): void {
    if (this.clockTimer !== null) {
      clearInterval(this.clockTimer);
      this.clockTimer = null;
    }
    if (this.barFrame !== null) {
      globalThis.cancelAnimationFrame?.(this.barFrame);
      this.barFrame = null;
    }
  }

  private scrollDown(): void {
    const el = this.list()?.nativeElement;
    if (el) {
      el.scrollTop = el.scrollHeight;
    }
  }
}
