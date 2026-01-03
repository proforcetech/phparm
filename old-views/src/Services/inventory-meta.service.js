import api from './api'

const endpointMap = {
  categories: '/inventory/categories',
  vendors: '/inventory/vendors',
  locations: '/inventory/locations',
}

function getEndpoint(type) {
  const endpoint = endpointMap[type]
  if (!endpoint) {
    throw new Error(`Unsupported inventory lookup type: ${type}`)
  }
  return endpoint
}

export default {
  async list(type, params = {}) {
    const response = await api.get(getEndpoint(type), { params })
    return response.data
  },

  async create(type, payload) {
    const response = await api.post(getEndpoint(type), payload)
    return response.data
  },

  async update(type, id, payload) {
    const response = await api.put(`${getEndpoint(type)}/${id}`, payload)
    return response.data
  },

  async remove(type, id) {
    const response = await api.delete(`${getEndpoint(type)}/${id}`)
    return response.data
  },
}
