import api from './api'

/**
 * Customer portal — service request wizard (Phase 14 / M8 of the WOMS plan).
 *
 * Reuses `/api/portal/*` endpoints from the existing portal:
 *   GET  /portal/request-types                  — top-level categories
 *   GET  /portal/request-types/{id}/subcategories
 *   GET  /portal/sites                          — sites the account can see
 *   GET  /portal/sites/{id}/assets              — assets at a given site
 *   POST /portal/requests                       — submit (mints a ticket)
 *
 * IT helpdesk-specific fields (it_request_kind, severity,
 * affected_users_count, business_impact) are accepted in the submit payload;
 * the backend's ItHelpdeskService validates and auto-derives severity. The
 * UI form should hide those fields unless the caller selects an IT request
 * kind in step 3.
 */
export const portalRequestService = {
  listRequestTypes() {
    return api.get('/portal/request-types').then((res) => res.data?.data ?? [])
  },

  listSubcategories(requestTypeId) {
    return api
      .get(`/portal/request-types/${requestTypeId}/subcategories`)
      .then((res) => res.data?.data ?? [])
  },

  listSites() {
    return api.get('/portal/sites').then((res) => res.data?.data ?? [])
  },

  listAssetsForSite(siteId) {
    return api.get(`/portal/sites/${siteId}/assets`).then((res) => res.data?.data ?? [])
  },

  submit(payload) {
    return api.post('/portal/requests', payload).then((res) => res.data?.data ?? res.data)
  },
}

export const IT_REQUEST_KINDS = [
  { value: 'incident', label: 'Incident — something broken' },
  { value: 'outage', label: 'Outage — service unavailable' },
  { value: 'request', label: 'Service request — new access / install' },
  { value: 'question', label: 'Question — how-to / advice' },
]

export const SEVERITIES = [
  { value: 'P4', label: 'P4 — Low (cosmetic / nice-to-have)' },
  { value: 'P3', label: 'P3 — Normal (single user impacted)' },
  { value: 'P2', label: 'P2 — High (team or department impacted)' },
]

export const PORTAL_PRIORITIES = [
  { value: 'p4_low', label: 'Low' },
  { value: 'p3_normal', label: 'Normal' },
]
