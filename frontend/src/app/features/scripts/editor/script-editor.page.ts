import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatChipsModule } from '@angular/material/chips';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { AuthService } from '../../../core/auth/auth.service';
import { EvaluationView } from '../evaluation/evaluation-view';
import { canManageScripts } from '../scripts.access';
import { FOLLOWUP_CONDITIONS, NextStepPatterns, PREVIEW_VALUES, ScriptStep, TEMPLATE_VARIABLES, renderTemplate, splitList, unknownTokens } from '../scripts.model';
import { scriptsErrorKey } from '../scripts.service';
import { ScriptEditorStore } from './script-editor.store';

/**
 * Script editor (Admin → Скрипти → script): tabs for steps (drag to reorder), objections, message templates
 * (variable chips + live preview), follow-up rules, "test on text" and versions. Edits go to the draft;
 * "Опублікувати" freezes it as a new active version (older versions stay and can be re-activated).
 */
@Component({
  selector: 'app-script-editor-page',
  imports: [
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    DatePipe,
    MatButtonModule,
    MatButtonToggleModule,
    MatCheckboxModule,
    MatChipsModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatTabsModule,
    MatTooltipModule,
    RouterLink,
    TranslocoPipe,
    EvaluationView,
  ],
  providers: [ScriptEditorStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './script-editor.page.html',
  styleUrl: './script-editor.page.scss',
})
export class ScriptEditorPage {
  readonly id = input.required({ transform: numberAttribute });

  protected readonly store = inject(ScriptEditorStore);
  private readonly auth = inject(AuthService);
  private readonly snack = inject(MatSnackBar);
  private readonly i18n = inject(TranslocoService);

  protected readonly conditions = FOLLOWUP_CONDITIONS;
  protected readonly variables = TEMPLATE_VARIABLES;
  protected readonly canEdit = computed(() => canManageScripts(this.auth.user()?.roles ?? []) && !this.store.script()?.archived);
  protected readonly testText = signal('');
  protected readonly testVersion = signal<'draft' | 'active'>('draft');

  constructor() {
    effect(() => this.store.load(this.id()));
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected num(event: Event): number {
    const n = Number(this.val(event));
    return Number.isFinite(n) ? Math.max(0, Math.round(n)) : 0;
  }

  protected list(event: Event): string[] {
    return splitList(this.val(event));
  }

  protected join(items: readonly string[]): string {
    return items.join(', ');
  }

  protected preview(text: string): string {
    return renderTemplate(text, PREVIEW_VALUES);
  }

  protected unknown(text: string): string {
    return unknownTokens(text).join(', ');
  }

  protected totalWeight(steps: readonly ScriptStep[]): number {
    return steps.reduce((sum, s) => sum + s.weight, 0);
  }

  protected dropStep(event: CdkDragDrop<ScriptStep[]>): void {
    this.store.moveStep(event.previousIndex, event.currentIndex);
  }

  /** Inserts {Variable} at the caret of the template textarea. */
  protected insertVariable(index: number, area: HTMLTextAreaElement, name: string): void {
    const token = `{${name}}`;
    const start = area.selectionStart ?? area.value.length;
    const end = area.selectionEnd ?? start;
    const text = area.value.slice(0, start) + token + area.value.slice(end);
    this.store.updateTemplate(index, { text });
    queueMicrotask(() => {
      area.focus();
      area.setSelectionRange(start + token.length, start + token.length);
    });
  }

  protected setPatterns(kind: keyof NextStepPatterns, event: Event): void {
    this.store.setPatterns(kind, this.list(event));
  }

  protected save(): void {
    this.store.saveDraft().subscribe({ next: () => this.toast('scripts.editor.saved'), error: (e: unknown) => this.toast(scriptsErrorKey(e)) });
  }

  protected publish(): void {
    this.store.publish().subscribe({ next: () => this.toast('scripts.editor.published'), error: (e: unknown) => this.toast(scriptsErrorKey(e)) });
  }

  protected activate(version: number): void {
    this.store.activate(version).subscribe({
      next: () => this.toast('scripts.versions.activated'),
      error: (e: unknown) => this.toast(scriptsErrorKey(e)),
    });
  }

  protected archive(archived: boolean): void {
    this.store.setArchived(archived).subscribe({ error: (e: unknown) => this.toast(scriptsErrorKey(e)) });
  }

  protected runTest(): void {
    this.store.runTest(this.testText(), this.testVersion());
  }

  private toast(key: string): void {
    this.snack.open(this.i18n.translate(key), undefined, { duration: 3000 });
  }
}
