import { useCallback, useEffect, useState } from 'react'
import { getPendingCount, onQueueChange, getQueueStats } from '../utils/offlineQueue'

export default function useOfflineStatus() {
  const [isOnline, setIsOnline] = useState(() => typeof navigator !== 'undefined' ? navigator.onLine : true)
  const [pendingCount, setPendingCount] = useState(0)
  const [queueStats, setQueueStats] = useState(null)
  const [lastSyncAttempt, setLastSyncAttempt] = useState(null)

  // Update online status
  useEffect(() => {
    if (typeof window === 'undefined') return

    const handleOnline = () => {
      setIsOnline(true)
      setLastSyncAttempt(new Date())
    }

    const handleOffline = () => {
      setIsOnline(false)
    }

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)

    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  // Update pending count on queue changes
  useEffect(() => {
    const loadCount = async () => {
      try {
        const count = await getPendingCount()
        setPendingCount(count)
      } catch (err) {
        console.error('Failed to get pending count:', err)
      }
    }

    loadCount()

    return onQueueChange(loadCount)
  }, [])

  // Refresh queue stats
  const refreshStats = useCallback(async () => {
    try {
      const stats = await getQueueStats()
      setQueueStats(stats)
      return stats
    } catch (err) {
      console.error('Failed to get queue stats:', err)
      return null
    }
  }, [])

  // Determine the overall status for display
  const status = isOnline
    ? pendingCount > 0
      ? 'syncing'
      : 'online'
    : 'offline'

  return {
    isOnline,
    isOffline: !isOnline,
    pendingCount,
    queueStats,
    refreshStats,
    lastSyncAttempt,
    status,
    // Helper booleans
    hasPendingItems: pendingCount > 0,
    canSubmit: true, // Even offline, we can queue items
  }
}
