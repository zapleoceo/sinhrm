import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { DayRouteView } from './overview.model';

/**
 * «Маршрут дня» card of the home page: today's interviews and my tasks as stations on a time line (decorative,
 * aria-hidden) plus the same events as an ordered list (time, kind, candidate link, title) — the list is what
 * screen readers and phones read. Empty day → app-empty. Data: GET /api/dashboard → day_route.
 */
@Component({
  selector: 'app-day-route',
  imports: [RouterLink, TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './day-route.html',
  styleUrl: './day-route.scss',
})
export class DayRouteCard {
  readonly route = input.required<DayRouteView>();
}
