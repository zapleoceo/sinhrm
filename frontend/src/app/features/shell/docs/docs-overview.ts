import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { FaIconComponent } from '@fortawesome/angular-fontawesome';
import { TranslocoPipe } from '@jsverse/transloco';
import { MapZone, VisibleBlock, visibleMap } from './docs-map';
import { DocPage } from './docs.model';

/** "How SinHRM works" map, shown on /docs while no section is open. Plain HTML/CSS; every block opens its doc section. */
@Component({
  selector: 'app-docs-overview',
  imports: [FaIconComponent, NgTemplateOutlet, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <p class="intro">{{ 'docs.map.intro' | transloco }}</p>
    <p class="visually-hidden">{{ 'docs.map.alt' | transloco }}</p>
    <ng-template #zoneTpl let-z>
      <h2>{{ 'docs.map.zones.' + z.zone | transloco }}</h2>
      <ul>
        @for (b of z.blocks; track b.id) {
          <li>
            <a class="block" [routerLink]="['/docs', b.slug]" [attr.data-block]="b.id">
              <fa-icon [icon]="b.icon" [fixedWidth]="true" a11yRole="presentation" />
              <span class="txt">
                <strong>{{ 'docs.map.blocks.' + b.id + '.title' | transloco }}</strong>
                <span class="hint">{{ 'docs.map.blocks.' + b.id + '.hint' | transloco }}</span>
              </span>
            </a>
          </li>
        }
      </ul>
    </ng-template>
    <div class="map">
      <div class="flow">
        @for (z of flow(); track z.zone; let last = $last) {
          <section class="zone" [attr.data-zone]="z.zone" [attr.aria-label]="'docs.map.zones.' + z.zone | transloco">
            <ng-container *ngTemplateOutlet="zoneTpl; context: { $implicit: z }" />
          </section>
          @if (!last) {
            <svg class="arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M3 12h16M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          }
        }
      </div>
      @for (z of bands(); track z.zone) {
        <section class="zone band" [attr.data-zone]="z.zone" [attr.aria-label]="'docs.map.zones.' + z.zone | transloco">
          <ng-container *ngTemplateOutlet="zoneTpl; context: { $implicit: z }" />
        </section>
      }
    </div>
  `,
  styles: `
    :host { display: block; }
    .intro { margin: 0 0 1rem; color: var(--app-muted); }
    .map { display: flex; flex-direction: column; gap: 0.75rem; }
    .flow { display: flex; gap: 0.5rem; align-items: stretch; }
    .flow .zone { flex: 1 1 0; min-width: 0; }
    .zone { border: 1px solid var(--app-border); border-radius: var(--app-radius); padding: 0.75rem; background: var(--mat-sys-surface-container-low); }
    .zone h2 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 0.5rem; color: var(--app-muted); }
    .zone[data-zone='recruiting'] { border-color: var(--mat-sys-primary); }
    .zone[data-zone='people'] { border-color: var(--mat-sys-tertiary); }
    ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.35rem; }
    .band ul { flex-direction: row; flex-wrap: wrap; }
    .band li { flex: 1 1 12rem; }
    .block { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.45rem 0.55rem; border-radius: 8px; color: inherit; text-decoration: none; background: var(--mat-sys-surface); }
    .block:hover { background: var(--mat-sys-surface-container-high); }
    .block:focus-visible { outline: 2px solid var(--mat-sys-primary); outline-offset: 1px; }
    .block fa-icon { color: var(--mat-sys-primary); margin-top: 0.15rem; }
    .txt { display: flex; flex-direction: column; min-width: 0; }
    .txt strong { font-weight: 500; }
    .hint { font-size: 0.8rem; color: var(--app-muted); }
    .arrow { flex: none; align-self: center; width: 1.75rem; height: 1.75rem; color: var(--app-muted); }
    @media (max-width: 1100px) {
      .flow { flex-direction: column; }
      .arrow { transform: rotate(90deg); }
    }
  `,
})
export class DocsOverview {
  readonly docs = input.required<readonly DocPage[]>();
  protected readonly map = computed(() => visibleMap(this.docs()));
  protected readonly flow = computed(() => this.nonEmpty(['sources', 'recruiting', 'people']));
  protected readonly bands = computed(() => this.nonEmpty(['daily', 'helpers']));

  private nonEmpty(zones: MapZone[]): { zone: MapZone; blocks: VisibleBlock[] }[] {
    const m = this.map();
    return zones.map((zone) => ({ zone, blocks: m[zone] })).filter((z) => z.blocks.length > 0);
  }
}
