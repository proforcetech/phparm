import api from './api'

/**
 * Bulk asset CSV import client (Phase 18 / S12).
 *
 * Workflow:
 *   1. upload(file)             → creates pending header
 *   2. updateMapping(id, …)     → save column → field mapping + defaults
 *   3. validate(id)             → dry-run; per-row parsed_data + errors
 *   4. apply(id)                → INSERT validated rows into site_assets
 *
 * Calls go through the shared `api` (staff JWT) — server enforces
 * `assets.manage` for writes and `assets.view` for reads.
 */
export default {
  list(limit = 50) {
    return api.get('/asset-imports', { params: { limit } }).then((r) => r.data)
  },

  get(id) {
    return api.get(`/asset-imports/${id}`).then((r) => r.data)
  },

  upload(file, defaults = {}) {
    const fd = new FormData()
    fd.append('file', file)
    if (defaults.default_site_id != null) fd.append('default_site_id', String(defaults.default_site_id))
    if (defaults.default_division_id != null) fd.append('default_division_id', String(defaults.default_division_id))
    if (defaults.default_asset_type_id != null) fd.append('default_asset_type_id', String(defaults.default_asset_type_id))
    if (defaults.mapping) fd.append('mapping', JSON.stringify(defaults.mapping))
    if (defaults.notes) fd.append('notes', defaults.notes)
    return api
      .post('/asset-imports', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      .then((r) => r.data)
  },

  updateMapping(id, payload) {
    return api.patch(`/asset-imports/${id}`, payload).then((r) => r.data)
  },

  validate(id) {
    return api.post(`/asset-imports/${id}/validate`, {}).then((r) => r.data)
  },

  apply(id) {
    return api.post(`/asset-imports/${id}/apply`, {}).then((r) => r.data)
  },

  cancel(id, reason = '') {
    return api.post(`/asset-imports/${id}/cancel`, reason ? { reason } : {}).then((r) => r.data)
  },

  listRows(id, { status = null, limit = 1000, offset = 0 } = {}) {
    const params = { limit, offset }
    if (status) params.status = status
    return api.get(`/asset-imports/${id}/rows`, { params }).then((r) => r.data)
  },
}
