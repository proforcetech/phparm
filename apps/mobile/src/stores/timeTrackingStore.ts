import AsyncStorage from '@react-native-async-storage/async-storage'
import { create } from 'zustand'

import timeTrackingService, {
  AssignedJob,
  ClockInData,
  ClockOutData,
  TimeEntry,
} from '../services/timeTracking.service'

const CACHE_KEY_PORTAL = 'time_tracking_portal_cache'
const CACHE_KEY_CURRENT_ENTRY = 'time_tracking_current_entry_cache'

type TimeTrackingState = {
  currentEntry: TimeEntry | null
  entries: TimeEntry[]
  assignedJobs: AssignedJob[]
  todayMinutes: number
  weekMinutes: number
  loading: boolean
  clockingIn: boolean
  clockingOut: boolean
  error: string | null
  lastRefreshed: string | null
  isClockingIn: () => boolean
  isClockingOut: () => boolean
  isClockedIn: () => boolean
  loadPortalData: () => Promise<void>
  loadEntries: () => Promise<void>
  loadAssignedJobs: () => Promise<void>
  clockIn: (data?: ClockInData) => Promise<boolean>
  clockOut: (notes?: string, location?: ClockOutData['location']) => Promise<boolean>
  clearError: () => void
  reset: () => void
}

export const useTimeTrackingStore = create<TimeTrackingState>((set, get) => ({
  currentEntry: null,
  entries: [],
  assignedJobs: [],
  todayMinutes: 0,
  weekMinutes: 0,
  loading: false,
  clockingIn: false,
  clockingOut: false,
  error: null,
  lastRefreshed: null,

  isClockingIn: () => get().clockingIn,
  isClockingOut: () => get().clockingOut,
  isClockedIn: () => get().currentEntry !== null && get().currentEntry?.ended_at === null,

  loadPortalData: async () => {
    try {
      set({ loading: true, error: null })

      // Try to load cached data first for faster initial render
      const cached = await AsyncStorage.getItem(CACHE_KEY_PORTAL)
      if (cached) {
        try {
          const cachedData = JSON.parse(cached)
          set({
            currentEntry: cachedData.active_entry || null,
            entries: cachedData.history || [],
            assignedJobs: cachedData.jobs || [],
            todayMinutes: cachedData.totals?.today_minutes || 0,
            weekMinutes: cachedData.totals?.week_minutes || 0,
          })
        } catch {
          // Ignore cache parse errors
        }
      }

      // Fetch fresh data from API
      const data = await timeTrackingService.getPortalData()

      set({
        currentEntry: data.active_entry || null,
        entries: data.history || [],
        assignedJobs: data.jobs || [],
        todayMinutes: data.totals?.today_minutes || 0,
        weekMinutes: data.totals?.week_minutes || 0,
        lastRefreshed: new Date().toISOString(),
      })

      // Cache the data
      await AsyncStorage.setItem(CACHE_KEY_PORTAL, JSON.stringify(data))
      if (data.active_entry) {
        await AsyncStorage.setItem(CACHE_KEY_CURRENT_ENTRY, JSON.stringify(data.active_entry))
      } else {
        await AsyncStorage.removeItem(CACHE_KEY_CURRENT_ENTRY)
      }
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Failed to load time tracking data'
      set({ error: errorMessage })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  loadEntries: async () => {
    try {
      set({ loading: true, error: null })

      const result = await timeTrackingService.getTimeEntries({ per_page: 50 })
      set({ entries: result.data || [] })
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Failed to load time entries'
      set({ error: errorMessage })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  loadAssignedJobs: async () => {
    try {
      set({ loading: true, error: null })

      const jobs = await timeTrackingService.getAssignedJobs()
      set({ assignedJobs: jobs || [] })
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Failed to load assigned jobs'
      set({ error: errorMessage })
      throw err
    } finally {
      set({ loading: false })
    }
  },

  clockIn: async (data?: ClockInData) => {
    try {
      set({ clockingIn: true, error: null })

      const result = await timeTrackingService.clockIn(data)

      if ('queued' in result && result.queued) {
        // Offline - create optimistic local entry
        const optimisticEntry: TimeEntry = {
          id: -Date.now(), // Negative ID to indicate pending
          technician_id: 0,
          estimate_job_id: data?.estimate_job_id || null,
          task_id: data?.task_id || null,
          task_name: null,
          flat_rate_minutes: null,
          started_at: new Date().toISOString(),
          ended_at: null,
          duration_minutes: null,
          status: 'pending',
          reviewed_by: null,
          reviewed_at: null,
          review_notes: null,
          payroll_included: false,
          payroll_included_at: null,
          en_route_at: null,
          on_site_at: null,
          wrap_up_at: null,
          start_latitude: data?.location?.lat || null,
          start_longitude: data?.location?.lng || null,
          end_latitude: null,
          end_longitude: null,
          manual_override: false,
          is_mobile: null,
          notes: data?.notes || null,
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
        }
        set({ currentEntry: optimisticEntry })
        await AsyncStorage.setItem(CACHE_KEY_CURRENT_ENTRY, JSON.stringify(optimisticEntry))
        return true
      }

      // Online - use real entry from API
      set({
        currentEntry: result as TimeEntry,
        entries: [result as TimeEntry, ...get().entries],
      })
      await AsyncStorage.setItem(CACHE_KEY_CURRENT_ENTRY, JSON.stringify(result))

      return true
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Failed to clock in'
      set({ error: errorMessage })
      return false
    } finally {
      set({ clockingIn: false })
    }
  },

  clockOut: async (notes?: string, location?: ClockOutData['location']) => {
    const currentEntry = get().currentEntry
    if (!currentEntry) {
      set({ error: 'No active time entry to clock out' })
      return false
    }

    try {
      set({ clockingOut: true, error: null })

      const result = await timeTrackingService.clockOut(currentEntry.id, { notes, location })

      if ('queued' in result && result.queued) {
        // Offline - update local entry optimistically
        const updatedEntry: TimeEntry = {
          ...currentEntry,
          ended_at: new Date().toISOString(),
          end_latitude: location?.lat || null,
          end_longitude: location?.lng || null,
          notes: notes || currentEntry.notes,
        }
        set({
          currentEntry: null,
          entries: get().entries.map((e) =>
            e.id === currentEntry.id ? updatedEntry : e
          ),
        })
        await AsyncStorage.removeItem(CACHE_KEY_CURRENT_ENTRY)
        return true
      }

      // Online - use real entry from API
      const updatedEntry = result as TimeEntry
      set({
        currentEntry: null,
        entries: get().entries.map((e) =>
          e.id === currentEntry.id ? updatedEntry : e
        ),
        todayMinutes: get().todayMinutes + (updatedEntry.duration_minutes || 0),
      })
      await AsyncStorage.removeItem(CACHE_KEY_CURRENT_ENTRY)

      return true
    } catch (err: any) {
      const errorMessage = err.response?.data?.message || err.message || 'Failed to clock out'
      set({ error: errorMessage })
      return false
    } finally {
      set({ clockingOut: false })
    }
  },

  clearError: () => {
    set({ error: null })
  },

  reset: () => {
    set({
      currentEntry: null,
      entries: [],
      assignedJobs: [],
      todayMinutes: 0,
      weekMinutes: 0,
      loading: false,
      clockingIn: false,
      clockingOut: false,
      error: null,
      lastRefreshed: null,
    })
    AsyncStorage.multiRemove([CACHE_KEY_PORTAL, CACHE_KEY_CURRENT_ENTRY])
  },
}))
