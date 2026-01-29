/**
 * SQLite Database Service for Offline Data Caching
 *
 * Provides persistent structured data storage using expo-sqlite.
 * Handles schema migrations, CRUD operations, and sync status tracking.
 */

import * as SQLite from 'expo-sqlite'

// Database configuration
const DATABASE_NAME = 'phparm_offline.db'
const SCHEMA_VERSION = 1

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

type Statement = {
  sql: string
  params?: (string | number | null)[]
}

// Database instance
let database: SQLite.WebSQLDatabase | null = null
let initPromise: Promise<void> | null = null

/**
 * Opens or returns the existing database connection
 */
const getDatabase = (): SQLite.WebSQLDatabase => {
  if (!database) {
    database = SQLite.openDatabase(DATABASE_NAME)
  }
  return database
}

/**
 * Executes a batch of SQL statements within a transaction
 */
const runBatch = (statements: Statement[]): Promise<void> =>
  new Promise((resolve, reject) => {
    const db = getDatabase()
    db.transaction(
      (tx) => {
        statements.forEach(({ sql, params = [] }) => {
          tx.executeSql(sql, params)
        })
      },
      (error) => {
        console.error('Database batch error:', error)
        reject(error)
      },
      () => resolve()
    )
  })

/**
 * Executes a single SQL query and returns results
 */
const runQuery = <T = SQLite.SQLResultSet>(
  sql: string,
  params: (string | number | null)[] = []
): Promise<T> =>
  new Promise((resolve, reject) => {
    const db = getDatabase()
    db.transaction(
      (tx) => {
        tx.executeSql(
          sql,
          params,
          (_, result) => resolve(result as T),
          (_, error) => {
            console.error('Database query error:', error)
            reject(error)
            return false
          }
        )
      },
      (error) => {
        console.error('Database transaction error:', error)
        reject(error)
      }
    )
  })

/**
 * Schema migrations array - add new migrations here
 */
