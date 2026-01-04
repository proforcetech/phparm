import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeftIcon,
  Bars3Icon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/react/24/outline'
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import { useCmsMenuStore } from '../../stores/cmsMenus'
import { useToast } from '../../stores/toast.jsx'

const createDefaultForm = () => ({
  name: '',
  location: '',
  description: '',
  items: [],
  is_published: false,
})

const createMenuItemKey = () => `menu-item-${Math.random().toString(36).slice(2, 10)}-${Date.now()}`

const normalizeMenuItems = (items = []) =>
  items.map((item) => {
    const children = Array.isArray(item.children) ? item.children : []
    return {
      ...item,
      _key: item._key || createMenuItemKey(),
      label: item.label ?? '',
      url: item.url ?? '',
      children: normalizeMenuItems(children),
    }
  })

const parseMenuItems = (items) => {
  if (Array.isArray(items)) {
    return items
  }

  if (typeof items === 'string' && items.trim()) {
    try {
      return JSON.parse(items)
    } catch (err) {
      return []
    }
  }

  return []
}

const serializeMenuItems = (items = []) =>
  items.map((item) => {
    const { _key, children, ...rest } = item
    const next = { ...rest }
    if (Array.isArray(children)) {
      next.children = serializeMenuItems(children)
    }
    return next
  })

const updateMenuItem = (items, key, updater) =>
  items.map((item) => {
    if (item._key === key) {
      return updater(item)
    }

    if (item.children?.length) {
      return {
        ...item,
        children: updateMenuItem(item.children, key, updater),
      }
    }

    return item
  })

const removeMenuItem = (items, key) =>
  items.reduce((acc, item) => {
    if (item._key === key) {
      return acc
    }

    const next = item.children?.length
      ? { ...item, children: removeMenuItem(item.children, key) }
      : item

    acc.push(next)
    return acc
  }, [])

const replaceMenuChildren = (items, parentKey, nextChildren) =>
  updateMenuItem(items, parentKey, (item) => ({
    ...item,
    children: nextChildren,
  }))

const hasIncompleteMenuItems = (items = []) =>
  items.some(
    (item) =>
      !item.label?.trim()
      || !item.url?.trim()
      || (item.children?.length ? hasIncompleteMenuItems(item.children) : false)
  )

function SortableMenuItem({
  item,
  depth,
  onUpdateItem,
  onRemoveItem,
  onAddChild,
  onChildrenChange,
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: item._key,
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`rounded-lg border border-gray-200 bg-white p-4 shadow-sm ${
        isDragging ? 'ring-2 ring-primary-300' : ''
      }`}
    >
      <div className="flex flex-col gap-4 md:flex-row md:items-start">
        <button
          type="button"
          className="mt-1 flex h-9 w-9 items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:bg-gray-50"
          aria-label="Drag to reorder"
          {...attributes}
          {...listeners}
        >
          <Bars3Icon className="h-5 w-5" />
        </button>
        <div className="grid flex-1 grid-cols-1 gap-3 md:grid-cols-2">
          <Input
            label={depth === 0 ? 'Label' : 'Child label'}
            placeholder="Menu item label"
            value={item.label}
            onUpdateModelValue={(value) => onUpdateItem(item._key, { label: value })}
          />
          <Input
            label={depth === 0 ? 'URL' : 'Child URL'}
            placeholder="/path"
            value={item.url}
            onUpdateModelValue={(value) => onUpdateItem(item._key, { url: value })}
          />
        </div>
        <div className="flex flex-wrap gap-2 md:flex-col">
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => onAddChild(item._key)}
          >
            <PlusIcon className="mr-1 h-4 w-4" />
            Add Child
          </Button>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="text-red-600 hover:text-red-700"
            onClick={() => onRemoveItem(item._key)}
          >
            <TrashIcon className="mr-1 h-4 w-4" />
            Remove
          </Button>
        </div>
      </div>

      {item.children?.length ? (
        <div className="mt-4 border-l border-dashed border-gray-200 pl-4">
          <MenuItemList
            items={item.children}
            depth={depth + 1}
            onItemsChange={(nextChildren) => onChildrenChange(item._key, nextChildren)}
            onUpdateItem={onUpdateItem}
            onRemoveItem={onRemoveItem}
            onAddChild={onAddChild}
            onChildrenChange={onChildrenChange}
          />
        </div>
      ) : null}
    </div>
  )
}

