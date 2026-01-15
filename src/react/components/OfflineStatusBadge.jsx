import { useEffect, useState } from 'react'

import Badge from './ui/Badge'
import {
  getPendingCount,
  listFailedItems,
  onQueueChange,
  getQueueStats,
  retryItem,
  removeItem,
} from '../utils/offlineQueue'
import offlineSync from '../services/offlineSync'

const getInitialOnline = () => (typeof navigator === 'undefined' ? true : navigator.onLine)

export default function OfflineStatusBadge({ showDetails = false, onManualSync }) {
  const [pendingCount, setPendingCount] = useState(0)
  const [online, setOnline] = useState(getInitialOnline())
  const [isSyncing, setIsSyncing] = useState(false)
  const [stats, setStats] = useState(null)
  const [failedItems, setFailedItems] = useState([])

  useEffect(() => {
    let isMounted = true

    const refreshCount = async () => {
      const count = await getPendingCount()
      if (isMounted) {
        setPendingCount(count)
      }
      if (showDetails) {
        const queueStats = await getQueueStats()
        if (isMounted) {
          setStats(queueStats)
        }
        const failed = await listFailedItems()
        if (isMounted) {
          setFailedItems(failed)
        }
      }
    }

    const handleOnlineStatus = () => setOnline(getInitialOnline())

    refreshCount()
    const unsubscribe = onQueueChange(refreshCount)

    // Subscribe to sync events
    const unsubscribeSync = offlineSync.subscribe((event) => {
      if (event.type === 'sync-start') {
        setIsSyncing(true)
      } else if (event.type === 'sync-complete' || event.type === 'sync-error') {
        setIsSyncing(false)
        refreshCount()
      }
    })

    window.addEventListener('online', handleOnlineStatus)
    window.addEventListener('offline', handleOnlineStatus)

    return () => {
      isMounted = false
      unsubscribe()
      unsubscribeSync()
      window.removeEventListener('online', handleOnlineStatus)
      window.removeEventListener('offline', handleOnlineStatus)
    }
  }, [showDetails])

  const handleManualSync = async () => {
    if (onManualSync) {
      onManualSync()
    } else {
      await offlineSync.manualSync()
    }
  }

  const handleRetryItem = async (itemId) => {
    await retryItem(itemId)
    if (typeof navigator !== 'undefined' && navigator.onLine) {
      await offlineSync.manualSync()
    }
  }

  const handleDiscardItem = async (itemId) => {
    await removeItem(itemId)
  }

  if (online && pendingCount === 0 && !showDetails) {
    return null
  }

  const getLabel = () => {
    if (isSyncing) {
      return 'Syncing...'
    }
    if (online) {
      if (pendingCount === 0) {
        return 'Online'
      }
      return `Syncing: ${pendingCount} pending`
    }
    return pendingCount ? `Offline (${pendingCount} queued)` : 'Offline'
  }

  const getVariant = () => {
    if (!online) return 'danger'
    if (isSyncing || pendingCount > 0) return 'warning'
    return 'success'
  }

  if (showDetails) {
    return (
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-2 flex-wrap">
          <Badge variant={getVariant()} size="sm" rounded>
            <span className="flex items-center gap-1">
              {!online && (
                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3m8.293 8.293l1.414 1.414" />
                </svg>
              )}
              {isSyncing && (
                <svg className="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                </svg>
              )}
              {getLabel()}
            </span>
          </Badge>
          {online && pendingCount > 0 && !isSyncing && (
            <button
              onClick={handleManualSync}
              className="text-xs text-indigo-600 hover:text-indigo-800 underline"
            >
              Sync now
            </button>
          )}
          {stats && stats.failed > 0 && (
            <Badge variant="danger" size="sm">
              {stats.failed} failed
            </Badge>
          )}
        </div>
        {failedItems.length > 0 && (
          <div className="rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-800">
            <div className="font-semibold mb-2">Conflict resolution needed</div>
            <ul className="space-y-2">
              {failedItems.map((item) => (
                <li key={item.id} className="flex flex-col gap-1">
                  <div className="flex items-center justify-between gap-2">
                    <div>
                      <span className="font-medium">{item.type.replace(/_/g, ' ')}</span>
                      <span className="ml-2 text-red-700">
                        {item.lastError || 'Sync failed'}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => handleRetryItem(item.id)}
                        className="text-xs text-indigo-600 hover:text-indigo-800 underline"
                      >
                        Retry
                      </button>
                      <button
                        onClick={() => handleDiscardItem(item.id)}
                        className="text-xs text-red-600 hover:text-red-800 underline"
                      >
                        Discard
                      </button>
                    </div>
                  </div>
                  {item.updatedAt && (
                    <div className="text-[11px] text-red-700">
                      Last attempted {new Date(item.updatedAt).toLocaleString()}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    )
  }

  return (
    <Badge variant={getVariant()} size="sm" rounded>
      {getLabel()}
    </Badge>
  )
}