const migrations: { version: number; statements: Statement[] }[] = [
  {
    version: 1,
    statements: [
      // Work orders cache table
      {
        sql: `CREATE TABLE IF NOT EXISTS work_orders (
          id TEXT PRIMARY KEY NOT NULL,
          data TEXT NOT NULL,
          synced_at TEXT,
          updated_at TEXT NOT NULL,
          expires_at TEXT
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_work_orders_synced ON work_orders (synced_at)`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_work_orders_expires ON work_orders (expires_at)`,
      },

      // Time entries cache table
      {
        sql: `CREATE TABLE IF NOT EXISTS time_entries (
          id TEXT PRIMARY KEY NOT NULL,
          data TEXT NOT NULL,
          synced_at TEXT,
          updated_at TEXT NOT NULL,
          expires_at TEXT
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_time_entries_synced ON time_entries (synced_at)`,
      },

      // Inspections cache table
      {
        sql: `CREATE TABLE IF NOT EXISTS inspections (
          id TEXT PRIMARY KEY NOT NULL,
          data TEXT NOT NULL,
          synced_at TEXT,
          updated_at TEXT NOT NULL,
          expires_at TEXT
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_inspections_synced ON inspections (synced_at)`,
      },

      // Customers cache table
      {
        sql: `CREATE TABLE IF NOT EXISTS customers (
          id TEXT PRIMARY KEY NOT NULL,
          data TEXT NOT NULL,
          synced_at TEXT,
          updated_at TEXT NOT NULL,
          expires_at TEXT
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_customers_synced ON customers (synced_at)`,
      },

      // Vehicles cache table
      {
        sql: `CREATE TABLE IF NOT EXISTS vehicles (
          id TEXT PRIMARY KEY NOT NULL,
          data TEXT NOT NULL,
          synced_at TEXT,
          updated_at TEXT NOT NULL,
          expires_at TEXT
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_vehicles_synced ON vehicles (synced_at)`,
      },

      // Offline queue table (replaces AsyncStorage-based queue)
      {
        sql: `CREATE TABLE IF NOT EXISTS offline_queue (
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
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_offline_queue_status ON offline_queue (status)`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_offline_queue_priority ON offline_queue (priority DESC, created_at ASC)`,
      },

      // Sync metadata table
      {
        sql: `CREATE TABLE IF NOT EXISTS sync_metadata (
          entity_type TEXT PRIMARY KEY NOT NULL,
          last_sync_at TEXT,
          last_sync_success INTEGER NOT NULL DEFAULT 0,
          sync_count INTEGER NOT NULL DEFAULT 0,
          error_count INTEGER NOT NULL DEFAULT 0
        )`,
      },

      // Schema version table
      {
        sql: `CREATE TABLE IF NOT EXISTS schema_version (
          id INTEGER PRIMARY KEY CHECK (id = 1),
          version INTEGER NOT NULL,
          updated_at TEXT NOT NULL
        )`,
      },
    ],
  },
]

/**
 * Gets the current schema version from the database
 */
const getCurrentSchemaVersion = async (): Promise<number> => {
  try {
    const result = await runQuery<SQLite.SQLResultSet>(
      'SELECT version FROM schema_version WHERE id = 1'
    )
    if (result.rows.length > 0) {
      return result.rows.item(0).version
    }
  } catch {
    // Table might not exist yet
  }
  return 0
}

/**
 * Sets the schema version in the database
 */
const setSchemaVersion = async (version: number): Promise<void> => {
  await runQuery(
    `INSERT OR REPLACE INTO schema_version (id, version, updated_at) VALUES (1, ?, ?)`,
    [version, new Date().toISOString()]
  )
}

/**
 * Runs pending database migrations
 */
const runMigrations = async (): Promise<void> => {
  const currentVersion = await getCurrentSchemaVersion()

  for (const migration of migrations) {
    if (migration.version > currentVersion) {
      console.log(`Running database migration v${migration.version}`)
      await runBatch(migration.statements)
      await setSchemaVersion(migration.version)
    }
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

/**
 * Normalizes an ID to a string
 */
const normalizeId = (id: unknown): string | null => {
  if (id === null || id === undefined) return null
  if (typeof id === 'string') return id
  if (typeof id === 'number') return String(id)
  return null
}

/**
 * Calculates expiration timestamp based on TTL
 */
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

  const id = normalizeId(entity.id)
  if (!id) {
    console.warn('Cannot cache entity without valid id')
    return
  }

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)
  const syncedAt = options.markSynced ? now : null

  await runQuery(
    `INSERT OR REPLACE INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
     VALUES (?, ?, COALESCE(?, (SELECT synced_at FROM ${entityType} WHERE id = ?)), ?, ?)`,
    [id, JSON.stringify(entity), syncedAt, id, now, expiresAt]
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

  if (entities.length === 0) return

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)
  const syncedAt = options.markSynced ? now : null

  const statements: Statement[] = entities
    .map((entity) => {
      const id = normalizeId(entity.id)
      if (!id) return null

      return {
        sql: `INSERT OR REPLACE INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
              VALUES (?, ?, COALESCE(?, (SELECT synced_at FROM ${entityType} WHERE id = ?)), ?, ?)`,
        params: [id, JSON.stringify(entity), syncedAt, id, now, expiresAt],
      }
    })
    .filter((s): s is Statement => s !== null)

  if (statements.length > 0) {
    await runBatch(statements)
  }
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

  const now = new Date().toISOString()
  const expiresAt = calculateExpiry(options.ttl)

  const statements: Statement[] = [
    { sql: `DELETE FROM ${entityType}` },
    ...entities
      .map((entity) => {
        const id = normalizeId(entity.id)
        if (!id) return null

        return {
          sql: `INSERT INTO ${entityType} (id, data, synced_at, updated_at, expires_at)
                VALUES (?, ?, ?, ?, ?)`,
          params: [id, JSON.stringify(entity), now, now, expiresAt],
        }
      })
      .filter((s): s is Statement => s !== null),
  ]

  await runBatch(statements)
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

  const normalizedId = normalizeId(id)
  if (!normalizedId) return null

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType} WHERE id = ?`,
    [normalizedId]
  )

  if (result.rows.length === 0) return null

  const row = result.rows.item(0)
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

  const now = new Date().toISOString()
  const sql = options.includeExpired
    ? `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType} ORDER BY updated_at DESC`
    : `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType}
       WHERE expires_at IS NULL OR expires_at > ?
       ORDER BY updated_at DESC`

  const result = await runQuery<SQLite.SQLResultSet>(
    sql,
    options.includeExpired ? [] : [now]
  )

  const entities: CachedEntity<T>[] = []
  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i)
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

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT id, data, synced_at, updated_at, expires_at FROM ${entityType}
     WHERE synced_at IS NULL
     ORDER BY updated_at ASC`
  )

  const entities: CachedEntity<T>[] = []
  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i)
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

  const normalizedId = normalizeId(id)
  if (!normalizedId) return

  await runQuery(
    `UPDATE ${entityType} SET synced_at = ? WHERE id = ?`,
    [new Date().toISOString(), normalizedId]
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

  const normalizedId = normalizeId(id)
  if (!normalizedId) return

  await runQuery(`DELETE FROM ${entityType} WHERE id = ?`, [normalizedId])
}

/**
 * Clears all entities of a type
 */
export const clearEntities = async (entityType: EntityType): Promise<void> => {
  await ensureInitialized()
  await runQuery(`DELETE FROM ${entityType}`)
}

/**
 * Clears expired entities from all tables
 */
export const clearExpiredEntities = async (): Promise<number> => {
  await ensureInitialized()

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
    const result = await runQuery<SQLite.SQLResultSet>(
      `DELETE FROM ${entityType} WHERE expires_at IS NOT NULL AND expires_at < ?`,
      [now]
    )
    totalDeleted += result.rowsAffected
  }

  return totalDeleted
}

