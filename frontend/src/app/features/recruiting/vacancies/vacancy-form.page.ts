import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, DestroyRef, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AbstractControl, FormArray, FormControl, FormGroup, NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { debounceTime, takeWhile, timer } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { safeStorage } from '../../../core/storage/safe-storage';
import { DictionaryItem } from '../../directory/directory.model';
import { DirectoryService } from '../../directory/directory.service';
import { withCurrent } from '../hiring-team';
import { canWriteRecruiting, isRecruitingAdmin } from '../recruiting.access';
import {
  ExternalPosting,
  Pipeline,
  Ref,
  SaveVacancy,
  VACANCY_STATUSES,
  Vacancy,
  VacancyLanguage,
  VacancyOptions,
  VacancyStatus,
  VacancyTemplate,
  VacancyTextDraft,
  VacancyTextSection,
} from '../recruiting.model';
import { RecruitingService, recruitingErrorKey } from '../recruiting.service';
import { MarkdownField } from './markdown-field';
import { aiTextErrorKey } from '../../ai/ai.service';

/** Markdown sections of the form, in page order. */
export const VACANCY_SECTIONS: readonly VacancyTextSection[] = ['description', 'requirements', 'responsibilities', 'additional_info'];

const DRAFT_PREFIX = 'sinhrm.vacancy-draft.';
const POLL_EVERY_MS = 3000;
const POLL_TIMES = 10;

type LanguageGroup = FormGroup<{ lang: FormControl<string>; level: FormControl<string> }>;
type PostingGroup = FormGroup<{ site: FormControl<string>; url: FormControl<string>; date: FormControl<string> }>;

/**
 * Full-page vacancy form (/vacancies/create, /vacancies/:id/edit): one centered column of card sections with a sticky
 * action bar. Company lists come from the Directory (branches, departments, cities, vacancy categories), generic
 * option lists from GET /api/vacancy-options. Inline 422 errors, an autosaved draft in localStorage, templates,
 * «Створити з ШІ» for the text sections.
 */
