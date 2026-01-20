import NetInfo from '@react-native-community/netinfo'

import { getEnv } from '../config/env'
import {
  getQueueStats,
  getRetryDelay,
  listPendingItems,
  markItemFailed,
  markItemProcessing,
  removeItem,
  resetFailedItems,
  shouldRetry,
} from '../utils/offlineQueue'

const PERIODIC_SYNC_INTERVAL = 30000
const OFFLINE_CHECK_INTERVAL = 5000

type SyncEvent = {
  type:
    | 'online'
    | 'offline'
    | 'sync-start'
    | 'sync-complete'
    | 'sync-error'
    | 'item-failed-permanent'
  result?: {
    timestamp: string
    duration: number
    processed: number
    failed: number
    remaining: number
  }
  error?: string
  item?: any
}

type SyncHandler = (payload: Record<string, any>) => Promise<unknown>

const handlers = new Map<string, SyncHandler>()

export const registerSyncHandler = (type: string, handler: SyncHandler) => {
  handlers.set(type, handler)
  return () => handlers.delete(type)
}

class OfflineSyncService {
  private isSyncing = false
  private periodicSyncTimer: ReturnType<typeof setInterval> | null = null
  private offlineCheckTimer: ReturnType<typeof setInterval> | null = null
  private syncListeners = new Set<(event: SyncEvent) => void>()
  private lastSyncResult: SyncEvent['result'] = null
  private unsubscribeNetInfo: (() => void) | null = null

  subscribe(listener: (event: SyncEvent) => void) {
    this.syncListeners.add(listener)
    return () => this.syncListeners.delete(listener)
  }

  notifyListeners(event: SyncEvent) {
    this.syncListeners.forEach((listener) => {
      try {
        listener(event)
      } catch (err) {
        console.error('Sync listener error:', err)
      }
    })
  }

  start() {
    this.unsubscribeNetInfo?.()
    this.unsubscribeNetInfo = NetInfo.addEventListener((state) => {
      const isOnline = Boolean(state.isConnected && state.isInternetReachable !== false)
      if (isOnline) {
        this.handleOnline()
      } else {
        this.handleOffline()
      }
    })

    this.checkInitialState()
  }

  stop() {
    this.unsubscribeNetInfo?.()
    this.unsubscribeNetInfo = null
    this.stopPeriodicSync()
    this.stopOfflineCheck()
  }

  private async checkInitialState() {
    const state = await NetInfo.fetch()
    if (state.isConnected && state.isInternetReachable !== false) {
      this.sync()
      this.startPeriodicSync()
    } else {
      this.startOfflineCheck()
    }
  }

  private handleOnline() {
    this.notifyListeners({ type: 'online' })
    this.stopOfflineCheck()
    this.sync()
    this.startPeriodicSync()
  }

  private handleOffline() {
    this.notifyListeners({ type: 'offline' })
    this.stopPeriodicSync()
    this.startOfflineCheck()
  }

  private startPeriodicSync() {
    this.stopPeriodicSync()
    this.periodicSyncTimer = setInterval(() => {
      this.sync()
    }, PERIODIC_SYNC_INTERVAL)
  }

  private stopPeriodicSync() {
    if (this.periodicSyncTimer) {
      clearInterval(this.periodicSyncTimer)
      this.periodicSyncTimer = null
    }
  }

  private startOfflineCheck() {
    this.stopOfflineCheck()
    this.offlineCheckTimer = setInterval(() => {
      this.checkConnectivity()
    }, OFFLINE_CHECK_INTERVAL)
  }

  private stopOfflineCheck() {
    if (this.offlineCheckTimer) {
      clearInterval(this.offlineCheckTimer)
      this.offlineCheckTimer = null
    }
  }

  private async checkConnectivity() {
    const { apiBaseUrl } = getEnv()
    try {
      const response = await fetch(`${apiBaseUrl}/health`, {
        method: 'HEAD',
        cache: 'no-store',
      })
      if (response.ok) {
        this.handleOnline()
      }
    } catch {
      // still offline
    }
  }

  async sync() {
    if (this.isSyncing) {
      return
    }

    const netState = await NetInfo.fetch()
    if (!netState.isConnected || netState.isInternetReachable === false) {
      return
    }

    this.isSyncing = true
    const startTime = Date.now()
    let processed = 0
    let failed = 0

    this.notifyListeners({ type: 'sync-start' })

    try {
      await resetFailedItems()

      const items = await listPendingItems()

      for (const item of items) {
        if (item.attempts > 0 && shouldRetry(item)) {
          const delay = getRetryDelay(item.attempts)
          const timeSinceLastAttempt = Date.now() - new Date(item.updatedAt).getTime()
          if (timeSinceLastAttempt < delay) {
            continue
          }
        }

        try {
          await markItemProcessing(item.id)
          await this.processItem(item)
          await removeItem(item.id)
          processed++
        } catch (error: any) {
          failed++
          await markItemFailed(item.id, error?.message || 'Sync failed')

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
    } catch (error: any) {
      this.notifyListeners({
        type: 'sync-error',
        error: error?.message,
      })
    } finally {
      this.isSyncing = false
    }
  }

  private async processItem(item: { type: string; payload: Record<string, any> }) {
    const handler = handlers.get(item.type)
    if (!handler) {
      throw new Error(`Unknown offline item type: ${item.type}`)
    }
    return handler(item.payload)
  }

  async manualSync() {
    const state = await NetInfo.fetch()
    if (!state.isConnected || state.isInternetReachable === false) {
      return { success: false, reason: 'offline' }
    }
    await this.sync()
    return { success: true, result: this.lastSyncResult }
  }

  getStatus() {
    return {
      isSyncing: this.isSyncing,
      lastSyncResult: this.lastSyncResult,
    }
  }
}

export default new OfflineSyncService()
