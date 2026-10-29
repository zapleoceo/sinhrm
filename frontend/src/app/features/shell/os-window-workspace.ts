import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import { WindowSlot, WINDOW_SLOTS } from '../../core/windows/window-context';
import { WindowNavigation } from '../../core/windows/window-navigation';

/** Window chrome owns the named outlets while the shell continues to own the primary route. */
@Component({
  selector: 'app-os-window-workspace',
  imports: [MatButtonModule, MatIconModule, RouterOutlet, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="window-layer" [attr.aria-label]="'osWindow.windows' | transloco">
      @let views = navigation.windows();
      @for (slot of slots; track slot) {
        @let view = views[slot];
        <article class="os-window" [class.open]="view.open" [class.active]="view.active"
                 [class.minimized]="view.minimized" [class.maximized]="view.maximized"
                 [class.window-os1]="slot === 'os1'" [class.window-os2]="slot === 'os2'"
                 [class.mobile-inactive]="narrow() && !view.active && !view.minimized"
                 [attr.aria-hidden]="!view.open || (narrow() && !view.active && !view.minimized) ? 'true' : null"
                 [attr.inert]="narrow() && view.open && !view.active && !view.minimized ? '' : null">
          @if (view.open) {
            <header class="window-titlebar" tabindex="0" (click)="focusWindow(slot)" (keydown.enter)="focusWindow(slot)" (keydown.space)="focusWindow(slot)">
              <h2>{{ view.titleKey | transloco }}</h2>
              <div class="window-controls">
                @if (view.minimized) {
                  <button mat-icon-button type="button" [attr.aria-label]="'osWindow.restore' | transloco" (click)="$event.stopPropagation(); navigation.restore(slot)"><mat-icon>open_in_full</mat-icon></button>
                } @else {
                  <button mat-icon-button type="button" [attr.aria-label]="'osWindow.minimize' | transloco" (click)="$event.stopPropagation(); navigation.minimize(slot)"><mat-icon>minimize</mat-icon></button>
                  <button mat-icon-button type="button" [attr.aria-label]="'osWindow.maximize' | transloco" (click)="$event.stopPropagation(); navigation.toggleMaximize(slot)"><mat-icon>{{ view.maximized ? 'close_fullscreen' : 'open_in_full' }}</mat-icon></button>
                }
                <button mat-icon-button type="button" [attr.aria-label]="'osWindow.close' | transloco" (click)="$event.stopPropagation(); navigation.close(slot)"><mat-icon>close</mat-icon></button>
              </div>
            </header>
          }
          <div class="window-body" [hidden]="!view.open || view.minimized || (narrow() && !view.active)"
               [attr.inert]="!view.open || view.minimized || (narrow() && !view.active) ? '' : null"
               [attr.aria-hidden]="!view.open || view.minimized || (narrow() && !view.active) ? 'true' : null"
               (pointerdown)="focusWindow(slot)">
            <router-outlet [name]="slot" />
          </div>
        </article>
      }
    </section>
  `,
  styleUrl: './os-window-workspace.scss',
})
export class OsWindowWorkspace {
  protected readonly navigation = inject(WindowNavigation);
  protected readonly slots = WINDOW_SLOTS;
  readonly narrow = input.required<boolean>();

  protected focusWindow(slot: WindowSlot): void {
    void this.navigation.focus(slot);
  }
}
