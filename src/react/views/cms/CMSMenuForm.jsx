import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon } from '@heroicons/react/24/outline'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Textarea from '../../components/ui/Textarea'
import { useCmsMenuStore } from '../../stores/cmsMenus'
import { useToast } from '../../stores/toast.jsx'

const createDefaultForm = () => ({
  name: '',
  location: '',
  description: '',
  items: '[]',
  is_published: false,
})

export default function CMSMenuForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const menuStore = useCmsMenuStore()
  const toast = useToast()

  const isEditing = useMemo(() => !!id && id !== 'create', [id])

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [validationErrors, setValidationErrors] = useState([])
  const [form, setForm] = useState(createDefaultForm())

  const loadMenu = useCallback(async () => {
    try {
      setError(null)
      const data = await menuStore.fetchMenu(id)
      setForm({
        ...createDefaultForm(),
        ...data,
        items: typeof data.items === 'string' ? data.items : JSON.stringify(data.items || []),
        is_published: !!data.is_published,
      })
    } catch (err) {
      console.error('Failed to load menu:', err)
      setError(err.response?.data?.message || 'Failed to load menu')
    }
  }, [id, menuStore])

  useEffect(() => {
    if (isEditing) {
      loadMenu()
    }
  }, [isEditing, loadMenu])

  const validateForm = () => {
    const errors = []
    if (!form.name) errors.push('Name is required')
    if (!form.location) errors.push('Location is required')
    try {
      JSON.parse(form.items || '[]')
    } catch (err) {
      errors.push('Menu items must be valid JSON')
    }
    return errors
  }

  const saveMenu = async (event) => {
    event.preventDefault()

    try {
      setSaving(true)
      setError(null)
      const errors = validateForm()
      setValidationErrors(errors)

      if (errors.length) {
        throw new Error(errors.join(', '))
      }

      const payload = {
        ...form,
        items: JSON.parse(form.items || '[]'),
      }

      if (isEditing) {
        await menuStore.updateMenu(id, payload)
        toast.success('Menu updated')
      } else {
        const newMenu = await menuStore.createMenu(payload)
        toast.success('Menu created')
        navigate(`/cp/cms/menus/${newMenu.id}`)
        return
      }
    } catch (err) {
      console.error('Failed to save menu:', err)
      const message = err.response?.data?.message || err.message || 'Failed to save menu'
      setError(message)
      if (!validationErrors.length) {
        setValidationErrors([message])
      }
    } finally {
      setSaving(false)
    }
  }

  const publishMenu = async () => {
    try {
      setSaving(true)
      setError(null)
      const errors = validateForm()
      setValidationErrors(errors)

      if (errors.length) {
        throw new Error(errors.join(', '))
      }

      const payload = {
        ...form,
        is_published: true,
        items: JSON.parse(form.items || '[]'),
      }

      if (isEditing) {
        await menuStore.publishMenu(id)
        toast.success('Menu published')
      } else {
        const newMenu = await menuStore.createMenu(payload)
        toast.success('Menu created and published')
        navigate(`/cp/cms/menus/${newMenu.id}`)
        return
      }
    } catch (err) {
      console.error('Failed to publish menu:', err)
      const message = err.response?.data?.message || err.message || 'Failed to publish menu'
      setError(message)
      if (!validationErrors.length) {
        setValidationErrors([message])
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="mb-8 flex items-center gap-4">
        <Button variant="ghost" onClick={() => navigate('/cp/cms/menus')}>
          <ArrowLeftIcon className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {isEditing ? 'Edit Menu' : 'Create Menu'}
          </h1>
          <p className="mt-1 text-sm text-gray-500">Configure navigation links and publish when ready</p>
        </div>
      </div>

      {menuStore.loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading menu..." />
        </div>
      ) : error ? (
        <Alert variant="danger" className="mb-6">
          {error}
        </Alert>
      ) : (
        <form className="grid grid-cols-1 gap-6 lg:grid-cols-3" onSubmit={saveMenu}>
          <div className="lg:col-span-2 space-y-6">
            <Card header={<h3 className="text-lg font-medium text-gray-900">Menu Details</h3>}>
              <div className="space-y-4">
                <Input
                  label="Name *"
                  required
                  placeholder="Main navigation"
                  value={form.name}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, name: value }))}
                />

                <Input
                  label="Location / Key *"
                  required
                  placeholder="header"
                  value={form.location}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, location: value }))}
                />

                <Input
                  label="Description"
                  placeholder="Shown in site header"
                  value={form.description}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, description: value }))}
                />

                <Textarea
                  label="Menu Items (JSON)"
                  rows={8}
                  placeholder='[{ "label": "Home", "url": "/" }]'
                  value={form.items}
                  onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, items: value }))}
                  helperText="Provide an array of menu item objects with label and url."
                />
              </div>
            </Card>
          </div>

          <div className="space-y-6">
            <Card header={<h3 className="text-lg font-medium text-gray-900">Publish</h3>}>
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <span className="text-sm text-gray-700">Status</span>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      className="sr-only peer"
                      checked={form.is_published}
                      onChange={(event) =>
                        setForm((prev) => ({ ...prev, is_published: event.target.checked }))
                      }
                    />
                    <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
                    <span className={`ml-2 text-sm font-medium ${form.is_published ? 'text-green-600' : 'text-gray-500'}`}>
                      {form.is_published ? 'Published' : 'Draft'}
                    </span>
                  </label>
                </div>

                <div>
                  {validationErrors.length ? (
                    <Alert variant="warning" className="mb-2">
                      <ul className="list-disc pl-5">
                        {validationErrors.map((message) => (
                          <li key={message}>{message}</li>
                        ))}
                      </ul>
                    </Alert>
                  ) : null}
                  <div className="flex flex-col gap-3">
                    <Button type="submit" disabled={saving}>
                      {saving ? 'Saving...' : isEditing ? 'Update Menu' : 'Create Menu'}
                    </Button>
                    {!form.is_published ? (
                      <Button
                        type="button"
                        variant="secondary"
                        disabled={saving}
                        onClick={publishMenu}
                      >
                        {saving ? 'Publishing...' : 'Save & Publish'}
                      </Button>
                    ) : null}
                  </div>
                </div>
              </div>
            </Card>
          </div>
        </form>
      )}
    </div>
  )
}
