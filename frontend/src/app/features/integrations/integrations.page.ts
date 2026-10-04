import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslocoPipe } from '@jsverse/transloco';
import { AiPanel } from '../ai/ai-panel';
import { GoogleConnectPanel } from '../google-workspace/google-connect.panel';
import { GoogleOAuthState } from '../google-workspace/google.model';
import { ConfirmAiDialog } from './confirm-ai.dialog';
import { IntegrationCard } from './integration-card';
import { IntegrationsStore } from './integrations.store';
import { NotifyService } from '../../core/ui/notify.service';

/** Superadmin: integrations grouped by kind + the global AI policy switch (confirmed, optimistic). */
@Component({
  selector: 'app-integrations-page',
  imports: [MatButtonModule, MatIconModule, MatProgressBarModule, MatSlideToggleModule, TranslocoPipe, IntegrationCard, GoogleConnectPanel, AiPanel],
  providers: [IntegrationsStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './integrations.page.html',
  styleUrl: './integrations.page.scss',
})
export class IntegrationsPage implements OnInit {
  protected readonly store = inject(IntegrationsStore);
  protected readonly googleOAuthState = signal<GoogleOAuthState>('loading');
  private readonly query = toSignal(inject(ActivatedRoute).queryParamMap);
  protected readonly focusedIntegration = computed(() => this.query()?.get('integration') ?? null);
  private readonly dialog = inject(MatDialog);
  private readonly notify = inject(NotifyService);

  ngOnInit(): void {
    this.store.load();
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
