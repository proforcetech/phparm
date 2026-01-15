import { useCallback, useEffect, useState } from 'react'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Loading from '../../components/ui/Loading'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import towingPricingService from '../../../services/towingPricing.service'

const feeFields = [
  { key: 'rollout_fee', label: 'Rollout Fee', description: 'Dispatch/response fee' },
  { key: 'hookup_fee', label: 'Hookup Fee', description: 'Vehicle hookup fee' },
  { key: 'onhook_fee', label: 'On-hook Fee', description: 'Loading vehicle onto truck' },
  { key: 'mileage_rate', label: 'Mileage Rate', description: 'Per-mile rate (loaded)' },
  { key: 'deadhead_rate', label: 'Deadhead Rate', description: 'Per-mile rate (unloaded)' },
  { key: 'accident_cleanup_fee', label: 'Accident Cleanup', description: 'Accident scene cleanup' },
  { key: 'winch_fee', label: 'Winch Fee', description: 'Winching operations' },
  { key: 'storage_daily_rate', label: 'Storage Rate', description: 'Daily storage rate' },
  { key: 'after_hours_multiplier', label: 'After Hours Mult.', description: 'After-hours multiplier' },
  { key: 'minimum_charge', label: 'Minimum Charge', description: 'Minimum service charge' },
]

