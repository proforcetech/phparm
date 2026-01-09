const DB_NAME = 'phparm-offline-queue'
const DB_VERSION = 2
const STORE_NAME = 'queue'
const DRAFT_STORE_NAME = 'drafts'

const MAX_RETRY_ATTEMPTS = 5
const BASE_RETRY_DELAY = 1000 // 1 second

const queueEvents = new EventTarget()

const notify = (eventType = 'change') => {
  queueEvents.dispatchEvent(new CustomEvent(eventType, { detail: { timestamp: Date.now() } }))
}

const openDb = () => new Promise((resolve, reject) => {
  const request = indexedDB.open(DB_NAME, DB_VERSION)

  request.onupgradeneeded = (event) => {
    const db = request.result
    if (!db.objectStoreNames.contains(STORE_NAME)) {
      const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true })
      store.createIndex('status', 'status', { unique: false })
      store.createIndex('createdAt', 'createdAt', { unique: false })
      store.createIndex('type', 'type', { unique: false })
    }
    // Add drafts store for inspection drafts in v2
    if (!db.objectStoreNames.contains(DRAFT_STORE_NAME)) {
      const draftStore = db.createObjectStore(DRAFT_STORE_NAME, { keyPath: 'id' })
      draftStore.createIndex('type', 'type', { unique: false })
      draftStore.createIndex('updatedAt', 'updatedAt', { unique: false })
    }
  }

  request.onsuccess = () => resolve(request.result)
  request.onerror = () => reject(request.error)
})

const withStore = async (mode, callback) => {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, mode)
    const store = transaction.objectStore(STORE_NAME)
    const result = callback(store)

    transaction.oncomplete = () => resolve(result)
    transaction.onerror = () => reject(transaction.error)
  })
}

export const onQueueChange = (handler) => {
  queueEvents.addEventListener('change', handler)
  return () => queueEvents.removeEventListener('change', handler)
}

export const enqueueItem = async (type, payload) => {
  const now = new Date().toISOString()
  const item = {
    type,
    payload,
    status: 'pending',
    attempts: 0,
    lastError: null,
    createdAt: now,
    updatedAt: now,
  }

  await withStore('readwrite', (store) => {
    const request = store.add(item)
    request.onsuccess = () => {
      item.id = request.result
    }
  })

  notify()
  return item
}

export const listPendingItems = async () => withStore('readonly', (store) => new Promise((resolve, reject) => {
  const request = store.getAll()
  request.onsuccess = () => {
    const items = request.result || []
    const filtered = items
      .filter((item) => item.status === 'pending' || item.status === 'failed')
      .sort((a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime())
    resolve(filtered)
  }
  request.onerror = () => reject(request.error)
}))

export const getPendingCount = async () => withStore('readonly', (store) => new Promise((resolve, reject) => {
  const request = store.getAll()
  request.onsuccess = () => {
    const items = request.result || []
    resolve(items.filter((item) => item.status === 'pending' || item.status === 'failed').length)
  }
  request.onerror = () => reject(request.error)
}))

export const markItemProcessing = async (id) => {
  await withStore('readwrite', (store) => {
    const request = store.get(id)
    request.onsuccess = () => {
      const item = request.result
      if (!item) return
      item.status = 'processing'
      item.updatedAt = new Date().toISOString()
      store.put(item)
    }
  })
  notify()
}

export const markItemFailed = async (id, errorMessage) => {
  await withStore('readwrite', (store) => {
    const request = store.get(id)
    request.onsuccess = () => {
      const item = request.result
      if (!item) return
      item.status = 'failed'
      item.attempts = (item.attempts || 0) + 1
      item.lastError = errorMessage
      item.updatedAt = new Date().toISOString()
      store.put(item)
    }
  })
  notify()
}

export const removeItem = async (id) => {
  await withStore('readwrite', (store) => {
    store.delete(id)
  })
  notify()
}

// Calculate exponential backoff delay for retries
export const getRetryDelay = (attempts) => {
  const delay = BASE_RETRY_DELAY * Math.pow(2, Math.min(attempts, MAX_RETRY_ATTEMPTS))
  // Add jitter (random 0-500ms) to prevent thundering herd
  return delay + Math.floor(Math.random() * 500)
}

// Check if item should be retried based on attempts
export const shouldRetry = (item) => {
  return item.attempts < MAX_RETRY_ATTEMPTS
}

// Get all items for a specific type
export const getItemsByType = async (type) => withStore('readonly', (store) => new Promise((resolve, reject) => {
  const index = store.index('type')
  const request = index.getAll(type)
  request.onsuccess = () => resolve(request.result || [])
  request.onerror = () => reject(request.error)
}))

// Get queue statistics
export const getQueueStats = async () => withStore('readonly', (store) => new Promise((resolve, reject) => {
  const request = store.getAll()
  request.onsuccess = () => {
    const items = request.result || []
    const stats = {
      total: items.length,
      pending: items.filter((i) => i.status === 'pending').length,
      processing: items.filter((i) => i.status === 'processing').length,
      failed: items.filter((i) => i.status === 'failed').length,
      byType: {},
    }
    items.forEach((item) => {
      stats.byType[item.type] = (stats.byType[item.type] || 0) + 1
    })
    resolve(stats)
  }
  request.onerror = () => reject(request.error)
}))

// Clear all failed items
export const clearFailedItems = async () => {
  await withStore('readwrite', (store) => {
    const request = store.getAll()
    request.onsuccess = () => {
      const items = request.result || []
      items.filter((i) => i.status === 'failed').forEach((item) => {
        store.delete(item.id)
      })
    }
  })
  notify()
}

// Reset failed items to pending for retry
export const resetFailedItems = async () => {
  await withStore('readwrite', (store) => {
    const request = store.getAll()
    request.onsuccess = () => {
      const items = request.result || []
      items
        .filter((i) => i.status === 'failed' && i.attempts < MAX_RETRY_ATTEMPTS)
        .forEach((item) => {
          item.status = 'pending'
          item.updatedAt = new Date().toISOString()
          store.put(item)
        })
    }
  })
  notify()
}

// Draft management for offline inspection data

const withDraftStore = async (mode, callback) => {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const transaction = db.transaction(DRAFT_STORE_NAME, mode)
    const store = transaction.objectStore(DRAFT_STORE_NAME)
    const result = callback(store)
    transaction.oncomplete = () => resolve(result)
    transaction.onerror = () => reject(transaction.error)
  })
}

export const saveDraft = async (id, type, data) => {
  const draft = {
    id,
    type,
    data,
    updatedAt: new Date().toISOString(),
  }
  await withDraftStore('readwrite', (store) => {
    store.put(draft)
  })
  notify('draft-saved')
  return draft
}

export const getDraft = async (id) => withDraftStore('readonly', (store) => new Promise((resolve, reject) => {
  const request = store.get(id)
  request.onsuccess = () => resolve(request.result || null)
  request.onerror = () => reject(request.error)
}))

export const deleteDraft = async (id) => {
  await withDraftStore('readwrite', (store) => {
    store.delete(id)
  })
  notify('draft-deleted')
}

export const listDraftsByType = async (type) => withDraftStore('readonly', (store) => new Promise((resolve, reject) => {
  const index = store.index('type')
  const request = index.getAll(type)
  request.onsuccess = () => resolve(request.result || [])
  request.onerror = () => reject(request.error)
}))

export const clearAllDrafts = async () => {
  await withDraftStore('readwrite', (store) => {
    store.clear()
  })
  notify('drafts-cleared')
}
