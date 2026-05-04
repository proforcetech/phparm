import api from './api'

/**
 * Trade-specific KPI dashboards — Phase 17 / S10 of
 * docs/woms-expansion-plan.md.
 *
 * Read-only. Pick a service line, get the KPI bundle for the chosen window.
 */
export default {
  listServiceLines() {
    return api.get('/trade-kpis/service-lines').then((res) => res.data)
  },
  bundle(serviceLineId, params = {}) {
    return api.get(`/trade-kpis/${serviceLineId}`, { params }).then((res) => res.data)
  },
}
