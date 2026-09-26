import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslocoPipe } from '@jsverse/transloco';
import { DocumentTemplate } from '../../documents/documents.model';
import { StepConfig, StepConfigValue, WorkflowAction, WorkflowTemplate } from '../workflows.model';

export interface ConfigChange {
  key: string;
  value: StepConfigValue;
}

/** Action-specific settings of one workflow step; emits single key changes to the editor store. */
@Component({
  selector: 'app-step-config',
  imports: [MatCheckboxModule, MatFormFieldModule, MatInputModule, MatSelectModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @let c = config();
    @switch (action()) {
      @case ('create_task') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.taskTitle' | transloco }}</mat-label>
          <input matInput [value]="text(c['title'])" (input)="set('title', val($event))" [readonly]="readonly()" maxlength="200" />
        </mat-form-field>
      }
      @case ('assign_buddy') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.taskTitle' | transloco }}</mat-label>
          <input matInput [value]="text(c['title'])" (input)="set('title', val($event))" [readonly]="readonly()" maxlength="200" />
        </mat-form-field>
      }
      @case ('request_form') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.taskTitle' | transloco }}</mat-label>
          <input matInput [value]="text(c['title'])" (input)="set('title', val($event))" [readonly]="readonly()" maxlength="200" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.formUrl' | transloco }}</mat-label>
          <input matInput type="url" [value]="text(c['url'])" (input)="set('url', val($event))" [readonly]="readonly()" placeholder="https://" />
          @if (error('url'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
      }
      @case ('send_email_template') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.subject' | transloco }}</mat-label>
          <input matInput [value]="text(c['subject'])" (input)="set('subject', val($event))" [readonly]="readonly()" required maxlength="200" />
          @if (error('subject'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.body' | transloco }}</mat-label>
          <textarea matInput rows="4" [value]="text(c['body'])" (input)="set('body', val($event))" [readonly]="readonly()" required></textarea>
          @if (error('body'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
      }
      @case ('add_calendar_event') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.eventTitle' | transloco }}</mat-label>
          <input matInput [value]="text(c['title'])" (input)="set('title', val($event))" [readonly]="readonly()" maxlength="200" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic">
          <mat-label>{{ 'workflows.config.time' | transloco }}</mat-label>
          <input matInput type="time" [value]="text(c['time'])" (input)="set('time', val($event))" [readonly]="readonly()" />
        </mat-form-field>
        <mat-form-field subscriptSizing="dynamic" class="narrow">
          <mat-label>{{ 'workflows.config.duration' | transloco }}</mat-label>
          <input matInput type="number" min="15" max="480" step="15" [value]="c['duration_minutes']" (input)="set('duration_minutes', num($event))" [readonly]="readonly()" />
        </mat-form-field>
        <mat-checkbox [checked]="c['online'] === true" (change)="set('online', $event.checked)" [disabled]="readonly()">{{ 'workflows.config.online' | transloco }}</mat-checkbox>
      }
      @case ('create_document') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.documentTemplate' | transloco }}</mat-label>
          <mat-select [value]="c['document_template_id']" (selectionChange)="set('document_template_id', $event.value)" [disabled]="readonly()" required>
            @for (d of documentTemplates(); track d.id) {
              <mat-option [value]="d.id">{{ d.name }}</mat-option>
            }
          </mat-select>
          @if (error('document_template_id'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
        <mat-checkbox [checked]="c['send'] === true" (change)="set('send', $event.checked)" [disabled]="readonly()">{{ 'workflows.config.sendDocument' | transloco }}</mat-checkbox>
      }
      @case ('upload_document_request') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.documentName' | transloco }}</mat-label>
          <input matInput [value]="text(c['document_name'])" (input)="set('document_name', val($event))" [readonly]="readonly()" required maxlength="200" />
          @if (error('document_name'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
      }
      @case ('webhook') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.webhookUrl' | transloco }}</mat-label>
          <input matInput type="url" [value]="text(c['url'])" (input)="set('url', val($event))" [readonly]="readonly()" required placeholder="https://" />
          <mat-hint>{{ 'workflows.config.webhookHint' | transloco }}</mat-hint>
          @if (error('url'); as e) { <mat-hint class="err" align="end">{{ e }}</mat-hint> }
        </mat-form-field>
      }
      @case ('start_workflow') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.workflow' | transloco }}</mat-label>
          <mat-select [value]="c['template_id']" (selectionChange)="set('template_id', $event.value)" [disabled]="readonly()" required>
            @for (w of workflows(); track w.id) {
              <mat-option [value]="w.id">{{ w.name }}</mat-option>
            }
          </mat-select>
          @if (error('template_id'); as e) { <mat-hint class="err">{{ e }}</mat-hint> }
        </mat-form-field>
      }
      @case ('notify_manager') {
        <mat-form-field subscriptSizing="dynamic" class="wide">
          <mat-label>{{ 'workflows.config.message' | transloco }}</mat-label>
          <textarea matInput rows="2" [value]="text(c['message'])" (input)="set('message', val($event))" [readonly]="readonly()"></textarea>
        </mat-form-field>
      }
    }
  `,
  styles: `
    :host { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
    .wide { flex: 1 1 18rem; }
    .narrow { width: 9rem; }
    .err { color: var(--app-danger); }
  `,
})
export class StepConfigForm {
  readonly action = input.required<WorkflowAction>();
  readonly config = input.required<StepConfig>();
  readonly readonly = input(false);
  readonly documentTemplates = input<readonly DocumentTemplate[]>([]);
  readonly workflows = input<readonly WorkflowTemplate[]>([]);
  /** Server messages of this step's config, keyed by config key. */
  readonly errors = input<Record<string, string>>({});
  readonly changed = output<ConfigChange>();

  protected set(key: string, value: StepConfigValue): void {
    this.changed.emit({ key, value });
  }

  protected text(value: StepConfigValue | undefined): string {
    return typeof value === 'string' ? value : '';
  }

  protected val(event: Event): string {
    return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
  }

  protected num(event: Event): number | null {
    const raw = this.val(event);
    const n = Number(raw);
    return raw === '' || !Number.isFinite(n) ? null : Math.round(n);
  }

  protected error(key: string): string | null {
    return this.errors()[key] ?? null;
  }
}
