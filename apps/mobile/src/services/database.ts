/**
 * SQLite Database Service for Offline Data Caching
 *
 * Provides persistent structured data storage using expo-sqlite v16+.
 * Handles schema migrations, CRUD operations, and sync status tracking.
 */

import * as SQLite from 'expo-sqlite'

// Database configuration
const DATABASE_NAME = 'phparm_offline.db'

// Cache TTL defaults (in milliseconds)
export const DEFAULT_CACHE_TTL = 24 * 60 * 60 * 1000 // 24 hours
export const SHORT_CACHE_TTL = 15 * 60 * 1000 // 15 minutes
export const LONG_CACHE_TTL = 7 * 24 * 60 * 60 * 1000 // 7 days

// Type definitions
export type EntityType =
  | 'work_orders'
  | 'time_entries'
  | 'inspections'
  | 'customers'
  | 'vehicles'

export type QueueItemStatus = 'pending' | 'processing' | 'failed'

export type CachedEntity<T = Record<string, unknown>> = {
  id: string
  data: T
  syncedAt: string | null
  updatedAt: string
  expiresAt: string | null
}

export type OfflineQueueItem = {
  id: string
  action: string
  endpoint: string
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  payload: Record<string, unknown>
  headers?: Record<string, string>
  createdAt: string
  status: QueueItemStatus
  attempts: number
  lastAttemptAt: string | null
  lastError: string | null
  priority: number
}

export type SyncMetadata = {
  entityType: EntityType
  lastSyncAt: string | null
  lastSyncSuccess: boolean
  syncCount: number
  errorCount: number
}

// Database instance
let database: SQLite.SQLiteDatabase | null = null
let initPromise: Promise<void> | null = null

/**
 * Opens or returns the existing database connection
 */
const getDatabase = (): SQLite.SQLiteDatabase => {
  if (!database) {
    database = SQLite.openDatabaseSync(DATABASE_NAME)
  }
  return database
}

/**
 * Schema migrations array - add new migrations here
 */
const migrationStatements: string[] = [
  // Work orders cache table
  `CREATE TABLE IF NOT EXISTS work_orders (
    id TEXT PRIMARY KEY NOT NULL,
    data TEXT NOT NULL,
    synced_at TEXT,
    updated_at TEXT NOT NULL,
    expires_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_work_orders_synced ON work_orders (synced_at)`,
  `CREATE INDEX IF NOT EXISTS idx_work_orders_expires ON work_orders (expires_at)`,

  // Time entries cache table
  `CREATE TABLE IF NOT EXISTS time_entries (
    id TEXT PRIMARY KEY NOT NULL,
    data TEXT NOT NULL,
    synced_at TEXT,
    updated_at TEXT NOT NULL,
    expires_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_time_entries_synced ON time_entries (synced_at)`,

  // Inspections cache table
  `CREATE TABLE IF NOT EXISTS inspections (
    id TEXT PRIMARY KEY NOT NULL,
    data TEXT NOT NULL,
    synced_at TEXT,
    updated_at TEXT NOT NULL,
    expires_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_inspections_synced ON inspections (synced_at)`,

  // Customers cache table
  `CREATE TABLE IF NOT EXISTS customers (
    id TEXT PRIMARY KEY NOT NULL,
    data TEXT NOT NULL,
    synced_at TEXT,
    updated_at TEXT NOT NULL,
    expires_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_customers_synced ON customers (synced_at)`,

  // Vehicles cache table
  `CREATE TABLE IF NOT EXISTS vehicles (
    id TEXT PRIMARY KEY NOT NULL,
    data TEXT NOT NULL,
    synced_at TEXT,
    updated_at TEXT NOT NULL,
    expires_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_vehicles_synced ON vehicles (synced_at)`,

  // Offline queue table
  `CREATE TABLE IF NOT EXISTS offline_queue (
    id TEXT PRIMARY KEY NOT NULL,
    action TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    method TEXT NOT NULL DEFAULT 'POST',
    payload TEXT NOT NULL,
    headers TEXT,
    created_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_attempt_at TEXT,
    last_error TEXT,
    priority INTEGER NOT NULL DEFAULT 0
  )`,
  `CREATE INDEX IF NOT EXISTS idx_offline_queue_status ON offline_queue (status)`,
  `CREATE INDEX IF NOT EXISTS idx_offline_queue_priority ON offline_queue (priority DESC, created_at ASC)`,

  // Sync metadata table
  `CREATE TABLE IF NOT EXISTS sync_metadata (
    entity_type TEXT PRIMARY KEY NOT NULL,
    last_sync_at TEXT,
    last_sync_success INTEGER NOT NULL DEFAULT 0,
    sync_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0
  )`,

  // Schema version table
  `CREATE TABLE IF NOT EXISTS schema_version (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    version INTEGER NOT NULL,
    updated_at TEXT NOT NULL
  )`,
]

