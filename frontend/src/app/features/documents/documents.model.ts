/** Types of the Documents API (backend app/Modules/Documents). */

export type DocumentStatus = 'draft' | 'sent' | 'signed' | 'rejected' | 'archived';
export const DOCUMENT_STATUSES: readonly DocumentStatus[] = ['draft', 'sent', 'signed', 'rejected', 'archived'];

/** Variables of document templates, written as {Name} in the body. */
export const DOCUMENT_VARIABLES = ['ПІБ', "Ім'я", 'Посада', 'Відділ', 'Філія', 'Дата прийому', 'Дата звільнення', 'Керівник', 'Сьогодні'] as const;

export interface DocumentTemplate {
  id: number;
  name: string;
  category: string | null;
  body: string;
  archived: boolean;
  updated_at: string;
}

export interface SaveDocumentTemplate {
  name?: string;
  body?: string;
  category?: string | null;
  archived?: boolean;
}

export interface TemplatePreview {
  markdown: string;
  /** Sanitized server side. */
  html: string;
  missing: string[];
  unknown: string[];
}

export interface DocumentFile {
  filename: string;
  mime: string;
  size: number;
}

export interface DocumentSignature {
  method: string;
  signed_at: string;
  signer_employee_id: number | null;
}

export interface HrDocument {
  id: number;
  title: string;
  category: string | null;
  status: DocumentStatus;
  employee: { id: number; full_name: string };
  template_id: number | null;
  file: DocumentFile | null;
  has_content: boolean;
  reject_reason: string | null;
  sent_at: string | null;
  created_at: string;
  signatures: DocumentSignature[];
  can_acknowledge: boolean;
  can_manage: boolean;
  /** Detail only: markdown for admins (null otherwise) and the rendered, sanitized html. */
  content_md?: string | null;
  html?: string | null;
}

export interface DocumentQuery {
  employee_id?: number;
  status?: DocumentStatus;
  category?: string;
}

export interface CreateDocument {
  employee_id: number;
  template_id?: number;
  title?: string;
  category?: string;
  content_md?: string;
}

export interface UpdateDocument {
  title?: string;
  category?: string | null;
  content_md?: string;
  status?: 'archived';
}

/** Business error codes with their own message (backend DocumentException). */
export const DOCUMENT_ERROR_CODES = [
  'unknown_variables',
  'not_editable',
  'invalid_file',
  'file_too_large',
  'empty_document',
  'employee_has_no_login',
  'already_signed',
  'not_sent',
] as const;

/** Accepted upload types and size (the server re-checks both). */
export const DOCUMENT_FILE_ACCEPT = '.pdf,.png,.jpg,.jpeg,.docx';
export const DOCUMENT_FILE_MAX_BYTES = 2 * 1024 * 1024;

/** Replaces the [start, end) selection of `text` with `{name}`; returns the new text and caret. */
export function insertVariable(text: string, start: number, end: number, name: string): { text: string; caret: number } {
  const token = `{${name}}`;
  const from = Math.max(0, Math.min(start, text.length));
  const to = Math.max(from, Math.min(end, text.length));
  return { text: text.slice(0, from) + token + text.slice(to), caret: from + token.length };
}

/** Human readable file size: 512 B, 12 KB, 1.4 MB. */
export function fileSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Documents an admin may still edit / send. */
export function isEditable(doc: Pick<HrDocument, 'status'>): boolean {
  return doc.status === 'draft' || doc.status === 'rejected';
}
