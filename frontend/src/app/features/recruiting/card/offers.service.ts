import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { DataEnvelope } from '../../../core/api/api.model';
import { unwrapData } from '../../../core/api/unwrap-data';

export type OfferStatus = 'draft' | 'sent' | 'accepted' | 'declined';

export interface Offer {
  id: number;
  position: string;
  salary: string;
  start_date: string | null;
  conditions: string | null;
  content_md: string;
  status: OfferStatus;
}

export interface OfferTemplateRef {
  id: number;
  name: string;
}

export interface CreateOffer {
  template_id: number | null;
  position: string;
  salary: string;
  start_date: string | null;
  conditions: string | null;
}

/** HTTP client of the offer of an application (/api/applications/{id}/offer, /api/offer-templates). */
@Injectable({ providedIn: 'root' })
export class OffersService {
  private readonly http = inject(HttpClient);

  /** The offer of the application, null when there is none yet. */
  offer(applicationId: number): Observable<Offer | null> {
    return this.http.get<DataEnvelope<Offer | null>>(offerUrl(applicationId)).pipe(unwrapData());
  }

  templates(): Observable<OfferTemplateRef[]> {
    return this.http.get<DataEnvelope<OfferTemplateRef[]>>('/api/offer-templates').pipe(unwrapData());
  }

  create(applicationId: number, body: CreateOffer): Observable<Offer> {
    return this.http.post<DataEnvelope<Offer>>(offerUrl(applicationId), body).pipe(unwrapData());
  }

  /** «send» marks the offer sent; «decision» records the candidate's answer (accepted / declined). */
  act(applicationId: number, path: 'send' | 'decision', status?: OfferStatus): Observable<Offer> {
    return this.http.post<DataEnvelope<Offer>>(`${offerUrl(applicationId)}/${path}`, status ? { status } : {}).pipe(unwrapData());
  }
}

function offerUrl(applicationId: number): string {
  return `/api/applications/${applicationId}/offer`;
}