export default function TowingPricingMatrix() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [serviceClasses, setServiceClasses] = useState([])
  const [serviceTypes, setServiceTypes] = useState([])
  const [matrixData, setMatrixData] = useState({})
  const [allPrices, setAllPrices] = useState([])
  const [editModal, setEditModal] = useState({ open: false, classId: null, typeId: null })
  const [editForm, setEditForm] = useState({})
  const [manageModal, setManageModal] = useState({ open: false, type: null })
  const [manageForm, setManageForm] = useState({})
  const [manageList, setManageList] = useState([])

  const loadMatrix = useCallback(async () => {
    setLoading(true)
    try {
      const data = await towingPricingService.getFullMatrix()
      setServiceClasses(data.classes || [])
      setServiceTypes(data.types || [])
      setMatrixData(data.matrix || {})
      setAllPrices(data.all_prices || [])
    } catch (error) {
      console.error('Failed to load pricing matrix', error)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadMatrix()
  }, [loadMatrix])

  const getMatrixEntry = (classId, typeId) => {
    return matrixData[classId]?.[typeId] || null
  }

  const openEditModal = (classId, typeId) => {
    const existing = getMatrixEntry(classId, typeId)
    const serviceClass = serviceClasses.find(c => c.id === classId)
    const serviceType = serviceTypes.find(t => t.id === typeId)

    setEditForm({
      service_class_id: classId,
      service_type_id: typeId,
      service_class_name: serviceClass?.name || '',
      service_type_name: serviceType?.name || '',
      rollout_fee: existing?.rollout_fee ?? 0,
      hookup_fee: existing?.hookup_fee ?? 0,
      onhook_fee: existing?.onhook_fee ?? 0,
      mileage_rate: existing?.mileage_rate ?? 0,
      deadhead_rate: existing?.deadhead_rate ?? 0,
      accident_cleanup_fee: existing?.accident_cleanup_fee ?? 0,
      winch_fee: existing?.winch_fee ?? 0,
      storage_daily_rate: existing?.storage_daily_rate ?? 0,
      after_hours_multiplier: existing?.after_hours_multiplier ?? 1.0,
      minimum_charge: existing?.minimum_charge ?? 0,
      is_active: existing?.is_active ?? true,
      notes: existing?.notes ?? '',
    })
    setEditModal({ open: true, classId, typeId })
  }

  const saveMatrixEntry = async () => {
    setSaving(true)
    try {
      await towingPricingService.upsertPriceMatrix({
        service_class_id: editForm.service_class_id,
        service_type_id: editForm.service_type_id,
        rollout_fee: parseFloat(editForm.rollout_fee) || 0,
        hookup_fee: parseFloat(editForm.hookup_fee) || 0,
        onhook_fee: parseFloat(editForm.onhook_fee) || 0,
        mileage_rate: parseFloat(editForm.mileage_rate) || 0,
        deadhead_rate: parseFloat(editForm.deadhead_rate) || 0,
        accident_cleanup_fee: parseFloat(editForm.accident_cleanup_fee) || 0,
        winch_fee: parseFloat(editForm.winch_fee) || 0,
        storage_daily_rate: parseFloat(editForm.storage_daily_rate) || 0,
        after_hours_multiplier: parseFloat(editForm.after_hours_multiplier) || 1.0,
        minimum_charge: parseFloat(editForm.minimum_charge) || 0,
        is_active: editForm.is_active,
        notes: editForm.notes || null,
      })
      await loadMatrix()
      setEditModal({ open: false, classId: null, typeId: null })
    } catch (error) {
      console.error('Failed to save pricing', error)
    } finally {
      setSaving(false)
    }
  }

  const openManageModal = async (type) => {
    setManageModal({ open: true, type })
    setManageForm({ name: '', code: '', description: '', weight_min: '', weight_max: '' })
    if (type === 'class') {
      setManageList(serviceClasses)
    } else {
      setManageList(serviceTypes)
    }
  }

  const saveManageItem = async () => {
    setSaving(true)
    try {
      if (manageModal.type === 'class') {
        await towingPricingService.createServiceClass({
          name: manageForm.name,
          description: manageForm.description || null,
          weight_min: manageForm.weight_min ? parseInt(manageForm.weight_min, 10) : null,
          weight_max: manageForm.weight_max ? parseInt(manageForm.weight_max, 10) : null,
        })
      } else {
        await towingPricingService.createServiceType({
          name: manageForm.name,
          code: manageForm.code,
          description: manageForm.description || null,
        })
      }
      await loadMatrix()
      setManageForm({ name: '', code: '', description: '', weight_min: '', weight_max: '' })
      if (manageModal.type === 'class') {
        const data = await towingPricingService.listServiceClasses()
        setManageList(data.data || [])
      } else {
        const data = await towingPricingService.listServiceTypes()
        setManageList(data.data || [])
      }
    } catch (error) {
      console.error('Failed to save', error)
    } finally {
      setSaving(false)
    }
  }

  const deleteManageItem = async (id) => {
    if (!confirm('Are you sure you want to delete this item? Any associated pricing will also be removed.')) {
      return
    }
    try {
      if (manageModal.type === 'class') {
        await towingPricingService.deleteServiceClass(id)
        const data = await towingPricingService.listServiceClasses()
        setManageList(data.data || [])
      } else {
        await towingPricingService.deleteServiceType(id)
        const data = await towingPricingService.listServiceTypes()
        setManageList(data.data || [])
      }
      await loadMatrix()
    } catch (error) {
      console.error('Failed to delete', error)
    }
  }

  const formatCurrency = (value) => {
    if (value === null || value === undefined) return '—'
    return `$${parseFloat(value).toFixed(2)}`
  }

  if (loading) {
    return (
      <div className="py-10">
        <Loading text="Loading pricing matrix..." />
      </div>
    )
  }

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Towing Pricing Matrix</h1>
          <p className="mt-1 text-sm text-gray-500">
            Configure pricing for roadside assistance and towing services by service class and type.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => openManageModal('class')}>
            Manage Classes
          </Button>
          <Button variant="secondary" onClick={() => openManageModal('type')}>
            Manage Service Types
          </Button>
        </div>
      </div>

      {serviceClasses.length === 0 || serviceTypes.length === 0 ? (
        <Card>
          <div className="py-10 text-center text-gray-500">
            <p>No service classes or types configured yet.</p>
            <p className="mt-2 text-sm">Add service classes and types to start configuring pricing.</p>
          </div>
        </Card>
      ) : (
        <Card className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Service Type
                </th>
                {serviceClasses.map((cls) => (
                  <th
                    key={cls.id}
                    className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500"
                  >
                    {cls.name}
                    {cls.weight_max && (
                      <div className="font-normal normal-case text-gray-400">
                        {cls.weight_min ? `${cls.weight_min.toLocaleString()}-` : 'Up to '}
                        {cls.weight_max.toLocaleString()} lbs
                      </div>
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {serviceTypes.map((type) => (
                <tr key={type.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-4 py-4">
                    <div className="font-medium text-gray-900">{type.name}</div>
                    <div className="text-xs text-gray-500">{type.code}</div>
                  </td>
                  {serviceClasses.map((cls) => {
                    const entry = getMatrixEntry(cls.id, type.id)
                    return (
                      <td
                        key={`${cls.id}-${type.id}`}
                        className="cursor-pointer whitespace-nowrap px-4 py-4 text-center hover:bg-indigo-50"
                        onClick={() => openEditModal(cls.id, type.id)}
                      >
                        {entry ? (
                          <div className="space-y-1">
                            <div className="text-sm font-medium text-gray-900">
                              {formatCurrency(entry.rollout_fee)} base
                            </div>
                            <div className="text-xs text-gray-500">
                              {formatCurrency(entry.mileage_rate)}/mi
                            </div>
                            {!entry.is_active && (
                              <Badge variant="secondary" size="sm">Inactive</Badge>
                            )}
                          </div>
                        ) : (
                          <span className="text-gray-400">Click to set</span>
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <div className="mt-8">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">All Configured Prices</h2>
        <Card>
          {allPrices.length === 0 ? (
            <div className="py-6 text-center text-gray-500">No prices configured yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-3 py-2 text-left font-medium text-gray-500">Class</th>
                    <th className="px-3 py-2 text-left font-medium text-gray-500">Type</th>
                    {feeFields.map((f) => (
                      <th key={f.key} className="px-3 py-2 text-right font-medium text-gray-500" title={f.description}>
                        {f.label}
                      </th>
                    ))}
                    <th className="px-3 py-2 text-center font-medium text-gray-500">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {allPrices.map((price) => (
                    <tr
                      key={price.id}
                      className="cursor-pointer hover:bg-gray-50"
                      onClick={() => openEditModal(price.service_class_id, price.service_type_id)}
                    >
                      <td className="px-3 py-2 font-medium text-gray-900">{price.service_class_name}</td>
                      <td className="px-3 py-2 text-gray-700">{price.service_type_name}</td>
                      {feeFields.map((f) => (
                        <td key={f.key} className="px-3 py-2 text-right text-gray-700">
                          {f.key === 'after_hours_multiplier'
                            ? `${parseFloat(price[f.key]).toFixed(2)}x`
                            : formatCurrency(price[f.key])}
                        </td>
                      ))}
                      <td className="px-3 py-2 text-center">
                        <Badge variant={price.is_active ? 'success' : 'secondary'}>
                          {price.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>

      {/* Edit Price Modal */}
      <Modal
        open={editModal.open}
        title={`Edit Pricing: ${editForm.service_class_name} - ${editForm.service_type_name}`}
        onClose={() => setEditModal({ open: false, classId: null, typeId: null })}
        size="lg"
        footer={(
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => setEditModal({ open: false, classId: null, typeId: null })}>
              Cancel
            </Button>
            <Button onClick={saveMatrixEntry} disabled={saving}>
              {saving ? 'Saving...' : 'Save'}
            </Button>
          </div>
        )}
      >
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {feeFields.map((field) => (
            <Input
              key={field.key}
              label={field.label}
              type="number"
              step={field.key === 'after_hours_multiplier' ? '0.01' : '0.01'}
              modelValue={editForm[field.key] ?? ''}
              onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, [field.key]: value }))}
              hint={field.description}
            />
          ))}
        </div>
        <div className="mt-4">
          <Input
            label="Notes"
            modelValue={editForm.notes ?? ''}
            onUpdateModelValue={(value) => setEditForm((prev) => ({ ...prev, notes: value }))}
            hint="Internal notes about this pricing"
          />
        </div>
        <div className="mt-4">
          <label className="inline-flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={editForm.is_active ?? true}
              onChange={(e) => setEditForm((prev) => ({ ...prev, is_active: e.target.checked }))}
              className="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            Active
          </label>
        </div>
      </Modal>

      {/* Manage Classes/Types Modal */}
      <Modal
        open={manageModal.open}
        title={manageModal.type === 'class' ? 'Manage Service Classes' : 'Manage Service Types'}
        onClose={() => setManageModal({ open: false, type: null })}
        size="lg"
        footer={(
          <div className="flex justify-end">
            <Button variant="secondary" onClick={() => setManageModal({ open: false, type: null })}>
              Close
            </Button>
          </div>
        )}
      >
        <div className="mb-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
          <h3 className="mb-3 text-sm font-medium text-gray-900">
            Add New {manageModal.type === 'class' ? 'Service Class' : 'Service Type'}
          </h3>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Input
              label="Name"
              modelValue={manageForm.name}
              onUpdateModelValue={(value) => setManageForm((prev) => ({ ...prev, name: value }))}
              placeholder={manageModal.type === 'class' ? 'e.g., Light Duty' : 'e.g., Standard Tow'}
            />
            {manageModal.type === 'type' && (
              <Input
                label="Code"
                modelValue={manageForm.code}
                onUpdateModelValue={(value) => setManageForm((prev) => ({ ...prev, code: value }))}
                placeholder="e.g., TOW"
              />
            )}
            {manageModal.type === 'class' && (
              <>
                <Input
                  label="Min Weight (lbs)"
                  type="number"
                  modelValue={manageForm.weight_min}
                  onUpdateModelValue={(value) => setManageForm((prev) => ({ ...prev, weight_min: value }))}
                  placeholder="Optional"
                />
                <Input
                  label="Max Weight (lbs)"
                  type="number"
                  modelValue={manageForm.weight_max}
                  onUpdateModelValue={(value) => setManageForm((prev) => ({ ...prev, weight_max: value }))}
                  placeholder="Optional"
                />
              </>
            )}
            <Input
              label="Description"
              modelValue={manageForm.description}
              onUpdateModelValue={(value) => setManageForm((prev) => ({ ...prev, description: value }))}
              placeholder="Optional description"
              className="md:col-span-2"
            />
          </div>
          <div className="mt-3 flex justify-end">
            <Button
              size="sm"
              onClick={saveManageItem}
              disabled={saving || !manageForm.name || (manageModal.type === 'type' && !manageForm.code)}
            >
              {saving ? 'Adding...' : 'Add'}
            </Button>
          </div>
        </div>

        <h3 className="mb-3 text-sm font-medium text-gray-900">
          Existing {manageModal.type === 'class' ? 'Service Classes' : 'Service Types'}
        </h3>
        <div className="divide-y divide-gray-200 rounded-lg border border-gray-200">
          {manageList.length === 0 ? (
            <div className="py-4 text-center text-sm text-gray-500">
              No {manageModal.type === 'class' ? 'service classes' : 'service types'} yet.
            </div>
          ) : (
            manageList.map((item) => (
              <div key={item.id} className="flex items-center justify-between px-4 py-3">
                <div>
                  <div className="font-medium text-gray-900">{item.name}</div>
                  {item.code && <div className="text-xs text-gray-500">{item.code}</div>}
                  {item.description && <div className="text-sm text-gray-600">{item.description}</div>}
                  {item.weight_max && (
                    <div className="text-xs text-gray-500">
                      {item.weight_min ? `${item.weight_min.toLocaleString()}-` : 'Up to '}
                      {item.weight_max.toLocaleString()} lbs
                    </div>
                  )}
                </div>
                <Button size="sm" variant="danger" onClick={() => deleteManageItem(item.id)}>
                  Delete
                </Button>
              </div>
            ))
          )}
        </div>
      </Modal>
    </div>
  )
}
