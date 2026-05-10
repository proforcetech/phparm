import portalApi, { writePortalSession, clearPortalSession } from './api'

/**
 * Phase 2a — portal authentication surface.
 *
 * Wraps /api/portal/auth/* endpoints. The portal login mints a bearer JWT
 * with scope='portal'; this layer persists it (via writePortalSession) so
 * the api.js request interceptor can attach it on subsequent calls.
 */
export const portalAuthService = {
  async login(email, password) {
    const response = await portalApi.post('/portal/auth/login', { email, password })
    const data = response.data || {}
    writePortalSession({
      accessToken: data.access_token,
      refreshToken: data.refresh_token,
      user: data.user,
      account: data.portal_account,
    })
    return data
  },

  async me() {
    const response = await portalApi.get('/portal/auth/me')
    return response.data
  },

  logout() {
    // Backend has no explicit logout endpoint for portal — token expiry +
    // local clear is the contract. Kept async for symmetry with future
    // server-side revocation.
    clearPortalSession()
    return Promise.resolve()
  },

  // --- Phase 2e: portal SSO (Decision D) ---

  /**
   * List portal-enabled SSO providers visible to a given company. The login
   * page resolves company_id from the public theme payload (host-scoped).
   */
  async listSsoProviders(companyId) {
    const response = await portalApi.get('/portal/auth/sso/providers', {
      params: { company_id: companyId },
    })
    return response.data || []
  },

  /**
   * Begin the OIDC dance. Returns the IdP authorize URL the caller should
   * redirect the browser to. The state is also returned so callers can drop
   * a defensive cookie if they want — the DB row is the authoritative side.
   */
  async startSso(slug, { companyId, redirectUri }) {
    const response = await portalApi.post(`/portal/auth/sso/${slug}/start`, {
      company_id: companyId,
      redirect_uri: redirectUri,
    })
    return response.data || {}
  },

  /**
   * Finalize the OIDC dance: exchange the IdP-provided state+code for a
   * portal-scoped JWT pair, then persist the session locally.
   */
  async completeSso({ state, code }) {
    const response = await portalApi.get('/portal/auth/sso/callback', {
      params: { state, code },
    })
    const data = response.data || {}
    writePortalSession({
      accessToken: data.access_token,
      refreshToken: data.refresh_token,
      user: data.user,
      account: data.portal_account,
    })
    return data
  },
}
