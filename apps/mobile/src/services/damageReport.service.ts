import { api, fetchWithAuth } from './api'
import { registerSyncHandler } from './offlineSync'
import { enqueueItem, saveDraft, getDraft, deleteDraft } from '../utils/offlineQueue'
import { isOnline } from '../utils/network'
import { getEnv } from '../config/env'

const DAMAGE_REPORT_SYNC_TYPE = 'damage_report_create'
const DAMAGE_REPORT_DRAFT_PREFIX = 'damage_report_draft_'

export type DamagePhoto = {
  uri: string
  position: 'front' | 'rear' | 'left' | 'right' | 'additional'
  label: string
  timestamp: string
}

export type DamageReportData = {
  photos: DamagePhoto[]
  notes: string
  reportedAt: string
}

export type DamageReport = {
  id: number
  workorder_id: number
  job_id: number
  photos: Array<{
    id: number
    url: string
    position: string
    label: string
  }>
  notes: string | null
  created_at: string
  updated_at: string
}

export type DamageReportResponse = {
  success: boolean
  data: DamageReport
  message: string
}

export type DamageReportsListResponse = {
  success: boolean
  data: DamageReport[]
  message: string
}

/**
 * Create FormData for damage report upload.
 * Compresses and prepares images for multipart submission.
 */
const buildFormData = (data: DamageReportData): FormData => {
  const formData = new FormData()

  data.photos.forEach((photo, index) => {
    const filename = `${photo.position}_${Date.now()}_${index}.jpg`
    formData.append('photos[]', {
      uri: photo.uri,
      type: 'image/jpeg',
      name: filename,
    } as any)
    formData.append(`photo_positions[${index}]`, photo.position)
    formData.append(`photo_labels[${index}]`, photo.label)
  })

  formData.append('notes', data.notes || '')
  formData.append('reported_at', data.reportedAt)

  return formData
}

/**
 * Upload damage report with photos to the server.
 * Uses native fetch for FormData multipart upload.
 */
const uploadDamageReport = async (
  workorderId: number | string,
  jobId: number | string,
  data: DamageReportData
): Promise<DamageReportResponse> => {
  const { apiBaseUrl } = getEnv()
  const url = `${apiBaseUrl}/workorders/${workorderId}/jobs/${jobId}/damage-reports`
  const formData = buildFormData(data)

  const response = await fetchWithAuth(url, {
    method: 'POST',
    body: formData,
  })

  if (!response.ok) {
    const errorBody = await response.text()
    throw new Error(`Upload failed: ${response.status} - ${errorBody}`)
  }

  return response.json()
}

/**
 * Register offline sync handler for damage reports.
 * When offline reports are synced, this handler uploads them.
 */
registerSyncHandler(DAMAGE_REPORT_SYNC_TYPE, async (payload) => {
  const { workorderId, jobId, data } = payload as {
    workorderId: number
    jobId: number
    data: DamageReportData
  }

  return uploadDamageReport(workorderId, jobId, data)
})

const damageReportService = {
  /**
   * Create a new damage report with photos.
   * If offline, queues the report for later sync.
   */
  async createDamageReport(
    workorderId: number | string,
    jobId: number | string,
    data: DamageReportData
  ): Promise<{ queued?: boolean; data?: DamageReport }> {
    const online = await isOnline()

    if (!online) {
      await enqueueItem(DAMAGE_REPORT_SYNC_TYPE, {
        workorderId: Number(workorderId),
        jobId: Number(jobId),
        data,
      })
      // Clear any existing draft since we've queued it
      await this.deleteDraft(workorderId, jobId)
      return { queued: true }
    }

    const response = await uploadDamageReport(workorderId, jobId, data)
    // Clear draft on successful upload
    await this.deleteDraft(workorderId, jobId)
    return { data: response.data }
  },

  /**
   * Get all damage reports for a specific job.
   */
  async getDamageReports(
    workorderId: number | string,
    jobId: number | string
  ): Promise<DamageReport[]> {
    try {
      const response = await api.get<DamageReportsListResponse>(
        `/workorders/${workorderId}/jobs/${jobId}/damage-reports`
      )
      return response.data?.data ?? []
    } catch (error) {
      console.warn('Failed to fetch damage reports:', error)
      return []
    }
  },

  /**
   * Check if a damage report exists for a job.
   */
  async hasDamageReport(
    workorderId: number | string,
    jobId: number | string
  ): Promise<boolean> {
    const reports = await this.getDamageReports(workorderId, jobId)
    return reports.length > 0
  },

  /**
   * Save a draft damage report (photos captured but not yet submitted).
   */
  async saveDraft(
    workorderId: number | string,
    jobId: number | string,
    data: Partial<DamageReportData>
  ): Promise<void> {
    const draftId = `${DAMAGE_REPORT_DRAFT_PREFIX}${workorderId}_${jobId}`
    await saveDraft(draftId, 'damage_report', {
      workorderId: Number(workorderId),
      jobId: Number(jobId),
      ...data,
    })
  },

  /**
   * Get a saved draft damage report.
   */
  async getDraft(
    workorderId: number | string,
    jobId: number | string
  ): Promise<Partial<DamageReportData> | null> {
    const draftId = `${DAMAGE_REPORT_DRAFT_PREFIX}${workorderId}_${jobId}`
    const draft = await getDraft(draftId)
    if (!draft) {
      return null
    }
    return draft.data as Partial<DamageReportData>
  },

  /**
   * Delete a draft damage report.
   */
  async deleteDraft(
    workorderId: number | string,
    jobId: number | string
  ): Promise<void> {
    const draftId = `${DAMAGE_REPORT_DRAFT_PREFIX}${workorderId}_${jobId}`
    await deleteDraft(draftId)
  },
}

export default damageReportService
