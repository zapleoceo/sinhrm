import { Clipboard } from '@angular/cdk/clipboard';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { firstValueFrom } from 'rxjs';
import { McpTokenInfo } from '../assistant.model';
import { AssistantService } from '../assistant.service';

const TOKEN_PLACEHOLDER = '<TOKEN>';

/** Claude Code command that connects the SinHRM MCP server. */
export function claudeCodeCommand(url: string, token: string): string {
  return `claude mcp add --transport http sinhrm ${url} --header "Authorization: Bearer ${token}"`;
}

/** Claude Desktop config (claude_desktop_config.json) via the mcp-remote bridge. */
export function claudeDesktopConfig(url: string, token: string): string {
  return JSON.stringify(
    { mcpServers: { sinhrm: { command: 'npx', args: ['-y', 'mcp-remote', url, '--header', `Authorization: Bearer ${token}`] } } },
    null,
    2,
  );
}

/** «Підключити до Claude / MCP»: personal MCP token (shown once) and ready-made configs. */
@Component({
  selector: 'app-assistant-mcp-panel',
  imports: [DatePipe, MatButtonModule, MatIconModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="mcp">
      <button mat-button type="button" class="back" (click)="back.emit()">
        <mat-icon>arrow_back</mat-icon>{{ 'assistant.mcp.back' | transloco }}
      </button>
      <h3>{{ 'assistant.mcp.title' | transloco }}</h3>
      <p class="muted">{{ 'assistant.mcp.intro' | transloco }}</p>

      <span class="label">{{ 'assistant.mcp.url' | transloco }}</span>
      <div class="copy-row">
        <code>{{ url() || '—' }}</code>
        @if (url()) {
          <button mat-icon-button type="button" [attr.aria-label]="'assistant.mcp.copy' | transloco" (click)="copy(url())"><mat-icon>content_copy</mat-icon></button>
        }
      </div>

      <span class="label">{{ 'assistant.mcp.token' | transloco }}</span>
      @if (error()) {
        <p class="error" role="alert">{{ 'assistant.mcp.loadError' | transloco }}</p>
      }
      @if (info(); as i) {
        <p class="status">
          @if (i.active) {
            {{ 'assistant.mcp.active' | transloco }}
            @if (i.created_at) { · {{ 'assistant.mcp.created' | transloco }} {{ i.created_at | date: 'dd.MM.yyyy HH:mm' }} }
            · {{ 'assistant.mcp.lastUsed' | transloco }}
            {{ i.last_used_at ? (i.last_used_at | date: 'dd.MM.yyyy HH:mm') : ('assistant.mcp.never' | transloco) }}
            @if (i.expires_at) { · {{ 'assistant.mcp.expires' | transloco }} {{ i.expires_at | date: 'dd.MM.yyyy' }} }
          } @else {
            {{ 'assistant.mcp.inactive' | transloco }}
          }
        </p>
      }
      @if (token(); as t) {
        <div class="token" role="status">
          <strong>{{ 'assistant.mcp.tokenOnce' | transloco }}</strong>
          <p class="warning">{{ 'assistant.mcp.warning' | transloco }}</p>
          <div class="copy-row">
            <code>{{ t }}</code>
            <button mat-icon-button type="button" [attr.aria-label]="'assistant.mcp.copy' | transloco" (click)="copy(t)"><mat-icon>content_copy</mat-icon></button>
          </div>
        </div>
      }
      @if (!token()) {
        <p class="warning" role="note"><mat-icon aria-hidden="true">warning</mat-icon>{{ 'assistant.mcp.warning' | transloco }}</p>
      }
      <div class="actions">
        <button mat-flat-button type="button" [disabled]="busy()" (click)="create()">
          {{ (info()?.active ? 'assistant.mcp.recreate' : 'assistant.mcp.create') | transloco }}
        </button>
        @if (info()?.active) {
          <button mat-button type="button" [disabled]="busy()" (click)="revoke()">{{ 'assistant.mcp.revoke' | transloco }}</button>
        }
      </div>
      @if (copied()) {
        <p class="muted" role="status">{{ 'assistant.mcp.copied' | transloco }}</p>
      }

      <span class="label">{{ 'assistant.mcp.claudeCode' | transloco }}</span>
      <div class="snippet">
        <pre>{{ codeSnippet() }}</pre>
        <button mat-icon-button type="button" [attr.aria-label]="'assistant.mcp.copy' | transloco" (click)="copy(codeSnippet())"><mat-icon>content_copy</mat-icon></button>
      </div>
      <span class="label">{{ 'assistant.mcp.claudeDesktop' | transloco }}</span>
      <div class="snippet">
        <pre>{{ desktopSnippet() }}</pre>
        <button mat-icon-button type="button" [attr.aria-label]="'assistant.mcp.copy' | transloco" (click)="copy(desktopSnippet())"><mat-icon>content_copy</mat-icon></button>
      </div>
    </div>
  `,
  styles: `
    .mcp { display: flex; flex-direction: column; gap: 0.5rem; padding: 0.75rem 1rem 1rem; }
    .back { align-self: flex-start; }
    h3 { margin: 0; font: var(--mat-sys-title-medium); }
    .muted { margin: 0; color: var(--app-muted); font: var(--mat-sys-body-small); }
    .label { margin-top: 0.4rem; font: var(--mat-sys-label-medium); color: var(--app-muted); }
    .status { margin: 0; font: var(--mat-sys-body-small); }
    .error { margin: 0; color: var(--app-danger); font: var(--mat-sys-body-small); }
    .copy-row, .snippet { display: flex; align-items: flex-start; gap: 0.25rem; }
    code, pre {
      flex: 1;
      min-width: 0;
      margin: 0;
      padding: 0.4rem 0.5rem;
      border-radius: 8px;
      background: var(--mat-sys-surface-container);
      font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace;
      word-break: break-all;
      white-space: pre-wrap;
    }
    .token { padding: 0.5rem; border: 1.5px dashed var(--app-warning); border-radius: 8px; font: var(--mat-sys-body-small); }
    .actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .warning {
      display: flex;
      gap: 0.4rem;
      align-items: flex-start;
      margin: 0.25rem 0;
      padding: 0.5rem 0.6rem;
      border-left: 3px solid var(--app-warning);
      border-radius: 4px;
      background: color-mix(in srgb, var(--app-warning) 10%, transparent);
      font: var(--mat-sys-body-small);
    }
    .warning mat-icon { flex-shrink: 0; width: 18px; height: 18px; font-size: 18px; color: var(--app-warning); }
  `,
})
export class AssistantMcpPanel {
  private readonly api = inject(AssistantService);
  private readonly clipboard = inject(Clipboard);

  readonly url = input<string>('');
  readonly back = output<void>();

  protected readonly info = signal<McpTokenInfo | null>(null);
  protected readonly token = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal(false);
  protected readonly copied = signal(false);
  protected readonly codeSnippet = computed(() => claudeCodeCommand(this.url() || '<MCP_URL>', this.token() ?? TOKEN_PLACEHOLDER));
  protected readonly desktopSnippet = computed(() => claudeDesktopConfig(this.url() || '<MCP_URL>', this.token() ?? TOKEN_PLACEHOLDER));

  constructor() {
    void this.load();
  }

  protected async create(): Promise<void> {
    await this.run(async () => {
      const created = await firstValueFrom(this.api.createMcpToken());
      this.token.set(created.token);
      this.info.set({ active: created.active, created_at: created.created_at, last_used_at: created.last_used_at, expires_at: created.expires_at });
    });
  }

  protected async revoke(): Promise<void> {
    await this.run(async () => {
      await firstValueFrom(this.api.revokeMcpToken());
      this.token.set(null);
      this.info.set({ active: false, created_at: null, last_used_at: null, expires_at: null });
    });
  }

  protected copy(text: string): void {
    this.copied.set(this.clipboard.copy(text));
  }

  private async load(): Promise<void> {
    await this.run(async () => this.info.set(await firstValueFrom(this.api.mcpToken())));
  }

  private async run(action: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    this.error.set(false);
    try {
      await action();
    } catch {
      this.error.set(true);
    } finally {
      this.busy.set(false);
    }
  }
}
