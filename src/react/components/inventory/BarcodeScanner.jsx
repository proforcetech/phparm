import { useCallback, useEffect, useRef, useState } from 'react'

import Alert from '../ui/Alert'
import Button from '../ui/Button'
import Input from '../ui/Input'
import Modal from '../ui/Modal'
import inventoryService from '../../../services/inventory.service'
import { useToast } from '../../stores/toast'

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

/**
 * Barcode Scanner Component
 * Supports USB barcode scanners and manual entry
 * Can be used for inventory lookup, adding items to workorders, etc.
 */
export default function BarcodeScanner({
  onItemFound,
  onItemNotFound,
  scanType = 'inventory_lookup',
  workorderId = null,
  invoiceId = null,
  autoAdd = false,
  placeholder = 'Scan barcode or enter manually...',
  showItemPreview = true,
}) {
  const { success, error: toastError } = useToast()

  const inputRef = useRef(null)
  const [inputValue, setInputValue] = useState('')
  const [scanning, setScanning] = useState(false)
  const [lastScannedItem, setLastScannedItem] = useState(null)
  const [showPreviewModal, setShowPreviewModal] = useState(false)

  // Handle input (barcode scanners typically send Enter key after scan)
  const handleKeyDown = useCallback((e) => {
    if (e.key === 'Enter' && inputValue.trim()) {
      e.preventDefault()
      handleScan(inputValue.trim())
    }
  }, [inputValue])

  const handleScan = async (code) => {
    if (!code || scanning) return

    setScanning(true)
    try {
      const result = await inventoryService.scanBarcode(code, scanType, workorderId, invoiceId)

      if (result.found && result.item) {
        setLastScannedItem(result.item)
        playBeep(true)

        if (autoAdd && onItemFound) {
          onItemFound(result.item)
          success(`Found: ${result.item.name}`)
        } else if (showItemPreview) {
          setShowPreviewModal(true)
        } else if (onItemFound) {
          onItemFound(result.item)
        }
      } else {
        setLastScannedItem(null)
        playBeep(false)

        if (onItemNotFound) {
          onItemNotFound(code)
        } else {
          toastError(`No item found with barcode: ${code}`)
        }
      }
    } catch (scanError) {
      console.error('Barcode scan failed:', scanError)
      playBeep(false)
      toastError('Failed to scan barcode')
    } finally {
      setScanning(false)
      setInputValue('')
      // Refocus input for next scan
      setTimeout(() => inputRef.current?.focus(), 100)
    }
  }

  const playBeep = (isSuccess) => {
    try {
      const audioContext = new (window.AudioContext || window.webkitAudioContext)()
      const oscillator = audioContext.createOscillator()
      const gainNode = audioContext.createGain()

      oscillator.connect(gainNode)
      gainNode.connect(audioContext.destination)

      oscillator.frequency.value = isSuccess ? 800 : 300
      oscillator.type = 'sine'
      gainNode.gain.value = 0.3

      oscillator.start()
      setTimeout(() => {
        oscillator.stop()
        audioContext.close()
      }, isSuccess ? 150 : 300)
    } catch (e) {
      // Audio not available, ignore
    }
  }

  const confirmAddItem = () => {
    if (lastScannedItem && onItemFound) {
      onItemFound(lastScannedItem)
      success(`Added: ${lastScannedItem.name}`)
    }
    setShowPreviewModal(false)
    setLastScannedItem(null)
  }

  // Auto-focus input on mount
  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  return (
    <div className="relative">
      <div className="flex gap-2">
        <div className="relative flex-1">
          <Input
            ref={inputRef}
            value={inputValue}
            placeholder={placeholder}
            disabled={scanning}
            onUpdateModelValue={setInputValue}
            onKeyDown={handleKeyDown}
            className="font-mono"
          />
          {scanning && (
            <div className="absolute right-3 top-1/2 -translate-y-1/2">
              <svg className="animate-spin h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            </div>
          )}
        </div>
        <Button
          variant="outline"
          onClick={() => handleScan(inputValue.trim())}
          disabled={!inputValue.trim() || scanning}
        >
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h2M4 12h2m10 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
          </svg>
        </Button>
      </div>

      <p className="text-xs text-gray-500 mt-1">
        Use USB barcode scanner or type code and press Enter
      </p>

      <Modal
        open={showPreviewModal}
        onClose={() => setShowPreviewModal(false)}
        title="Item Found"
        content={lastScannedItem && (
          <div className="space-y-4">
            <Alert variant="success">
              Barcode matched to inventory item
            </Alert>

            <div className="bg-gray-50 rounded-lg p-4">
              <h4 className="font-medium text-gray-900 text-lg">{lastScannedItem.name}</h4>
              {lastScannedItem.description && (
                <p className="text-gray-600 mt-1">{lastScannedItem.description}</p>
              )}

              <div className="grid grid-cols-2 gap-4 mt-4">
                {lastScannedItem.sku && (
                  <div>
                    <span className="text-sm text-gray-500">SKU</span>
                    <p className="font-mono">{lastScannedItem.sku}</p>
                  </div>
                )}
                {lastScannedItem.upc && (
                  <div>
                    <span className="text-sm text-gray-500">UPC</span>
                    <p className="font-mono">{lastScannedItem.upc}</p>
                  </div>
                )}
                {lastScannedItem.barcode && (
                  <div>
                    <span className="text-sm text-gray-500">Barcode</span>
                    <p className="font-mono">{lastScannedItem.barcode}</p>
                  </div>
                )}
                <div>
                  <span className="text-sm text-gray-500">Price</span>
                  <p className="font-medium">{formatCurrency(lastScannedItem.sale_price)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Stock</span>
                  <p className={lastScannedItem.stock_quantity <= lastScannedItem.low_stock_threshold ? 'text-red-600 font-medium' : ''}>
                    {lastScannedItem.stock_quantity}
                  </p>
                </div>
                {lastScannedItem.location && (
                  <div>
                    <span className="text-sm text-gray-500">Location</span>
                    <p>{lastScannedItem.location}</p>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowPreviewModal(false)}>Cancel</Button>
            <Button onClick={confirmAddItem}>
              Add to Cart
            </Button>
          </div>
        )}
      />
    </div>
  )
}
