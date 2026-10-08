import { TestBed } from '@angular/core/testing';
import { TranslocoTestingModule } from '@jsverse/transloco';
import { Subject } from 'rxjs';
import { HrDocument } from '../documents.model';
import { DocumentsService } from '../documents.service';
import { EmployeeDocumentsTab } from './employee-documents.tab';

const doc = (id: number, title: string): HrDocument => ({ id, title, status: 'draft', file: null }) as unknown as HrDocument;

describe('EmployeeDocumentsTab', () => {
  it('another employee cancels the request still in flight: the previous person’s documents never land', async () => {
    const answers = new Map<number, Subject<HrDocument[]>>();
    const api = {
      list: (q: { employee_id: number }) => {
        const answer = new Subject<HrDocument[]>();
        answers.set(q.employee_id, answer);
        return answer;
      },
    };
    TestBed.configureTestingModule({
      imports: [EmployeeDocumentsTab, TranslocoTestingModule.forRoot({ langs: {}, translocoConfig: { availableLangs: ['uk'], defaultLang: 'uk' } })],
      providers: [{ provide: DocumentsService, useValue: api }],
    });
    const fixture = TestBed.createComponent(EmployeeDocumentsTab);
    fixture.componentRef.setInput('employeeId', 1);
    fixture.detectChanges();
    fixture.componentRef.setInput('employeeId', 2);
    fixture.detectChanges();
    expect(answers.get(1)!.observed).toBe(false);
    answers.get(1)!.next([doc(1, 'old')]);
    answers.get(2)!.next([doc(2, 'new')]);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect([...el.querySelectorAll('button.link')].map((b) => b.textContent?.trim())).toEqual(['new']);
  });
});
