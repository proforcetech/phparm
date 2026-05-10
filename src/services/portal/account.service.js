import portalApi from './api'

/**
 * Phase 2f — portal account-management surface.
 *
 * Wraps the four 2f endpoints: CSAT (pending + history + submit),
 * notification preferences (matrix + set), audit trail (read), and
 * self-issued API tokens (list/issue/revoke).
 */
export const portalAccountService = {
  // ── CSAT ──────────────────────────────────────────────────────────────
  async listCsatPending() {
    const response = await portalApi.get('/portal/csat/pending')
    return response.data?.data ?? response.data ?? []
  },

  async listCsatHistory() {
    const response = await portalApi.get('/portal/csat/history')
    return response.data?.data ?? response.data ?? []
  },

  async submitCsat(workorderId, { rating, comment = null }) {
    const body = { rating }
    if (comment != null && comment !== '') body.comment = comment
    const response = await portalApi.post(`/portal/csat/workorders/${workorderId}`, body)
    return response.data?.data ?? response.data
  },

  // ── Notification preferences ──────────────────────────────────────────
  async listNotificationPrefs() {
    const response = await portalApi.get('/portal/notification-preferences')
    return response.data?.data ?? response.data ?? []
  },

  async setNotificationPref({ pref_key, channel, enabled }) {
    const response = await portalApi.put('/portal/notification-preferences', {
      pref_key,
      channel,
      enabled,
    })
    return response.data?.data ?? response.data
  },

  // ── Audit trail ───────────────────────────────────────────────────────
  async listAuditTrail() {
    const response = await portalApi.get('/portal/audit-trail')
    return response.data?.data ?? response.data ?? []
  },

  // ── API tokens ────────────────────────────────────────────────────────
  async listApiTokens() {
    const response = await portalApi.get('/portal/api-tokens')
    return response.data?.data ?? response.data ?? []
  },

  /**
   * Returns the persisted token row PLUS a `plaintext_token` field. The
   * plaintext is shown to the user once and never returned again — the UI
   * must surface it immediately.
   */
  async issueApiToken({ name, scopes = null, expires_at = null }) {
    const body = { name }
    if (scopes !== null) body.scopes = scopes
    if (expires_at) body.expires_at = expires_at
    const response = await portalApi.post('/portal/api-tokens', body)
    return response.data?.data ?? response.data
  },

  async revokeApiToken(tokenId) {
    await portalApi.delete(`/portal/api-tokens/${tokenId}`)
  },
}
