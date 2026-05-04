import api from './api'

/**
 * Multi-site chain rollup — Phase 17 / S4 of docs/woms-expansion-plan.md.
 *
 * Read-only surface: list active chain customers, then fetch the rollup
 * (chain-level totals + per-site comparison rows) for one of them across a
 * date window.
 */
export default {
  listChains(params = {}) {
    return api.get('/chain-rollup/chains', { params }).then((res) => res.data)
  },
  rollup(companyId, params = {}) {
    return api.get(`/chain-rollup/${companyId}`, { params }).then((res) => res.data)
  },
}
