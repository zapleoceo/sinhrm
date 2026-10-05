import { Injectable, inject, signal } from '@angular/core';
import { EmployeeDirectoryPreview, EmployeeDirectoryStatus } from './integrations.model';
import { IntegrationsService } from './integrations.service';

@Injectable()
export class IntegrationsDirectoryStore {
  private readonly api = inject(IntegrationsService);

  readonly status = signal<EmployeeDirectoryStatus | null>(null);
  readonly preview = signal<EmployeeDirectoryPreview | null>(null);
  readonly previewLoading = signal(false);
  readonly statusError = signal(false);
  readonly previewError = signal(false);

  loadStatus(): void {
    this.statusError.set(false);
    this.api.employeeDirectoryStatus().subscribe({
      next: (response) => { this.status.set(response.data); this.statusError.set(false); },
      error: () => { this.status.set(null); this.statusError.set(true); },
    });
  }

  loadSyntheticPreview(): void {
    this.preview.set(null);
    this.previewError.set(false);
    this.previewLoading.set(true);
    this.api.employeeDirectorySyntheticPreview().subscribe({
      next: (response) => { this.preview.set(response.data); this.previewLoading.set(false); },
      error: () => { this.previewError.set(true); this.previewLoading.set(false); },
    });
  }

  retry(): void {
    this.loadStatus();
    this.loadSyntheticPreview();
  }
}
