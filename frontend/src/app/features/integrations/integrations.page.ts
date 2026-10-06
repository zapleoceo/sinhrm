import { ChangeDetectionStrategy, Component, OnInit, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiPanel } from '../ai/ai-panel';
import { GoogleConnectPanel } from '../google-workspace/google-connect.panel';
import { ConfirmAiDialog } from './confirm-ai.dialog';
import { IntegrationCard } from './integration-card';
import { IntegrationsDirectoryStore } from './integrations-directory.store';
import { IntegrationsStore } from './integrations.store';
import { NotifyService } from '../../core/ui/notify.service';

/** Superadmin: integrations grouped by kind + the global AI policy switch (confirmed, optimistic). */
@Component({
  selector: 'app-integrations-page',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, MatSlideToggleModule, TranslocoPipe, IntegrationCard, GoogleConnectPanel, AiPanel],
  providers: [IntegrationsDirectoryStore, IntegrationsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './integrations.page.html',
  styleUrl: './integrations.page.scss',
})
export class IntegrationsPage implements OnInit {
  protected readonly store = inject(IntegrationsStore);
  private readonly dialog = inject(MatDialog);
  private readonly notify = inject(NotifyService);
  protected readonly directory = inject(IntegrationsDirectoryStore);

  ngOnInit(): void {
    this.store.load();
    this.directory.loadStatus();
  }

  protected toggleAi(change: MatSlideToggleChange): void {
    const enable = change.checked;
    this.dialog
      .open<ConfirmAiDialog, boolean, boolean>(ConfirmAiDialog, { data: enable })
      .afterClosed()
      .subscribe((confirmed) => {
        if (confirmed) {
          this.store.setAi(enable, (key) => this.notify.show(key, { duration: 3000 }));
        } else {
          // Cancelled: the toggle already moved visually, put it back.
          change.source.checked = this.store.aiEnabled();
        }
      });
  }
}
