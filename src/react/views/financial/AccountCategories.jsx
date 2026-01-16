import { useEffect, useState } from 'react'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Select from '../../components/ui/Select'
import financialService from '../../../services/financial.service'
import { useToast } from '../../stores/toast.jsx'

const accountTypeOptions = [
  { label: 'Asset', value: 'asset' },
  { label: 'Liability', value: 'liability' },
  { label: 'Income', value: 'income' },
  { label: 'Expense', value: 'expense' },
  { label: 'Equity', value: 'equity' },
]

const emptyForm = {
  id: null,
  name: '',
  type: 'asset',
}

export default function AccountCategories() {
  const toast = useToast()
  const [categories, setCategories] = useState([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [filters, setFilters] = useState({ type: '' })
  const [form, setForm] = useState({ ...emptyForm })

  useEffect(() => {
    loadCategories()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.type])

  const loadCategories = () => {
    setLoading(true)
    const params = filters.type ? { type: filters.type } : {}
    financialService
      .listCategories(params)
      .then((data) => {
        setCategories(data || [])
      })
      .catch(() => toast.error('Failed to load account categories'))
      .finally(() => setLoading(false))
  }

  const resetForm = () => {
    setForm({ ...emptyForm })
  }

  const handleEdit = (category) => {
    setForm({ id: category.id, name: category.name, type: category.type })
  }

  const handleDelete = (category) => {
    if (!window.confirm(`Delete ${category.name}?`)) return
    financialService
      .deleteCategory(category.id)
      .then(() => {
        toast.success('Category deleted')
        loadCategories()
        if (form.id === category.id) {
          resetForm()
        }
      })
      .catch(() => toast.error('Failed to delete category'))
  }

  const saveCategory = () => {
    if (!form.name.trim()) {
      toast.error('Category name is required')
      return
    }
    setSaving(true)
    const payload = { name: form.name.trim(), type: form.type }
    const request = form.id
      ? financialService.updateCategory(form.id, payload)
      : financialService.createCategory(payload)

    request
      .then(() => {
        toast.success('Category saved')
        resetForm()
        loadCategories()
      })
      .catch(() => toast.error('Failed to save category'))
      .finally(() => setSaving(false))
  }

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Account Categories</h1>
        <p className="text-sm text-gray-600">Maintain the chart of accounts used for financial reporting.</p>
      </div>

      <Card className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Name</label>
            <Input
              modelValue={form.name}
              placeholder="e.g., Operating Cash"
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Account Type</label>
            <Select
              modelValue={form.type}
              options={accountTypeOptions}
              placeholder={null}
              onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, type: value }))}
            />
          </div>
          <div className="flex items-end gap-2">
            <Button onClick={saveCategory} disabled={saving}>
              {form.id ? 'Update Category' : 'Add Category'}
            </Button>
            <Button variant="secondary" onClick={resetForm} disabled={saving}>
              Clear
            </Button>
          </div>
        </div>
      </Card>

      <Card className="space-y-4">
        <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Filter by Type</label>
            <Select
              modelValue={filters.type}
              options={[{ label: 'All Types', value: '' }, ...accountTypeOptions]}
              placeholder={null}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, type: value }))}
            />
          </div>
          <Button variant="secondary" onClick={loadCategories}>Refresh</Button>
        </div>

        {loading ? <Loading /> : null}

        <div className="hidden md:block">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {categories.map((category) => (
                <tr key={category.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2 text-sm text-gray-900">{category.name}</td>
                  <td className="px-4 py-2 text-sm capitalize">{category.type}</td>
                  <td className="px-4 py-2 text-right text-sm space-x-2">
                    <button className="text-blue-600 hover:underline" onClick={() => handleEdit(category)}>
                      Edit
                    </button>
                    <button className="text-red-600 hover:underline" onClick={() => handleDelete(category)}>
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
              {!categories.length && !loading ? (
                <tr>
                  <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={3}>
                    No categories found.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        {categories.length ? (
          <div className="space-y-3 md:hidden">
            {categories.map((category) => (
              <div key={category.id} className="rounded border border-gray-200 bg-gray-50 p-3 shadow-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold text-gray-900">{category.name}</p>
                    <p className="text-xs text-gray-600 capitalize">{category.type}</p>
                  </div>
                  <div className="flex gap-3 text-sm">
                    <button className="text-blue-600 font-semibold" onClick={() => handleEdit(category)}>
                      Edit
                    </button>
                    <button className="text-red-600 font-semibold" onClick={() => handleDelete(category)}>
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : null}

        {!categories.length && !loading ? (
          <div className="p-4 text-center text-sm text-gray-500">No categories found.</div>
        ) : null}
      </Card>
    </div>
  )
}
