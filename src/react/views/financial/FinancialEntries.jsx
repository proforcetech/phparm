import { useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import financialService from '../../../services/financial.service'
import inventoryMetaService from '../../../services/inventory-meta.service'
import { useToast } from '../../stores/toast.jsx'

const defaultFilters = {
  type: '',
  category: '',
  vendor: '',
  start_date: '',
  end_date: '',
  search: '',
  page: 1,
  per_page: 25,
}

const emptyForm = {
  id: null,
  type: 'expense',
  category: '',
  reference: '',
  purchase_order: '',
  vendor: '',
  amount: 0,
  entry_date: '',
  description: '',
  attachment_path: null,
}

export default function FinancialEntries() {
  const toast = useToast()
  const [entries, setEntries] = useState([])
  const [loading, setLoading] = useState(false)
  const [showForm, setShowForm] = useState(false)
  const [hasMore, setHasMore] = useState(false)
  const [categoryOptions, setCategoryOptions] = useState([])
  const [vendorOptions, setVendorOptions] = useState([])
  const [lookupsLoading, setLookupsLoading] = useState({ categories: false, vendors: false })
  const [lookupError, setLookupError] = useState({ categories: '', vendors: '' })
  const [filters, setFilters] = useState({ ...defaultFilters })
  const [form, setForm] = useState({ ...emptyForm })
  const [pendingFile, setPendingFile] = useState(null)
  const [removeAttachment, setRemoveAttachment] = useState(false)

  useEffect(() => {
    fetchEntries()
  }, [])

  useEffect(() => {
    loadLookups()
  }, [])

  const loadLookups = () => {
    loadLookup('categories', setCategoryOptions)
    loadLookup('vendors', setVendorOptions)
  }

  const loadLookup = async (type, setTarget) => {
    setLookupsLoading((prev) => ({ ...prev, [type]: true }))
    setLookupError((prev) => ({ ...prev, [type]: '' }))
    try {
      const params = type === 'vendors' ? { parts_supplier: true } : {}
      const data = await inventoryMetaService.list(type, params)
      setTarget(data.map((item) => ({ label: item.name, value: item.name })))
    } catch (err) {
      console.error(err)
      setLookupError((prev) => ({ ...prev, [type]: 'Unable to load options' }))
    } finally {
      setLookupsLoading((prev) => ({ ...prev, [type]: false }))
    }
  }

  const fetchEntries = (nextFilters = filters) => {
    setLoading(true)
    financialService
      .list(nextFilters)
      .then((res) => {
        setEntries(res.data || [])
        setHasMore((res.data || []).length === nextFilters.per_page)
      })
      .catch(() => toast.error('Failed to load entries'))
      .finally(() => {
        setLoading(false)
      })
  }

  const resetFilters = () => {
    const nextFilters = { ...defaultFilters }
    setFilters(nextFilters)
    fetchEntries(nextFilters)
  }

  const changePage = (page) => {
    const nextFilters = { ...filters, page: Math.max(1, page) }
    setFilters(nextFilters)
    fetchEntries(nextFilters)
  }

  const handleFileChange = (event) => {
    const file = event.target?.files?.[0]
    setPendingFile(file || null)
    if (file) {
      setRemoveAttachment(false)
    }
  }

  const markAttachmentRemoval = () => {
    setRemoveAttachment(true)
    setPendingFile(null)
  }

  const openForm = (entry = null) => {
    if (entry) {
      setForm({ ...entry })
      setPendingFile(null)
      setRemoveAttachment(false)
    } else {
      setForm({ ...emptyForm })
      setPendingFile(null)
      setRemoveAttachment(false)
    }
    setShowForm(true)
  }

  const closeForm = () => {
    setShowForm(false)
  }

  const saveEntry = async () => {
    const payload = { ...form }
    try {
      const saved = payload.id
        ? await financialService.update(payload.id, payload)
        : await financialService.create(payload)

      const entryId = saved.id || payload.id

      if (removeAttachment && entryId) {
        await financialService.removeAttachment(entryId)
        setForm((prev) => ({ ...prev, attachment_path: null }))
      }

      if (pendingFile && entryId) {
        const uploaded = await financialService.uploadAttachment(entryId, pendingFile)
        setForm((prev) => ({ ...prev, attachment_path: uploaded.path }))
      }

      toast.success('Entry saved')
      setShowForm(false)
      fetchEntries()
    } catch (err) {
      console.error(err)
      toast.error('Failed to save entry')
    }
  }

  const confirmDelete = (entry) => {
    if (!window.confirm('Delete this entry?')) return
    financialService
      .destroy(entry.id)
      .then(() => {
        toast.success('Entry deleted')
        fetchEntries()
      })
      .catch(() => toast.error('Failed to delete entry'))
  }

  const exportEntries = () => {
    financialService
      .exportEntries(filters)
      .then((res) => {
        const rows = res.data
        if (!rows || !rows.length) {
          toast.info('No data to export')
          return
        }
        const header = Object.keys(rows[0])
        const csvRows = [header.join(',')]
        rows.forEach((row) => {
          csvRows.push(header.map((key) => `"${(row[key] ?? '').toString().replace('"', '""')}"`).join(','))
        })
        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', res.filename || 'financial-entries.csv')
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)
      })
      .catch(() => toast.error('Failed to export entries'))
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Purchases & Expenses</h1>
          <p className="text-sm text-gray-600">Track vendor spend, references, and categories with CSV export.</p>
        </div>
        <Button onClick={() => openForm()}>Add Entry</Button>
      </div>

      <Card className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Type</label>
            <Select
              modelValue={filters.type}
              options={[
                { label: 'All', value: '' },
                { label: 'Purchase', value: 'purchase' },
                { label: 'Expense', value: 'expense' },
                { label: 'Income', value: 'income' },
              ]}
              placeholder={null}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, type: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Category</label>
            <Select
              modelValue={filters.category}
              options={[{ label: 'All', value: '' }, ...categoryOptions]}
              placeholder={null}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, category: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Vendor</label>
            <Select
              modelValue={filters.vendor}
              options={[{ label: 'All', value: '' }, ...vendorOptions]}
              placeholder={null}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, vendor: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Start Date</label>
            <Input
              modelValue={filters.start_date}
              type="date"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, start_date: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">End Date</label>
            <Input
              modelValue={filters.end_date}
              type="date"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, end_date: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Search</label>
            <Input
              modelValue={filters.search}
              placeholder="Vendor, reference, PO"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, search: value }))}
            />
          </div>
        </div>
        <div className="flex gap-3">
          <Button onClick={fetchEntries}>Apply Filters</Button>
          <Button variant="secondary" onClick={resetFilters}>Reset</Button>
          <Button variant="secondary" className="ml-auto" onClick={exportEntries}>Export CSV</Button>
        </div>
      </Card>

      <Card>
        <div className="hidden md:block">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PO</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {entries.map((entry) => (
                <tr key={entry.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2 text-sm text-gray-900">{entry.entry_date}</td>
                  <td className="px-4 py-2 text-sm capitalize">{entry.type}</td>
                  <td className="px-4 py-2 text-sm">{entry.category}</td>
                  <td className="px-4 py-2 text-sm">{entry.vendor}</td>
                  <td className="px-4 py-2 text-sm">{entry.reference}</td>
                  <td className="px-4 py-2 text-sm">{entry.purchase_order}</td>
                  <td className="px-4 py-2 text-sm text-right font-semibold">${Number(entry.amount).toFixed(2)}</td>
                  <td className="px-4 py-2 text-right text-sm space-x-2">
                    <button className="text-blue-600 hover:underline" onClick={() => openForm(entry)}>Edit</button>
                    <button className="text-red-600 hover:underline" onClick={() => confirmDelete(entry)}>Delete</button>
                  </td>
                </tr>
              ))}
              {!entries.length && !loading ? (
                <tr>
                  <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={8}>No entries found.</td>
                </tr>
              ) : null}
              {loading ? (
                <tr>
                  <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={8}>Loading...</td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        {entries.length ? (
          <div className="space-y-3 p-4 md:hidden">
            {entries.map((entry) => (
              <div key={entry.id} className="rounded border border-gray-200 bg-gray-50 p-3 shadow-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold text-gray-900">{entry.vendor || 'Unknown vendor'}</p>
                    <p className="text-xs text-gray-600">{entry.entry_date} • {entry.category || 'Uncategorized'}</p>
                  </div>
                  <span className="text-sm font-semibold text-gray-900">${Number(entry.amount).toFixed(2)}</span>
                </div>
                <div className="mt-2 text-xs text-gray-700 space-y-1">
                  <div className="capitalize">Type: {entry.type}</div>
                  <div>Reference: {entry.reference || '—'}</div>
                  <div>PO: {entry.purchase_order || '—'}</div>
                  <div>Description: {entry.description || '—'}</div>
                </div>
                <div className="mt-3 flex gap-3 text-sm">
                  <button className="text-blue-600 font-semibold" onClick={() => openForm(entry)}>Edit</button>
                  <button className="text-red-600 font-semibold" onClick={() => confirmDelete(entry)}>Delete</button>
                </div>
              </div>
            ))}
          </div>
        ) : null}
        {!entries.length && loading ? (
          <div className="p-4 text-center text-sm text-gray-500">Loading...</div>
        ) : null}
        {!entries.length && !loading ? (
          <div className="p-4 text-center text-sm text-gray-500">No entries found.</div>
        ) : null}

        <div className="flex items-center justify-between px-4 py-3 bg-gray-50">
          <div className="text-sm text-gray-600">Page {filters.page}</div>
          <div className="space-x-2">
            <button
              className="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
              disabled={filters.page === 1 || loading}
              onClick={() => changePage(filters.page - 1)}
            >
              Previous
            </button>
            <button
              className="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
              disabled={!hasMore || loading}
              onClick={() => changePage(filters.page + 1)}
            >
              Next
            </button>
          </div>
        </div>
      </Card>

      {showForm ? (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-40">
          <div className="bg-white rounded shadow-lg w-full max-w-2xl p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-lg font-semibold">{form.id ? 'Edit Entry' : 'Add Entry'}</h2>
              <button className="text-gray-500 hover:text-gray-700" onClick={closeForm}>✕</button>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Type</label>
                <Select
                  modelValue={form.type}
                  options={[
                    { label: 'Purchase', value: 'purchase' },
                    { label: 'Expense', value: 'expense' },
                    { label: 'Income', value: 'income' },
                  ]}
                  placeholder={null}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, type: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Category</label>
                <Select
                  modelValue={form.category}
                  options={[{ label: 'Select category', value: '' }, ...categoryOptions]}
                  placeholder={null}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, category: value }))}
                />
                {lookupsLoading.categories ? (
                  <p className="mt-1 text-xs text-gray-500">Loading categories...</p>
                ) : null}
                {!lookupsLoading.categories && lookupError.categories ? (
                  <p className="mt-1 text-xs text-red-600">{lookupError.categories}</p>
                ) : null}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Reference</label>
                <Input
                  modelValue={form.reference}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, reference: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Purchase Order</label>
                <Input
                  modelValue={form.purchase_order}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, purchase_order: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Vendor</label>
                <Select
                  modelValue={form.vendor}
                  options={[{ label: 'Select vendor', value: '' }, ...vendorOptions]}
                  placeholder={null}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, vendor: value }))}
                />
                {lookupsLoading.vendors ? (
                  <p className="mt-1 text-xs text-gray-500">Loading vendors...</p>
                ) : null}
                {!lookupsLoading.vendors && lookupError.vendors ? (
                  <p className="mt-1 text-xs text-red-600">{lookupError.vendors}</p>
                ) : null}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Amount</label>
                <Input
                  modelValue={form.amount}
                  type="number"
                  step="0.01"
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, amount: Number(value) }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Entry Date</label>
                <Input
                  modelValue={form.entry_date}
                  type="date"
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, entry_date: value }))}
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Description</label>
                <textarea
                  value={form.description}
                  onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
                  className="mt-1 w-full border-gray-300 rounded"
                  rows={2}
                />
              </div>
            </div>
            <div className="space-y-2">
              <label className="block text-sm font-medium text-gray-700">Attachment</label>
              <div className="flex flex-col gap-2">
                <input
                  type="file"
                  accept="application/pdf,image/png,image/jpeg"
                  onChange={handleFileChange}
                />
                {form.attachment_path && !removeAttachment ? (
                  <div className="flex items-center gap-3 text-sm text-gray-700">
                    <a href={form.attachment_path} className="text-blue-600 hover:underline" target="_blank" rel="noopener">View current file</a>
                    <button className="text-red-600 hover:underline" type="button" onClick={markAttachmentRemoval}>Remove</button>
                  </div>
                ) : null}
                {removeAttachment ? <div className="text-sm text-gray-500">Attachment will be removed</div> : null}
                {!removeAttachment && pendingFile ? <div className="text-sm text-gray-700">{pendingFile.name}</div> : null}
                <p className="text-xs text-gray-500">PDF or image files only.</p>
              </div>
            </div>
            <div className="flex justify-end space-x-3">
              <button className="px-4 py-2 bg-gray-100 rounded" onClick={closeForm}>Cancel</button>
              <button className="px-4 py-2 bg-blue-600 text-white rounded" onClick={saveEntry}>
                {form.id ? 'Update' : 'Create'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