/**
 * Runs pending database migrations
 */
const runMigrations = async (): Promise<void> => {
  const db = getDatabase()

  // Check current schema version
  let currentVersion = 0
  try {
    const row = db.getFirstSync<{ version: number }>(
      'SELECT version FROM schema_version WHERE id = 1'
    )
    if (row) {
      currentVersion = row.version
    }
  } catch {
    // Table doesn't exist yet
  }

  if (currentVersion < 1) {
    console.log('Running database migration v1')
    await db.withTransactionAsync(async () => {
      for (const sql of migrationStatements) {
        db.runSync(sql)
      }
    })
    db.runSync(
      'INSERT OR REPLACE INTO schema_version (id, version, updated_at) VALUES (1, 1, ?)',
      new Date().toISOString()
    )
  }
}

/**
 * Initializes the database and runs migrations
 */
export const initDatabase = async (): Promise<void> => {
  if (initPromise) {
    return initPromise
  }

  initPromise = (async () => {
    try {
      await runMigrations()
      console.log('Database initialized successfully')
    } catch (error) {
      console.error('Database initialization failed:', error)
      initPromise = null
      throw error
    }
  })()

  return initPromise
}

/**
 * Ensures database is initialized before operations
 */
const ensureInitialized = async (): Promise<void> => {
  if (!initPromise) {
    await initDatabase()
  }
  await initPromise
}

// ============================================================================
// Entity Cache Operations
// ============================================================================

const normalizeId = (id: unknown): string | null => {
  if (id === null || id === undefined) return null
  if (typeof id === 'string') return id
  if (typeof id === 'number') return String(id)
  return null
}

const calculateExpiry = (ttl?: number): string | null => {
  if (!ttl) return null
  return new Date(Date.now() + ttl).toISOString()
}

/**
 * Upserts a single entity into the cache
 */
export const upsertEntity = async <T extends { id: unknown }>(
  entityType: EntityType,
  entity: T,
  options: { ttl?: number; markSynced?: boolean } = {}
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  const id = normalizeId(entity.id)
  if (!id) {
    console.warn('Cannot cache entity without valid id')
    return
  }

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)
  const syncedAt = options.markSynced ? now : null

  db.runSync(
    `INSERT OR REPLACE INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
     VALUES (?, ?, COALESCE(?, (SELECT synced_at FROM ${entityType} WHERE id = ?)), ?, ?)`,
    id, JSON.stringify(entity), syncedAt, id, now, expiresAt
  )
}

/**
 * Upserts multiple entities into the cache
 */
export const upsertEntities = async <T extends { id: unknown }>(
  entityType: EntityType,
  entities: T[],
  options: { ttl?: number; markSynced?: boolean } = {}
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  if (entities.length === 0) return

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)
  const syncedAt = options.markSynced ? now : null

  await db.withTransactionAsync(async () => {
    for (const entity of entities) {
      const id = normalizeId(entity.id)
      if (!id) continue

      db.runSync(
        `INSERT OR REPLACE INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
         VALUES (?, ?, COALESCE(?, (SELECT synced_at FROM ${entityType} WHERE id = ?)), ?, ?)`,
        id, JSON.stringify(entity), syncedAt, id, now, expiresAt
      )
    }
  })
}

/**
 * Replaces all entities of a type (useful for full sync)
 */