// ============================================================================
// Offline Queue Operations
// ============================================================================

/**
 * Generates a unique ID for queue items
 */
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

  await runQuery(
    `INSERT INTO offline_queue (id, action, endpoint, method, payload, headers, created_at, status, attempts, priority)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      item.id,
      item.action,
      item.endpoint,
      item.method,
      JSON.stringify(item.payload),
      item.headers ? JSON.stringify(item.headers) : null,
      item.createdAt,
      item.status,
      item.attempts,
      item.priority,
    ]
  )

  return item
}

/**
 * Gets all pending queue items ordered by priority
 */
export const getPendingQueueItems = async (): Promise<OfflineQueueItem[]> => {
  await ensureInitialized()

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT * FROM offline_queue
     WHERE status IN ('pending', 'failed')
     ORDER BY priority DESC, created_at ASC`
  )

  return parseQueueItems(result)
}

/**
 * Gets queue items by status
 */
export const getQueueItemsByStatus = async (
  status: QueueItemStatus
): Promise<OfflineQueueItem[]> => {
  await ensureInitialized()

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT * FROM offline_queue WHERE status = ? ORDER BY priority DESC, created_at ASC`,
    [status]
  )

  return parseQueueItems(result)
}

/**
 * Gets a single queue item by ID
 */
export const getQueueItem = async (
  id: string
): Promise<OfflineQueueItem | null> => {
  await ensureInitialized()

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT * FROM offline_queue WHERE id = ?`,
    [id]
  )

  const items = parseQueueItems(result)
  return items[0] || null
}

/**
 * Parses queue items from SQL result
 */
const parseQueueItems = (result: SQLite.SQLResultSet): OfflineQueueItem[] => {
  const items: OfflineQueueItem[] = []
  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i)
    try {
      items.push({
        id: row.id,
        action: row.action,
        endpoint: row.endpoint,
        method: row.method,
        payload: JSON.parse(row.payload),
        headers: row.headers ? JSON.parse(row.headers) : undefined,
        createdAt: row.created_at,
        status: row.status,
        attempts: row.attempts,
        lastAttemptAt: row.last_attempt_at,
        lastError: row.last_error,
        priority: row.priority,
      })
    } catch {
      // Skip malformed entries
    }
  }
  return items
}

/**
 * Updates queue item status to processing
 */
