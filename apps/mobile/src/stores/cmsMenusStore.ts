import { create } from 'zustand'

import { cmsService } from '../services/cms.service'

type CmsMenu = Record<string, any>

type CmsMenuState = {
  menus: CmsMenu[]
  currentMenu: CmsMenu | null
  drafts: Record<string, CmsMenu>
  loading: boolean
  saving: boolean
  error: string | null
  setDraft: (id: number | string | null, data: CmsMenu) => void
  clearDraft: (id: number | string | null) => void
  fetchMenus: (params?: Record<string, unknown>) => Promise<CmsMenu[]>
  fetchMenu: (id: number | string) => Promise<CmsMenu>
  createMenu: (payload: Record<string, unknown>) => Promise<CmsMenu>
  updateMenu: (id: number | string, payload: Record<string, unknown>) => Promise<CmsMenu>
  publishMenu: (id: number | string) => Promise<CmsMenu>
  deleteMenu: (id: number | string) => Promise<void>
}

export const useCmsMenusStore = create<CmsMenuState>((set, get) => ({
  menus: [],
  currentMenu: null,
  drafts: {},
  loading: false,
  saving: false,
  error: null,
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
  fetchMenus: async (params = {}) => {
    try {
      set({ loading: true, error: null })
      const data = await cmsService.getMenus(params)
      const nextMenus = data.data || data || []
      set({ menus: nextMenus })
      return nextMenus
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to load menus' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  fetchMenu: async (id) => {
    try {
      set({ loading: true, error: null })
      const data = await cmsService.getMenu(id)
      const menu = data.data || data
      set({ currentMenu: menu })
      return menu
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to load menu' })
      throw err
    } finally {
      set({ loading: false })
    }
  },
  createMenu: async (payload) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.createMenu(payload)
      const created = data.data || data
      const menus = get().menus
      const index = menus.findIndex((menu) => menu.id === created.id)
      const nextMenus = index === -1 ? [...menus, created] : menus.map((menu, i) => (i === index ? created : menu))
      set({ menus: nextMenus, currentMenu: created })
      get().clearDraft('new')
      return created
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to create menu' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  updateMenu: async (id, payload) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.updateMenu(id, payload)
      const updated = data.data || data
      const nextMenus = get().menus.map((menu) => (menu.id === updated.id ? updated : menu))
      set({ menus: nextMenus, currentMenu: updated })
      get().clearDraft(id)
      return updated
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to update menu' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  publishMenu: async (id) => {
    try {
      set({ saving: true, error: null })
      const data = await cmsService.publishMenu(id)
      const published = data.data || data
      const nextMenus = get().menus.map((menu) => (menu.id === published.id ? published : menu))
      set({ menus: nextMenus, currentMenu: published })
      get().clearDraft(id)
      return published
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to publish menu' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
  deleteMenu: async (id) => {
    try {
      set({ saving: true, error: null })
      await cmsService.deleteMenu(id)
      set({
        menus: get().menus.filter((menu) => menu.id !== id),
        currentMenu: get().currentMenu?.id === id ? null : get().currentMenu,
      })
      get().clearDraft(id)
    } catch (err: any) {
      set({ error: err.response?.data?.message || 'Failed to delete menu' })
      throw err
    } finally {
      set({ saving: false })
    }
  },
}))
