import { api } from './api'

export const cmsService = {
  async getDashboard() {
    const response = await api.get('/cms/dashboard')
    return response.data
  },

  async getPages(params: Record<string, unknown> = {}) {
    const response = await api.get('/cms/pages', { params })
    return response.data
  },

  async getPage(id: number | string) {
    const response = await api.get(`/cms/pages/${id}`)
    return response.data
  },

  async getPageBySlug(slug: string) {
    const response = await api.get(`/cms/page/${slug}`)
    return response.data
  },

  async getRenderedPageBySlug(slug: string) {
    const response = await api.get(`/cms/page/${slug}/rendered`)
    return response.data
  },

  async getPageFormOptions() {
    const response = await api.get('/cms/pages/form-options')
    return response.data
  },

  async createPage(data: Record<string, unknown>) {
    const response = await api.post('/cms/pages', data)
    return response.data
  },

  async updatePage(id: number | string, data: Record<string, unknown>) {
    const response = await api.put(`/cms/pages/${id}`, data)
    return response.data
  },

  async publishPage(id: number | string) {
    const response = await api.post(`/cms/pages/${id}/publish`)
    return response.data
  },

  async getPagePreviewToken(id: number | string, regenerate = false) {
    const response = await api.post(`/cms/pages/${id}/preview-token`, { regenerate })
    return response.data
  },

  async deletePage(id: number | string) {
    const response = await api.delete(`/cms/pages/${id}`)
    return response.data
  },

  async getPageRevisions(id: number | string) {
    const response = await api.get(`/cms/pages/${id}/revisions`)
    return response.data
  },

  async restorePageRevision(id: number | string, revisionId: number | string) {
    const response = await api.post(`/cms/pages/${id}/revisions/${revisionId}/restore`)
    return response.data
  },

  async getCategories(params: Record<string, unknown> = {}) {
    const response = await api.get('/cms/categories', { params })
    return response.data
  },

  async getCategory(id: number | string) {
    const response = await api.get(`/cms/categories/${id}`)
    return response.data
  },

  async createCategory(data: Record<string, unknown>) {
    const response = await api.post('/cms/categories', data)
    return response.data
  },

  async updateCategory(id: number | string, data: Record<string, unknown>) {
    const response = await api.put(`/cms/categories/${id}`, data)
    return response.data
  },

  async deleteCategory(id: number | string) {
    const response = await api.delete(`/cms/categories/${id}`)
    return response.data
  },

  async getMenus(params: Record<string, unknown> = {}) {
    const response = await api.get('/cms/menus', { params })
    return response.data
  },

  async getMenu(id: number | string) {
    const response = await api.get(`/cms/menus/${id}`)
    return response.data
  },

  async createMenu(data: Record<string, unknown>) {
    const response = await api.post('/cms/menus', data)
    return response.data
  },

  async updateMenu(id: number | string, data: Record<string, unknown>) {
    const response = await api.put(`/cms/menus/${id}`, data)
    return response.data
  },

  async deleteMenu(id: number | string) {
    const response = await api.delete(`/cms/menus/${id}`)
    return response.data
  },

  async publishMenu(id: number | string) {
    const response = await api.post(`/cms/menus/${id}/publish`)
    return response.data
  },
}
