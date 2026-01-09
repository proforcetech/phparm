import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Modal from '../../components/ui/Modal'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import { useToast } from '../../stores/toast.jsx'
import auctionService from '../../../services/auction.service'
import impoundService from '../../../services/impound.service'

const auctionStatusOptions = [
  { value: 'in_storage', label: 'In Storage' },
  { value: 'lien_notice', label: 'Lien Notice' },
  { value: 'auction_ready', label: 'Auction Ready' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_lot', label: 'In Lot' },
  { value: 'sold', label: 'Sold' },
  { value: 'withdrawn', label: 'Withdrawn' },
  { value: 'released', label: 'Released' },
]

const lotStatusOptions = [
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_lot', label: 'In Lot' },
  { value: 'sold', label: 'Sold' },
  { value: 'withdrawn', label: 'Withdrawn' },
]

const emptyLotForm = {
  impound_case_id: '',
  lot_number: '',
  status: 'scheduled',
  auction_date: '',
  sale_price: '',
  buyer_name: '',
  notes: '',
}

export default function AuctionManagement() {
  const { success } = useToast()
  const [cases, setCases] = useState([])
  const [lots, setLots] = useState([])
  const [summary, setSummary] = useState({})
  const [statusBreakdown, setStatusBreakdown] = useState([])
  const [loadingCases, setLoadingCases] = useState(false)
  const [loadingLots, setLoadingLots] = useState(false)
  const [loadingSummary, setLoadingSummary] = useState(false)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [showModal, setShowModal] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editingLot, setEditingLot] = useState(null)
  const [lotForm, setLotForm] = useState(emptyLotForm)
  const [updatingCaseId, setUpdatingCaseId] = useState(null)

  const loadCases = useCallback(async () => {
    setLoadingCases(true)
    setError('')
    try {
      const response = await impoundService.listCases({ search: search || undefined })
      const rows = Array.isArray(response) ? response : response?.data ?? []
      setCases(Array.isArray(rows) ? rows : [])
    } catch (fetchError) {
      setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load impound cases.')
      setCases([])
    } finally {
      setLoadingCases(false)
    }
  }, [search])

  const loadLots = useCallback(async () => {
    setLoadingLots(true)
    setError('')
    try {
      const response = await auctionService.listLots({ search: search || undefined })
      const rows = Array.isArray(response) ? response : response?.data ?? []
      setLots(Array.isArray(rows) ? rows : [])
    } catch (fetchError) {
      setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load auction lots.')
      setLots([])
    } finally {
      setLoadingLots(false)
    }
  }, [search])

  const loadSummary = useCallback(async () => {
    setLoadingSummary(true)
    try {
      const response = await auctionService.getSummary()
      setSummary(response?.summary ?? {})
      setStatusBreakdown(response?.auction_status_breakdown ?? [])
    } catch (fetchError) {
      setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load auction report.')
      setSummary({})
      setStatusBreakdown([])
    } finally {
      setLoadingSummary(false)
    }
  }, [])

  useEffect(() => {
    loadCases()
    loadLots()
    loadSummary()
  }, [loadCases, loadLots, loadSummary])

  const caseColumns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'impound_date', label: 'Impound Date' },
    { key: 'status', label: 'Status' },
    { key: 'auction_status', label: 'Auction Status' },
    { key: 'intake_location', label: 'Location' },
  ]), [])

  const lotColumns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'lot_number', label: 'Lot #' },
    { key: 'status', label: 'Status' },
    { key: 'auction_date', label: 'Auction Date' },
    { key: 'sale_price', label: 'Sale Price' },
    { key: 'buyer_name', label: 'Buyer' },
  ]), [])

  const caseOptions = useMemo(() => (
    cases.map((item) => ({ value: String(item.id), label: item.case_number }))
  ), [cases])

  const handleStatusChange = useCallback(async (row, value) => {
    if (!row?.id || row.auction_status === value) return
    setUpdatingCaseId(row.id)
    setError('')

    try {
      const response = await impoundService.updateCase(row.id, { auction_status: value })
      const updated = response?.data ?? response
      setCases((prev) => prev.map((item) => (item.id === row.id ? { ...item, ...updated } : item)))
      success(`Auction status updated for ${row.case_number}`)
      await loadSummary()
    } catch (updateError) {
      setError(updateError?.response?.data?.message || updateError?.message || 'Unable to update auction status.')
    } finally {
      setUpdatingCaseId(null)
    }
  }, [loadSummary, success])

  const openCreateModal = () => {
    setEditingLot(null)
    setLotForm(emptyLotForm)
    setShowModal(true)
  }

  const openEditModal = (lot) => {
    setEditingLot(lot)
    setLotForm({
      impound_case_id: String(lot.impound_case_id ?? ''),
      lot_number: lot.lot_number ?? '',
      status: lot.status ?? 'scheduled',
      auction_date: lot.auction_date ?? '',
      sale_price: lot.sale_price ?? '',
      buyer_name: lot.buyer_name ?? '',
      notes: lot.notes ?? '',
    })
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditingLot(null)
    setLotForm(emptyLotForm)
  }

  const handleSaveLot = async () => {
    setSaving(true)
    setError('')

    const payload = {
      impound_case_id: Number(lotForm.impound_case_id) || undefined,
      lot_number: lotForm.lot_number,
      status: lotForm.status,
      auction_date: lotForm.auction_date || null,
      sale_price: lotForm.sale_price === '' ? null : Number(lotForm.sale_price),
      buyer_name: lotForm.buyer_name || null,
      notes: lotForm.notes || null,
    }

    try {
      const response = editingLot
        ? await auctionService.updateLot(editingLot.id, payload)
        : await auctionService.createLot(payload)
      const saved = response?.data ?? response

      if (saved?.id) {
        setLots((prev) => {
          if (editingLot) {
            return prev.map((item) => (item.id === saved.id ? { ...item, ...saved } : item))
          }
          return [saved, ...prev]
        })
      } else {
        await loadLots()
      }

      success(editingLot ? 'Auction lot updated.' : 'Auction lot created.')
      closeModal()
      await loadSummary()
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Unable to save auction lot.')
    } finally {
      setSaving(false)
    }
  }

  const statusBreakdownDisplay = useMemo(() => {
    return statusBreakdown.map((item) => ({
      ...item,
      label: auctionStatusOptions.find((option) => option.value === item.auction_status)?.label || item.auction_status,
    }))
  }, [statusBreakdown])

  const caseCellRenderers = useMemo(() => ({
    auction_status: (value, row) => (
      <Select
        modelValue={value}
        options={auctionStatusOptions}
        onUpdateModelValue={(next) => handleStatusChange(row, next)}
        disabled={updatingCaseId === row.id}
      />
    ),
    status: (value) => (
      <Badge variant={value === 'released' ? 'success' : 'secondary'}>{value}</Badge>
    ),
  }), [handleStatusChange, updatingCaseId])

  const lotCellRenderers = useMemo(() => ({
    status: (value) => (
      <Badge variant={value === 'sold' ? 'success' : value === 'withdrawn' ? 'danger' : 'secondary'}>
        {value}
      </Badge>
    ),
    sale_price: (value) => (value ? `$${Number(value).toFixed(2)}` : '--'),
  }), [])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Auction Management</h1>
          <p className="text-sm text-gray-500">Move impound cases from storage to auction, track lots, and report outcomes.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
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

      <Card>
        <div className="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Auction Status Flow</h2>
            <p className="text-sm text-gray-500">Advance cases as they clear lien requirements and head to auction.</p>
          </div>
          <Input
            label="Search cases"
            modelValue={search}
            placeholder="IMP-2024-017"
            onUpdateModelValue={setSearch}
          />
        </div>
        <Table
          columns={caseColumns}
          data={cases}
          loading={loadingCases}
          hoverable={false}
          cellRenderers={caseCellRenderers}
        />
      </Card>

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <p className="text-sm text-gray-500">Total Lots</p>
          <p className="text-2xl font-semibold text-gray-900">{loadingSummary ? '—' : summary?.total_lots ?? 0}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Lots Sold</p>
          <p className="text-2xl font-semibold text-gray-900">{loadingSummary ? '—' : summary?.sold ?? 0}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Total Proceeds</p>
          <p className="text-2xl font-semibold text-gray-900">
            {loadingSummary ? '—' : `$${Number(summary?.total_proceeds ?? 0).toFixed(2)}`}
          </p>
        </Card>
      </div>

      <Card>
        <div className="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Auction Lot Management</h2>
            <p className="text-sm text-gray-500">Track auction lot assignments, buyers, and sale results.</p>
          </div>
          <Button variant="primary" onClick={openCreateModal}>Add Auction Lot</Button>
        </div>
        <Table
          columns={lotColumns}
          data={lots}
          loading={loadingLots}
          hoverable={false}
          cellRenderers={lotCellRenderers}
          renderActions={(row) => (
            <Button variant="outline" size="sm" onClick={() => openEditModal(row)}>Edit</Button>
          )}
        />
      </Card>

      <Card>
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Auction Reporting</h2>
          <p className="text-sm text-gray-500">View auction readiness and lot status distribution.</p>
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <div>
            <p className="text-sm text-gray-500">Scheduled Lots</p>
            <p className="text-xl font-semibold text-gray-900">{summary?.scheduled ?? 0}</p>
          </div>
          <div>
            <p className="text-sm text-gray-500">In Lot</p>
            <p className="text-xl font-semibold text-gray-900">{summary?.in_lot ?? 0}</p>
          </div>
          <div>
            <p className="text-sm text-gray-500">Withdrawn</p>
            <p className="text-xl font-semibold text-gray-900">{summary?.withdrawn ?? 0}</p>
          </div>
        </div>
        <div className="mt-4">
          <p className="text-sm font-semibold text-gray-900">Impound Auction Status</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {statusBreakdownDisplay.length === 0 ? (
              <span className="text-sm text-gray-500">No auction status data available.</span>
            ) : statusBreakdownDisplay.map((item) => (
              <Badge key={item.auction_status} variant="secondary">
                {item.label}: {item.count}
              </Badge>
            ))}
          </div>
        </div>
      </Card>

      <Modal open={showModal} onClose={closeModal} title={editingLot ? 'Edit Auction Lot' : 'Add Auction Lot'}>
        <div className="space-y-4">
          <Select
            label="Impound Case"
            modelValue={lotForm.impound_case_id}
            options={caseOptions}
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, impound_case_id: value }))}
          />
          <Input
            label="Lot Number"
            modelValue={lotForm.lot_number}
            placeholder="LOT-2024-014"
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, lot_number: value }))}
          />
          <Select
            label="Lot Status"
            modelValue={lotForm.status}
            options={lotStatusOptions}
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, status: value }))}
          />
          <Input
            label="Auction Date"
            type="date"
            modelValue={lotForm.auction_date}
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, auction_date: value }))}
          />
          <Input
            label="Sale Price"
            type="number"
            modelValue={lotForm.sale_price}
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, sale_price: value }))}
          />
          <Input
            label="Buyer Name"
            modelValue={lotForm.buyer_name}
            placeholder="Western Auto Auction"
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, buyer_name: value }))}
          />
          <Input
            label="Notes"
            modelValue={lotForm.notes}
            placeholder="Lien cleared, title packet attached."
            onUpdateModelValue={(value) => setLotForm((prev) => ({ ...prev, notes: value }))}
          />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={closeModal}>Cancel</Button>
            <Button variant="primary" disabled={saving} onClick={handleSaveLot}>
              {saving ? 'Saving...' : 'Save Lot'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
