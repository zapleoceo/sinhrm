/** Types of the Assets API (backend app/Modules/Assets). */

export type AssetStatus = 'in_stock' | 'assigned' | 'repair' | 'written_off';
export const ASSET_STATUSES: readonly AssetStatus[] = ['in_stock', 'assigned', 'repair', 'written_off'];
/** Statuses an asset may take when edited or returned ("assigned" only through assign). */
export const RETURN_STATUSES: readonly AssetStatus[] = ['in_stock', 'repair', 'written_off'];

export interface AssetType {
  id: number;
  name: string;
}

export interface AssetHistory {
  id: number;
  employee: { id: number; full_name?: string };
  assigned_at: string;
  returned_at: string | null;
  condition_out: string | null;
  condition_in: string | null;
  /** Only in the employee view. */
  asset?: { id: number; inventory_number: string; name: string; serial: string | null; type: string | null; status: AssetStatus };
}

export interface Asset {
  id: number;
  inventory_number: string;
  serial: string | null;
  name: string;
  type: AssetType | null;
  status: AssetStatus;
  cost: string | null;
  purchased_at: string | null;
  notes: string | null;
  employee: { id: number; full_name: string } | null;
  history?: AssetHistory[];
}

export interface SaveAsset {
  inventory_number?: string;
  name?: string;
  serial?: string | null;
  type_id?: number | null;
  status?: AssetStatus;
  cost?: number | null;
  purchased_at?: string | null;
  notes?: string | null;
}

export interface AssetQuery {
  status?: AssetStatus;
  type_id?: number;
  q?: string;
}

export const ASSET_ERROR_CODES = ['inventory_number_taken', 'not_in_stock', 'not_assigned', 'status_via_assign', 'employee_terminated', 'return_before_assign'] as const;
