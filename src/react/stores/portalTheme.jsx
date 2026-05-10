import { createContext, useContext, useEffect, useMemo, useState } from 'react'

import portalApi from '../../services/portal/api'

const PortalThemeContext = createContext(null)

const CSS_VAR_MAP = {
  primary_color: '--portal-primary',
  secondary_color: '--portal-secondary',
  accent_color: '--portal-accent',
  background_color: '--portal-bg',
  text_color: '--portal-text',
}

/**
 * Phase 2a — host-resolved branding.
 *
 * Calls the unauthenticated /api/portal/theme endpoint, which keys on the
 * Host header to return the tenant theme (or a platform default) so the
 * login page renders branded BEFORE any JWT exists. Once a portal user
 * authenticates, downstream views can opt to refetch /portal/theme/me to
 * confirm the theme matches the account, but the host-resolved payload is
 * authoritative for shell chrome.
 *
 * CSS variables are written to documentElement.style so plain CSS in
 * components (or Tailwind arbitrary values) can read them without prop
 * drilling.
 */
export function PortalThemeProvider({ children }) {
  const [theme, setTheme] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    portalApi
      .get('/portal/theme')
      .then((res) => {
        if (cancelled) return
        setTheme(res.data || null)
      })
      .catch((err) => {
        if (cancelled) return
        setError(err)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (!theme || typeof document === 'undefined') return
    const root = document.documentElement
    Object.entries(CSS_VAR_MAP).forEach(([key, cssVar]) => {
      const value = theme[key]
      if (value) root.style.setProperty(cssVar, value)
      else root.style.removeProperty(cssVar)
    })
    if (theme.display_name) {
      document.title = theme.display_name
    }
    if (theme.favicon_url) {
      let link = document.querySelector("link[rel='icon']")
      if (!link) {
        link = document.createElement('link')
        link.rel = 'icon'
        document.head.appendChild(link)
      }
      link.href = theme.favicon_url
    }
  }, [theme])

  const value = useMemo(() => ({ theme, loading, error }), [theme, loading, error])
  return <PortalThemeContext.Provider value={value}>{children}</PortalThemeContext.Provider>
}

export function usePortalTheme() {
  const ctx = useContext(PortalThemeContext)
  if (!ctx) {
    throw new Error('usePortalTheme must be used within a PortalThemeProvider')
  }
  return ctx
}
