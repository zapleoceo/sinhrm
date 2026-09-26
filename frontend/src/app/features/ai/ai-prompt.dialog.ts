import { DatePipe, DecimalPipe, JsonPipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiPromptInfo, AiPurpose, AiTrialResult, AiTrialSide } from './ai.model';
import { AiService, aiCodeKey, aiErrorKey, promptProblemKeys } from './ai.service';

/**
 * Prompt editor of one AI purpose (superadmin): the instruction text (ROLE/TASK/RULES) and its version, the broker
 * capability, "Спробувати" (draft vs active on a built-in synthetic sample) and the list of saved versions with
 * rollback. The OUTPUT line, the JSON schema and parsing are code-owned and shown read-only. Closes with true when
 * something changed (the panel reloads its status).
 */
@Component({
  selector: 'app-ai-prompt-dialog',
  imports: [
    DatePipe,
    DecimalPipe,
    JsonPipe,
    FormsModule,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    TranslocoPipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>
      {{ 'ai.prompt.title' | transloco: { purpose: ('ai.purposes.' + purpose | transloco) } }}
    </h2>
    <mat-dialog-content>
      @if (busy()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (info(); as i) {
        <div class="meta">
          <span>
            {{ 'ai.prompt.version' | transloco }}: <code>{{ i.version }}</code>
            @if (i.active_version === null) {
              <span class="muted">({{ 'ai.prompt.builtin' | transloco }})</span>
            }
          </span>
          <mat-form-field appearance="outline" subscriptSizing="dynamic" class="cap">
            <mat-label>{{ 'ai.prompt.capability' | transloco }}</mat-label>
            <mat-select
              [value]="i.capability"
              (selectionChange)="setCapability($event.value)"
              [disabled]="busy()"
            >
              @for (c of i.capabilities; track c) {
                <mat-option [value]="c">{{ c }}</mat-option>
              }
            </mat-select>
          </mat-form-field>
        </div>

        <mat-form-field appearance="outline" class="body">
          <mat-label>{{ 'ai.prompt.text' | transloco }}</mat-label>
          <textarea
            matInput
            rows="12"
            [(ngModel)]="draft"
            [maxlength]="i.limits.max"
            spellcheck="false"
          ></textarea>
          <mat-hint>{{ 'ai.prompt.hint' | transloco }}</mat-hint>
          <mat-hint align="end">{{ draft.length }} / {{ i.limits.max }}</mat-hint>
        </mat-form-field>
        <p class="output">
          <mat-icon aria-hidden="true" inline>lock</mat-icon>
          <span class="muted">{{ 'ai.prompt.outputLocked' | transloco }}</span>
          <code>{{ i.output }}</code>
        </p>

        @for (key of problems(); track key) {
          <p class="notice error" role="alert">{{ key | transloco }}</p>
        }
        @if (error(); as key) {
          <p class="notice error" role="alert">{{ key | transloco }}</p>
        }

        @if (trial(); as t) {
          <div class="trial">
            @for (side of sides(t); track side.key) {
              <div class="side">
                <strong>{{ 'ai.prompt.' + side.key | transloco }}</strong>
                <code>{{ side.value.version }}</code>
                @if (side.value.status === 'done') {
                  <pre>{{ side.value.data | json }}</pre>
                  <span class="muted small">
                    {{
                      'ai.panel.tokens'
                        | transloco
                          : {
                              in: side.value.tokens_in ?? 0,
                              out: side.value.tokens_out ?? 0,
                              cached: side.value.tokens_cached ?? 0,
                            }
                    }}
                    · \${{ side.value.cost_usd ?? 0 | number: '1.0-4' }}
                  </span>
                } @else if (side.value.status === 'deferred') {
                  <p class="muted">{{ 'ai.panel.testDeferred' | transloco }}</p>
                } @else {
                  <p class="error">{{ sideErrorKey(side.value) | transloco }}</p>
                }
              </div>
            }
          </div>
        }

        <details class="versions">
          <summary>{{ 'ai.prompt.versions' | transloco: { count: i.versions.length } }}</summary>
          <ul>
            <li>
              <code>{{ i.builtin_version }}</code>
              <span class="muted">{{ 'ai.prompt.builtin' | transloco }}</span>
              @if (i.active_version === null) {
                <span class="badge">{{ 'ai.prompt.active' | transloco }}</span>
              } @else {
                <button mat-button type="button" [disabled]="busy()" (click)="restoreBuiltin()">
                  {{ 'ai.prompt.restoreBuiltin' | transloco }}
                </button>
              }
            </li>
            @for (v of i.versions; track v.id) {
              <li>
                <code>{{ v.version }}</code>
                <span class="muted"
                  >{{ v.author ?? '—' }} · {{ v.created_at | date: 'short' }}</span
                >
                @if (v.is_active) {
                  <span class="badge">{{ 'ai.prompt.active' | transloco }}</span>
                } @else {
                  <button mat-button type="button" [disabled]="busy()" (click)="activate(v.id)">
                    {{ 'ai.prompt.activate' | transloco }}
                  </button>
                }
                <button mat-button type="button" (click)="draft = v.body">
                  {{ 'ai.prompt.load' | transloco }}
                </button>
              </li>
            }
          </ul>
        </details>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" [mat-dialog-close]="changed">
        {{ 'common.close' | transloco }}
      </button>
      <button mat-stroked-button type="button" [disabled]="busy() || !info()" (click)="runTrial()">
        <mat-icon>science</mat-icon>
        {{ 'ai.prompt.try' | transloco }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="busy() || !info() || draft === info()?.body"
        (click)="save()"
      >
        <mat-icon>save</mat-icon>
        {{ 'ai.prompt.save' | transloco }}
      </button>
    </mat-dialog-actions>
  `,
  styles: `
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      justify-content: space-between;
      margin-top: 0.5rem;
    }
    .cap {
      width: 12rem;
    }
    .body {
      width: 100%;
      margin-top: 0.5rem;
    }
    .body textarea {
      font-family: monospace;
      font-size: 0.85rem;
    }
    .output {
      display: flex;
      gap: 0.35rem;
      align-items: baseline;
      flex-wrap: wrap;
      font-size: 0.8rem;
      margin: 0.25rem 0;
    }
    .output code {
      word-break: break-all;
    }
    .notice,
    .error {
      margin: 0.25rem 0;
    }
    .error {
      color: var(--app-danger);
    }
    .trial {
      display: grid;
      gap: 0.75rem;
      grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
      margin-top: 0.5rem;
    }
    .side pre {
      max-height: 16rem;
      overflow: auto;
      font-size: 0.75rem;
      white-space: pre-wrap;
      border: 1px solid var(--app-border);
      border-radius: 8px;
      padding: 0.5rem;
    }
    .versions {
      margin-top: 0.75rem;
    }
    .versions ul {
      list-style: none;
      padding: 0;
      margin: 0.25rem 0 0;
      display: grid;
      gap: 0.25rem;
    }
    .versions li {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      flex-wrap: wrap;
    }
    .badge {
      font-size: 0.75rem;
      border: 1px solid var(--app-border);
      border-radius: 999px;
      padding: 0 0.5rem;
    }
    .small {
      font-size: 0.8rem;
    }
  `,
})
export class AiPromptDialog implements OnInit {
  private readonly api = inject(AiService);

  protected readonly purpose = inject<AiPurpose>(MAT_DIALOG_DATA);
  protected readonly info = signal<AiPromptInfo | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly problems = signal<string[]>([]);
  protected readonly trial = signal<AiTrialResult | null>(null);
  protected draft = '';
  protected changed = false;

  ngOnInit(): void {
    this.call(this.api.prompt(this.purpose), false);
  }

  protected save(): void {
    this.call(this.api.savePrompt(this.purpose, this.draft), true);
  }

  protected activate(id: number): void {
    this.call(this.api.activatePrompt(this.purpose, id), true);
  }

  protected restoreBuiltin(): void {
    this.call(this.api.restoreBuiltinPrompt(this.purpose), true);
  }

  protected setCapability(capability: string): void {
    this.call(this.api.setCapability(this.purpose, capability), true, false);
  }

  protected runTrial(): void {
    this.start();
    this.trial.set(null);
    this.api.tryPrompt(this.purpose, this.draft).subscribe({
      next: (t) => {
        this.trial.set(t);
        this.busy.set(false);
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  protected sides(t: AiTrialResult): { key: 'draft' | 'active'; value: AiTrialSide }[] {
    return [
      { key: 'draft', value: t.draft },
      { key: 'active', value: t.active },
    ];
  }

  protected sideErrorKey(side: AiTrialSide): string {
    return aiCodeKey(side.error) ?? 'ai.errors.generic';
  }

  private call(
    request: ReturnType<AiService['prompt']>,
    changes: boolean,
    resetDraft = true,
  ): void {
    this.start();
    request.subscribe({
      next: (info) => {
        this.info.set(info);
        if (resetDraft) {
          this.draft = info.body;
        }
        this.changed ||= changes;
        this.busy.set(false);
      },
      error: (e: unknown) => this.fail(e),
    });
  }

  private start(): void {
    this.busy.set(true);
    this.error.set(null);
    this.problems.set([]);
  }

  private fail(e: unknown): void {
    const problems = promptProblemKeys(e);
    this.problems.set(problems);
    this.error.set(problems.length ? null : aiErrorKey(e));
    this.busy.set(false);
  }
}
