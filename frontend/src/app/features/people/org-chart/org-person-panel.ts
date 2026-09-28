import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { initials } from '../org-tree';
import { Employee, OrgNode } from '../people.model';
import { PeopleService } from '../people.service';
import { departmentHue } from './org-layout';

/**
 * Side panel (bottom sheet on phones) with key facts about a chart node. Contacts come from GET /api/people/{id},
 * so the backend's field access rules apply: whatever the directory/profile hides stays hidden here too.
 */
@Component({
  selector: 'app-org-person-panel',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    role: 'dialog',
    '[attr.aria-label]': 'node().full_name',
    '(keydown.escape)': 'closed.emit()',
  },
  template: `
    <div class="grip" aria-hidden="true"></div>
    <header>
      <span class="avatar" [style.--h]="hue()" aria-hidden="true">
        @if (node().avatar_url; as src) {
          <img [src]="src" alt="" />
        } @else {
          {{ initialsOf(node().full_name) }}
        }
      </span>
      <div class="who">
        <h2>{{ node().full_name }}</h2>
        <p class="muted">{{ node().position?.name ?? '—' }}</p>
      </div>
      <button
        mat-icon-button
        type="button"
        class="close"
        (click)="closed.emit()"
        [attr.aria-label]="'common.close' | transloco"
      >
        <mat-icon>close</mat-icon>
      </button>
    </header>

    @if (loading()) {
      <mat-progress-bar mode="indeterminate" />
    }

    <dl>
      @if (node().department; as d) {
        <dt>{{ 'people.orgChart.department' | transloco }}</dt>
        <dd>
          <span class="chip" [style.--h]="hue()">{{ d.name }}</span>
        </dd>
      }
      @if (node().branch; as b) {
        <dt>{{ 'people.orgChart.branch' | transloco }}</dt>
        <dd>{{ b.name }}</dd>
      }
      @if (manager(); as m) {
        <dt>{{ 'people.orgChart.manager' | transloco }}</dt>
        <dd>
          <button type="button" class="link" (click)="navigate.emit(m.id)">
            {{ m.full_name }}
          </button>
        </dd>
      }
      @if (employee()?.work_email; as email) {
        <dt>{{ 'people.orgChart.email' | transloco }}</dt>
        <dd>
          <a [href]="'mailto:' + email">{{ email }}</a>
        </dd>
      }
      @if (employee()?.phone; as phone) {
        <dt>{{ 'people.orgChart.phone' | transloco }}</dt>
        <dd>
          <a [href]="'tel:' + phone">{{ phone }}</a>
        </dd>
      }
    </dl>

    @if (node().reports.length) {
      <h3>{{ 'people.orgChart.directReports' | transloco: { n: node().reports.length } }}</h3>
      <ul class="reports">
        @for (r of node().reports; track r.id) {
          <li>
            <button type="button" class="link" (click)="navigate.emit(r.id)">
              {{ r.full_name }}
            </button>
            <span class="muted">{{ r.position?.name }}</span>
          </li>
        }
      </ul>
    }

    <a mat-flat-button class="profile" [routerLink]="['/people', node().id]">
      <mat-icon>person</mat-icon>{{ 'people.orgChart.openProfile' | transloco }}
    </a>
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      padding: 1rem 1.25rem 1.25rem;
      overflow-y: auto;
      background: var(--mat-sys-surface-container-low);
      color: var(--mat-sys-on-surface);
      border-left: 1px solid var(--app-border);
      box-shadow: -8px 0 32px rgb(0 0 0 / 0.08);
      width: 22rem;
      flex: none;
      animation: slide 220ms ease-out;
      --dept: light-dark(hsl(var(--h, 210) 60% 40%), hsl(var(--h, 210) 70% 72%));
    }
    .grip {
      display: none;
    }
    header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .who {
      flex: 1;
      min-width: 0;
    }
    h2 {
      margin: 0;
      font-size: 1.1rem;
    }
    h3 {
      margin: 0.25rem 0 0;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--app-muted);
    }
    p {
      margin: 0;
    }
    .avatar {
      display: grid;
      place-items: center;
      width: 3rem;
      height: 3rem;
      border-radius: 50%;
      overflow: hidden;
      flex: none;
      font-weight: 600;
      background: color-mix(in srgb, var(--dept) 16%, transparent);
      color: var(--dept);
    }
    .avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    dl {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 0.4rem 1rem;
      margin: 0;
      font-size: 0.9rem;
    }
    dt {
      color: var(--app-muted);
    }
    dd {
      margin: 0;
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .chip {
      padding: 0.1rem 0.5rem;
      border-radius: 999px;
      background: color-mix(in srgb, var(--dept) 14%, transparent);
      color: var(--dept);
    }
    .link {
      all: unset;
      cursor: pointer;
      color: var(--app-brand-text);
    }
    .link:hover,
    .link:focus-visible {
      text-decoration: underline;
    }
    .reports {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 0.35rem;
      font-size: 0.9rem;
    }
    .reports li {
      display: flex;
      flex-direction: column;
    }
    .profile {
      align-self: flex-start;
      margin-top: 0.25rem;
    }
    @keyframes slide {
      from {
        opacity: 0;
        transform: translateX(24px);
      }
    }
    @keyframes rise {
      from {
        transform: translateY(100%);
      }
    }
    @media (prefers-reduced-motion: reduce) {
      :host {
        animation: none;
      }
    }
    @media (max-width: 720px) {
      :host {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        width: auto;
        max-height: 70%;
        z-index: 4;
        box-sizing: border-box;
        animation-name: rise;
        border-left: 0;
        border-top: 1px solid var(--app-border);
        border-radius: 16px 16px 0 0;
        box-shadow: 0 -8px 32px rgb(0 0 0 / 0.12);
      }
      .grip {
        display: block;
        align-self: center;
        width: 2.5rem;
        height: 4px;
        border-radius: 2px;
        background: var(--app-border);
      }
    }
  `,
})
export class OrgPersonPanel {
  readonly node = input.required<OrgNode>();
  readonly manager = input<OrgNode | null>(null);
  readonly closed = output<void>();
  readonly navigate = output<number>();

  private readonly api = inject(PeopleService);
  protected readonly employee = signal<Employee | null>(null);
  protected readonly loading = signal(false);
  protected readonly hue = computed(() => departmentHue(this.node().department?.id) ?? 210);

  constructor() {
    effect((onCleanup) => {
      const id = this.node().id;
      this.employee.set(null);
      this.loading.set(true);
      const sub = this.api.get(id).subscribe({
        next: (e) => {
          this.employee.set(e);
          this.loading.set(false);
        },
        // No access / network: the panel still shows what the chart itself already knows.
        error: () => this.loading.set(false),
      });
      onCleanup(() => sub.unsubscribe());
    });
  }

  protected initialsOf(name: string): string {
    return initials(name);
  }
}
