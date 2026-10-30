import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslocoPipe } from '@jsverse/transloco';
import type { DesktopAppEntry, DesktopGroupId } from './desktop.model';

export interface DesktopAppGroupView {
  readonly id: DesktopGroupId;
  readonly label: string;
  readonly apps: readonly DesktopAppEntry[];
}

export interface DesktopAppLaunchRequest {
  readonly event: MouseEvent;
  readonly route: string;
}

@Component({
  selector: 'app-desktop-app-launcher',
  standalone: true,
  imports: [MatIconModule, TranslocoPipe],
  templateUrl: './desktop-app-launcher.component.html',
  styleUrl: './desktop-app-launcher.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesktopAppLauncherComponent {
  readonly groups = input.required<readonly DesktopAppGroupView[]>();
  readonly launch = output<DesktopAppLaunchRequest>();
}
