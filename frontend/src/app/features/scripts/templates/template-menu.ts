import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe } from '@jsverse/transloco';
import { CandidateTemplate } from '../scripts.model';
import { ScriptsService } from '../scripts.service';

/**
 * "Шаблон" button of the touch composer: templates of the active scripts, filled for the candidate
 * (GET /api/candidates/{id}/templates, loaded when the menu opens). Emits the text to insert; tokens without a value
 * (links, address) stay in the text for the recruiter to fill in.
 */
@Component({
  selector: 'app-template-menu',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, MatTooltipModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button mat-stroked-button type="button" [matMenuTriggerFor]="menu" (menuOpened)="load()">
      <mat-icon>article</mat-icon>{{ 'scripts.templates.button' | transloco }}
    </button>
    <mat-menu #menu="matMenu" class="template-menu">
      @if (loading()) {
        <p class="muted item-note">{{ 'common.loading' | transloco }}</p>
      }
      @for (t of templates(); track t.script_id + t.key) {
        <button mat-menu-item type="button" (click)="picked.emit(t.text)" [matTooltip]="t.text" matTooltipPosition="left">
          <mat-icon>{{ t.channel === 'call' ? 'call' : 'chat' }}</mat-icon>
          <span>{{ t.title }}</span>
          @if (t.missing.length) {
            <span class="muted"> · {{ 'scripts.templates.missing' | transloco: { n: t.missing.length } }}</span>
          }
        </button>
      } @empty {
        @if (!loading()) {
          <p class="muted item-note">{{ (failed() ? 'scripts.errors.generic' : 'scripts.templates.empty') | transloco }}</p>
        }
      }
    </mat-menu>
  `,
  styles: `.item-note { padding: 0.5rem 1rem; margin: 0; }`,
})
export class TemplateMenu {
  readonly candidateId = input.required<number>();
  readonly picked = output<string>();

  private readonly api = inject(ScriptsService);
  private loadedFor: number | null = null;
  protected readonly templates = signal<CandidateTemplate[]>([]);
  protected readonly loading = signal(false);
  protected readonly failed = signal(false);

  protected load(): void {
    const id = this.candidateId();
    if (this.loadedFor === id) {
      return;
    }
    this.loading.set(true);
    this.failed.set(false);
    this.api.candidateTemplates(id).subscribe({
      next: (list) => {
        this.templates.set(list);
        this.loadedFor = id;
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }
}
