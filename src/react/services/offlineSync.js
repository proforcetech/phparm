import inspectionService from '../../services/inspection.service'
import workorderService from '../../services/workorder.service'
import timeTrackingService from '../../services/time-tracking.service'
import {
  listPendingItems,
  markItemProcessing,
  markItemFailed,
  removeItem,
  getRetryDelay,
  shouldRetry,
  resetFailedItems,
  getQueueStats,
} from '../utils/offlineQueue'

const PERIODIC_SYNC_INTERVAL = 30000 // 30 seconds when online
const OFFLINE_CHECK_INTERVAL = 5000 // Check every 5 seconds when offline

class OfflineSyncService {
  constructor() {
    this.isSyncing = false
    this.boundSync = this.sync.bind(this)
    this.periodicSyncTimer = null
    this.offlineCheckTimer = null
    this.syncListeners = new Set()
    this.lastSyncResult = null
  }

  // Subscribe to sync events
  subscribe(listener) {
    this.syncListeners.add(listener)
    return () => this.syncListeners.delete(listener)
  }

  notifyListeners(event) {
    this.syncListeners.forEach((listener) => {
      try {
        listener(event)
      } catch (err) {
        console.error('Sync listener error:', err)
      }
    })
  }

  start() {
    if (typeof window === 'undefined') return

    window.addEventListener('online', this.handleOnline.bind(this))
    window.addEventListener('offline', this.handleOffline.bind(this))

    // Initial sync if online
    if (navigator.onLine) {
      this.sync()
      this.startPeriodicSync()
    } else {
      this.startOfflineCheck()
    }
  }

  stop() {
    if (typeof window === 'undefined') return

    window.removeEventListener('online', this.handleOnline.bind(this))
    window.removeEventListener('offline', this.handleOffline.bind(this))

    this.stopPeriodicSync()
    this.stopOfflineCheck()
  }

  handleOnline() {
    this.notifyListeners({ type: 'online' })
    this.stopOfflineCheck()
    this.sync()
    this.startPeriodicSync()
  }

  handleOffline() {
    this.notifyListeners({ type: 'offline' })
    this.stopPeriodicSync()
    this.startOfflineCheck()
  }

  startPeriodicSync() {
    this.stopPeriodicSync()
    this.periodicSyncTimer = setInterval(() => {
      this.sync()
    }, PERIODIC_SYNC_INTERVAL)
  }

  stopPeriodicSync() {
    if (this.periodicSyncTimer) {
      clearInterval(this.periodicSyncTimer)
      this.periodicSyncTimer = null
    }
  }

  startOfflineCheck() {
    this.stopOfflineCheck()
    this.offlineCheckTimer = setInterval(() => {
      // Actively check connectivity by trying a HEAD request
      this.checkConnectivity()
    }, OFFLINE_CHECK_INTERVAL)
  }

  stopOfflineCheck() {
    if (this.offlineCheckTimer) {
      clearInterval(this.offlineCheckTimer)
      this.offlineCheckTimer = null
    }
  }

  async checkConnectivity() {
    if (!navigator.onLine) return

    try {
      // Try a lightweight request to verify actual connectivity
      const response = await fetch('/api/health', {
        method: 'HEAD',
        cache: 'no-store',
      })
      if (response.ok) {
        this.handleOnline()
      }
    } catch {
      // Still offline or server unreachable
    }
  }

  async sync() {
    if (this.isSyncing || typeof navigator === 'undefined' || !navigator.onLine) {
      return
    }

    this.isSyncing = true
    const startTime = Date.now()
    let processed = 0
    let failed = 0

    this.notifyListeners({ type: 'sync-start' })

    try {
      // Reset failed items that can be retried
      await resetFailedItems()

      const items = await listPendingItems()

      for (const item of items) {
        // Check if we should delay this item based on retry count
        if (item.attempts > 0 && shouldRetry(item)) {
          const delay = getRetryDelay(item.attempts)
          const timeSinceLastAttempt = Date.now() - new Date(item.updatedAt).getTime()
          if (timeSinceLastAttempt < delay) {
            continue // Skip this item for now, will retry later
          }
        }

        try {
          await markItemProcessing(item.id)
          await this.processItem(item)
          await removeItem(item.id)
          processed++
        } catch (error) {
          failed++
          await markItemFailed(item.id, error?.message || 'Sync failed')

          // If this item has exhausted retries, notify
          if (!shouldRetry({ ...item, attempts: (item.attempts || 0) + 1 })) {
            this.notifyListeners({
              type: 'item-failed-permanent',
              item,
              error: error?.message,
            })
          }
        }
      }

      const stats = await getQueueStats()

      this.lastSyncResult = {
        timestamp: new Date().toISOString(),
        duration: Date.now() - startTime,
        processed,
        failed,
        remaining: stats.total,
      }

      this.notifyListeners({
        type: 'sync-complete',
        result: this.lastSyncResult,
      })
    } catch (error) {
      this.notifyListeners({
        type: 'sync-error',
        error: error?.message,
      })
    } finally {
      this.isSyncing = false
    }
  }

  async processItem(item) {
    switch (item.type) {
      case 'inspection_media':
        return inspectionService.uploadMedia(
          item.payload.reportId,
          item.payload.file,
          {
            allowQueue: false,
            clientToken: item.payload.clientToken,
          }
        )

      case 'inspection_complete':
        return inspectionService.completeInspection(
          item.payload.reportId,
          item.payload.payload,
          { allowQueue: false }
        )

      case 'inspection_start':
        return inspectionService.startInspection(item.payload, { allowQueue: false })

      case 'workorder_status':
        return workorderService.updateStatus(
          item.payload.id,
          item.payload.status,
          item.payload.notes,
          {
            allowQueue: false,
            clientEventId: item.payload.clientEventId,
            location: item.payload.location ?? null,
          }
        )

      case 'workorder_job_status':
        return workorderService.updateJobStatus(
          item.payload.workorderId,
          item.payload.jobId,
          item.payload.status,
          {
            allowQueue: false,
            clientEventId: item.payload.clientEventId,
            location: item.payload.location ?? null,
          }
        )

      case 'time_clock_start':
        return timeTrackingService.start(item.payload, { allowQueue: false })

      case 'time_clock_stop':
        return timeTrackingService.stop(item.payload.id, item.payload, { allowQueue: false })

      default:
        throw new Error(`Unknown offline item type: ${item.type}`)
    }
  }

  // Manual sync trigger (e.g., from UI button)
  async manualSync() {
    if (!navigator.onLine) {
      return { success: false, reason: 'offline' }
    }
    await this.sync()
    return { success: true, result: this.lastSyncResult }
  }

  // Get current sync status
  getStatus() {
    return {
      isSyncing: this.isSyncing,
      isOnline: typeof navigator !== 'undefined' ? navigator.onLine : false,
      lastSyncResult: this.lastSyncResult,
    }
  }
}

export default new OfflineSyncService()