export const markQueueItemProcessing = async (id: string): Promise<void> => {
  await ensureInitialized()

  await runQuery(
    `UPDATE offline_queue SET status = 'processing', last_attempt_at = ? WHERE id = ?`,
    [new Date().toISOString(), id]
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

  await runQuery(
    `UPDATE offline_queue
     SET status = 'failed', attempts = attempts + 1, last_error = ?, last_attempt_at = ?
     WHERE id = ?`,
    [error, new Date().toISOString(), id]
  )
}

/**
 * Removes a queue item (on success)
 */
export const removeQueueItem = async (id: string): Promise<void> => {
  await ensureInitialized()
  await runQuery(`DELETE FROM offline_queue WHERE id = ?`, [id])
}

/**
 * Resets failed items to pending for retry
 */
export const resetFailedQueueItems = async (
  maxAttempts: number = 5
): Promise<number> => {
  await ensureInitialized()

  const result = await runQuery<SQLite.SQLResultSet>(
    `UPDATE offline_queue SET status = 'pending' WHERE status = 'failed' AND attempts < ?`,
    [maxAttempts]
  )

  return result.rowsAffected
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

  const countResult = await runQuery<SQLite.SQLResultSet>(
    `SELECT status, COUNT(*) as count FROM offline_queue GROUP BY status`
  )

  const stats = {
    total: 0,
    pending: 0,
    processing: 0,
    failed: 0,
    byAction: {} as Record<string, number>,
  }

  for (let i = 0; i < countResult.rows.length; i++) {
    const row = countResult.rows.item(i)
    const count = row.count as number
    stats.total += count
    if (row.status === 'pending') stats.pending = count
    if (row.status === 'processing') stats.processing = count
    if (row.status === 'failed') stats.failed = count
  }

  const actionResult = await runQuery<SQLite.SQLResultSet>(
    `SELECT action, COUNT(*) as count FROM offline_queue GROUP BY action`
  )

  for (let i = 0; i < actionResult.rows.length; i++) {
    const row = actionResult.rows.item(i)
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

  const cutoff = new Date(Date.now() - maxAge).toISOString()
  const result = await runQuery<SQLite.SQLResultSet>(
    `DELETE FROM offline_queue WHERE status = 'failed' AND created_at < ?`,
    [cutoff]
  )

  return result.rowsAffected
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

  const now = new Date().toISOString()
  await runQuery(
    `INSERT INTO sync_metadata (entity_type, last_sync_at, last_sync_success, sync_count, error_count)
     VALUES (?, ?, ?, 1, ?)
     ON CONFLICT(entity_type) DO UPDATE SET
       last_sync_at = ?,
       last_sync_success = ?,
       sync_count = sync_count + 1,
       error_count = error_count + ?`,
    [
      entityType,
      now,
      success ? 1 : 0,
      success ? 0 : 1,
      now,
      success ? 1 : 0,
      success ? 0 : 1,
    ]
  )
}

/**
 * Gets sync metadata for an entity type
 */
export const getSyncMetadata = async (
  entityType: EntityType
): Promise<SyncMetadata | null> => {
  await ensureInitialized()

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT * FROM sync_metadata WHERE entity_type = ?`,
    [entityType]
  )

  if (result.rows.length === 0) return null

  const row = result.rows.item(0)
  return {
    entityType: row.entity_type,
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

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT * FROM sync_metadata ORDER BY entity_type`
  )

  const metadata: SyncMetadata[] = []
  for (let i = 0; i < result.rows.length; i++) {
    const row = result.rows.item(i)
    metadata.push({
      entityType: row.entity_type,
      lastSyncAt: row.last_sync_at,
      lastSyncSuccess: Boolean(row.last_sync_success),
      syncCount: row.sync_count,
      errorCount: row.error_count,
    })
  }

  return metadata
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

  const entityTypes: EntityType[] = [
    'work_orders',
    'time_entries',
    'inspections',
    'customers',
    'vehicles',
  ]

  const entityCounts: Record<EntityType, number> = {} as Record<EntityType, number>

  for (const entityType of entityTypes) {
    const result = await runQuery<SQLite.SQLResultSet>(
      `SELECT COUNT(*) as count FROM ${entityType}`
    )
    entityCounts[entityType] = result.rows.item(0).count
  }

  const queueStats = await getQueueStats()

  // Approximate cache size by summing data lengths
  let cacheSize = 0
  for (const entityType of entityTypes) {
    const result = await runQuery<SQLite.SQLResultSet>(
      `SELECT SUM(LENGTH(data)) as size FROM ${entityType}`
    )
    cacheSize += result.rows.item(0).size || 0
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

  await runBatch([
    { sql: 'DELETE FROM work_orders' },
    { sql: 'DELETE FROM time_entries' },
    { sql: 'DELETE FROM inspections' },
    { sql: 'DELETE FROM customers' },
    { sql: 'DELETE FROM vehicles' },
    { sql: 'DELETE FROM sync_metadata' },
  ])
}

/**
 * Resets the entire database (use with caution)
 */
export const resetDatabase = async (): Promise<void> => {
  await ensureInitialized()

  await runBatch([
    { sql: 'DELETE FROM work_orders' },
    { sql: 'DELETE FROM time_entries' },
    { sql: 'DELETE FROM inspections' },
    { sql: 'DELETE FROM customers' },
    { sql: 'DELETE FROM vehicles' },
    { sql: 'DELETE FROM offline_queue' },
    { sql: 'DELETE FROM sync_metadata' },
    { sql: 'DELETE FROM schema_version' },
  ])

  // Reset init promise to force re-initialization
  initPromise = null
}

/**
 * Vacuum the database to reclaim space
 */
export const vacuumDatabase = async (): Promise<void> => {
  await ensureInitialized()
  await runQuery('VACUUM')
}

// Export database for advanced usage
export { getDatabase }
