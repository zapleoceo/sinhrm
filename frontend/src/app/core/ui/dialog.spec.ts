import { ApplicationRef, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { provideAppDialogDefaults } from './dialog';

@Component({ template: '<p>x</p>' })
class Body {}

describe('provideAppDialogDefaults', () => {
  it('keeps role="dialog" and caps the width', () => {
    TestBed.configureTestingModule({ imports: [MatDialogModule, Body], providers: [provideAppDialogDefaults()] });
    const ref = TestBed.inject(MatDialog).open(Body);
    TestBed.inject(ApplicationRef).tick();
    const container = document.querySelector('mat-dialog-container');
    expect(container?.getAttribute('role')).toBe('dialog');
    expect(ref.componentRef?.instance).toBeTruthy();
    const max = parseFloat(getComputedStyle(document.querySelector('.cdk-overlay-pane') as Element).maxWidth);
    expect(Math.abs(max - window.innerWidth * 0.95)).toBeLessThan(2);
    ref.close();
  });
});
