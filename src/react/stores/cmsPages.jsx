import { createContext, useCallback, useContext, useMemo, useState } from 'react'

import { cmsService } from '../../services/cms.service'

const CmsPageContext = createContext(null)

export function CmsPageProvider({ children }) {
  const [pages, setPages] = useState([])
  const [currentPage, setCurrentPage] = useState(null)
  const [drafts, setDrafts] = useState({})
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  const draftForCurrent = useMemo(() => {
    const key = currentPage?.id || 'new'
    return drafts[key]
  }, [currentPage, drafts])

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

  const mergePage = useCallback((updated) => {
    setPages((prev) => {
      const index = prev.findIndex((page) => page.id === updated.id)
      if (index !== -1) {
        const next = [...prev]
        next[index] = updated
        return next
      }
      return [...prev, updated]
    })
  }, [])

  const fetchPages = useCallback(async (params = {}) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getPages(params)
      const nextPages = data.data || data || []
      setPages(nextPages)
      return nextPages
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load pages')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const fetchPage = useCallback(async (id) => {
    try {
      setLoading(true)
      setError(null)

      const data = await cmsService.getPage(id)
      const page = data.data || data
      setCurrentPage(page)
      return page
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load page')
      throw err
    } finally {
      setLoading(false)
    }
  }, [])

  const createPage = useCallback(
    async (payload) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.createPage(payload)
        const created = data.data || data
        mergePage(created)
        setCurrentPage(created)
        clearDraft('new')
        return created
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to create page')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergePage]
  )

  const updatePage = useCallback(
    async (id, payload) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.updatePage(id, payload)
        const updated = data.data || data
        mergePage(updated)
        setCurrentPage(updated)
        clearDraft(id)
        return updated
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to update page')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergePage]
  )

  const publishPage = useCallback(
    async (id) => {
      try {
        setSaving(true)
        setError(null)

        const data = await cmsService.publishPage(id)
        const published = data.data || data
        mergePage(published)
        setCurrentPage(published)
        clearDraft(id)
        return published
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to publish page')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft, mergePage]
  )

  const deletePage = useCallback(
    async (id) => {
      try {
        setSaving(true)
        setError(null)

        await cmsService.deletePage(id)
        setPages((prev) => prev.filter((page) => page.id !== id))
        setCurrentPage((prev) => (prev?.id === id ? null : prev))
        clearDraft(id)
      } catch (err) {
        setError(err.response?.data?.message || 'Failed to delete page')
        throw err
      } finally {
        setSaving(false)
      }
    },
    [clearDraft]
  )

  const value = useMemo(
    () => ({
      pages,
      currentPage,
      drafts,
      loading,
      saving,
      error,
      draftForCurrent,
      fetchPages,
      fetchPage,
      createPage,
      updatePage,
      publishPage,
      deletePage,
      setDraft,
      clearDraft,
    }),
    [
      clearDraft,
      createPage,
      currentPage,
      deletePage,
      draftForCurrent,
      drafts,
      error,
      fetchPage,
      fetchPages,
      loading,
      pages,
      publishPage,
      saving,
      setDraft,
      updatePage,
    ]
  )

  return <CmsPageContext.Provider value={value}>{children}</CmsPageContext.Provider>
}

export function useCmsPageStore() {
  const context = useContext(CmsPageContext)

  if (!context) {
    throw new Error('useCmsPageStore must be used within a CmsPageProvider.')
  }

  return context
}
