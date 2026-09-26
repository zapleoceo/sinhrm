/** Types of the Knowledge API (backend app/Modules/Knowledge). */
import { UserRole } from '../../core/auth/auth.model';

export type ArticleStatus = 'draft' | 'published';

export type Audience = { type: 'all' } | { type: 'branches'; ids: number[] } | { type: 'roles'; roles: UserRole[] };

export interface KbCategory {
  id: number;
  name: string;
  emoji: string | null;
  position: number;
}

export interface KbArticle {
  id: number;
  title: string;
  category: { id: number; name: string; emoji: string | null } | null;
  tags: string[];
  status: ArticleStatus;
  version: number;
  votes: { helpful: number; not_helpful: number };
  published_at: string | null;
  updated_at: string | null;
  can_edit: boolean;
  /** Editors only. */
  audience?: Audience;
  /** Detail only: sanitized on the server (Documents Markdown renderer). */
  html?: string;
  /** Detail, editors only. */
  body_md?: string | null;
  my_vote?: boolean | null;
}

export interface KbVersion {
  version: number;
  title: string;
  body_md: string;
  edited_by: number | null;
  created_at: string | null;
}

export interface SaveArticle {
  title?: string;
  body_md?: string;
  category_id?: number | null;
  tags?: string[];
  audience?: Audience;
  status?: ArticleStatus;
}

export interface ArticleQuery {
  q?: string;
  category_id?: number;
  tag?: string;
}

/** "a, b ,, c" → ["a", "b", "c"] (the server lower-cases and de-duplicates). */
export function parseTags(text: string): string[] {
  return text
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t !== '');
}

/** Share of "helpful" votes, 0–100, or null without votes. */
export function helpfulPercent(votes: KbArticle['votes']): number | null {
  const total = votes.helpful + votes.not_helpful;
  return total === 0 ? null : Math.round((votes.helpful / total) * 100);
}
