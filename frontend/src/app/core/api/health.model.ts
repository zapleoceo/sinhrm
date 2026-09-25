export interface HealthCheckResult {
  ok: boolean;
  detail?: string;
}

/** Response of GET /api/health (backend: App\Modules\Core\Http\Controllers\HealthController). */
export interface HealthReport {
  version: string;
  ok: boolean;
  checks: Record<string, HealthCheckResult>;
}
