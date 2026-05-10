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
}
