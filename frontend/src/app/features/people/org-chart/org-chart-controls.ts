import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { TranslocoPipe } from '@jsverse/transloco';
import { Orientation } from './org-layout';

export type OrgChartAction =
  'zoomIn' | 'zoomOut' | 'fit' | 'orientation' | 'expand' | 'collapse' | 'me' | 'png' | 'svg';

/** Floating minimal control rail of the org chart (zoom, fit, layout, expand/collapse, my place, export). */
@Component({
  selector: 'app-org-chart-controls',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, MatTooltipModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('zoomIn')"
      [matTooltip]="'people.orgChart.zoomIn' | transloco"
      [attr.aria-label]="'people.orgChart.zoomIn' | transloco"
    >
      <mat-icon>add</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('zoomOut')"
      [matTooltip]="'people.orgChart.zoomOut' | transloco"
      [attr.aria-label]="'people.orgChart.zoomOut' | transloco"
    >
      <mat-icon>remove</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('fit')"
      [matTooltip]="'people.orgChart.fit' | transloco"
      [attr.aria-label]="'people.orgChart.fit' | transloco"
    >
      <mat-icon>fit_screen</mat-icon>
    </button>
    <span class="sep"></span>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('orientation')"
      [matTooltip]="'people.orgChart.orientation' | transloco"
      [attr.aria-label]="'people.orgChart.orientation' | transloco"
    >
      <mat-icon [class.rot]="orientation() === 'horizontal'">account_tree</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('expand')"
      [matTooltip]="'people.orgChart.expand' | transloco"
      [attr.aria-label]="'people.orgChart.expand' | transloco"
    >
      <mat-icon>unfold_more</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('collapse')"
      [matTooltip]="'people.orgChart.collapse' | transloco"
      [attr.aria-label]="'people.orgChart.collapse' | transloco"
    >
      <mat-icon>unfold_less</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      (click)="action.emit('me')"
      [matTooltip]="'people.orgChart.myPlace' | transloco"
      [attr.aria-label]="'people.orgChart.myPlace' | transloco"
    >
      <mat-icon>my_location</mat-icon>
    </button>
    <button
      mat-icon-button
      type="button"
      [matMenuTriggerFor]="exportMenu"
      [matTooltip]="'people.orgChart.export' | transloco"
      [attr.aria-label]="'people.orgChart.export' | transloco"
    >
      <mat-icon>download</mat-icon>
    </button>
    <mat-menu #exportMenu>
      <button mat-menu-item type="button" (click)="action.emit('png')">PNG</button>
      <button mat-menu-item type="button" (click)="action.emit('svg')">SVG</button>
    </mat-menu>
  `,
  styles: `
    :host {
      position: absolute;
      right: 12px;
      bottom: 12px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      padding: 4px;
      border-radius: 14px;
      background: color-mix(in srgb, var(--mat-sys-surface-container) 88%, transparent);
      backdrop-filter: blur(6px);
      border: 1px solid var(--app-border);
      cursor: default;
    }
    .sep {
      height: 1px;
      margin: 2px 8px;
      background: var(--app-border);
    }
    .rot {
      rotate: -90deg;
    }
    @media (max-width: 720px) {
      :host {
        flex-direction: row;
        right: 8px;
        bottom: 8px;
      }
      .sep {
        width: 1px;
        height: auto;
        margin: 8px 2px;
      }
    }
  `,
})
export class OrgChartControls {
  readonly orientation = input.required<Orientation>();
  readonly action = output<OrgChartAction>();
}

export interface LegendItem {
  id: number;
  name: string;
  hue: number | null;
}

/** Department colour legend; a chip click filters the chart by that department (click again to clear). */
@Component({
  selector: 'app-org-chart-legend',
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ul [attr.aria-label]="'people.orgChart.legend' | transloco">
      @for (d of items(); track d.id) {
        <li>
          <button
            type="button"
            [style.--h]="d.hue"
            [class.active]="active() === d.id"
            [attr.aria-pressed]="active() === d.id"
            (click)="picked.emit(d.id)"
          >
            <i></i>{{ d.name }}
          </button>
        </li>
      }
    </ul>
  `,
  styles: `
    :host {
      display: block;
      position: absolute;
      left: 12px;
      bottom: 12px;
      max-width: min(60%, 36rem);
    }
    ul {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
      list-style: none;
      margin: 0;
      padding: 0;
      cursor: default;
    }
    button {
      --dept: light-dark(hsl(var(--h) 58% 42%), hsl(var(--h) 70% 70%));
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      cursor: pointer;
      font: 0.75rem/1.4 inherit;
      color: var(--mat-sys-on-surface);
      border: 1px solid var(--app-border);
      background: color-mix(in srgb, var(--mat-sys-surface-container) 88%, transparent);
    }
    button.active {
      border-color: var(--dept);
      background: color-mix(in srgb, var(--dept) 14%, transparent);
    }
    i {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--dept);
    }
    @media (max-width: 720px) {
      :host {
        display: none;
      }
    }
  `,
})
export class OrgChartLegend {
  readonly items = input.required<LegendItem[]>();
  readonly active = input<number | null>(null);
  readonly picked = output<number>();
}
