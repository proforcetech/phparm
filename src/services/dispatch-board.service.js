import api from './api'

/**
 * Multi-trade dispatch board — Phase 17 / M10 of
 * docs/woms-expansion-plan.md.
 *
 * board(filters) returns one combined payload (workorders + per-WO ranked
 * candidates + technician roster + skills lookup) so the kanban view can
 * render in a single fetch instead of N+1.
 *
 * assign(woId, techId) is the drop gesture; pass null to unassign.
 */
export default {
  board(params = {}) {
    return api.get('/dispatch-board', { params }).then((res) => res.data)
  },
  assign(workorderId, technicianId) {
    return api
      .post(`/dispatch-board/${workorderId}/assign`, { technician_id: technicianId })
      .then((res) => res.data)
  },
}