function MenuItemList({
  items,
  depth,
  onItemsChange,
  onUpdateItem,
  onRemoveItem,
  onAddChild,
  onChildrenChange,
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  const handleDragEnd = (event) => {
    const { active, over } = event
    if (!over || active.id === over.id) {
      return
    }

    const oldIndex = items.findIndex((item) => item._key === active.id)
    const newIndex = items.findIndex((item) => item._key === over.id)

    if (oldIndex === -1 || newIndex === -1) {
      return
    }

    onItemsChange(arrayMove(items, oldIndex, newIndex))
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
      <SortableContext items={items.map((item) => item._key)} strategy={verticalListSortingStrategy}>
        <div className="space-y-4">
          {items.map((item) => (
            <SortableMenuItem
              key={item._key}
              item={item}
              depth={depth}
              onUpdateItem={onUpdateItem}
              onRemoveItem={onRemoveItem}
              onAddChild={onAddChild}
              onChildrenChange={onChildrenChange}
            />
          ))}
        </div>
      </SortableContext>
    </DndContext>
  )
}

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
      const parsedItems = parseMenuItems(data.items || [])
      setForm({
        ...createDefaultForm(),
        ...data,
        items: normalizeMenuItems(parsedItems),
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

  const handleAddRootItem = () => {
    setForm((prev) => ({
      ...prev,
      items: [
        ...prev.items,
        {
          _key: createMenuItemKey(),
          label: '',
          url: '',
          children: [],
        },
      ],
    }))
  }

  const handleUpdateItem = useCallback((key, updates) => {
    setForm((prev) => ({
      ...prev,
      items: updateMenuItem(prev.items, key, (item) => ({
        ...item,
        ...updates,
      })),
    }))
  }, [])

  const handleRemoveItem = useCallback((key) => {
    setForm((prev) => ({
      ...prev,
      items: removeMenuItem(prev.items, key),
    }))
  }, [])

  const handleAddChild = useCallback((parentKey) => {
    setForm((prev) => ({
      ...prev,
      items: updateMenuItem(prev.items, parentKey, (item) => ({
        ...item,
        children: [
          ...(Array.isArray(item.children) ? item.children : []),
          {
            _key: createMenuItemKey(),
            label: '',
            url: '',
            children: [],
          },
        ],
      })),
    }))
  }, [])

  const handleReplaceChildren = useCallback((parentKey, nextChildren) => {
    setForm((prev) => ({
      ...prev,
      items: replaceMenuChildren(prev.items, parentKey, nextChildren),
    }))
  }, [])

  const validateForm = () => {
    const errors = []
    if (!form.name) errors.push('Name is required')
    if (!form.location) errors.push('Location is required')
    if (!Array.isArray(form.items)) {
      errors.push('Menu items must be a list')
    } else if (hasIncompleteMenuItems(form.items)) {
      errors.push('Each menu item needs a label and URL')
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
        items: serializeMenuItems(form.items),
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
        items: serializeMenuItems(form.items),
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
              </div>
            </Card>

            <Card header={<h3 className="text-lg font-medium text-gray-900">Menu Items</h3>}>
              <div className="space-y-4">
                <p className="text-sm text-gray-500">
                  Drag and drop items to reorder. Add child links to create nested navigation.
                </p>
                {form.items.length ? (
                  <MenuItemList
                    items={form.items}
                    depth={0}
                    onItemsChange={(nextItems) => setForm((prev) => ({ ...prev, items: nextItems }))}
                    onUpdateItem={handleUpdateItem}
                    onRemoveItem={handleRemoveItem}
                    onAddChild={handleAddChild}
                    onChildrenChange={handleReplaceChildren}
                  />
                ) : (
                  <div className="rounded-lg border border-dashed border-gray-200 p-6 text-center text-sm text-gray-500">
                    No menu items yet. Add your first link below.
                  </div>
                )}
                <Button type="button" variant="secondary" onClick={handleAddRootItem}>
                  <PlusIcon className="mr-2 h-5 w-5" />
                  Add Menu Item
                </Button>
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
