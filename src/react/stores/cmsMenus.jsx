import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import { cmsService } from '../../services/cms.service'

const CmsMenuContext = createContext(null)

export function CmsMenuProvider({ children }) {
  const [menus, setMenus] = useState([])
  const [currentMenu, setCurrentMenu] = useState(null)
  const [drafts, setDrafts] = useState({})
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  const setDraft = useCallback((id, data) => {
    const key = id || 'new'
    setDrafts((prev) => ({ ...prev, [key]: { ...data } }))
  }, [])

  const clearDraft = useCallback((id) => {
    const key = id || 'new'
    setDrafts((prev) => {
      const next = { ...prev }
      delete next[key]
      return next
    })
  }, [])

  const mergeMenu = useCallback((updated) => {
    setMenus((prev) => {
      const index = prev.findIndex((menu) => menu.id === updated.id)
      if (index !== -1) {
        const next = [...prev]
        next[index] = updated
        return next
      }
      return [...prev, updated]
    })
  }, [])

  const fetchMenus = useCallback(async (params = {}) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getMenus(params)
      const nextMenus = data.data || data || []
      setMenus(nextMenus)
      return nextMenus
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load menus')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const fetchMenu = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getMenu(id)
      const menu = data.data || data
      setCurrentMenu(menu)
      return menu
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load menu')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createMenu = useCallback(
    async (payload) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.createMenu(payload)
        const created = data.data || data
        mergeMenu(created)
        setCurrentMenu(created)
        clearDraft('new')
        return created
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to create menu')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergeMenu]
  )

  const updateMenu = useCallback(
    async (id, payload) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.updateMenu(id, payload)
        const updated = data.data || data
        mergeMenu(updated)
        setCurrentMenu(updated)
        clearDraft(id)
        return updated
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to update menu')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergeMenu]
  )

  const publishMenu = useCallback(
    async (id) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.publishMenu(id)
        const published = data.data || data
        mergeMenu(published)
        setCurrentMenu(published)
        clearDraft(id)
        return published
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to publish menu')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergeMenu]
  )

  const deleteMenu = useCallback(
    async (id) => {
      try {
        setSaving(true)
        setError(null)

        await cmsService.deleteMenu(id)
        setMenus((prev) => prev.filter((menu) => menu.id !== id))
        setCurrentMenu((prev) => (prev?.id === id ? null : prev))
        clearDraft(id)
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to delete menu')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft]
  )

  const value = useMemo(
    () => ({
      menus,
      currentMenu,
      drafts,
      loading,
      saving,
      error,
      fetchMenus,
      fetchMenu,
      createMenu,
      updateMenu,
      publishMenu,
      deleteMenu,
      setDraft,
      clearDraft,
    }),
    [
      clearDraft,
      createMenu,
      currentMenu,
      deleteMenu,
      drafts,
      error,
      fetchMenu,
      fetchMenus,
      loading,
      menus,
      publishMenu,
      saving,
      setDraft,
      updateMenu,
    ]
  )

  return <CmsMenuContext.Provider value={value}>{children}</CmsMenuContext.Provider>
}

export function useCmsMenuStore() {
  const context = useContext(CmsMenuContext)

  if (!context) {
    throw new Error('useCmsMenuStore must be used within a CmsMenuProvider.')
  }

  return context
}
