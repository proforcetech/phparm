import api from './api'

export function listTechnicians(params = {}) {
  return api.get('/technicians', { params }).then((r) => r.data)
}

export function searchTechnicians(query) {
  return api.get('/technicians', {
    params: {
      query
    }
  }).then((r) => r.data)
}

const technicianService = {
  listTechnicians,
  searchTechnicians
}

export default technicianService
