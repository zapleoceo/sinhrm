import { Overlay, OverlayRef } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommandPalette } from './command-palette';

/** Opens/closes the command palette in a CDK overlay (one at a time). The shell binds Cmd/Ctrl+K to toggle(). */
@Injectable({ providedIn: 'root' })
export class CommandPaletteService {
  private readonly overlay = inject(Overlay);
  private readonly router = inject(Router);
  private ref: OverlayRef | null = null;

  get isOpen(): boolean {
    return this.ref !== null;
  }

  toggle(): void {
    if (this.ref) {
      this.close();
    } else {
      this.open();
    }
  }

  open(): void {
    if (this.ref) {
      return;
    }
    const ref = this.overlay.create({
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-dark-backdrop',
      positionStrategy: this.overlay.position().global().centerHorizontally().top('12vh'),
      scrollStrategy: this.overlay.scrollStrategies.block(),
    });
    this.ref = ref;
    const palette = ref.attach(new ComponentPortal(CommandPalette)).instance;
    palette.chosen.subscribe((item) => {
      this.close();
      void this.router.navigate(item.route);
    });
    palette.closed.subscribe(() => this.close());
    ref.backdropClick().subscribe(() => this.close());
    ref.keydownEvents().subscribe((e) => {
      if (e.key === 'Escape') {
        this.close();
      }
    });
  }

  close(): void {
    this.ref?.dispose();
    this.ref = null;
  }
}
