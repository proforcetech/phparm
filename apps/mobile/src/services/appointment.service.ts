import { api } from './api'

const appointmentService = {
  getAppointments(params: Record<string, unknown> = {}) {
    return api.get('/appointments', { params })
  },

  getAppointment(id: number | string) {
    return api.get(`/appointments/${id}`)
  },

  createAppointment(payload: Record<string, unknown>) {
    return api.post('/appointments', payload)
  },

  updateAppointment(id: number | string, payload: Record<string, unknown>) {
    return api.put(`/appointments/${id}`, payload)
  },

  deleteAppointment(id: number | string) {
    return api.delete(`/appointments/${id}`)
  },

  updateAppointmentStatus(id: number | string, status: string) {
    return api.patch(`/appointments/${id}/status`, { status })
  },

  fetchAvailability(params: Record<string, unknown> = {}) {
    return api.get('/appointments/availability', { params })
  },

  fetchPublicAvailability(params: Record<string, unknown> = {}) {
    return api.get('/public/appointments/availability', { params })
  },

  fetchAvailabilityConfig() {
    return api.get('/appointments/availability/config')
  },

  saveAvailabilityConfig(payload: Record<string, unknown>) {
    return api.put('/appointments/availability/config', payload)
  },
}

export default appointmentService
