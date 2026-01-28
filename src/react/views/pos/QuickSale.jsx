import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Autocomplete from '../../components/ui/Autocomplete'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import customerService from '../../../services/customer.service'
import inventoryService from '../../../services/inventory.service'
import invoiceService from '../../../services/invoice.service'
import { useToast } from '../../stores/toast.jsx'
import { useAuthStore } from '../../stores/auth'

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value || 0)
}

const formatStock = (quantity) => {
  if (quantity === null || quantity === undefined) return 'N/A'
  return Number(quantity).toLocaleString()
}

const createQuickSaleNumber = () => {
  const timestamp = Date.now().toString(36).toUpperCase()
  return `QS-${timestamp}`
}

export default function QuickSale() {
  const navigate = useNavigate()
  const { success, error } = useToast()
  const { user } = useAuthStore()

  const [walkInCustomer, setWalkInCustomer] = useState(null)
  const [loadingCustomer, setLoadingCustomer] = useState(true)
  const [customerMode, setCustomerMode] = useState('walk_in')
  const [selectedCustomer, setSelectedCustomer] = useState(null)

  const [searchQuery, setSearchQuery] = useState('')
  const [searchResults, setSearchResults] = useState([])
  const [searchingInventory, setSearchingInventory] = useState(false)

  const [cartItems, setCartItems] = useState([])
  const [notes, setNotes] = useState('')

  const [apiError, setApiError] = useState('')
  const [creatingInvoice, setCreatingInvoice] = useState(false)
  const [createdInvoice, setCreatedInvoice] = useState(null)
  const [checkoutProvider, setCheckoutProvider] = useState(null)

  const loadWalkInCustomer = useCallback(async () => {
    setLoadingCustomer(true)
    try {
      const results = await customerService.searchCustomers('Walk-in Customer')
      const customerList = Array.isArray(results) ? results : results?.data || []
      const existing = customerList.find(
        (customer) => customer.name?.toLowerCase() === 'walk-in customer'
      )
      let customer = existing
      if (!customer) {
        customer = await customerService.createCustomer({
          first_name: 'Walk-in',
          last_name: 'Customer',
          email: 'walkin@example.com',
          phone: '000-000-0000',
        })
      }
      setWalkInCustomer(customer)
      if (customerMode === 'walk_in') {
        setSelectedCustomer(customer)
      }
    } catch (err) {
      console.error('Failed to load walk-in customer', err)
      error('Unable to load walk-in customer')
    } finally {
      setLoadingCustomer(false)
    }
  }, [customerMode, error])

  useEffect(() => {
    // Wait for user to be authenticated before making API calls
    if (user) {
      loadWalkInCustomer()
    }
  }, [loadWalkInCustomer, user])

  useEffect(() => {
    if (customerMode === 'walk_in' && walkInCustomer) {
      setSelectedCustomer(walkInCustomer)
    }
  }, [customerMode, walkInCustomer])

  useEffect(() => {
    if (!searchQuery || searchQuery.trim().length < 2) {
      setSearchResults([])
      return
    }

    const handle = setTimeout(async () => {
      setSearchingInventory(true)
      try {
        const results = await inventoryService.searchParts(searchQuery.trim(), null, 12)
        const inventoryList = Array.isArray(results) ? results : results?.data || []
        setSearchResults(inventoryList)
      } catch (err) {
        console.error('Inventory search failed', err)
        setSearchResults([])
      } finally {
        setSearchingInventory(false)
      }
    }, 300)

    return () => clearTimeout(handle)
  }, [searchQuery])

  const cartSubtotal = useMemo(() => {
    return cartItems.reduce((sum, item) => sum + item.quantity * item.unit_price, 0)
  }, [cartItems])

  const paymentProviders = useMemo(() => {
    return createdInvoice?.payment_providers ?? []
  }, [createdInvoice])

  const addItemToCart = (item) => {
    setCartItems((prev) => {
      const existingIndex = prev.findIndex((entry) => entry.inventory_item_id === item.id)
      if (existingIndex >= 0) {
        const updated = [...prev]
        updated[existingIndex] = {
          ...updated[existingIndex],
          quantity: updated[existingIndex].quantity + 1,
        }
        return updated
      }
      return [
        ...prev,
        {
          inventory_item_id: item.id,
          name: item.name,
          sku: item.sku,
          unit_price: Number(item.sale_price) || 0,
          quantity: 1,
          stock_quantity: item.stock_quantity ?? null,
        },
      ]
    })
  }

  const updateQuantity = (inventoryItemId, nextQuantity) => {
    const quantity = Math.max(0, Number(nextQuantity) || 0)
    setCartItems((prev) => {
      if (quantity <= 0) {
        return prev.filter((item) => item.inventory_item_id !== inventoryItemId)
      }
      return prev.map((item) =>
        item.inventory_item_id === inventoryItemId ? { ...item, quantity } : item
      )
    })
  }

  const clearSale = () => {
    setCartItems([])
    setNotes('')
    setSearchQuery('')
    setSearchResults([])
    setCreatedInvoice(null)
    setApiError('')
    setCheckoutProvider(null)
  }

  const ensureCustomer = () => {
    if (customerMode === 'walk_in') {
      return walkInCustomer?.id
    }
    return selectedCustomer?.id || walkInCustomer?.id
  }

  const handleCreateInvoice = async () => {
    setApiError('')
    const customerId = ensureCustomer()
    if (cartItems.length === 0) {
      setApiError('Add at least one item to the cart.')
      return
    }

    setCreatingInvoice(true)
    try {
      const payload = {
        number: createQuickSaleNumber(),
        issue_date: new Date().toISOString().split('T')[0],
        notes: notes.trim() ? `Quick Sale: ${notes.trim()}` : 'Quick Sale',
        items: cartItems.map((item) => ({
          description: item.name,
          sku: item.sku || null,
          inventory_item_id: item.inventory_item_id,
          quantity: item.quantity,
          unit_price: item.unit_price,
        })),
      }
      if (customerId) {
        payload.customer_id = customerId
      }

      const created = await invoiceService.create(payload)
      const invoiceId = created?.id || created?.data?.id
      const fullInvoice = invoiceId ? await invoiceService.getById(invoiceId) : created
      setCreatedInvoice(fullInvoice)
      setCartItems([])
      setSearchQuery('')
      setSearchResults([])
      setNotes('')
      success('Quick sale invoice created')
    } catch (err) {
      setApiError(err.response?.data?.message || 'Failed to create quick sale invoice.')
    } finally {
      setCreatingInvoice(false)
    }
  }

  const handleCheckout = async (provider) => {
    if (!createdInvoice?.id) return
    setCheckoutProvider(provider)
    setApiError('')

    try {
      const response = await invoiceService.createCheckout(createdInvoice.id, provider)
      if (response?.checkout_url) {
        window.location.assign(response.checkout_url)
      } else {
        setApiError('Unable to start checkout. Please try again.')
      }
    } catch (err) {
      setApiError(err.response?.data?.message || 'Unable to start checkout.')
    } finally {
      setCheckoutProvider(null)
    }
  }

  const searchCustomers = async (query) => {
    if (!query || query.length < 2) return []
    const results = await customerService.searchCustomers(query)
    return Array.isArray(results) ? results : results?.data || []
  }

  if (loadingCustomer) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading quick sale..." />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link to="/cp/dashboard" className="text-gray-500 hover:text-gray-700">
            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 className="text-3xl font-bold text-gray-900">Quick Sale</h1>
            <p className="mt-1 text-sm text-gray-500">
              Touch-friendly counter sales for walk-in customers.
            </p>
          </div>
        </div>
        <div className="flex flex-wrap gap-3">
          <Button variant="ghost" size="lg" onClick={clearSale}>
            New Sale
          </Button>
          {createdInvoice?.id ? (
            <Button size="lg" onClick={() => navigate(`/cp/invoices/${createdInvoice.id}`)}>
              View Invoice
            </Button>
          ) : null}
        </div>
      </div>

      {apiError ? (
        <Alert variant="danger" className="mb-4" closable onClose={() => setApiError('')}>
          {apiError}
        </Alert>
      ) : null}

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div className="xl:col-span-2 space-y-6">
          <Card className="p-6">
            <h2 className="text-lg font-semibold text-gray-900">Customer</h2>
            <p className="text-sm text-gray-500 mb-4">
              Skip vehicle details and use a walk-in profile for fast checkout.
            </p>
            <div className="flex flex-wrap gap-3 mb-4">
              <Button
                type="button"
                size="lg"
                variant={customerMode === 'walk_in' ? 'primary' : 'secondary'}
                onClick={() => {
                  setCustomerMode('walk_in')
                  setSelectedCustomer(walkInCustomer)
                }}
              >
                Walk-in Customer
              </Button>
              <Button
                type="button"
                size="lg"
                variant={customerMode === 'existing' ? 'primary' : 'secondary'}
                onClick={() => {
                  setCustomerMode('existing')
                  setSelectedCustomer(null)
                }}
              >
                Select Existing
              </Button>
            </div>
            {customerMode === 'walk_in' ? (
              <div className="rounded-lg border border-dashed border-gray-300 p-4">
                <div className="text-sm text-gray-500">Using customer</div>
                <div className="text-lg font-semibold text-gray-900">
                  {walkInCustomer?.name || 'Walk-in Customer'}
                </div>
              </div>
            ) : (
              <Autocomplete
                label="Find customer"
                placeholder="Search customers..."
                searchFn={searchCustomers}
                itemLabel="name"
                itemSubtext={(item) => item.email || item.phone || 'No contact info'}
                minChars={2}
                onSelect={(item) => setSelectedCustomer(item)}
                className="text-base"
              />
            )}
          </Card>

          <Card className="p-6">
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">Inventory Lookup</h2>
                <p className="text-sm text-gray-500">Search by name, SKU, or barcode.</p>
              </div>
            </div>

            <div className="mt-4">
              <Input
                value={searchQuery}
                onUpdateModelValue={setSearchQuery}
                placeholder="Start typing to search inventory..."
                className="text-lg"
              />
            </div>

            <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {searchingInventory ? (
                <div className="col-span-full text-center text-gray-500">Searching inventory...</div>
              ) : null}
              {!searchingInventory && searchQuery.length >= 2 && searchResults.length === 0 ? (
                <div className="col-span-full text-center text-gray-500">No items found.</div>
              ) : null}
              {searchResults.map((item) => (
                <div
                  key={item.id}
                  className="border border-gray-200 rounded-xl p-4 flex flex-col justify-between shadow-sm"
                >
                  <div>
                    <div className="text-base font-semibold text-gray-900">{item.name}</div>
                    <div className="text-sm text-gray-500">SKU: {item.sku || 'N/A'}</div>
                    <div className="text-sm text-gray-500">Stock: {formatStock(item.stock_quantity)}</div>
                  </div>
                  <div className="mt-3 flex items-center justify-between">
                    <div className="text-lg font-semibold text-gray-900">
                      {formatCurrency(item.sale_price)}
                    </div>
                    <Button size="lg" onClick={() => addItemToCart(item)}>
                      Add
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="p-6">
            <h2 className="text-lg font-semibold text-gray-900">Cart</h2>
            <p className="text-sm text-gray-500 mb-4">Tap quantities to adjust.</p>
            {cartItems.length === 0 ? (
              <div className="text-sm text-gray-500">No items added yet.</div>
            ) : (
              <div className="space-y-4">
                {cartItems.map((item) => (
                  <div key={item.inventory_item_id} className="border-b border-gray-200 pb-4">
                    <div className="flex items-center justify-between">
                      <div>
                        <div className="text-base font-semibold text-gray-900">{item.name}</div>
                        <div className="text-sm text-gray-500">SKU: {item.sku || 'N/A'}</div>
                      </div>
                      <div className="text-base font-semibold text-gray-900">
                        {formatCurrency(item.unit_price * item.quantity)}
                      </div>
                    </div>
                    <div className="mt-3 flex items-center gap-3">
                      <Button
                        size="lg"
                        variant="secondary"
                        onClick={() => updateQuantity(item.inventory_item_id, item.quantity - 1)}
                      >
                        −
                      </Button>
                      <Input
                        type="number"
                        min="0"
                        value={item.quantity}
                        onUpdateModelValue={(value) => updateQuantity(item.inventory_item_id, value)}
                        className="w-24 text-center text-lg"
                      />
                      <Button
                        size="lg"
                        variant="secondary"
                        onClick={() => updateQuantity(item.inventory_item_id, item.quantity + 1)}
                      >
                        +
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            <div className="mt-6 border-t pt-4">
              <div className="flex items-center justify-between text-base font-semibold text-gray-900">
                <span>Total</span>
                <span>{formatCurrency(cartSubtotal)}</span>
              </div>
            </div>

            <div className="mt-6">
              <Input
                label="Sale Notes"
                value={notes}
                onUpdateModelValue={setNotes}
                placeholder="Optional notes for the receipt"
              />
            </div>

            <div className="mt-6 space-y-3">
              <Button size="lg" fullWidth loading={creatingInvoice} onClick={handleCreateInvoice}>
                Complete Sale
              </Button>
              <Button size="lg" fullWidth variant="ghost" onClick={() => navigate('/cp/invoices')}>
                View All Invoices
              </Button>
            </div>
          </Card>

          {createdInvoice ? (
            <Card className="p-6">
              <h2 className="text-lg font-semibold text-gray-900">Collect Payment</h2>
              <p className="text-sm text-gray-500 mb-4">
                Use the existing checkout flows to accept payment.
              </p>
              {paymentProviders.length === 0 ? (
                <div className="text-sm text-gray-500">
                  No payment gateways are configured. Update payment settings to enable checkout.
                </div>
              ) : (
                <div className="space-y-3">
                  {paymentProviders.map((provider) => (
                    <Button
                      key={provider}
                      size="lg"
                      fullWidth
                      variant="secondary"
                      loading={checkoutProvider === provider}
                      onClick={() => handleCheckout(provider)}
                    >
                      Pay with {provider.charAt(0).toUpperCase() + provider.slice(1)}
                    </Button>
                  ))}
                </div>
              )}
            </Card>
          ) : null}
        </div>
      </div>
    </div>
  )
}
