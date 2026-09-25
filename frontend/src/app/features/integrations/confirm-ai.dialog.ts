import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslocoPipe } from '@jsverse/transloco';

/** Confirmation before switching the global AI policy; closes with true on confirm. */
@Component({
  selector: 'app-confirm-ai-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ (enable ? 'integrations.ai.confirmOnTitle' : 'integrations.ai.confirmOffTitle') | transloco }}</h2>
    <mat-dialog-content>
      <p>{{ (enable ? 'integrations.ai.confirmOnText' : 'integrations.ai.confirmOffText') | transloco }}</p>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" [mat-dialog-close]="false">{{ 'common.cancel' | transloco }}</button>
      <button mat-flat-button type="button" [mat-dialog-close]="true">{{ 'integrations.ai.confirm' | transloco }}</button>
    </mat-dialog-actions>
  `,
})
export class ConfirmAiDialog {
  protected readonly enable = inject<boolean>(MAT_DIALOG_DATA);
}
