import api from './api'

/**
 * CMS Service
 *
 * Handles all CMS-related API calls for content management
 * within the phparm dashboard.
 */
export default {
  // ================================================
  // Dashboard
  // ================================================

  /**
   * Get CMS dashboard statistics
   */
  async getDashboard() {
    const response = await api.get('/cms/dashboard')
    return response.data
  },

  // ================================================
  // Pages
  // ================================================

  /**
   * Get all CMS pages
   */
  async getPages(params = {}) {
    const response = await api.get('/cms/pages', { params })
    return response.data
  },

  /**
   * Get a single page by ID
   */
  async getPage(id) {
    const response = await api.get(`/cms/pages/${id}`)
    return response.data
  },

  /**
   * Get a published page by slug (public access)
   */
  async getPageBySlug(slug) {
    const response = await api.get(`/cms/page/${slug}`)
    return response.data
  },

  /**
   * Get fully rendered HTML for a published page by slug
   */
  async getRenderedPageBySlug(slug) {
    const response = await api.get(`/cms/page/${slug}/rendered`)
    return response.data
  },

  /**
   * Get form options for page editing (templates, components, etc.)
   */
  async getPageFormOptions() {
    const response = await api.get('/cms/pages/form-options')
    return response.data
  },

  /**
   * Create a new page
   */
  async createPage(data) {
    const response = await api.post('/cms/pages', data)
    return response.data
  },

  /**
   * Update an existing page
   */
  async updatePage(id, data) {
    const response = await api.put(`/cms/pages/${id}`, data)
    return response.data
  },

  /**
   * Publish a page
   */
  async publishPage(id) {
    const response = await api.post(`/cms/pages/${id}/publish`)
    return response.data
  },

  /**
   * Delete a page
   */
  async deletePage(id) {
    const response = await api.delete(`/cms/pages/${id}`)
    return response.data
  },

  // ================================================
  // Categories
  // ================================================

  /**
   * Get all CMS categories
   */
  async getCategories(params = {}) {
    const response = await api.get('/cms/categories', { params })
    return response.data
  },

  /**
   * Get a single category by ID
   */
  async getCategory(id) {
    const response = await api.get(`/cms/categories/${id}`)
    return response.data
  },

  /**
   * Create a new category
   */
  async createCategory(data) {
    const response = await api.post('/cms/categories', data)
    return response.data
  },

  /**
   * Update an existing category
   */
  async updateCategory(id, data) {
    const response = await api.put(`/cms/categories/${id}`, data)
    return response.data
  },

  /**
   * Delete a category
   */
  async deleteCategory(id) {
    const response = await api.delete(`/cms/categories/${id}`)
    return response.data
  },

  // ================================================
  // Menus
  // ================================================

  /**
   * Get all CMS menus
   */
  async getMenus(params = {}) {
    const response = await api.get('/cms/menus', { params })
    return response.data
  },

  /**
   * Get a single menu by ID
   */
  async getMenu(id) {
    const response = await api.get(`/cms/menus/${id}`)
    return response.data
  },

  /**
   * Create a new menu
   */
  async createMenu(data) {
    const response = await api.post('/cms/menus', data)
    return response.data
  },

  /**
   * Update an existing menu
   */
  async updateMenu(id, data) {
    const response = await api.put(`/cms/menus/${id}`, data)
    return response.data
  },

  /**
   * Delete a menu
   */
  async deleteMenu(id) {
    const response = await api.delete(`/cms/menus/${id}`)
    return response.data
  },

  /**
   * Publish a menu
   */
  async publishMenu(id) {
    const response = await api.post(`/cms/menus/${id}/publish`)
    return response.data
  },

  // ================================================
  // Components
  // ================================================

  /**
   * Get all CMS components
   */
  async getComponents(params = {}) {
    const response = await api.get('/cms/components', { params })
    return response.data
  },

  /**
   * Get a single component by ID
   */
  async getComponent(id) {
    const response = await api.get(`/cms/components/${id}`)
    return response.data
  },

  /**
   * Create a new component
   */
  async createComponent(data) {
    const response = await api.post('/cms/components', data)
    return response.data
  },

  /**
   * Update an existing component
   */
  async updateComponent(id, data) {
    const response = await api.put(`/cms/components/${id}`, data)
    return response.data
  },

  /**
   * Delete a component
   */
  async deleteComponent(id) {
    const response = await api.delete(`/cms/components/${id}`)
    return response.data
  },

  /**
   * Duplicate a component
   */
  async duplicateComponent(id) {
    const response = await api.post(`/cms/components/${id}/duplicate`)
    return response.data
  },

  // ================================================
  // Templates
  // ================================================

  /**
   * Get all CMS templates
   */
  async getTemplates(params = {}) {
    const response = await api.get('/cms/templates', { params })
    return response.data
  },

  /**
   * Get a single template by ID
   */
  async getTemplate(id) {
    const response = await api.get(`/cms/templates/${id}`)
    return response.data
  },

  /**
   * Create a new template
   */
  async createTemplate(data) {
    const response = await api.post('/cms/templates', data)
    return response.data
  },

  /**
   * Update an existing template
   */
  async updateTemplate(id, data) {
    const response = await api.put(`/cms/templates/${id}`, data)
    return response.data
  },

  /**
   * Delete a template
   */
  async deleteTemplate(id) {
    const response = await api.delete(`/cms/templates/${id}`)
    return response.data
  },

  // ================================================
  // Settings
  // ================================================

  /**
   * Get CMS settings
   */
  async getSettings() {
    const response = await api.get('/cms/settings')
    return response.data
  },

  /**
   * Update CMS settings
   */
  async updateSettings(settings) {
    const response = await api.put('/cms/settings', settings)
    return response.data
  },

  // ================================================
  // Cache
  // ================================================

  /**
   * Get cache statistics
   */
  async getCacheStats() {
    const response = await api.get('/cms/cache')
    return response.data
  },

  /**
   * Clear cache
   */
  async clearCache(type = null) {
    const response = await api.post('/cms/cache/clear', { type })
    return response.data
  },

  // ================================================
  // Component Types (for dropdowns)
  // ================================================

  /**
   * Get available component types
   */
  getComponentTypes() {
    return [
      { value: 'header', label: 'Header' },
      { value: 'footer', label: 'Footer' },
      { value: 'navigation', label: 'Navigation' },
      { value: 'sidebar', label: 'Sidebar' },
      { value: 'widget', label: 'Widget' },
      { value: 'custom', label: 'Custom' },
    ]
  },
}
