import { ChangeDetectionStrategy, Component, ElementRef, input, output, viewChild } from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe } from '@jsverse/transloco';

export type MarkdownFormat = 'bold' | 'italic' | 'ol' | 'ul' | 'clear';

export interface FormattedText {
  text: string;
  start: number;
  end: number;
}

const LIST_PREFIX = /^(\s*)(?:[-*+]\s+|\d+\.\s+)/;

/**
 * Applies a toolbar action to the selection [start, end) of Markdown text. Pure (unit-tested): bold/italic wrap the
 * selection, lists prefix every selected line, clear removes emphasis markers and list prefixes (whole text when
 * nothing is selected). The text stays Markdown: the server renders it with raw HTML escaped.
 */
export function applyMarkdown(text: string, start: number, end: number, format: MarkdownFormat): FormattedText {
  if (format === 'bold' || format === 'italic') {
    const mark = format === 'bold' ? '**' : '*';
    const selected = text.slice(start, end);
    const next = text.slice(0, start) + mark + selected + mark + text.slice(end);
    return { text: next, start: start + mark.length, end: end + mark.length };
  }
  if (format === 'clear') {
    const [from, to] = start === end ? [0, text.length] : [start, end];
    const cleaned = text
      .slice(from, to)
      .split('\n')
      .map((line) => line.replace(LIST_PREFIX, '$1'))
      .join('\n')
      .replace(/\*\*|__|\*|_/g, '');
    return { text: text.slice(0, from) + cleaned + text.slice(to), start: from, end: from + cleaned.length };
  }
  // Lists work on whole lines touched by the selection.
  const lineStart = text.lastIndexOf('\n', start - 1) + 1;
  const nextBreak = text.indexOf('\n', end);
  const lineEnd = nextBreak === -1 ? text.length : nextBreak;
  const lines = text.slice(lineStart, lineEnd).split('\n');
  const block = lines.map((line, i) => (format === 'ol' ? `${i + 1}. ` : '- ') + line.replace(LIST_PREFIX, '$1')).join('\n');
  return { text: text.slice(0, lineStart) + block + text.slice(lineEnd), start: lineStart, end: lineStart + block.length };
}

/**
 * A Markdown section of the vacancy form: minimal toolbar (bold, italic, lists, clear) over a textarea and the
 * «Створити з ШІ» button (the parent runs the request; this only shows busy / hint states).
 */
@Component({
  selector: 'app-markdown-field',
  imports: [ReactiveFormsModule, MatButtonModule, MatIconModule, MatProgressSpinnerModule, MatTooltipModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="head">
      <label class="label" [for]="fieldId()">{{ label() }}</label>
      <button mat-stroked-button type="button" class="ai" [disabled]="aiBusy() || !aiReady()" (click)="ai.emit()">
        @if (aiBusy()) {
          <mat-spinner diameter="16" />
        } @else {
          <mat-icon>auto_awesome</mat-icon>
        }
        {{ 'recruiting.form.aiCreate' | transloco }}
      </button>
    </div>
    <div class="box" [class.invalid]="!!error()">
      <div class="toolbar" role="toolbar" [attr.aria-label]="'recruiting.form.toolbar.label' | transloco">
        @for (b of buttons; track b.format) {
          <button
            mat-icon-button
            type="button"
            [attr.aria-label]="'recruiting.form.toolbar.' + b.format | transloco"
            [matTooltip]="'recruiting.form.toolbar.' + b.format | transloco"
            (click)="format(b.format)"
          >
            <mat-icon>{{ b.icon }}</mat-icon>
          </button>
        }
      </div>
      <textarea #area [id]="fieldId()" [formControl]="control()" rows="6" maxlength="10000" [attr.aria-invalid]="!!error()"></textarea>
    </div>
    @if (aiHint(); as hint) {
      <p class="hint" role="status">{{ hint | transloco }}</p>
    }
    @if (error(); as e) {
      <p class="error" role="alert">{{ e | transloco }}</p>
    }
  `,
  styles: `
    :host { display: block; }
    .head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem; }
    .label { font-weight: 600; }
    .ai mat-spinner { display: inline-block; margin-right: 0.4rem; }
    .box { border: 1px solid var(--app-border); border-radius: var(--app-radius, 8px); overflow: hidden; }
    .box:focus-within { border-color: var(--mat-sys-primary); }
    .box.invalid { border-color: var(--app-danger); }
    .toolbar { display: flex; gap: 0.125rem; padding: 0.125rem 0.25rem; border-bottom: 1px solid var(--app-border); }
    textarea {
      display: block; width: 100%; box-sizing: border-box; border: 0; outline: none; resize: vertical; padding: 0.75rem;
      font: inherit; color: inherit; background: transparent; min-height: 8rem;
    }
    .hint { color: var(--app-warning); margin: 0.25rem 0 0; font-size: 0.875rem; }
    .error { color: var(--app-danger); margin: 0.25rem 0 0; font-size: 0.875rem; }
  `,
})
export class MarkdownField {
  readonly control = input.required<FormControl<string>>();
  readonly label = input.required<string>();
  readonly fieldId = input.required<string>();
  readonly aiBusy = input(false);
  /** The AI button needs at least a title. */
  readonly aiReady = input(true);
  /** i18n key: why AI did not help (disabled, limit, …). */
  readonly aiHint = input<string | null>(null);
  /** i18n key of a server validation error. */
  readonly error = input<string | null>(null);
  readonly ai = output();

  protected readonly buttons: readonly { format: MarkdownFormat; icon: string }[] = [
    { format: 'bold', icon: 'format_bold' },
    { format: 'italic', icon: 'format_italic' },
    { format: 'ol', icon: 'format_list_numbered' },
    { format: 'ul', icon: 'format_list_bulleted' },
    { format: 'clear', icon: 'format_clear' },
  ];

  private readonly area = viewChild.required<ElementRef<HTMLTextAreaElement>>('area');

  protected format(format: MarkdownFormat): void {
    const el = this.area().nativeElement;
    const result = applyMarkdown(this.control().value, el.selectionStart, el.selectionEnd, format);
    this.control().setValue(result.text);
    this.control().markAsDirty();
    el.focus();
    el.setSelectionRange(result.start, result.end);
  }
}