@Component({
  selector: 'app-vacancy-form-page',
  imports: [
    ReactiveFormsModule,
    RouterLink,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    TranslocoPipe,
    MarkdownField,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './vacancy-form.page.html',
  styleUrl: './vacancy-form.page.scss',
})
export class VacancyFormPage implements OnInit {
  private readonly api = inject(RecruitingService);
  private readonly directory = inject(DirectoryService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fb = inject(NonNullableFormBuilder);

  protected readonly sections = VACANCY_SECTIONS;
  protected readonly statuses = VACANCY_STATUSES;
  protected readonly canWrite = computed(() => canWriteRecruiting(this.auth.user()?.roles ?? []));
  protected readonly isAdmin = computed(() => isRecruitingAdmin(this.auth.user()?.roles ?? []));

  protected readonly vacancyId = signal<number | null>(null);
  protected readonly vacancy = signal<Vacancy | null>(null);
  protected readonly loading = signal(false);
  protected readonly saving = signal(false);
  protected readonly error = signal<string | null>(null);
  /** Field path (as in the API: languages.0.level) → i18n key. */
  protected readonly fieldErrors = signal<Record<string, string>>({});
  protected readonly draftRestored = signal(false);
  protected readonly templateSaved = signal(false);
  protected readonly templateName = signal<string | null>(null);

  protected readonly options = signal<VacancyOptions | null>(null);
  protected readonly branches = signal<DictionaryItem[]>([]);
  protected readonly departments = signal<DictionaryItem[]>([]);
  protected readonly cities = signal<DictionaryItem[]>([]);
  protected readonly categories = signal<DictionaryItem[]>([]);
  protected readonly pipelines = signal<Pipeline[]>([]);
  protected readonly people = signal<Ref[]>([]);
  protected readonly templates = signal<VacancyTemplate[]>([]);

  protected readonly aiBusy = signal<Partial<Record<VacancyTextSection, boolean>>>({});
  protected readonly aiHints = signal<Partial<Record<VacancyTextSection, string>>>({});

  readonly form = this.fb.group({
    title: ['', [Validators.required, Validators.maxLength(255)]],
    recruiter_id: [null as number | null],
    hiring_manager_id: [null as number | null],
    status: ['open' as VacancyStatus],
    category_id: [null as number | null],
    pipeline_id: [null as number | null],
    branch_id: [null as number | null, Validators.required],
    department_id: [null as number | null],
    description: [''],
    requirements: [''],
    responsibilities: [''],
    additional_info: [''],
    employment_type: [null as string | null],
    work_format: [null as string | null],
    country: [null as string | null],
    city_id: [null as number | null],
    experience_level: [null as string | null],
    education_level: [null as string | null],
    salary_min: [null as number | null, Validators.min(0)],
    salary_max: [null as number | null, Validators.min(0)],
    salary_currency: ['UAH'],
    salary_visible: [false],
    languages: this.fb.array<LanguageGroup>([]),
    published: [false],
    public_description: [''],
    external_postings: this.fb.array<PostingGroup>([]),
  });

  private saved = false;

  get languages(): FormArray<LanguageGroup> {
    return this.form.controls.languages;
  }

  get postings(): FormArray<PostingGroup> {
    return this.form.controls.external_postings;
  }

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    this.vacancyId.set(Number.isInteger(id) && id > 0 ? id : null);
    this.loadLists();
    const current = this.vacancyId();
    if (current !== null) {
      this.form.controls.pipeline_id.disable();
      this.loading.set(true);
      this.api.vacancy(current).subscribe({
        next: (v) => {
          this.vacancy.set(v);
          this.people.update((list) => withCurrent(list, v.recruiter, v.hiring_manager));
          this.fill(v);
          this.restoreDraft();
          this.loading.set(false);
        },
        error: (e: unknown) => {
          this.error.set(recruitingErrorKey(e));
          this.loading.set(false);
        },
      });
    } else {
      const templateId = Number(this.route.snapshot.queryParamMap.get('template'));
      if (templateId > 0) {
        this.api.vacancyTemplates().subscribe({
          next: (list) => {
            const tpl = list.find((t) => t.id === templateId);
            if (tpl) {
              this.fill(tpl.data);
              this.form.markAsDirty();
            }
          },
          error: () => undefined,
        });
      } else {
        this.restoreDraft();
      }
    }
    this.form.valueChanges.pipe(debounceTime(800), takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      if (this.form.dirty && !this.saved) {
        safeStorage.set(this.draftKey(), JSON.stringify(this.body()));
      }
    });
  }

  /** CanDeactivate: saved or untouched → leave; otherwise ask. */
  canLeave(): boolean {
    return this.saved || !this.form.dirty || window.confirm(this.i18n.translate('recruiting.form.leaveConfirm'));
  }

  @HostListener('window:beforeunload', ['$event'])
  protected onBeforeUnload(event: BeforeUnloadEvent): void {
    if (this.form.dirty && !this.saved) {
      event.preventDefault();
    }
  }

  protected addLanguage(value?: VacancyLanguage): void {
    this.languages.push(
      this.fb.group({ lang: [value?.lang ?? '', Validators.required], level: [value?.level ?? 'B1', Validators.required] }),
    );
    this.form.markAsDirty();
  }

  protected removeLanguage(index: number): void {
    this.languages.removeAt(index);
    this.form.markAsDirty();
  }

  protected addPosting(value?: ExternalPosting): void {
    this.postings.push(
      this.fb.group({
        site: [value?.site ?? 'work_ua', Validators.required],
        url: [value?.url ?? '', Validators.maxLength(500)],
        date: [value?.date ?? ''],
      }),
    );
    this.form.markAsDirty();
  }

  protected removePosting(index: number): void {
    this.postings.removeAt(index);
    this.form.markAsDirty();
  }

  protected sectionControl(section: VacancyTextSection): FormControl<string> {
    return this.form.controls[section];
  }

  protected languageName(code: string): string {
    return displayName('language', code, this.i18n.getActiveLang());
  }

  protected countryName(code: string): string {
    return displayName('region', code, this.i18n.getActiveLang());
  }

  protected fieldError(path: string): string | null {
    return this.fieldErrors()[path] ?? null;
  }

  protected discardDraft(): void {
    safeStorage.remove(this.draftKey());
    this.draftRestored.set(false);
    const v = this.vacancy();
    this.form.reset();
    this.languages.clear();
    this.postings.clear();
    if (v) {
      this.fill(v);
    } else {
      this.preselectPipeline();
    }
    this.form.markAsPristine();
  }

  /** «Створити з ШІ»: fills the section with a draft; an existing text is replaced only after a confirm. */
  protected generate(section: VacancyTextSection): void {
    const v = this.form.getRawValue();
    const control = this.sectionControl(section);
    if (control.value.trim() !== '' && !window.confirm(this.i18n.translate('recruiting.form.aiReplace'))) {
      return;
    }
    this.setAi(section, true, null);
    this.api
      .vacancyText({
        section,
        title: v.title.trim(),
        category_id: v.category_id,
        branch_id: v.branch_id,
        employment_type: v.employment_type,
        experience_level: v.experience_level,
      })
      .subscribe({
        next: (draft) => this.onDraft(section, draft, 0),
        error: (e: unknown) => this.setAi(section, false, aiTextErrorKey(e)),
      });
  }

  protected saveTemplate(): void {
    const name = (this.templateName() ?? '').trim();
    if (name === '') {
      this.templateName.set('');
      return;
    }
    // A template keeps the content only: no branch, people, status or publication.
    const data: SaveVacancy = this.body();
    delete data.status;
    delete data.branch_id;
    delete data.recruiter_id;
    delete data.hiring_manager_id;
    delete data.published;
    delete data.pipeline_id;
    this.templateSaved.set(false);
    this.api.saveVacancyTemplate(name, data).subscribe({
      next: (t) => {
        this.templates.update((list) => [...list, t]);
        this.templateName.set(null);
        this.templateSaved.set(true);
      },
      error: (e: unknown) => this.error.set(recruitingErrorKey(e)),
    });
  }

  protected submit(): void {
    this.fieldErrors.set({});
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set('recruiting.form.fixErrors');
      return;
    }
    const id = this.vacancyId();
    const body = this.body();
    this.saving.set(true);
    this.error.set(null);
    (id === null ? this.api.createVacancy(body) : this.api.updateVacancy(id, body)).subscribe({
      next: (saved) => {
        this.saved = true;
        safeStorage.remove(this.draftKey());
        this.saving.set(false);
        void this.router.navigate(['/vacancies', saved.id]);
      },
      error: (e: unknown) => {
        this.saving.set(false);
        this.error.set(recruitingErrorKey(e));
        this.applyServerErrors(e);
      },
    });
  }

  /** Request body from the form (empty strings → null; the API validates everything again). */
  body(): SaveVacancy {
    const v = this.form.getRawValue();
    const text = (s: string): string | null => s.trim() || null;
    return {
      title: v.title.trim(),
      branch_id: v.branch_id ?? undefined,
      ...(v.recruiter_id !== null ? { recruiter_id: v.recruiter_id } : {}),
      // Mirrors the API: only recruiting writers assign the hiring manager.
      ...(this.canWrite() ? { hiring_manager_id: v.hiring_manager_id } : {}),
      ...(this.vacancyId() === null && v.pipeline_id !== null ? { pipeline_id: v.pipeline_id } : {}),
      status: v.status,
      category_id: v.category_id,
      department_id: v.department_id,
      description: text(v.description),
      requirements: text(v.requirements),
      responsibilities: text(v.responsibilities),
      additional_info: text(v.additional_info),
      employment_type: v.employment_type,
      work_format: v.work_format,
      country: v.country,
      city_id: v.city_id,
      experience_level: v.experience_level,
      education_level: v.education_level,
      salary_min: v.salary_min,
      salary_max: v.salary_max,
      salary_currency: v.salary_currency,
      salary_visible: v.salary_visible,
      languages: v.languages.filter((l) => l.lang !== ''),
      published: v.published,
      public_description: text(v.public_description),
      external_postings: v.external_postings.map((p) => ({ site: p.site, url: text(p.url), date: text(p.date) })),
    };
  }

  private onDraft(section: VacancyTextSection, draft: VacancyTextDraft, polls: number): void {
    if (draft.status === 'done' && draft.text !== null) {
      this.sectionControl(section).setValue(draft.text);
      this.form.markAsDirty();
      this.setAi(section, false, null);
      return;
    }
    if (draft.status === 'failed' || polls >= POLL_TIMES) {
      this.setAi(section, false, aiTextErrorKey(draft.error ?? 'ai_timeout'));
      return;
    }
    timer(POLL_EVERY_MS)
      .pipe(
        takeWhile(() => this.aiBusy()[section] === true),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(() =>
        this.api.vacancyTextResult(draft.request_id).subscribe({
          next: (next) => this.onDraft(section, next, polls + 1),
          error: (e: unknown) => this.setAi(section, false, aiTextErrorKey(e)),
        }),
      );
  }

  private setAi(section: VacancyTextSection, busy: boolean, hint: string | null): void {
    this.aiBusy.update((m) => ({ ...m, [section]: busy }));
    this.aiHints.update((m) => {
      const next = { ...m };
      if (hint === null) {
        delete next[section];
      } else {
        next[section] = hint;
      }
      return next;
    });
  }

  private applyServerErrors(e: unknown): void {
    if (!(e instanceof HttpErrorResponse) || e.status !== 422) {
      return;
    }
    const errors = (e.error as { errors?: Record<string, string[]> } | null)?.errors ?? {};
    const mapped: Record<string, string> = {};
    for (const [path, messages] of Object.entries(errors)) {
      mapped[path] = messages.includes('salary_range') ? 'recruiting.form.errors.salaryRange' : 'recruiting.form.errors.invalid';
      const control = this.controlAt(path);
      control?.setErrors({ server: true });
      control?.markAsTouched();
    }
    this.fieldErrors.set(mapped);
    this.error.set('recruiting.form.fixErrors');
  }

  private controlAt(path: string): AbstractControl | null {
    return this.form.get(path.split('.'));
  }

  private loadLists(): void {
    const keep = <T>(set: (v: T) => void) => ({ next: set, error: () => undefined });
    this.api.vacancyOptions().subscribe(keep((o: VacancyOptions) => this.options.set(o)));
    this.directory.active('branches').subscribe(keep((l: DictionaryItem[]) => this.branches.set(l)));
    this.directory.active('departments').subscribe(keep((l: DictionaryItem[]) => this.departments.set(l)));
    this.directory.active('cities').subscribe(keep((l: DictionaryItem[]) => this.cities.set(l)));
    this.directory.active('vacancy_categories').subscribe(keep((l: DictionaryItem[]) => this.categories.set(l)));
    this.api.pipelines().subscribe(
      keep((l: Pipeline[]) => {
        this.pipelines.set(l);
        this.preselectPipeline();
      }),
    );
    if (this.canWrite()) {
      this.api.assignableUsers().subscribe(keep((l: Ref[]) => this.people.set(withCurrent(l, this.vacancy()?.recruiter ?? null))));
      this.api.vacancyTemplates().subscribe(keep((l: VacancyTemplate[]) => this.templates.set(l)));
    }
  }

  private preselectPipeline(): void {
    if (this.vacancyId() === null && this.form.controls.pipeline_id.value === null) {
      const def = this.pipelines().find((p) => p.is_default) ?? this.pipelines()[0];
      this.form.controls.pipeline_id.setValue(def?.id ?? null, { emitEvent: false });
    }
  }

  /** Patches the form from a vacancy, a template or a draft (arrays rebuilt row by row). */
  private fill(data: SaveVacancy): void {
    const { languages, external_postings, ...rest } = data;
    for (const [key, value] of Object.entries(rest)) {
      const control = key in this.form.controls ? this.form.get(key) : null;
      control?.setValue(value ?? this.nullFor(key), { emitEvent: false });
    }
    if (languages) {
      this.languages.clear();
      languages.forEach((l) => this.addLanguage(l));
    }
    if (external_postings) {
      this.postings.clear();
      external_postings.forEach((p) => this.addPosting(p));
    }
    this.form.markAsPristine();
  }

  /** Text controls are non-nullable: null from the API becomes an empty string. */
  private nullFor(key: string): string | null {
    return (VACANCY_SECTIONS as readonly string[]).includes(key) || key === 'public_description' || key === 'title' ? '' : null;
  }

  private restoreDraft(): void {
    const raw = safeStorage.get(this.draftKey());
    if (raw === null) {
      return;
    }
    try {
      this.fill(JSON.parse(raw) as SaveVacancy);
      this.form.markAsDirty();
      this.draftRestored.set(true);
    } catch {
      safeStorage.remove(this.draftKey());
    }
  }

  private draftKey(): string {
    return DRAFT_PREFIX + (this.vacancyId() ?? 'new');
  }
}

/** Language / country name in the UI language (Intl), the code itself when the browser cannot name it. */
function displayName(type: 'language' | 'region', code: string, locale: string): string {
  try {
    return new Intl.DisplayNames([locale], { type }).of(code) ?? code;
  } catch {
    return code;
  }
}
