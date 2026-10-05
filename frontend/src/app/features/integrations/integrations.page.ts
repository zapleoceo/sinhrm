import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
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
import { IntegrationsStore } from './integrations.store';
import { IntegrationsService } from './integrations.service';
import { NotifyService } from '../../core/ui/notify.service';
import { EmployeeDirectoryPreview, EmployeeDirectoryStatus } from './integrations.model';

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
  private readonly dialog = inject(MatDialog);
  private readonly notify = inject(NotifyService);
  private readonly integrations = inject(IntegrationsService);
  protected readonly directoryStatus = signal<EmployeeDirectoryStatus | null>(null);
  protected readonly directoryPreview = signal<EmployeeDirectoryPreview | null>(null);
  protected readonly directoryLoading = signal(false);
  protected readonly directoryError = signal(false);

  ngOnInit(): void {
    this.store.load();
    this.loadDirectoryStatus();
  }

  protected loadDirectoryStatus(): void {
    this.directoryError.set(false);
    this.integrations.employeeDirectoryStatus().subscribe({
      next: (response) => { this.directoryStatus.set(response.data); this.directoryError.set(false); },
      error: () => { this.directoryStatus.set(null); this.directoryError.set(true); },
    });
  }

  protected loadSyntheticPreview(): void {
    this.directoryError.set(false);
    this.directoryLoading.set(true);
    this.integrations.employeeDirectorySyntheticPreview().subscribe({
      next: (response) => { this.directoryPreview.set(response.data); this.directoryLoading.set(false); },
      error: () => { this.directoryError.set(true); this.directoryLoading.set(false); },
    });
  }

  protected retryDirectory(): void {
    this.loadDirectoryStatus();
    this.loadSyntheticPreview();
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