export const replaceAllEntities = async <T extends { id: unknown }>(
  entityType: EntityType,
  entities: T[],
  options: { ttl?: number } = {}
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)

  await db.withTransactionAsync(async () => {
    db.runSync(`DELETE FROM ${entityType}`)
    for (const entity of entities) {
      const id = normalizeId(entity.id)
      if (!id) continue

      db.runSync(
        `INSERT INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
         VALUES (?, ?, ?, ?, ?)`,
        id, JSON.stringify(entity), now, now, expiresAt
      )
    }
  })
  await updateSyncMetadata(entityType, true)
}

/**
 * Gets a single entity by ID
 */
export const getEntity = async <T = Record<string, unknown>>(
  entityType: EntityType,
  id: string | number
): Promise<CachedEntity<T> | null> => {
  await ensureInitialized()
  const db = getDatabase()

  const normalizedId = normalizeId(id)
  if (!normalizedId) return null

  const row = db.getFirstSync<{
    id: string
    data: string
    synced_at: string | null
    updated_at: string
    expires_at: string | null
  }>(
    `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType} WHERE id = ?`,
    normalizedId
  )

  if (!row) return null

  try {
    return {
      id: row.id,
      data: JSON.parse(row.data) as T,
      syncedAt: row.synced_at,
      updatedAt: row.updated_at,
      expiresAt: row.expires_at,
    }
  } catch {
    return null
  }
}

/**
 * Gets all entities of a type (optionally filtering expired)
 */
export const getAllEntities = async <T = Record<string, unknown>>(
  entityType: EntityType,
  options: { includeExpired?: boolean } = {}
): Promise<CachedEntity<T>[]> => {
  await ensureInitialized()
  const db = getDatabase()

  const now = new Date().toISOString()

  type Row = {
    id: string
    data: string
    synced_at: string | null
    updated_at: string
    expires_at: string | null
  }

  const rows = options.includeExpired
    ? db.getAllSync<Row>(
        `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType} ORDER BY updated_at DESC`
      )
    : db.getAllSync<Row>(
        `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType}
         WHERE expires_at IS NULL OR expires_at > ?
         ORDER BY updated_at DESC`,
        now
      )

  const entities: CachedEntity<T>[] = []
  for (const row of rows) {
    try {
      entities.push({
        id: row.id,
        data: JSON.parse(row.data) as T,
        syncedAt: row.synced_at,
        updatedAt: row.updated_at,
        expiresAt: row.expires_at,
      })
    } catch {
      // Skip malformed entries
    }
  }

  return entities
}

/**
 * Gets entities that have not been synced
 */
export const getUnsyncedEntities = async <T = Record<string, unknown>>(
  entityType: EntityType
): Promise<CachedEntity<T>[]> => {
  await ensureInitialized()
  const db = getDatabase()

  type Row = {
    id: string
    data: string
    synced_at: string | null
    updated_at: string
    expires_at: string | null
  }

  const rows = db.getAllSync<Row>(
    `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType}
     WHERE synced_at IS NULL
     ORDER BY updated_at ASC`
  )

  const entities: CachedEntity<T>[] = []
  for (const row of rows) {
    try {
      entities.push({
        id: row.id,
        data: JSON.parse(row.data) as T,
        syncedAt: row.synced_at,
        updatedAt: row.updated_at,
        expiresAt: row.expires_at,
      })
    } catch {
      // Skip malformed entries
    }
  }

  return entities
}

/**
 * Marks an entity as synced
 */
export const markEntitySynced = async (
  entityType: EntityType,
  id: string | number
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  const normalizedId = normalizeId(id)
  if (!normalizedId) return

  db.runSync(
    `UPDATE ${entityType} SET synced_at = ? WHERE id = ?`,
    new Date().toISOString(), normalizedId
  )
}

/**
 * Deletes an entity from the cache
 */
export const deleteEntity = async (
  entityType: EntityType,
  id: string | number
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  const normalizedId = normalizeId(id)
  if (!normalizedId) return

  db.runSync(`DELETE FROM ${entityType} WHERE id = ?`, normalizedId)
}

