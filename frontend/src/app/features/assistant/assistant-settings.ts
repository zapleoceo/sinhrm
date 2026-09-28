import { Injectable, signal } from '@angular/core';
import { safeStorage } from '../../core/storage/safe-storage';

/** '0' — «Стік» is switched off (curled into the corner circle); anything else — on. */
export const ENABLED_KEY = 'sinhrm.assistant.enabled';
/** '1' — «Легкий режим анімації»: no line boil, no particles, 30 fps cap. */
export const LITE_KEY = 'sinhrm.assistant.lite';

export type AssistantPanel = 'chat' | 'mcp';

/** UI state of the assistant: on/off and lite animation (remembered in this browser) and the chat panel. */
@Injectable({ providedIn: 'root' })
export class AssistantSettings {
  private readonly enabledState = signal(safeStorage.get(ENABLED_KEY) !== '0');
  private readonly liteState = signal(safeStorage.get(LITE_KEY) === '1');
  private readonly open = signal(false);
  private readonly panelState = signal<AssistantPanel>('chat');

  /** On: he walks around and pops up by himself. Off: a small circle with eyes in the corner, no quips. */
  readonly enabled = this.enabledState.asReadonly();
  /** The user asked for lighter animation (the mascot also goes lite by itself on weak machines). */
  readonly lite = this.liteState.asReadonly();
  readonly chatOpen = this.open.asReadonly();
  readonly panel = this.panelState.asReadonly();

  setLite(on: boolean): void {
    this.liteState.set(on);
    safeStorage.set(LITE_KEY, on ? '1' : '0');
  }

  setEnabled(on: boolean): void {
    this.enabledState.set(on);
    safeStorage.set(ENABLED_KEY, on ? '1' : '0');
  }

  toggleEnabled(): void {
    this.setEnabled(!this.enabledState());
  }

  openChat(panel: AssistantPanel = 'chat'): void {
    this.panelState.set(panel);
    this.open.set(true);
  }

  showPanel(panel: AssistantPanel): void {
    this.panelState.set(panel);
  }

  closeChat(): void {
    this.open.set(false);
  }
}
