import inspectionService from '../../services/inspection.service'
import workorderService from '../../services/workorder.service'
import {
  listPendingItems,
  markItemProcessing,
  markItemFailed,
  removeItem,
} from '../utils/offlineQueue'

class OfflineSyncService {
  constructor() {
    this.isSyncing = false
    this.boundSync = this.sync.bind(this)
  }

  start() {
    if (typeof window === 'undefined') return
    window.addEventListener('online', this.boundSync)
    this.sync()
  }

  stop() {
    if (typeof window === 'undefined') return
    window.removeEventListener('online', this.boundSync)
  }

  async sync() {
    if (this.isSyncing || typeof navigator === 'undefined' || !navigator.onLine) {
      return
    }

    this.isSyncing = true

    try {
      const items = await listPendingItems()
      for (const item of items) {
        try {
          await markItemProcessing(item.id)
          await this.processItem(item)
          await removeItem(item.id)
        } catch (error) {
          await markItemFailed(item.id, error?.message || 'Sync failed')
        }
      }
    } finally {
      this.isSyncing = false
    }
  }

  async processItem(item) {
    switch (item.type) {
      case 'inspection_media':
        return inspectionService.uploadMedia(
          item.payload.reportId,
          item.payload.file,
          {
            allowQueue: false,
            clientToken: item.payload.clientToken,
          }
        )
      case 'inspection_complete':
        return inspectionService.completeInspection(
          item.payload.reportId,
          item.payload.payload,
          { allowQueue: false }
        )
      case 'workorder_status':
        return workorderService.updateStatus(
          item.payload.id,
          item.payload.status,
          item.payload.notes,
          {
            allowQueue: false,
            clientEventId: item.payload.clientEventId,
          }
        )
      default:
        throw new Error(`Unknown offline item type: ${item.type}`)
    }
  }
}

export default new OfflineSyncService()
