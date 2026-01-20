import api from './api'

export async function uploadImportCsv(entity, file, { dryRun = false } = {}) {
  const formData = new FormData()
  formData.append('file', file)

  const response = await api.post(`/import/${entity}`, formData, {
    params: { dry_run: dryRun },
    headers: { 'Content-Type': 'multipart/form-data' },
  })

  return response.data
}

export default {
  uploadImportCsv,
}
