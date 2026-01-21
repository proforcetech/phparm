import * as SQLite from 'expo-sqlite'

type CacheEntity = Record<string, any>

type Statement = {
  sql: string
  params?: (string | number | null)[]
}

const DATABASE_NAME = 'phparm-offline-cache.db'
const TABLE_NAME = 'cached_entities'

const database = SQLite.openDatabase(DATABASE_NAME)
let cacheReady: Promise<void> | null = null

const runBatch = (statements: Statement[]) =>
  new Promise<void>((resolve, reject) => {
    database.transaction(
      (transaction) => {
        statements.forEach(({ sql, params = [] }) => {
          transaction.executeSql(sql, params)
        })
      },
      (error) => {
        reject(error)
      },
      () => resolve()
    )
  })

const runQuery = <T = SQLite.SQLResultSet>(sql: string, params: (string | number)[] = []) =>
  new Promise<T>((resolve, reject) => {
    database.transaction(
      (transaction) => {
        transaction.executeSql(
          sql,
          params,
          (_, result) => resolve(result as T),
          (_, error) => {
            reject(error)
            return false
          }
        )
      },
      (error) => reject(error)
    )
  })

export const initOfflineCache = () => {
  if (!cacheReady) {
    cacheReady = runBatch([
      {
        sql: `CREATE TABLE IF NOT EXISTS ${TABLE_NAME} (
          entity_type TEXT NOT NULL,
          entity_id TEXT NOT NULL,
          payload TEXT NOT NULL,
          updated_at TEXT NOT NULL,
          PRIMARY KEY (entity_type, entity_id)
        )`,
      },
      {
        sql: `CREATE INDEX IF NOT EXISTS idx_${TABLE_NAME}_type ON ${TABLE_NAME} (entity_type)`,
      },
    ])
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
  const normalized = extractEntities(entities)
  const statements: Statement[] = [
    {
      sql: `DELETE FROM ${TABLE_NAME} WHERE entity_type = ?`,
      params: [type],
    },
  ]

  normalized.forEach(({ entityId, payload, updatedAt }) => {
    statements.push({
      sql: `INSERT OR REPLACE INTO ${TABLE_NAME} (entity_type, entity_id, payload, updated_at) VALUES (?, ?, ?, ?)`,
      params: [type, entityId, payload, updatedAt],
    })
  })

  await runBatch(statements)
}

export const upsertCachedEntities = async (type: string, entities: CacheEntity[]) => {
  await ensureReady()
  const normalized = extractEntities(entities)
  const statements: Statement[] = normalized.map(({ entityId, payload, updatedAt }) => ({
    sql: `INSERT OR REPLACE INTO ${TABLE_NAME} (entity_type, entity_id, payload, updated_at) VALUES (?, ?, ?, ?)`,
    params: [type, entityId, payload, updatedAt],
  }))

  if (statements.length === 0) {
    return
  }

  await runBatch(statements)
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
  await runQuery(
    `DELETE FROM ${TABLE_NAME} WHERE entity_type = ? AND entity_id = ?`,
    [type, entityId]
  )
}

export const getCachedEntities = async (type: string) => {
  await ensureReady()
  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT payload FROM ${TABLE_NAME} WHERE entity_type = ? ORDER BY updated_at DESC`,
    [type]
  )

  return result.rows
    ._array
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

  const result = await runQuery<SQLite.SQLResultSet>(
    `SELECT payload FROM ${TABLE_NAME} WHERE entity_type = ? AND entity_id = ? LIMIT 1`,
    [type, entityId]
  )

  const row = result.rows._array[0]
  if (!row?.payload) {
    return null
  }

  try {
    return JSON.parse(row.payload)
  } catch {
    return null
  }
}
