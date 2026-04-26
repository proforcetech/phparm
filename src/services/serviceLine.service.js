import api from './api'

/**
 * Service Line Service
 * Endpoints for the multi-trade WOMS expansion (Phase 11+).
 * Backed by ServiceLineController on the PHP backend.
 *
 * Backend wraps every response in `{ data: <payload> }` to match the
 * divisions module convention; we unwrap once here so callers see the
 * controller payload directly.
 */
export const serviceLineService = {
  /**
   * List all service lines (full DTOs).
   * Returns the array directly.
   */
  async list() {
    const response = await api.get('/service-lines')
    const payload = response.data?.data ?? response.data ?? {}
    return payload.service_lines ?? []
  },

  /**
   * List the current user's accessible service lines (trimmed DTOs)
   * along with their primary id.
   * Returns `{ service_lines, primary_id }`.
   */
  async listMine() {
    const response = await api.get('/me/service-lines')
    const payload = response.data?.data ?? response.data ?? {}
    return {
      service_lines: payload.service_lines ?? [],
      primary_id: payload.primary_id ?? null,
    }
  },

  /**
   * Set the current user's primary service line.
   * Returns the updated `{ service_lines, primary_id }` payload.
   */
  async setPrimary(serviceLineId) {
    const response = await api.put('/me/service-lines/primary', {
      service_line_id: serviceLineId,
    })
    const payload = response.data?.data ?? response.data ?? {}
    return {
      service_lines: payload.service_lines ?? [],
      primary_id: payload.primary_id ?? null,
    }
  },
}

export default serviceLineService
