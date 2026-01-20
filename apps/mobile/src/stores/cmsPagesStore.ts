import { create } from 'zustand'

import { cmsService } from '../services/cms.service'

type CmsPage = Record<string, any>

type CmsPageState = {
  pages: CmsPage[]
  currentPage: CmsPage | null
  drafts: Record<string, CmsPage>
  loading: boolean
  saving: boolean
  error: string | null
  draftForCurrent: () => CmsPage | undefined
  setDraft: (id: number | string | null, data: CmsPage) => void
  clearDraft: (id: number | string | null) => void
  fetchPages: (params?: Record<string, unknown>) => Promise<CmsPage[]>
  fetchPage: (id: number | string) => Promise<CmsPage>
  createPage: (payload: Record<string, unknown>) => Promise<CmsPage>
  updatePage: (id: number | string, payload: Record<string, unknown>) => Promise<CmsPage>
  publishPage: (id: number | string) => Promise<CmsPage>
  deletePage: (id: number | string) => Promise<void>
}

export const useCmsPagesStore = create<CmsPageState>((set, get) => ({
  pages: [],
  currentPage: null,
  drafts: {},
  loading: false,
  saving: false,
  error: null,
  draftForCurrent: () => {
    const currentPage = get().currentPage
    const key = currentPage?.id || 'new'
    return get().drafts[key]
  },
  setDraft: (id, data) => {
    const key = id || 'new'
    set((state) => ({ drafts: { ...state.drafts, [key]: { ...data } } }))
  },
  clearDraft: (id) => {
    const key = id || 'new'
    set((state) => {
      const next = { ...state.drafts }
      delete next[key]
      return { drafts: next }
    })
  },
  fetchPages: async (params = {}) => {
    try {
      set({ loading: true, error: null })
      const data = await cmsService.getPages(params)
      const nextPages = data.data || data || []
      set({ pages: nextPages })
      return nextPages
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to load pages' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchPage: async (id) => {
    try {
      set({ loading: true, error: null })
      const data = await cmsService.getPage(id)
      const page = data.data || data
      set({ currentPage: page })
      return page
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to load page' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createPage: async (payload) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.createPage(payload)
      const created = data.data || data
      const pages = get().pages
      const index = pages.findIndex((page) => page.id === created.id)
      const nextPages = index === -1 ? [...pages, created] : pages.map((page, i) => (i === index ? created : page))
      set({ pages: nextPages, currentPage: created })
      get().clearDraft('new')
      return created
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to create page' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  updatePage: async (id, payload) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.updatePage(id, payload)
      const updated = data.data || data
      const nextPages = get().pages.map((page) => (page.id === updated.id ? updated : page))
      set({ pages: nextPages, currentPage: updated })
      get().clearDraft(id)
      return updated
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to update page' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  publishPage: async (id) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.publishPage(id)
      const published = data.data || data
      const nextPages = get().pages.map((page) => (page.id === published.id ? published : page))
      set({ pages: nextPages, currentPage: published })
      get().clearDraft(id)
      return published
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to publish page' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  deletePage: async (id) => {
    try {
      set({ saving: true, error: null })
      await cmsService.deletePage(id)
      set({
        pages: get().pages.filter((page) => page.id !== id),
        currentPage: get().currentPage?.id === id ? null : get().currentPage,
      })
      get().clearDraft(id)
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to delete page' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
}))
