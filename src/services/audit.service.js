import api from './api'

export function listAuditLogs(filters = {}) {
  return api.get('/audit', { params: filters }).then((response) => response.data)
}

const auditService = {
  listAuditLogs,
}

export default auditService
