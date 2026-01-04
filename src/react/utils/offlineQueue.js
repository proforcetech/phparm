const DB_NAME = 'phparm-offline-queue'
const DB_VERSION = 1
const STORE_NAME = 'queue'

const queueEvents = new EventTarget()

const notify = () => {
  queueEvents.dispatchEvent(new Event('change'))
}

const openDb = () => new Promise((resolve, reject) => {
  const request = indexedDB.open(DB_NAME, DB_VERSION)

  request.onupgradeneeded = () => {
    const db = request.result
    if (!db.objectStoreNames.contains(STORE_NAME)) {
      const store = db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true })
      store.createIndex('status', 'status', { unique: false })
      store.createIndex('createdAt', 'createdAt', { unique: false })
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
