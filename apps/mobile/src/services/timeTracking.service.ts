import { api } from './api'
import { registerSyncHandler } from './offlineSync'
import { enqueueItem } from '../utils/offlineQueue'
import { isOnline } from '../utils/network'

const TIME_CLOCK_IN = 'time_clock_in'
const TIME_CLOCK_OUT = 'time_clock_out'

export type TimeEntry = {
  id: number
  technician_id: number
  estimate_job_id: number | null
  task_id: number | null
  task_name: string | null
  flat_rate_minutes: number | null
  started_at: string
  ended_at: string | null
  duration_minutes: number | null
  status: 'approved' | 'pending' | 'rejected'
  reviewed_by: number | null
  reviewed_at: string | null
  review_notes: string | null
  payroll_included: boolean
  payroll_included_at: string | null
  en_route_at: string | null
  on_site_at: string | null
  wrap_up_at: string | null
  start_latitude: number | null
  start_longitude: number | null
  end_latitude: number | null
  end_longitude: number | null
  manual_override: boolean
  is_mobile: boolean | null
  notes: string | null
  created_at: string | null
  updated_at: string | null
}

export type AssignedJob = {
  id: number
  title: string
  estimate_number: string
  is_mobile: boolean
  customer_name: string
  vehicle_vin: string | null
  customer_status: string | null
}

export type TechnicianPortalData = {
  jobs: AssignedJob[]
  active_entry: TimeEntry | null
  history: TimeEntry[]
  totals: {
    today_minutes: number
    week_minutes: number
  }
}

export type ClockInData = {
  estimate_job_id?: number
  task_id?: number
  notes?: string
  location?: {
    lat: number | null
    lng: number | null
    accuracy?: number | null
    altitude?: number | null
    speed?: number | null
    heading?: number | null
    recorded_at?: string | null
    source?: string | null
    error?: string | null
  }
}

export type ClockOutData = {
  notes?: string
  location?: {
    lat: number | null
    lng: number | null
    accuracy?: number | null
    altitude?: number | null
    speed?: number | null
    heading?: number | null
    recorded_at?: string | null
    source?: string | null
    error?: string | null
  }
}

// Register offline sync handlers
registerSyncHandler(TIME_CLOCK_IN, async (payload) => {
  return api.post('/time-tracking/start', payload)
})

registerSyncHandler(TIME_CLOCK_OUT, async (payload) => {
  return api.post(`/time-tracking/${payload.entryId}/stop`, {
    notes: payload.notes,
    location: payload.location,
  })
})

const timeTrackingService = {
  /**
   * Fetch technician portal data including active entry, jobs, history, and totals
   */
  async getPortalData(): Promise<TechnicianPortalData> {
    const response = await api.get('/time-tracking/technician/portal')
    return response.data?.data || response.data
  },

  /**
   * Fetch current/recent time entries
   */
  async getTimeEntries(params: Record<string, unknown> = {}): Promise<{
    data: TimeEntry[]
    pagination: { total: number; limit: number; offset: number }
  }> {
    const response = await api.get('/time-tracking', { params })
    return response.data?.data || response.data
  },

  /**
   * Start time tracking (clock in)
   * Supports offline mode - will queue if offline
   */
  async clockIn(data?: ClockInData): Promise<TimeEntry | { queued: true }> {
    const online = await isOnline()

    const payload = {
      estimate_job_id: data?.estimate_job_id ?? null,
      task_id: data?.task_id ?? null,
      notes: data?.notes ?? null,
      location: data?.location ?? null,
    }

    if (!online) {
      await enqueueItem(TIME_CLOCK_IN, payload)
      return { queued: true }
    }

    const response = await api.post('/time-tracking/start', payload)
    return response.data?.data || response.data
  },

  /**
   * Stop time tracking (clock out)
   * Supports offline mode - will queue if offline
   */
  async clockOut(
    entryId: number,
    data?: ClockOutData
  ): Promise<TimeEntry | { queued: true }> {
    const online = await isOnline()

    const payload = {
      entryId,
      notes: data?.notes ?? null,
      location: data?.location ?? null,
    }

    if (!online) {
      await enqueueItem(TIME_CLOCK_OUT, payload)
      return { queued: true }
    }

    const response = await api.post(`/time-tracking/${entryId}/stop`, {
      notes: data?.notes,
      location: data?.location,
    })
    return response.data?.data || response.data
  },

  /**
   * Get assigned jobs for the technician
   */
  async getAssignedJobs(): Promise<AssignedJob[]> {
    const response = await api.get('/time-tracking/technician/jobs')
    return response.data?.data || response.data
  },
}

export default timeTrackingService
