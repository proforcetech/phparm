import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Table from '../../components/ui/Table'
import inventoryService from '../../../services/inventory.service'
import { useAuthStore } from '../../stores/auth.jsx'

export default function InventoryList() {
  const navigate = useNavigate()
  const { hasPermission } = useAuthStore()
  const [loading, setLoading] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [items, setItems] = useState([])
  const [page, setPage] = useState(1)
  const [hasNextPage, setHasNextPage] = useState(false)
  const [scanInput, setScanInput] = useState('')
  const [scanLoading, setScanLoading] = useState(false)
  const [scanMessage, setScanMessage] = useState('')
  const [scanError, setScanError] = useState('')
  const [cameraOpen, setCameraOpen] = useState(false)
  const [cameraError, setCameraError] = useState('')
  const [cameraSupported, setCameraSupported] = useState(false)
  const [pendingFilters, setPendingFilters] = useState({
    query: '',
    category: '',
    location: '',
    low_stock_only: false,
  })
  const [filters, setFilters] = useState({
    query: '',
    category: '',
    location: '',
    low_stock_only: false,
  })

  const perPage = 10
  const debounceDelay = 400

  const scanInputRef = useRef(null)
  const videoRef = useRef(null)

  const canCreate = hasPermission('inventory.create')
  const canEdit = hasPermission('inventory.edit')
  const canDelete = hasPermission('inventory.delete')
  const canManageLookups = hasPermission('inventory.manage') || hasPermission('inventory.edit')

  const formatCurrency = (value) => `$${Number(value || 0).toFixed(2)}`

  const columns = useMemo(() => ([
    { key: 'name', label: 'Item' },
    { key: 'category', label: 'Category' },
    { key: 'stock_quantity', label: 'Stock' },
    { key: 'forecast', label: 'Forecast' },
    { key: 'pricing', label: 'Pricing' },
    { key: 'core', label: 'Core' },
    { key: 'reorder_quantity', label: 'Reorder' },
  ]), [])

  const loadItems = useCallback(async () => {
    setLoading(true)
    try {
      const params = {
        limit: perPage + 1,
        offset: (page - 1) * perPage,
      }

      Object.entries(filters).forEach(([key, value]) => {
        if (value) params[key] = value
      })

      const data = await inventoryService.list(params)
      const normalized = data.map((item) => ({
        ...item,
        severity: item.stock_quantity === 0 ? 'out' : item.stock_quantity <= item.low_stock_threshold ? 'low' : 'ok',
      }))
      setItems(normalized.slice(0, perPage))
      setHasNextPage(normalized.length > perPage)
    } finally {
      setLoading(false)
    }
  }, [filters, page, perPage])

  useEffect(() => {
    loadItems()
  }, [loadItems])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setPage(1)
      setFilters(pendingFilters)
    }, debounceDelay)

    return () => window.clearTimeout(timeout)
  }, [pendingFilters, debounceDelay])

  useEffect(() => {
    setCameraSupported(Boolean(navigator.mediaDevices?.getUserMedia && window.BarcodeDetector))
  }, [])

  const updateFilters = (nextFilters) => {
    setPendingFilters((prev) => ({ ...prev, ...nextFilters }))
  }

  const nextPage = () => {
    if (!hasNextPage) return
    setPage((prev) => prev + 1)
  }

  const previousPage = () => {
    if (page === 1) return
    setPage((prev) => prev - 1)
  }

  const confirmDelete = async (id) => {
    if (!window.confirm('Delete this inventory item?')) return
    setDeletingId(id)
    try {
      await inventoryService.remove(id)
      setItems((prev) => prev.filter((item) => item.id !== id))
    } finally {
      setDeletingId(null)
    }
  }

  const handleScanLookup = useCallback(async (rawCode) => {
    const code = rawCode.trim()
    if (!code || scanLoading) return

    setScanLoading(true)
    setScanError('')
    setScanMessage('')

    try {
      const result = await inventoryService.findByBarcode(code, 'inventory_lookup')
      if (result?.found && result.item) {
        const mappedValue = result.item.sku || result.item.upc || code
        const mappedLabel = result.item.sku ? `SKU ${result.item.sku}` : result.item.upc ? `UPC ${result.item.upc}` : code
        updateFilters({ query: mappedValue })
        setScanMessage(`Mapped ${code} to ${mappedLabel}`)
      } else {
        updateFilters({ query: code })
        setScanError(result?.message || `No item found for ${code}`)
      }
    } catch (error) {
      console.error('Scanner lookup failed:', error)
      updateFilters({ query: code })
      setScanError('Failed to scan barcode/QR code')
    } finally {
      setScanLoading(false)
      setScanInput('')
      setTimeout(() => scanInputRef.current?.focus(), 100)
    }
  }, [scanLoading, updateFilters])

  const handleScanKeyDown = useCallback((event) => {
    if (event.key === 'Enter' && scanInput.trim()) {
      event.preventDefault()
      handleScanLookup(scanInput)
    }
  }, [handleScanLookup, scanInput])

  useEffect(() => {
    if (!cameraOpen) return

    let isActive = true
    let stream = null
    let animationFrame = null

    const startCamera = async () => {
      setCameraError('')
      try {
        if (!navigator.mediaDevices?.getUserMedia) {
          throw new Error('Camera access is not available on this device.')
        }
        if (!window.BarcodeDetector) {
          throw new Error('Barcode scanning is not supported in this browser.')
        }

        const detector = new window.BarcodeDetector({
          formats: ['qr_code', 'code_128', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_39', 'code_93', 'codabar', 'data_matrix', 'itf', 'pdf417'],
        })

        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
          audio: false,
        })

        if (!isActive) return
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play()
        }

        const scanFrame = async () => {
          if (!isActive || !videoRef.current) return
          try {
            const barcodes = await detector.detect(videoRef.current)
            if (barcodes.length && barcodes[0].rawValue) {
              setCameraOpen(false)
              handleScanLookup(barcodes[0].rawValue)
              return
            }
          } catch (error) {
            console.error('Barcode detection failed:', error)
          }
          animationFrame = requestAnimationFrame(scanFrame)
        }

        animationFrame = requestAnimationFrame(scanFrame)
      } catch (error) {
        console.error('Camera start failed:', error)
        setCameraError(error instanceof Error ? error.message : 'Unable to access camera.')
      }
    }

    startCamera()

    return () => {
      isActive = false
      if (animationFrame) {
        cancelAnimationFrame(animationFrame)
      }
      if (stream) {
        stream.getTracks().forEach((track) => track.stop())
      }
      if (videoRef.current) {
        videoRef.current.srcObject = null
      }
    }
  }, [cameraOpen, handleScanLookup])

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory</h1>
          <p className="mt-1 text-sm text-gray-500">Search, filter, and manage stock</p>
        </div>
        {canCreate ? (
          <Button onClick={() => navigate('/cp/inventory/create')}>
            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
            </svg>
            Add Item
          </Button>
        ) : null}
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-4">
        <Card className="xl:col-span-3">
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-1 gap-3 md:grid-cols-5">
              <div>
                <label className="block text-sm font-medium text-gray-700">Search</label>
                <Input
                  modelValue={pendingFilters.query}
                  placeholder="Name or SKU"
                  onUpdateModelValue={(value) => updateFilters({ query: value })}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Scan SKU / UPC</label>
                <div className="flex gap-2">
                  <Input
                    ref={scanInputRef}
                    value={scanInput}
                    placeholder="Scan barcode or QR code"
                    onUpdateModelValue={setScanInput}
                    onKeyDown={handleScanKeyDown}
                    disabled={scanLoading}
                    className="font-mono"
                  />
                  <Button
                    variant="secondary"
                    onClick={() => handleScanLookup(scanInput)}
                    disabled={!scanInput.trim() || scanLoading}
                  >
                    Scan
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => setCameraOpen(true)}
                    disabled={scanLoading || !cameraSupported}
                  >
                    Camera
                  </Button>
                </div>
                {scanMessage ? (
                  <p className="mt-1 text-xs text-green-600">{scanMessage}</p>
                ) : null}
                {scanError ? (
                  <p className="mt-1 text-xs text-red-600">{scanError}</p>
                ) : (
                  <p className="mt-1 text-xs text-gray-500">
                    USB scanner input and {cameraSupported ? 'camera scanning' : 'camera scanning (unsupported on this device)'}.
                  </p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Category</label>
                <Input
                  modelValue={pendingFilters.category}
                  placeholder="Brakes"
                  onUpdateModelValue={(value) => updateFilters({ category: value })}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Location</label>
                <Input
                  modelValue={pendingFilters.location}
                  placeholder="Aisle 3"
                  onUpdateModelValue={(value) => updateFilters({ location: value })}
                />
              </div>
              <div className="flex items-end gap-2">
                <input
                  id="lowStockOnly"
                  checked={pendingFilters.low_stock_only}
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                  onChange={(event) => updateFilters({ low_stock_only: event.target.checked })}
                />
                <label htmlFor="lowStockOnly" className="text-sm text-gray-700">Show low stock only</label>
              </div>
            </div>

            <Table
              columns={columns}
              data={items}
              loading={loading}
              hoverable
              cellRenderers={{
                name: ({ row }) => (
                  <div>
                    <div className="font-semibold text-gray-900">{row.name}</div>
                    <p className="text-xs text-gray-500">SKU: {row.sku || '—'}</p>
                    <p className="text-xs text-gray-500">Bin: {row.bin_location || '—'}</p>
                  </div>
                ),
                stock_quantity: ({ row }) => (
                  <div className="flex items-center gap-2">
                    <Badge variant={row.severity === 'out' ? 'danger' : row.severity === 'low' ? 'warning' : 'secondary'}>
                      {row.stock_quantity}
                    </Badge>
                    <span className="text-xs text-gray-500">Threshold {row.low_stock_threshold}</span>
                  </div>
                ),
                pricing: ({ row }) => (
                  <div className="text-sm text-gray-800">
                    <div>
                      <span className="font-semibold">Cost:</span> ${Number(row.cost).toFixed(2)}
                    </div>
                    <div>
                      <span className="font-semibold">Price:</span> ${Number(row.sale_price).toFixed(2)}
                    </div>
                    <div className="text-xs text-gray-500">Markup: {row.markup ?? '—'}%</div>
                  </div>
                ),
                core: ({ row }) => (
                  <div className="text-sm text-gray-800">
                    <div className="flex items-center gap-2">
                      <Badge variant={row.core_eligible ? 'primary' : 'secondary'}>
                        {row.core_eligible ? 'Eligible' : 'Not Eligible'}
                      </Badge>
                      {row.core_eligible ? (
                        <span className="text-xs text-gray-500">Core charge tracked</span>
                      ) : null}
                    </div>
                    {row.core_eligible ? (
                      <div className="mt-2 text-xs text-gray-500">
                        <div>Customer: {formatCurrency(row.core_price)}</div>
                        <div>Vendor: {formatCurrency(row.core_cost)}</div>
                      </div>
                    ) : (
                      <div className="mt-2 text-xs text-gray-400">No core balance</div>
                    )}
                forecast: ({ row }) => (
                  <div className="text-sm text-gray-800">
                    <div>
                      <span className="font-semibold">Usage:</span>{' '}
                      {Number(row.usage_rate_30d || 0).toFixed(2)} / day
                    </div>
                    <div>
                      <span className="font-semibold">Suggested ROP:</span>{' '}
                      {row.suggested_reorder_point ?? '—'}
                    </div>
                    <div className="text-xs text-gray-500">
                      Effective: {row.effective_reorder_point ?? row.suggested_reorder_point ?? '—'}
                      {row.reorder_point_override !== null ? ' (override)' : ''}
                    </div>
                  </div>
                ),
                reorder_quantity: ({ row }) => (
                  <div className="text-sm text-gray-900">
                    {row.reorder_quantity || '—'}
                    <p className="text-xs text-gray-500">Vendor: {row.vendor || '—'}</p>
                  </div>
                ),
              }}
              renderActions={canEdit || canDelete ? (row) => (
                <div className="flex gap-2">
                  {canEdit ? (
                    <Button size="sm" variant="secondary" onClick={() => navigate(`/cp/inventory/${row.id}/edit`)}>Edit</Button>
                  ) : null}
                  {canDelete ? (
                    <Button size="sm" variant="danger" loading={deletingId === row.id} onClick={() => confirmDelete(row.id)}>
                      Delete
                    </Button>
                  ) : null}
                </div>
              ) : undefined}
              renderEmpty={() => <p className="text-sm text-gray-500">No inventory items found for the current filters.</p>}
            />
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
              <div className="text-sm text-gray-600">Page {page} ({items.length} items)</div>
              <div className="flex items-center gap-2"> 
                <Button disabled={page === 1} variant="secondary" onClick={previousPage}>Previous</Button>
                <Button disabled={!hasNextPage} variant="secondary" onClick={nextPage}>Next</Button> 
              </div> 
            </div> 
          </div> >
        </Card>
                  
        <Card className="space-y-3">
          <h3 className="text-lg font-semibold text-gray-900">Quick actions</h3>
          <p className="text-sm text-gray-600">Import/export and alerts at a glance.</p>
          <div className="rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">
            Low stock alerting is enabled. Use the toggle above to triage items that need restocking.
          </div>
          <Button variant="secondary" onClick={() => navigate('/cp/inventory/alerts')}>View Alerts</Button>
          {canManageLookups ? (
            <div className="pt-2 border-t border-gray-100 space-y-2">
              <h4 className="text-sm font-semibold text-gray-900">Manage lists</h4>
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/categories')}>Categories</Button>
                <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/vendors')}>Vendors</Button>
                <Button size="sm" variant="secondary" onClick={() => navigate('/cp/inventory/locations')}>Locations</Button>
              </div>
            </div>
          ) : null}
        </Card>
      </div>

      <Modal
        open={cameraOpen}
        onClose={() => setCameraOpen(false)}
        title="Scan with Camera"
        content={(
          <div className="space-y-3">
            <div className="overflow-hidden rounded-lg border border-gray-200 bg-black">
              <video ref={videoRef} className="h-64 w-full object-cover" playsInline muted />
            </div>
            <p className="text-sm text-gray-600">
              Align the barcode or QR code within the frame to scan automatically.
            </p>
            {cameraError ? (
              <p className="text-sm text-red-600">{cameraError}</p>
            ) : null}
          </div>
        )}
        footer={(
          <div className="flex justify-end">
            <Button variant="outline" onClick={() => setCameraOpen(false)}>Close</Button>
          </div>
        )}
      />
    </div>
  )
}
