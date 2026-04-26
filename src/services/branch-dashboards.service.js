import api from './api'

/**
 * Branch dashboard rollups — per-branch operational KPIs.
 */
export default {
  overview(params = {}) {
    return api.get('/branches/dashboards/overview', { params }).then((res) => res.data)
  },
  byBranch(branchId, params = {}) {
    return api.get(`/branches/${branchId}/dashboard`, { params }).then((res) => res.data)
  },
}
