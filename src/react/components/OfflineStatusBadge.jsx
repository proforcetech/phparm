import { useEffect, useState } from 'react'

import Badge from './ui/Badge'
import { getPendingCount, onQueueChange } from '../utils/offlineQueue'

const getInitialOnline = () => (typeof navigator === 'undefined' ? true : navigator.onLine)

export default function OfflineStatusBadge() {
  const [pendingCount, setPendingCount] = useState(0)
  const [online, setOnline] = useState(getInitialOnline())

  useEffect(() => {
    let isMounted = true

    const refreshCount = async () => {
      const count = await getPendingCount()
      if (isMounted) {
        setPendingCount(count)
      }
    }

    const handleOnlineStatus = () => setOnline(getInitialOnline())

    refreshCount()
    const unsubscribe = onQueueChange(refreshCount)

    window.addEventListener('online', handleOnlineStatus)
    window.addEventListener('offline', handleOnlineStatus)

    return () => {
      isMounted = false
      unsubscribe()
      window.removeEventListener('online', handleOnlineStatus)
      window.removeEventListener('offline', handleOnlineStatus)
    }
  }, [])

  if (online && pendingCount === 0) {
    return null
  }

  const label = online
    ? `Pending uploads: ${pendingCount}`
    : pendingCount
      ? `Offline (${pendingCount} queued)`
      : 'Offline'

  return (
    <Badge variant={online ? 'warning' : 'danger'} size="sm" rounded>
      {label}
    </Badge>
  )
}
