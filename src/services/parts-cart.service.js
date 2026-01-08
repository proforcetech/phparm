import api from './api'

export default {
  /**
   * Get or create a parts cart for a workorder
   * @param {number} workorderId - Workorder ID
   * @returns {Promise}
   */
  async getOrCreate(workorderId) {
    const response = await api.get(`/workorders/${workorderId}/parts-cart`)
    return response.data
  },

  /**
   * Get a single parts cart by ID
   * @param {number} cartId - Parts cart ID
   * @returns {Promise}
   */
  async get(cartId) {
    const response = await api.get(`/parts-carts/${cartId}`)
    return response.data
  },

  /**
   * Add a custom item to the cart
   * @param {number} cartId - Parts cart ID
   * @param {Object} data - Item data
   * @returns {Promise}
   */
  async addItem(cartId, data) {
    const response = await api.post(`/parts-carts/${cartId}/items`, data)
    return response.data
  },

  /**
   * Add an item from inventory to the cart
   * @param {number} cartId - Parts cart ID
   * @param {number} inventoryItemId - Inventory item ID
   * @param {number} quantity - Quantity to add
   * @param {number|null} workorderJobId - Optional workorder job ID
   * @returns {Promise}
   */
  async addFromInventory(cartId, inventoryItemId, quantity = 1, workorderJobId = null) {
    const response = await api.post(`/parts-carts/${cartId}/items/from-inventory`, {
      inventory_item_id: inventoryItemId,
      quantity,
      workorder_job_id: workorderJobId
    })
    return response.data
  },

  /**
   * Add an item from PartsTech search to the cart
   * @param {number} cartId - Parts cart ID
   * @param {Object} partData - PartsTech part data
   * @param {number} quantity - Quantity to add
   * @param {number|null} workorderJobId - Optional workorder job ID
   * @returns {Promise}
   */
  async addFromPartsTech(cartId, partData, quantity = 1, workorderJobId = null) {
    const response = await api.post(`/parts-carts/${cartId}/items/from-partstech`, {
      part_data: partData,
      quantity,
      workorder_job_id: workorderJobId
    })
    return response.data
  },

  /**
   * Update a cart item
   * @param {number} cartId - Parts cart ID
   * @param {number} itemId - Cart item ID
   * @param {Object} data - Updated data
   * @returns {Promise}
   */
  async updateItem(cartId, itemId, data) {
    const response = await api.patch(`/parts-carts/${cartId}/items/${itemId}`, data)
    return response.data
  },

  /**
   * Remove an item from the cart
   * @param {number} cartId - Parts cart ID
   * @param {number} itemId - Cart item ID
   * @returns {Promise}
   */
  async removeItem(cartId, itemId) {
    const response = await api.delete(`/parts-carts/${cartId}/items/${itemId}`)
    return response.data
  },

  /**
   * Submit cart for approval
   * @param {number} cartId - Parts cart ID
   * @returns {Promise}
   */
  async submit(cartId) {
    const response = await api.post(`/parts-carts/${cartId}/submit`)
    return response.data
  },

  /**
   * Approve a parts cart
   * @param {number} cartId - Parts cart ID
   * @returns {Promise}
   */
  async approve(cartId) {
    const response = await api.post(`/parts-carts/${cartId}/approve`)
    return response.data
  },

  /**
   * Reject a parts cart
   * @param {number} cartId - Parts cart ID
   * @param {string|null} reason - Rejection reason
   * @returns {Promise}
   */
  async reject(cartId, reason = null) {
    const response = await api.post(`/parts-carts/${cartId}/reject`, { reason })
    return response.data
  },

  /**
   * Place order for approved cart (triggers PartsTech order if applicable)
   * @param {number} cartId - Parts cart ID
   * @returns {Promise}
   */
  async order(cartId) {
    const response = await api.post(`/parts-carts/${cartId}/order`)
    return response.data
  },

  /**
   * Mark cart items as received
   * @param {number} cartId - Parts cart ID
   * @param {Array|null} items - Optional array of {id, quantity} for partial receive
   * @returns {Promise}
   */
  async receive(cartId, items = null) {
    const data = items ? { items } : {}
    const response = await api.post(`/parts-carts/${cartId}/receive`, data)
    return response.data
  },

  /**
   * Cancel a parts cart
   * @param {number} cartId - Parts cart ID
   * @param {string|null} reason - Cancellation reason
   * @returns {Promise}
   */
  async cancel(cartId, reason = null) {
    const response = await api.post(`/parts-carts/${cartId}/cancel`, { reason })
    return response.data
  },

  /**
   * Search PartsTech for parts
   * @param {string} query - Search query
   * @param {Object} vehicle - Optional vehicle data {year, make, model}
   * @param {number} limit - Maximum results
   * @returns {Promise}
   */
  async searchPartsTech(query, vehicle = {}, limit = 20) {
    const params = { q: query, limit }
    if (vehicle.year) params.year = vehicle.year
    if (vehicle.make) params.make = vehicle.make
    if (vehicle.model) params.model = vehicle.model

    const response = await api.get('/parts-carts/partstech/search', { params })
    return response.data
  },

  /**
   * Get PartsTech integration status
   * @returns {Promise}
   */
  async getPartsTechStatus() {
    const response = await api.get('/parts-carts/partstech/status')
    return response.data
  }
}
