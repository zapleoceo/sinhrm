import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import uk from '../../../../public/i18n/uk.json';
import { ChangeableField } from './people.model';
import { HiddenChangesLine } from './hidden-changes';

@Component({
  imports: [HiddenChangesLine],
  template: `<app-hidden-changes [fields]="fields" />`,
})
class Host {
  fields: ChangeableField[] = [];
}

/** Text of the whole host with the template whitespace collapsed, so the rendered line reads as a sentence. */
function text(el: HTMLElement): string {
  return (el.textContent ?? '').replace(/\s+/g, ' ').trim();
}

describe('HiddenChangesLine', () => {
  function setup(fields: ChangeableField[]) {
    TestBed.configureTestingModule({
      imports: [Host, TranslocoTestingModule.forRoot({ langs: { uk }, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' }, preloadLangs: true })],
    });
    const fixture = TestBed.createComponent(Host);
    fixture.componentInstance.fields = fields;
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('lists the hidden fields with their translated labels', () => {
    const el = setup(['address', 'personal_email']);
    expect(text(el)).toBe('Приховано: Адреса, Особистий e-mail');
  });

  it('renders nothing when the caller sees every value', () => {
    expect(setup([]).querySelector('.hidden-changes')).toBeNull();
  });
});
