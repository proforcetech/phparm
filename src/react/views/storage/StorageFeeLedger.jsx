import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import storageService from '../../../services/storage.service'

const emptyFeeForm = {
  caseNumber: '',
  feeDate: '',
  feeType: 'Daily Storage',
  amount: '',
  status: 'posted',
}

const statusVariant = {
  posted: 'success',
  pending: 'warning',
  void: 'danger',
}

const statusOptions = [
  { label: 'Posted', value: 'posted' },
  { label: 'Pending', value: 'pending' },
  { label: 'Void', value: 'void' },
]

const feeTypeOptions = [
  'Daily Storage',
  'Gate Fee',
  'After Hours Fee',
  'Impound Fee',
  'Adjustment',
]

export default function StorageFeeLedger() {
  const [search, setSearch] = useState('')
  const [fees, setFees] = useState([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [error, setError] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingFee, setEditingFee] = useState(null)
  const [form, setForm] = useState(emptyFeeForm)
  const hasLoadedRef = useRef(false)

  const loadFees = useCallback(async (options = {}) => {
    const { automate = false, preserveError = false } = options
    setLoading(true)
    if (!preserveError) {
      setError('')
    }

    try {
      if (automate) {
        await storageService.automateFees()
      }
      const response = await storageService.listFees({ search: search || undefined })
      const rows = Array.isArray(response) ? response : response?.data ?? []
      setFees(Array.isArray(rows) ? rows : [])
    } catch (fetchError) {
      setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load storage fees.')
      setFees([])
    } finally {
      setLoading(false)
    }
  }, [search])

  const handleSyncLedger = useCallback(async () => {
    setSyncing(true)
    setError('')
    try {
      try {
        await storageService.automateFees()
      } catch (syncError) {
        setError(syncError?.response?.data?.message || syncError?.message || 'Unable to sync automated fees.')
      }
      await loadFees({ automate: false, preserveError: true })
    } finally {
      setSyncing(false)
    }
  }, [loadFees])

  useEffect(() => {
    handleSyncLedger()
  }, [handleSyncLedger])

  useEffect(() => {
    if (!hasLoadedRef.current) {
      hasLoadedRef.current = true
      return
    }
    loadFees({ automate: false })
  }, [loadFees, search])

  const filtered = useMemo(() => {
    const term = search.toLowerCase()
    if (!term) return fees
    return fees.filter((row) => row.case_number?.toLowerCase().includes(term))
  }, [fees, search])

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'fee_date', label: 'Fee Date' },
    { key: 'fee_type', label: 'Type' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
  ]), [])

  const totals = useMemo(() => {
    return filtered.reduce((acc, row) => {
      const amount = Number(row.amount) || 0
      acc.total += amount
      if (row.fee_type === 'Daily Storage') {
        acc.daily += amount
      }
      if (row.fee_type === 'Gate Fee') {
        acc.gate += amount
      }
      if (row.fee_type === 'After Hours Fee') {
        acc.afterHours += amount
      }
      return acc
    }, { total: 0, daily: 0, gate: 0, afterHours: 0 })
  }, [filtered])

  const openCreateModal = () => {
    setEditingFee(null)
    setForm(emptyFeeForm)
    setShowModal(true)
  }

  const openEditModal = (fee) => {
    setEditingFee(fee)
    setForm({
      caseNumber: fee.case_number || '',
      feeDate: fee.fee_date || '',
      feeType: fee.fee_type || 'Daily Storage',
      amount: fee.amount ?? '',
      status: fee.status || 'posted',
    })
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditingFee(null)
    setForm(emptyFeeForm)
  }

  const handleSave = async () => {
    setSaving(true)
    setError('')

    const payload = {
      case_number: form.caseNumber,
      fee_date: form.feeDate,
      fee_type: form.feeType,
      amount: Number(form.amount) || 0,
      status: form.status,
    }

    try {
      const response = editingFee
        ? await storageService.updateFee(editingFee.id, payload)
        : await storageService.createFee(payload)
      const savedFee = response?.data ?? response

      if (savedFee?.id) {
        setFees((prev) => {
          if (editingFee) {
            return prev.map((row) => (row.id === editingFee.id ? { ...row, ...savedFee } : row))
          }
          return [savedFee, ...prev]
        })
      } else {
        await loadFees()
      }

      closeModal()
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Unable to save fee adjustment.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (fee) => {
    if (!fee?.id) return
    if (!window.confirm(`Delete fee adjustment for ${fee.case_number}?`)) return

    try {
      await storageService.removeFee(fee.id)
      setFees((prev) => prev.filter((row) => row.id !== fee.id))
    } catch (deleteError) {
      setError(deleteError?.response?.data?.message || deleteError?.message || 'Unable to delete fee adjustment.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Storage Fee Ledger</h1>
          <p className="text-sm text-gray-500">Track daily accruals, gate fees, after-hours fees, and adjustments.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/notices">
            <Button variant="secondary">Notice Generation</Button>
          </Link>
          <Link to="/cp/storage/release-checklist">
            <Button variant="secondary">Release Checklist</Button>
          </Link>
        </div>
      </div>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <p className="text-sm text-gray-500">Total Accrued</p>
          <p className="text-2xl font-semibold text-gray-900">${totals.total.toFixed(2)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Daily Storage</p>
          <p className="text-2xl font-semibold text-gray-900">${totals.daily.toFixed(2)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Gate Fees</p>
          <p className="text-2xl font-semibold text-gray-900">${totals.gate.toFixed(2)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">After Hours</p>
          <p className="text-2xl font-semibold text-gray-900">${totals.afterHours.toFixed(2)}</p>
        </Card>
      </div>

      <Card>
        <div className="mb-4 grid gap-4 md:grid-cols-3">
          <Input
            label="Search case"
            modelValue={search}
            placeholder="IMP-2024-017"
            onUpdateModelValue={setSearch}
          />
          <div className="flex items-end gap-2">
            <Button variant="secondary" loading={syncing} onClick={handleSyncLedger}>
              Sync Automated Fees
            </Button>
            <Button variant="primary" onClick={openCreateModal}>Add Fee Adjustment</Button>
            <Button variant="outline">Export Ledger</Button>
          </div>
        </div>
        <Table
          columns={columns}
          data={filtered.map((row) => ({
            ...row,
            amount: `$${Number(row.amount || 0).toFixed(2)}`,
            status: (
              <Badge variant={statusVariant[row.status] || 'default'}>
                {row.status}
              </Badge>
            ),
          }))}
          loading={loading}
          hoverable={false}
          renderActions={(row) => (
            <div className="flex justify-end gap-2">
              <Button size="sm" variant="secondary" onClick={() => openEditModal(row)}>Edit</Button>
              <Button size="sm" variant="danger" onClick={() => handleDelete(row)}>Delete</Button>
            </div>
          )}
        />
      </Card>

      <Modal
        open={showModal}
        title={editingFee ? 'Edit Fee Adjustment' : 'Add Fee Adjustment'}
        onClose={closeModal}
      >
        <div className="space-y-4">
          <Input
            label="Case #"
            value={form.caseNumber}
            placeholder="IMP-2024-017"
            onChange={(event) => setForm((prev) => ({ ...prev, caseNumber: event.target.value }))}
          />
          <Input
            label="Fee Date"
            type="date"
            value={form.feeDate}
            onChange={(event) => setForm((prev) => ({ ...prev, feeDate: event.target.value }))}
          />
          <Select
            label="Fee Type"
            modelValue={form.feeType}
            options={feeTypeOptions}
            placeholder="Select fee type"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, feeType: value }))}
          />
          <Input
            label="Amount"
            type="number"
            min="0"
            step="0.01"
            value={form.amount}
            onChange={(event) => setForm((prev) => ({ ...prev, amount: event.target.value }))}
          />
          <Select
            label="Status"
            modelValue={form.status}
            options={statusOptions}
            placeholder="Select status"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, status: value }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={closeModal}>Cancel</Button>
            <Button variant="primary" loading={saving} onClick={handleSave}>
              {editingFee ? 'Save Changes' : 'Add Fee'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
