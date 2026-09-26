/** Mirrors GET/POST /api/me/extension-token (backend Recruiting → ExtensionController). */
export interface ExtensionTokenStatus {
  active: boolean;
  created_at: string | null;
  last_used_at: string | null;
  expires_at: string | null;
  /** Plaintext — only in the POST response, shown once. */
  token?: string;
}

/** Install instructions (docs/modules/extension.md in the public repository). */
export const EXTENSION_DOCS_URL = 'https://github.com/zapleoceo/sinhrm/blob/main/docs/modules/extension.md';
