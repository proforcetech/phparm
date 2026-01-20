import AsyncStorage from '@react-native-async-storage/async-storage'

type QueueItem = {
  id: string
  type: string
  payload: Record<string, unknown>
  status: 'pending' | 'processing' | 'failed'
  attempts: number
  lastError: string | null
  createdAt: string
  updatedAt: string
}

type Draft = {
  id: string
  type: string
  data: Record<string, unknown>
  updatedAt: string
}

const QUEUE_KEY = 'offline_queue_items'
const DRAFT_KEY = 'offline_queue_drafts'

const MAX_RETRY_ATTEMPTS = 5
const BASE_RETRY_DELAY = 1000

const listeners = new Set<(eventType: string) => void>()

const notify = (eventType = 'change') => {
  listeners.forEach((listener) => listener(eventType))
}

const loadQueue = async (): Promise<QueueItem[]> => {
  const raw = await AsyncStorage.getItem(QUEUE_KEY)
  return raw ? (JSON.parse(raw) as QueueItem[]) : []
}

const saveQueue = async (items: QueueItem[]) => {
  await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(items))
}

const loadDrafts = async (): Promise<Record<string, Draft>> => {
  const raw = await AsyncStorage.getItem(DRAFT_KEY)
  return raw ? (JSON.parse(raw) as Record<string, Draft>) : {}
}

const saveDrafts = async (drafts: Record<string, Draft>) => {
  await AsyncStorage.setItem(DRAFT_KEY, JSON.stringify(drafts))
}

export const onQueueChange = (handler: (eventType: string) => void) => {
  listeners.add(handler)
  return () => listeners.delete(handler)
}

export const enqueueItem = async (type: string, payload: Record<string, unknown>) => {
  const now = new Date().toISOString()
  const item: QueueItem = {
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    type,
    payload,
    status: 'pending',
    attempts: 0,
    lastError: null,
    createdAt: now,
    updatedAt: now,
  }

  const items = await loadQueue()
  items.push(item)
  await saveQueue(items)
  notify()
  return item
}

export const listPendingItems = async () => {
  const items = await loadQueue()
  return items
    .filter((item) => item.status === 'pending' || item.status === 'failed')
    .sort((a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime())
}

export const listFailedItems = async () => {
  const items = await loadQueue()
  return items
    .filter((item) => item.status === 'failed')
    .sort((a, b) => new Date(a.updatedAt).getTime() - new Date(b.updatedAt).getTime())
}

export const getPendingCount = async () => {
  const items = await loadQueue()
  return items.filter((item) => item.status === 'pending' || item.status === 'failed').length
}

export const markItemProcessing = async (id: string) => {
  const items = await loadQueue()
  const next = items.map((item) =>
    item.id === id
      ? { ...item, status: 'processing', updatedAt: new Date().toISOString() }
      : item
  )
  await saveQueue(next)
  notify()
}

export const markItemFailed = async (id: string, errorMessage: string) => {
  const items = await loadQueue()
  const next = items.map((item) =>
    item.id === id
      ? {
          ...item,
          status: 'failed',
          attempts: (item.attempts || 0) + 1,
          lastError: errorMessage,
          updatedAt: new Date().toISOString(),
        }
      : item
  )
  await saveQueue(next)
  notify()
}

export const retryItem = async (id: string) => {
  const items = await loadQueue()
  const next = items.map((item) =>
    item.id === id
      ? {
          ...item,
          status: 'pending',
          attempts: 0,
          lastError: null,
          updatedAt: new Date().toISOString(),
        }
      : item
  )
  await saveQueue(next)
  notify()
}

export const removeItem = async (id: string) => {
  const items = await loadQueue()
  await saveQueue(items.filter((item) => item.id !== id))
  notify()
}

export const getRetryDelay = (attempts: number) => {
  const delay = BASE_RETRY_DELAY * Math.pow(2, Math.min(attempts, MAX_RETRY_ATTEMPTS))
  return delay + Math.floor(Math.random() * 500)
}

export const shouldRetry = (item: { attempts: number }) => item.attempts < MAX_RETRY_ATTEMPTS

export const getItemsByType = async (type: string) => {
  const items = await loadQueue()
  return items.filter((item) => item.type === type)
}

export const getQueueStats = async () => {
  const items = await loadQueue()
  const stats = {
    total: items.length,
    pending: items.filter((i) => i.status === 'pending').length,
    processing: items.filter((i) => i.status === 'processing').length,
    failed: items.filter((i) => i.status === 'failed').length,
    byType: {} as Record<string, number>,
  }

  items.forEach((item) => {
    stats.byType[item.type] = (stats.byType[item.type] || 0) + 1
  })

  return stats
}

export const clearFailedItems = async () => {
  const items = await loadQueue()
  await saveQueue(items.filter((item) => item.status !== 'failed'))
  notify()
}

export const resetFailedItems = async () => {
  const items = await loadQueue()
  const next = items.map((item) =>
    item.status === 'failed' && item.attempts < MAX_RETRY_ATTEMPTS
      ? { ...item, status: 'pending', updatedAt: new Date().toISOString() }
      : item
  )
  await saveQueue(next)
  notify()
}

export const saveDraft = async (id: string, type: string, data: Record<string, unknown>) => {
  const drafts = await loadDrafts()
  const draft: Draft = {
    id,
    type,
    data,
    updatedAt: new Date().toISOString(),
  }
  drafts[id] = draft
  await saveDrafts(drafts)
  notify('draft-saved')
  return draft
}

export const getDraft = async (id: string) => {
  const drafts = await loadDrafts()
  return drafts[id] ?? null
}

export const deleteDraft = async (id: string) => {
  const drafts = await loadDrafts()
  delete drafts[id]
  await saveDrafts(drafts)
  notify('draft-deleted')
}

export const listDraftsByType = async (type: string) => {
  const drafts = await loadDrafts()
  return Object.values(drafts).filter((draft) => draft.type === type)
}

export const clearAllDrafts = async () => {
  await saveDrafts({})
  notify('drafts-cleared')
}