/**
 * Clears all entities of a type
 */
export const clearEntities = async (entityType: EntityType): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()
  db.runSync(`DELETE FROM ${entityType}`)
}

/**
 * Clears expired entities from all tables
 */
export const clearExpiredEntities = async (): Promise<number> => {
  await ensureInitialized()
  const db = getDatabase()

  const now = new Date().toISOString()
  const entityTypes: EntityType[] = [
    'work_orders',
    'time_entries',
    'inspections',
    'customers',
    'vehicles',
  ]

  let totalDeleted = 0
  for (const entityType of entityTypes) {
    const result = db.runSync(
      `DELETE FROM ${entityType} WHERE expires_at IS NOT NULL AND expires_at < ?`,
      now
    )
    totalDeleted += result.changes
  }

  return totalDeleted
}

// ============================================================================
// Offline Queue Operations
// ============================================================================

const generateQueueId = (): string => {
  return `${Date.now()}-${Math.random().toString(36).substring(2, 11)}`
}

/**
 * Adds an item to the offline queue
 */
export const enqueueOfflineAction = async (
  action: string,
  endpoint: string,
  payload: Record<string, unknown>,
  options: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
    headers?: Record<string, string>
    priority?: number
  } = {}
): Promise<OfflineQueueItem> => {
  await ensureInitialized()
  const db = getDatabase()

  const item: OfflineQueueItem = {
    id: generateQueueId(),
    action,
    endpoint,
    method: options.method || 'POST',
    payload,
    headers: options.headers,
    createdAt: new Date().toISOString(),
    status: 'pending',
    attempts: 0,
    lastAttemptAt: null,
    lastError: null,
    priority: options.priority || 0,
  }

  db.runSync(
    `INSERT INTO offline_queue (id, action, endpoint, method, payload, headers, created_at, status, attempts, priority)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    item.id,
    item.action,
    item.endpoint,
    item.method,
    JSON.stringify(item.payload),
    item.headers ? JSON.stringify(item.headers) : null,
    item.createdAt,
    item.status,
    item.attempts,
    item.priority
  )

  return item
}

type QueueRow = {
  id: string
  action: string
  endpoint: string
  method: string
  payload: string
  headers: string | null
  created_at: string
  status: string
  attempts: number
  last_attempt_at: string | null
  last_error: string | null
  priority: number
}

const parseQueueRow = (row: QueueRow): OfflineQueueItem => ({
  id: row.id,
  action: row.action,
  endpoint: row.endpoint,
  method: row.method as OfflineQueueItem['method'],
  payload: JSON.parse(row.payload),
  headers: row.headers ? JSON.parse(row.headers) : undefined,
  createdAt: row.created_at,
  status: row.status as QueueItemStatus,
  attempts: row.attempts,
  lastAttemptAt: row.last_attempt_at,
  lastError: row.last_error,
  priority: row.priority,
})

/**
 * Gets all pending queue items ordered by priority
 */
export const getPendingQueueItems = async (): Promise<OfflineQueueItem[]> => {
  await ensureInitialized()
  const db = getDatabase()

  const rows = db.getAllSync<QueueRow>(
    `SELECT * FROM offline_queue
     WHERE status IN ('pending', 'failed')
     ORDER BY priority DESC, created_at ASC`
  )

  return rows.map(parseQueueRow)
}

/**
 * Gets queue items by status
 */
export const getQueueItemsByStatus = async (
  status: QueueItemStatus
): Promise<OfflineQueueItem[]> => {
  await ensureInitialized()
  const db = getDatabase()

  const rows = db.getAllSync<QueueRow>(
    `SELECT * FROM offline_queue WHERE status = ? ORDER BY priority DESC, created_at ASC`,
    status
  )

  return rows.map(parseQueueRow)
}

/**
 * Gets a single queue item by ID
 */
export const getQueueItem = async (
  id: string
): Promise<OfflineQueueItem | null> => {
  await ensureInitialized()
  const db = getDatabase()

  const row = db.getFirstSync<QueueRow>(
    `SELECT * FROM offline_queue WHERE id = ?`,
    id
  )

  return row ? parseQueueRow(row) : null
}

/**
 * Updates queue item status to processing
 */
export const markQueueItemProcessing = async (id: string): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  db.runSync(
    `UPDATE offline_queue SET status = 'processing', last_attempt_at = ? WHERE id = ?`,
    new Date().toISOString(), id
  )
}

/**
 * Marks a queue item as failed with error details
 */
export const markQueueItemFailed = async (
  id: string,
  error: string
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  db.runSync(
    `UPDATE offline_queue
     SET status = 'failed', attempts = attempts + 1, last_error = ?, last_attempt_at = ?
     WHERE id = ?`,
    error, new Date().toISOString(), id
  )
}

/**
 * Removes a queue item (on success)
 */
export const removeQueueItem = async (id: string): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()
  db.runSync(`DELETE FROM offline_queue WHERE id = ?`, id)
}

/**
 * Resets failed items to pending for retry
 */
export const resetFailedQueueItems = async (
  maxAttempts: number = 5
): Promise<number> => {
  await ensureInitialized()
  const db = getDatabase()

  const result = db.runSync(
    `UPDATE offline_queue SET status = 'pending' WHERE status = 'failed' AND attempts < ?`,
    maxAttempts
  )

  return result.changes
}

/**
 * Gets queue statistics
 */
export const getQueueStats = async (): Promise<{
  total: number
  pending: number
  processing: number
  failed: number
  byAction: Record<string, number>
}> => {
  await ensureInitialized()
  const db = getDatabase()

  const countRows = db.getAllSync<{ status: string; count: number }>(
    `SELECT status, COUNT(*) as count FROM offline_queue GROUP BY status`
  )

  const stats = {
    total: 0,
    pending: 0,
    processing: 0,
    failed: 0,
    byAction: {} as Record<string, number>,
  }

  for (const row of countRows) {
    stats.total += row.count
    if (row.status === 'pending') stats.pending = row.count
    if (row.status === 'processing') stats.processing = row.count
    if (row.status === 'failed') stats.failed = row.count
  }

  const actionRows = db.getAllSync<{ action: string; count: number }>(
    `SELECT action, COUNT(*) as count FROM offline_queue GROUP BY action`
  )

  for (const row of actionRows) {
    stats.byAction[row.action] = row.count
  }

  return stats
}

/**
 * Clears completed or old failed queue items
 */
export const cleanupQueue = async (
  maxAge: number = 7 * 24 * 60 * 60 * 1000 // 7 days
): Promise<number> => {
  await ensureInitialized()
  const db = getDatabase()

  const cutoff = new Date(Date.now() - maxAge).toISOString()
  const result = db.runSync(
    `DELETE FROM offline_queue WHERE status = 'failed' AND created_at < ?`,
    cutoff
  )

  return result.changes
}

// ============================================================================
// Sync Metadata Operations
// ============================================================================

/**
 * Updates sync metadata for an entity type
 */
export const updateSyncMetadata = async (
  entityType: EntityType,
  success: boolean
): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  const now = new Date().toISOString()
  db.runSync(
    `INSERT INTO sync_metadata (entity_type, last_sync_at, last_sync_success, sync_count, error_count)
     VALUES (?, ?, ?, 1, ?)
     ON CONFLICT(entity_type) DO UPDATE SET
       last_sync_at = ?,
       last_sync_success = ?,
       sync_count = sync_count + 1,
       error_count = error_count + ?`,
    entityType,
    now,
    success ? 1 : 0,
    success ? 0 : 1,
    now,
    success ? 1 : 0,
    success ? 0 : 1
  )
}

/**
 * Gets sync metadata for an entity type
 */
export const getSyncMetadata = async (
  entityType: EntityType
): Promise<SyncMetadata | null> => {
  await ensureInitialized()
  const db = getDatabase()

  const row = db.getFirstSync<{
    entity_type: string
    last_sync_at: string | null
    last_sync_success: number
    sync_count: number
    error_count: number
  }>(
    `SELECT * FROM sync_metadata WHERE entity_type = ?`,
    entityType
  )

  if (!row) return null

  return {
    entityType: row.entity_type as EntityType,
    lastSyncAt: row.last_sync_at,
    lastSyncSuccess: Boolean(row.last_sync_success),
    syncCount: row.sync_count,
    errorCount: row.error_count,
  }
}

/**
 * Gets all sync metadata
 */
export const getAllSyncMetadata = async (): Promise<SyncMetadata[]> => {
  await ensureInitialized()
  const db = getDatabase()

  const rows = db.getAllSync<{
    entity_type: string
    last_sync_at: string | null
    last_sync_success: number
    sync_count: number
    error_count: number
  }>(
    `SELECT * FROM sync_metadata ORDER BY entity_type`
  )

  return rows.map((row) => ({
    entityType: row.entity_type as EntityType,
    lastSyncAt: row.last_sync_at,
    lastSyncSuccess: Boolean(row.last_sync_success),
    syncCount: row.sync_count,
    errorCount: row.error_count,
  }))
}

/**
 * Checks if an entity type needs sync based on TTL
 */
export const needsSync = async (
  entityType: EntityType,
  maxAge: number = DEFAULT_CACHE_TTL
): Promise<boolean> => {
  const metadata = await getSyncMetadata(entityType)

  if (!metadata || !metadata.lastSyncAt) return true

  const lastSync = new Date(metadata.lastSyncAt).getTime()
  return Date.now() - lastSync > maxAge
}

// ============================================================================
// Database Utilities
// ============================================================================

/**
 * Gets database statistics
 */
export const getDatabaseStats = async (): Promise<{
  entityCounts: Record<EntityType, number>
  queueStats: { total: number; pending: number; failed: number }
  cacheSize: number
}> => {
  await ensureInitialized()
  const db = getDatabase()

  const entityTypes: EntityType[] = [
    'work_orders',
    'time_entries',
    'inspections',
    'customers',
    'vehicles',
  ]

  const entityCounts: Record<EntityType, number> = {} as Record<EntityType, number>

  for (const entityType of entityTypes) {
    const row = db.getFirstSync<{ count: number }>(
      `SELECT COUNT(*) as count FROM ${entityType}`
    )
    entityCounts[entityType] = row?.count ?? 0
  }

  const queueStats = await getQueueStats()

  // Approximate cache size by summing data lengths
  let cacheSize = 0
  for (const entityType of entityTypes) {
    const row = db.getFirstSync<{ size: number | null }>(
      `SELECT SUM(LENGTH(data)) as size FROM ${entityType}`
    )
    cacheSize += row?.size ?? 0
  }

  return {
    entityCounts,
    queueStats: {
      total: queueStats.total,
      pending: queueStats.pending,
      failed: queueStats.failed,
    },
    cacheSize,
  }
}

/**
 * Clears all cached data (preserves queue)
 */
export const clearAllCache = async (): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  await db.withTransactionAsync(async () => {
    db.runSync('DELETE FROM work_orders')
    db.runSync('DELETE FROM time_entries')
    db.runSync('DELETE FROM inspections')
    db.runSync('DELETE FROM customers')
    db.runSync('DELETE FROM vehicles')
    db.runSync('DELETE FROM sync_metadata')
  })
}

/**
 * Resets the entire database (use with caution)
 */
export const resetDatabase = async (): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()

  await db.withTransactionAsync(async () => {
    db.runSync('DELETE FROM work_orders')
    db.runSync('DELETE FROM time_entries')
    db.runSync('DELETE FROM inspections')
    db.runSync('DELETE FROM customers')
    db.runSync('DELETE FROM vehicles')
    db.runSync('DELETE FROM offline_queue')
    db.runSync('DELETE FROM sync_metadata')
    db.runSync('DELETE FROM schema_version')
  })

  // Reset init promise to force re-initialization
  initPromise = null
}

/**
 * Vacuum the database to reclaim space
 */
export const vacuumDatabase = async (): Promise<void> => {
  await ensureInitialized()
  const db = getDatabase()
  db.execSync('VACUUM')
}

// Export database for advanced usage
export { getDatabase }
