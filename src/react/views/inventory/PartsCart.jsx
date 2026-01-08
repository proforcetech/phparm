import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Autocomplete from '../../components/ui/Autocomplete'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import partsCartService from '../../../services/parts-cart.service'
import inventoryService from '../../../services/inventory.service'
import { useToast } from '../../stores/toast'

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

const formatDateTime = (date) => {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(date))
}

const getStatusVariant = (status) => {
  const variants = {
    draft: 'default',
    pending_approval: 'warning',
    approved: 'info',
    ordered: 'primary',
    received: 'success',
    cancelled: 'danger',
  }
  return variants[status] || 'default'
}

const formatStatus = (status) => {
  if (!status) return ''
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export default function PartsCart({ workorderId, vehicle, jobs = [], onUpdate }) {
  const { success, error: toastError } = useToast()

  const [loading, setLoading] = useState(true)
  const [cart, setCart] = useState(null)
  const [items, setItems] = useState([])
  const [partsTechEnabled, setPartsTechEnabled] = useState(false)

  const [showAddItemModal, setShowAddItemModal] = useState(false)
  const [showPartsTechModal, setShowPartsTechModal] = useState(false)
  const [showRejectModal, setShowRejectModal] = useState(false)
  const [showReceiveModal, setShowReceiveModal] = useState(false)

  const [submitting, setSubmitting] = useState(false)
  const [addingItem, setAddingItem] = useState(false)
  const [searchingPartsTech, setSearchingPartsTech] = useState(false)

  const [partsTechResults, setPartsTechResults] = useState([])
  const [partsTechQuery, setPartsTechQuery] = useState('')

  const [rejectReason, setRejectReason] = useState('')
  const [receiveItems, setReceiveItems] = useState([])

  const [itemForm, setItemForm] = useState({
    source: 'inventory',
    inventory_item_id: null,
    description: '',
    sku: '',
    quantity: 1,
    unit_cost: 0,
    unit_price: 0,
    workorder_job_id: '',
    notes: '',
  })

  const jobOptions = useMemo(() => {
    return [
      { value: '', label: 'No specific job' },
      ...jobs.map((job) => ({ value: job.id, label: job.title || job.description })),
    ]
  }, [jobs])

  const cartTotal = useMemo(() => {
    return items.reduce((sum, item) => {
      return sum + (item.quantity * item.unit_price)
    }, 0)
  }, [items])

  const canEdit = useMemo(() => {
    return !cart || cart.status === 'draft'
  }, [cart])

  const canSubmit = useMemo(() => {
    return cart && cart.status === 'draft' && items.length > 0
  }, [cart, items])

  const canApprove = useMemo(() => {
    return cart && cart.status === 'pending_approval'
  }, [cart])

  const canOrder = useMemo(() => {
    return cart && cart.status === 'approved'
  }, [cart])

  const canReceive = useMemo(() => {
    return cart && cart.status === 'ordered'
  }, [cart])

  const loadCart = useCallback(async () => {
    if (!workorderId) return
    setLoading(true)
    try {
      const response = await partsCartService.getOrCreate(workorderId)
      setCart(response)
      setItems(response.items || [])

      const statusResponse = await partsCartService.getPartsTechStatus()
      setPartsTechEnabled(statusResponse.enabled)
    } catch (loadError) {
      console.error('Failed to load parts cart:', loadError)
      toastError('Failed to load parts cart')
    } finally {
      setLoading(false)
    }
  }, [workorderId, toastError])

  useEffect(() => {
    loadCart()
  }, [loadCart])

  const searchInventory = async (query) => {
    if (!query || query.length < 2) return []
    try {
      const vehicleMasterId = vehicle?.vehicle_master_id || null
      // Use searchWithCompatibility to get compatibility highlighting
      if (vehicleMasterId) {
        const results = await inventoryService.searchWithCompatibility(query, vehicleMasterId, 15)
        return Array.isArray(results) ? results : results?.data || []
      }
      const results = await inventoryService.searchParts(query, null, 15)
      return Array.isArray(results) ? results : results?.data || []
    } catch (searchError) {
      console.error('Failed to search inventory:', searchError)
      return []
    }
  }

  const selectInventoryItem = (item) => {
    if (!item) return
    setItemForm((prev) => ({
      ...prev,
      inventory_item_id: item.id,
      description: item.name,
      sku: item.sku || '',
      unit_cost: item.cost || 0,
      unit_price: item.sale_price || 0,
    }))
  }

  const resetItemForm = () => {
    setItemForm({
      source: 'inventory',
      inventory_item_id: null,
      description: '',
      sku: '',
      quantity: 1,
      unit_cost: 0,
      unit_price: 0,
      workorder_job_id: '',
      notes: '',
    })
  }

  const addItemToCart = async () => {
    if (!cart) return
    setAddingItem(true)
    try {
      if (itemForm.source === 'inventory' && itemForm.inventory_item_id) {
        await partsCartService.addFromInventory(
          cart.id,
          itemForm.inventory_item_id,
          Number(itemForm.quantity) || 1,
          itemForm.workorder_job_id || null
        )
      } else {
        await partsCartService.addItem(cart.id, {
          description: itemForm.description,
          sku: itemForm.sku || null,
          quantity: Number(itemForm.quantity) || 1,
          unit_cost: Number(itemForm.unit_cost) || 0,
          unit_price: Number(itemForm.unit_price) || 0,
          workorder_job_id: itemForm.workorder_job_id || null,
          notes: itemForm.notes || null,
        })
      }
      success('Item added to cart')
      setShowAddItemModal(false)
      resetItemForm()
      loadCart()
      if (onUpdate) onUpdate()
    } catch (addError) {
      console.error('Failed to add item:', addError)
      toastError(addError.response?.data?.error || 'Failed to add item')
    } finally {
      setAddingItem(false)
    }
  }

  const searchPartsTech = async () => {
    if (!partsTechQuery || partsTechQuery.length < 2) return
    setSearchingPartsTech(true)
    try {
      const vehicleData = vehicle ? {
        year: vehicle.year,
        make: vehicle.make,
        model: vehicle.model,
      } : {}
      const response = await partsCartService.searchPartsTech(partsTechQuery, vehicleData, 30)
      setPartsTechResults(response.parts || [])
    } catch (searchError) {
      console.error('Failed to search PartsTech:', searchError)
      toastError('Failed to search PartsTech')
      setPartsTechResults([])
    } finally {
      setSearchingPartsTech(false)
    }
  }

  const addPartsTechItem = async (part) => {
    if (!cart) return
    setAddingItem(true)
    try {
      await partsCartService.addFromPartsTech(cart.id, part, 1, null)
      success('Part added from PartsTech')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (addError) {
      console.error('Failed to add PartsTech part:', addError)
      toastError(addError.response?.data?.error || 'Failed to add part')
    } finally {
      setAddingItem(false)
    }
  }

  const updateItemQuantity = async (item, newQuantity) => {
    if (!cart || !canEdit) return
    try {
      await partsCartService.updateItem(cart.id, item.id, { quantity: newQuantity })
      loadCart()
    } catch (updateError) {
      console.error('Failed to update quantity:', updateError)
      toastError('Failed to update quantity')
    }
  }

  const removeItem = async (item) => {
    if (!cart || !canEdit) return
    if (!window.confirm('Remove this item from the cart?')) return
    try {
      await partsCartService.removeItem(cart.id, item.id)
      success('Item removed')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (removeError) {
      console.error('Failed to remove item:', removeError)
      toastError('Failed to remove item')
    }
  }

  const submitForApproval = async () => {
    if (!cart || !canSubmit) return
    setSubmitting(true)
    try {
      await partsCartService.submit(cart.id)
      success('Cart submitted for approval')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (submitError) {
      console.error('Failed to submit cart:', submitError)
      toastError(submitError.response?.data?.error || 'Failed to submit cart')
    } finally {
      setSubmitting(false)
    }
  }

  const approveCart = async () => {
    if (!cart || !canApprove) return
    setSubmitting(true)
    try {
      await partsCartService.approve(cart.id)
      success('Cart approved')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (approveError) {
      console.error('Failed to approve cart:', approveError)
      toastError(approveError.response?.data?.error || 'Failed to approve cart')
    } finally {
      setSubmitting(false)
    }
  }

  const rejectCart = async () => {
    if (!cart || !canApprove) return
    setSubmitting(true)
    try {
      await partsCartService.reject(cart.id, rejectReason || null)
      success('Cart rejected')
      setShowRejectModal(false)
      setRejectReason('')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (rejectError) {
      console.error('Failed to reject cart:', rejectError)
      toastError(rejectError.response?.data?.error || 'Failed to reject cart')
    } finally {
      setSubmitting(false)
    }
  }

  const placeOrder = async () => {
    if (!cart || !canOrder) return
    if (!window.confirm('Place order for these parts? This will create orders with vendors.')) return
    setSubmitting(true)
    try {
      await partsCartService.order(cart.id)
      success('Order placed successfully')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (orderError) {
      console.error('Failed to place order:', orderError)
      toastError(orderError.response?.data?.error || 'Failed to place order')
    } finally {
      setSubmitting(false)
    }
  }

  const openReceiveModal = () => {
    setReceiveItems(items.map(item => ({
      id: item.id,
      description: item.description,
      ordered: item.quantity,
      received: item.quantity_received || 0,
      toReceive: item.quantity - (item.quantity_received || 0),
    })))
    setShowReceiveModal(true)
  }

  const markAsReceived = async () => {
    if (!cart || !canReceive) return
    setSubmitting(true)
    try {
      const itemData = receiveItems
        .filter(item => item.toReceive > 0)
        .map(item => ({ id: item.id, quantity: item.toReceive }))

      await partsCartService.receive(cart.id, itemData.length > 0 ? itemData : null)
      success('Items marked as received')
      setShowReceiveModal(false)
      loadCart()
      if (onUpdate) onUpdate()
    } catch (receiveError) {
      console.error('Failed to mark as received:', receiveError)
      toastError(receiveError.response?.data?.error || 'Failed to mark as received')
    } finally {
      setSubmitting(false)
    }
  }

  const cancelCart = async () => {
    if (!cart) return
    if (!window.confirm('Cancel this parts cart? This action cannot be undone.')) return
    setSubmitting(true)
    try {
      await partsCartService.cancel(cart.id, null)
      success('Cart cancelled')
      loadCart()
      if (onUpdate) onUpdate()
    } catch (cancelError) {
      console.error('Failed to cancel cart:', cancelError)
      toastError(cancelError.response?.data?.error || 'Failed to cancel cart')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <Card>
        <div className="flex justify-center py-8">
          <Loading size="lg" text="Loading parts cart..." />
        </div>
      </Card>
    )
  }

  return (
    <Card>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-3">
          <h3 className="text-lg font-medium text-gray-900">Parts Cart</h3>
          {cart && (
            <Badge variant={getStatusVariant(cart.status)}>
              {formatStatus(cart.status)}
            </Badge>
          )}
          {partsTechEnabled && (
            <Badge variant="primary" size="sm">PartsTech</Badge>
          )}
        </div>
        <div className="flex items-center gap-2">
          {canEdit && (
            <>
              <Button variant="outline" size="sm" onClick={() => setShowAddItemModal(true)}>
                <svg className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Item
              </Button>
              {partsTechEnabled && (
                <Button variant="outline" size="sm" onClick={() => setShowPartsTechModal(true)}>
                  <svg className="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                  Search PartsTech
                </Button>
              )}
            </>
          )}
        </div>
      </div>

      {items.length === 0 ? (
        <div className="text-center py-8 text-gray-500">
          <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
          <p className="mt-2">No items in cart</p>
          {canEdit && (
            <p className="text-sm">Click "Add Item" to add parts from inventory or PartsTech</p>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((item) => (
            <div
              key={item.id}
              className={`flex items-center justify-between p-3 border border-gray-200 rounded-lg ${
                item.source === 'partstech' ? 'bg-blue-50 border-blue-200' : ''
              }`}
            >
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-gray-900">{item.description}</span>
                  {item.source === 'partstech' && (
                    <Badge variant="info" size="sm">PartsTech</Badge>
                  )}
                  {item.source === 'inventory' && (
                    <Badge variant="success" size="sm">Inventory</Badge>
                  )}
                </div>
                <div className="text-sm text-gray-500 mt-1">
                  {item.sku && <span className="mr-3">SKU: {item.sku}</span>}
                  <span className="mr-3">{formatCurrency(item.unit_price)} each</span>
                  {item.partstech_part_number && (
                    <span className="mr-3">Part#: {item.partstech_part_number}</span>
                  )}
                  {item.vendor && <span>Vendor: {item.vendor}</span>}
                </div>
              </div>
              <div className="flex items-center gap-4">
                <div className="flex items-center gap-2">
                  {canEdit ? (
                    <Input
                      value={item.quantity}
                      type="number"
                      min="1"
                      className="w-20"
                      onUpdateModelValue={(value) => updateItemQuantity(item, value)}
                    />
                  ) : (
                    <span className="text-sm">Qty: {item.quantity}</span>
                  )}
                </div>
                <span className="font-medium text-gray-900 w-24 text-right">
                  {formatCurrency(item.quantity * item.unit_price)}
                </span>
                {canEdit && (
                  <Button variant="ghost" size="sm" onClick={() => removeItem(item)}>
                    <svg className="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </Button>
                )}
              </div>
            </div>
          ))}

          <div className="border-t border-gray-200 pt-4 mt-4">
            <div className="flex justify-between items-center">
              <span className="text-lg font-medium text-gray-900">Total</span>
              <span className="text-lg font-bold text-gray-900">{formatCurrency(cartTotal)}</span>
            </div>
          </div>
        </div>
      )}

      {cart && (cart.partstech_quote_id || cart.partstech_order_id) && (
        <div className="mt-4 p-3 bg-blue-50 rounded-lg">
          <h4 className="text-sm font-medium text-blue-900 mb-2">PartsTech Integration</h4>
          <div className="text-sm text-blue-700">
            {cart.partstech_quote_id && (
              <p>Quote ID: {cart.partstech_quote_id}</p>
            )}
            {cart.partstech_order_id && (
              <p>Order ID: {cart.partstech_order_id}</p>
            )}
          </div>
        </div>
      )}

      {cart && (
        <div className="mt-6 pt-4 border-t border-gray-200 flex flex-wrap gap-2">
          {canSubmit && (
            <Button onClick={submitForApproval} loading={submitting} disabled={submitting}>
              Submit for Approval
            </Button>
          )}
          {canApprove && (
            <>
              <Button onClick={approveCart} loading={submitting} disabled={submitting}>
                Approve
              </Button>
              <Button variant="danger" onClick={() => setShowRejectModal(true)} disabled={submitting}>
                Reject
              </Button>
            </>
          )}
          {canOrder && (
            <Button onClick={placeOrder} loading={submitting} disabled={submitting}>
              Place Order
            </Button>
          )}
          {canReceive && (
            <Button onClick={openReceiveModal} disabled={submitting}>
              Mark as Received
            </Button>
          )}
          {cart.status !== 'received' && cart.status !== 'cancelled' && (
            <Button variant="outline" onClick={cancelCart} disabled={submitting}>
              Cancel Cart
            </Button>
          )}
        </div>
      )}

      <Modal
        open={showAddItemModal}
        onClose={() => setShowAddItemModal(false)}
        title="Add Item to Cart"
        size="lg"
        content={(
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Source</label>
              <Select
                value={itemForm.source}
                options={[
                  { value: 'inventory', label: 'From Inventory' },
                  { value: 'custom', label: 'Custom Item' },
                ]}
                onChange={(e) => setItemForm((prev) => ({ ...prev, source: e.target.value }))}
              />
            </div>

            {itemForm.source === 'inventory' && (
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Search Inventory</label>
                {vehicle?.vehicle_master_id && (
                  <p className="text-xs text-green-600 mb-1">
                    Compatible parts for {vehicle.year} {vehicle.make} {vehicle.model} shown first
                  </p>
                )}
                <Autocomplete
                  modelValue={itemForm.inventory_item_id}
                  placeholder="Search by name, SKU, or description..."
                  searchFn={searchInventory}
                  itemValue={(item) => item.id}
                  itemLabel={(item) => item.is_compatible ? `✓ ${item.name}` : item.name}
                  itemSubtext={(item) => {
                    const compatBadge = item.is_compatible ? '[FITS VEHICLE] ' : ''
                    return `${compatBadge}SKU: ${item.sku || 'N/A'} | Stock: ${item.stock_quantity} | ${formatCurrency(item.sale_price)}`
                  }}
                  onSelect={selectInventoryItem}
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, inventory_item_id: value }))}
                />
              </div>
            )}

            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <Input
                  value={itemForm.description}
                  placeholder="Part description"
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, description: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">SKU</label>
                <Input
                  value={itemForm.sku}
                  placeholder="SKU-1234"
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, sku: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                <Input
                  value={itemForm.quantity}
                  type="number"
                  min="1"
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, quantity: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Unit Cost</label>
                <Input
                  value={itemForm.unit_cost}
                  type="number"
                  step="0.01"
                  min="0"
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, unit_cost: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Unit Price</label>
                <Input
                  value={itemForm.unit_price}
                  type="number"
                  step="0.01"
                  min="0"
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, unit_price: value }))}
                />
              </div>
              <div className="col-span-2">
                <label className="block text-sm font-medium text-gray-700 mb-1">Job (Optional)</label>
                <Select
                  value={itemForm.workorder_job_id}
                  options={jobOptions}
                  onChange={(e) => setItemForm((prev) => ({ ...prev, workorder_job_id: e.target.value }))}
                />
              </div>
              <div className="col-span-2">
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <Textarea
                  value={itemForm.notes}
                  rows={2}
                  placeholder="Additional notes..."
                  onUpdateModelValue={(value) => setItemForm((prev) => ({ ...prev, notes: value }))}
                />
              </div>
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowAddItemModal(false)}>Cancel</Button>
            <Button onClick={addItemToCart} loading={addingItem} disabled={addingItem || !itemForm.description}>
              Add to Cart
            </Button>
          </div>
        )}
      />

      <Modal
        open={showPartsTechModal}
        onClose={() => setShowPartsTechModal(false)}
        title="Search PartsTech"
        size="xl"
        content={(
          <div className="space-y-4">
            {vehicle && (
              <Alert variant="info">
                Searching for parts compatible with: {vehicle.year} {vehicle.make} {vehicle.model}
              </Alert>
            )}

            <div className="flex gap-2">
              <Input
                value={partsTechQuery}
                placeholder="Search for parts..."
                className="flex-1"
                onUpdateModelValue={setPartsTechQuery}
                onKeyDown={(e) => e.key === 'Enter' && searchPartsTech()}
              />
              <Button onClick={searchPartsTech} loading={searchingPartsTech} disabled={searchingPartsTech}>
                Search
              </Button>
            </div>

            {partsTechResults.length > 0 ? (
              <div className="max-h-96 overflow-y-auto space-y-2">
                {partsTechResults.map((part, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                  >
                    <div className="flex-1">
                      <div className="font-medium text-gray-900">{part.description || part.name}</div>
                      <div className="text-sm text-gray-500">
                        {part.part_number && <span className="mr-3">Part#: {part.part_number}</span>}
                        {part.brand && <span className="mr-3">Brand: {part.brand}</span>}
                        {part.vendor && <span>Vendor: {part.vendor}</span>}
                      </div>
                      {part.availability && (
                        <div className="text-sm text-green-600 mt-1">
                          In Stock: {part.availability.quantity || 'Available'}
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-4">
                      <span className="font-medium text-gray-900">
                        {formatCurrency(part.price || part.list_price)}
                      </span>
                      <Button
                        size="sm"
                        onClick={() => addPartsTechItem(part)}
                        disabled={addingItem}
                      >
                        Add
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            ) : partsTechQuery && !searchingPartsTech ? (
              <div className="text-center py-8 text-gray-500">
                No parts found. Try a different search term.
              </div>
            ) : null}
          </div>
        )}
        footer={(
          <div className="flex justify-end">
            <Button variant="outline" onClick={() => setShowPartsTechModal(false)}>Close</Button>
          </div>
        )}
      />

      <Modal
        open={showRejectModal}
        onClose={() => setShowRejectModal(false)}
        title="Reject Parts Cart"
        content={(
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              Provide a reason for rejecting this parts request:
            </p>
            <Textarea
              value={rejectReason}
              rows={3}
              placeholder="Reason for rejection..."
              onUpdateModelValue={setRejectReason}
            />
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowRejectModal(false)}>Cancel</Button>
            <Button variant="danger" onClick={rejectCart} loading={submitting} disabled={submitting}>
              Reject
            </Button>
          </div>
        )}
      />

      <Modal
        open={showReceiveModal}
        onClose={() => setShowReceiveModal(false)}
        title="Receive Parts"
        size="lg"
        content={(
          <div className="space-y-4">
            <Alert variant="info">
              Enter the quantity received for each item. Leave at 0 if not yet received.
            </Alert>

            <div className="space-y-3">
              {receiveItems.map((item, index) => (
                <div key={item.id} className="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                  <div className="flex-1">
                    <span className="font-medium text-gray-900">{item.description}</span>
                    <p className="text-sm text-gray-500">
                      Ordered: {item.ordered} | Already Received: {item.received}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm text-gray-500">Receive:</span>
                    <Input
                      value={item.toReceive}
                      type="number"
                      min="0"
                      max={item.ordered - item.received}
                      className="w-20"
                      onUpdateModelValue={(value) => {
                        setReceiveItems((prev) => prev.map((i, idx) =>
                          idx === index ? { ...i, toReceive: Number(value) || 0 } : i
                        ))
                      }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="outline" onClick={() => setShowReceiveModal(false)}>Cancel</Button>
            <Button onClick={markAsReceived} loading={submitting} disabled={submitting}>
              Mark as Received
            </Button>
          </div>
        )}
      />
    </Card>
  )
}
