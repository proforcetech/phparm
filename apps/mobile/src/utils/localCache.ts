import * as SQLite from 'expo-sqlite'

type CacheEntity = Record<string, any>

const DATABASE_NAME = 'phparm-offline-cache.db'
const TABLE_NAME = 'cached_entities'

let database: SQLite.SQLiteDatabase | null = null
let cacheReady: Promise<void> | null = null

const getDatabase = (): SQLite.SQLiteDatabase => {
  if (!database) {
    database = SQLite.openDatabaseSync(DATABASE_NAME)
  }
  return database
}

export const initOfflineCache = () => {
  if (!cacheReady) {
    cacheReady = (async () => {
      const db = getDatabase()
      await db.withTransactionAsync(async () => {
        db.runSync(
          `CREATE TABLE IF NOT EXISTS ${TABLE_NAME} (
            entity_type TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (entity_type, entity_id)
          )`
        )
        db.runSync(
          `CREATE INDEX IF NOT EXISTS idx_${TABLE_NAME}_type ON ${TABLE_NAME} (entity_type)`
        )
      })
    })()
  }

  return cacheReady
}

const ensureReady = async () => {
  await initOfflineCache()
}

const normalizeId = (value: unknown) => {
  if (value === null || value === undefined) {
    return null
  }
  if (typeof value === 'string' || typeof value === 'number') {
    return String(value)
  }
  return null
}

const extractEntities = (entities: CacheEntity[]) =>
  entities
    .map((entity) => {
      const entityId = normalizeId(entity.id)
      if (!entityId) {
        return null
      }
      return {
        entityId,
        payload: JSON.stringify(entity),
        updatedAt: new Date().toISOString(),
      }
    })
    .filter((value): value is { entityId: string; payload: string; updatedAt: string } =>
      Boolean(value)
    )

export const replaceCachedEntities = async (type: string, entities: CacheEntity[]) => {
  await ensureReady()
  const db = getDatabase()
  const normalized = extractEntities(entities)

  await db.withTransactionAsync(async () => {
    db.runSync(`DELETE FROM ${TABLE_NAME} WHERE entity_type = ?`, type)

    for (const { entityId, payload, updatedAt } of normalized) {
      db.runSync(
        `INSERT OR REPLACE INTO ${TABLE_NAME} (entity_type, entity_id, payload, updated_at) VALUES (?, ?, ?, ?)`,
        type, entityId, payload, updatedAt
      )
    }
  })
}

export const upsertCachedEntities = async (type: string, entities: CacheEntity[]) => {
  await ensureReady()
  const db = getDatabase()
  const normalized = extractEntities(entities)

  if (normalized.length === 0) {
    return
  }

  await db.withTransactionAsync(async () => {
    for (const { entityId, payload, updatedAt } of normalized) {
      db.runSync(
        `INSERT OR REPLACE INTO ${TABLE_NAME} (entity_type, entity_id, payload, updated_at) VALUES (?, ?, ?, ?)`,
        type, entityId, payload, updatedAt
      )
    }
  })
}

export const upsertCachedEntity = async (type: string, entity: CacheEntity) => {
  await upsertCachedEntities(type, [entity])
}

export const removeCachedEntity = async (type: string, id: string | number) => {
  await ensureReady()
  const entityId = normalizeId(id)
  if (!entityId) {
    return
  }
  const db = getDatabase()
  db.runSync(
    `DELETE FROM ${TABLE_NAME} WHERE entity_type = ? AND entity_id = ?`,
    type, entityId
  )
}

export const getCachedEntities = async (type: string) => {
  await ensureReady()
  const db = getDatabase()

  const rows = db.getAllSync<{ payload: string }>(
    `SELECT payload FROM ${TABLE_NAME} WHERE entity_type = ? ORDER BY updated_at DESC`,
    type
  )

  return rows
    .map((row) => {
      try {
        return JSON.parse(row.payload)
      } catch {
        return null
      }
    })
    .filter((row): row is CacheEntity => Boolean(row))
}

export const getCachedEntity = async (type: string, id: string | number) => {
  await ensureReady()
  const entityId = normalizeId(id)
  if (!entityId) {
    return null
  }

  const db = getDatabase()
  const row = db.getFirstSync<{ payload: string }>(
    `SELECT payload FROM ${TABLE_NAME} WHERE entity_type = ? AND entity_id = ? LIMIT 1`,
    type, entityId
  )

  if (!row?.payload) {
    return null
  }

  try {
    return JSON.parse(row.payload)
  } catch {
    return null
  }
}
