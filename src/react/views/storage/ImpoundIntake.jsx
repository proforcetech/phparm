import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Alert from '../../components/ui/Alert'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import { useToast } from '../../stores/toast.jsx'
import impoundService from '../../../services/impound.service'
import { decodeVin } from '../../../services/vehicle.service'
import { normalizeVinData } from '../../../utils/vin'

const initialForm = {
  case_number: '',
  intake_date: '',
  state_code: 'CA',
  tow_agency: '',
  intake_location: '',
  gate_fee: '125.00',
  daily_rate: '45.00',
  hold_release_contact: '',
  status: 'open',
  auction_status: 'in_storage',
  vin: '',
  vehicle_year: '',
  vehicle_make: '',
  vehicle_model: '',
  vehicle_trim: '',
  vehicle_weight_class: '',
}

const statusOptions = [
  { value: 'open', label: 'Open' },
  { value: 'hold', label: 'Hold' },
  { value: 'released', label: 'Released' },
]

const auctionStatusOptions = [
  { value: 'in_storage', label: 'In Storage' },
  { value: 'lien_notice', label: 'Lien Notice' },
  { value: 'auction_ready', label: 'Auction Ready' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'in_lot', label: 'In Lot' },
  { value: 'sold', label: 'Sold' },
  { value: 'released', label: 'Released' },
]

export default function ImpoundIntake() {
  const { success } = useToast()
  const [cases, setCases] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState(initialForm)
  const [vinStatus, setVinStatus] = useState('')
  const [vinStatusType, setVinStatusType] = useState('success')
  const [vinDecodedPayload, setVinDecodedPayload] = useState(null)
  const [vinOverrides, setVinOverrides] = useState([])

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'impound_date', label: 'Impound Date' },
    { key: 'state_code', label: 'State' },
    { key: 'status', label: 'Status' },
    { key: 'auction_status', label: 'Auction Status' },
  ]), [])

  const loadCases = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await impoundService.listCases()
      const rows = Array.isArray(response) ? response : response?.data ?? []
      setCases(Array.isArray(rows) ? rows : [])
    } catch (fetchError) {
      setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load impound cases.')
      setCases([])
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadCases()
  }, [loadCases])

  const updateVehicleField = (field, value, trackOverride = true) => {
    setForm((prev) => {
      if (trackOverride && prev[field] !== value) {
        setVinOverrides((entries) => [
          {
            field,
            previousValue: prev[field] ?? '—',
            newValue: value ?? '—',
            updatedAt: new Date().toISOString(),
          },
          ...entries,
        ])
      }
      return { ...prev, [field]: value }
    })
  }

  const handleVinDecode = async () => {
    if (!form.vin || form.vin.length < 17) return
    try {
      const response = await decodeVin(form.vin)
      const normalized = normalizeVinData(response)
      setVinDecodedPayload(response?.decoded ?? null)
      setForm((prev) => ({
        ...prev,
        vin: normalized.vin || prev.vin,
        vehicle_year: normalized.year ? String(normalized.year) : '',
        vehicle_make: normalized.make || '',
        vehicle_model: normalized.model || '',
        vehicle_trim: normalized.trim || '',
        vehicle_weight_class: normalized.weightClass || '',
      }))
      setVinOverrides([])
      setVinStatusType('success')
      setVinStatus('Vehicle details populated from VIN decode.')
    } catch (decodeError) {
      console.error('VIN decode failed', decodeError)
      setVinDecodedPayload(null)
      setVinStatusType('error')
      setVinStatus('VIN decoded, but vehicle details could not be retrieved.')
    }
  }

  const submitIntake = async (event) => {
    event.preventDefault()
    setError('')

    try {
      const payload = {
        case_number: form.case_number,
        impound_date: form.intake_date,
        state_code: form.state_code,
        tow_agency: form.tow_agency,
        intake_location: form.intake_location,
        hold_release_contact: form.hold_release_contact,
        gate_fee: Number(form.gate_fee) || 0,
        daily_rate: Number(form.daily_rate) || 0,
        status: form.status,
        auction_status: form.auction_status,
        vin: form.vin || null,
        vehicle_year: form.vehicle_year ? Number(form.vehicle_year) : null,
        vehicle_make: form.vehicle_make || null,
        vehicle_model: form.vehicle_model || null,
        vehicle_trim: form.vehicle_trim || null,
        vehicle_weight_class: form.vehicle_weight_class || null,
        vin_decoded: vinDecodedPayload,
        vin_overrides: vinOverrides,
      }
      const response = await impoundService.createCase(payload)
      const created = response?.data ?? response
      if (created?.id) {
        setCases((prev) => [created, ...prev])
      } else {
        await loadCases()
      }
      success(`Impound case ${form.case_number || 'draft'} saved`)
      setForm(initialForm)
      setVinStatus('')
      setVinStatusType('success')
      setVinDecodedPayload(null)
      setVinOverrides([])
    } catch (saveError) {
      setError(saveError?.response?.data?.message || saveError?.message || 'Unable to save impound case.')
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Impound Intake</h1>
          <p className="text-sm text-gray-500">Capture incoming vehicles and begin storage tracking.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/spot-checks">
            <Button variant="secondary">Inventory Spot-Checks</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
          </Link>
          <Link to="/cp/storage/auction-management">
            <Button variant="secondary">Auction Management</Button>
          </Link>
          <Link to="/cp/storage/notices">
            <Button variant="secondary">Notice Generation</Button>
          </Link>
          <Link to="/cp/storage/release-checklist">
            <Button variant="secondary">Release Checklist</Button>
          </Link>
        </div>
      </div>

      <Card>
        <form className="grid grid-cols-1 gap-4 md:grid-cols-2" onSubmit={submitIntake}>
          <Input
            label="Case Number"
            modelValue={form.case_number}
            placeholder="IMP-2024-019"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, case_number: value }))}
          />
          <Input
            label="Impound Date"
            type="date"
            modelValue={form.intake_date}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, intake_date: value }))}
          />
          <Select
            label="State"
            modelValue={form.state_code}
            options={[
              { value: 'CA', label: 'California' },
              { value: 'TX', label: 'Texas' },
              { value: 'FL', label: 'Florida' },
            ]}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, state_code: value }))}
          />
          <Input
            label="Tow Agency"
            modelValue={form.tow_agency}
            placeholder="City Police Tow Yard"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, tow_agency: value }))}
          />
          <Input
            label="Intake Location"
            modelValue={form.intake_location}
            placeholder="Main lot - Row C"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, intake_location: value }))}
          />
          <div className="md:col-span-2 grid gap-4 md:grid-cols-[1fr_auto]">
            <Input
              label="VIN"
              modelValue={form.vin}
              placeholder="Enter 17-character VIN"
              onUpdateModelValue={(value) => updateVehicleField('vin', value, false)}
            />
            <Button
              className="mt-6"
              variant="secondary"
              onClick={handleVinDecode}
              disabled={!form.vin || form.vin.length < 17}
              type="button"
            >
              Decode VIN
            </Button>
          </div>
          {vinStatus ? (
            <div
              className={`md:col-span-2 text-sm ${vinStatusType === 'error' ? 'text-red-600' : 'text-green-600'}`}
            >
              {vinStatus}
            </div>
          ) : null}
          <Input
            label="Vehicle Year"
            type="number"
            modelValue={form.vehicle_year}
            placeholder="2021"
            onUpdateModelValue={(value) => updateVehicleField('vehicle_year', value, true)}
          />
          <Input
            label="Vehicle Make"
            modelValue={form.vehicle_make}
            placeholder="Ford"
            onUpdateModelValue={(value) => updateVehicleField('vehicle_make', value, true)}
          />
          <Input
            label="Vehicle Model"
            modelValue={form.vehicle_model}
            placeholder="F-150"
            onUpdateModelValue={(value) => updateVehicleField('vehicle_model', value, true)}
          />
          <Input
            label="Vehicle Trim"
            modelValue={form.vehicle_trim}
            placeholder="XLT"
            onUpdateModelValue={(value) => updateVehicleField('vehicle_trim', value, true)}
          />
          <Input
            label="Weight Class"
            modelValue={form.vehicle_weight_class}
            placeholder="Class 2"
            onUpdateModelValue={(value) => updateVehicleField('vehicle_weight_class', value, true)}
          />
          <Input
            label="Hold Release Contact"
            modelValue={form.hold_release_contact}
            placeholder="Officer Jackson"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, hold_release_contact: value }))}
          />
          <Select
            label="Case Status"
            modelValue={form.status}
            options={statusOptions}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, status: value }))}
          />
          <Select
            label="Auction Status"
            modelValue={form.auction_status}
            options={auctionStatusOptions}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, auction_status: value }))}
          />
          <Input
            label="Gate Fee"
            type="number"
            modelValue={form.gate_fee}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, gate_fee: value }))}
          />
          <Input
            label="Daily Rate"
            type="number"
            modelValue={form.daily_rate}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, daily_rate: value }))}
          />
          <div className="md:col-span-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
            <div className="flex items-center justify-between">
              <span className="font-medium text-gray-800">VIN override audit trail</span>
              <span>
                {vinOverrides.length ? `${vinOverrides.length} override${vinOverrides.length === 1 ? '' : 's'}` : 'No overrides'}
              </span>
            </div>
            {vinOverrides.length ? (
              <ul className="mt-2 space-y-1">
                {vinOverrides.slice(0, 4).map((entry, index) => (
                  <li key={`${entry.field}-${entry.updatedAt}-${index}`}>
                    {entry.field}: {entry.previousValue || '—'} → {entry.newValue || '—'}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-2 text-gray-500">Manual edits will be logged here.</p>
            )}
          </div>
          <div className="md:col-span-2 flex justify-end">
            <Button type="submit">Save Intake</Button>
          </div>
        </form>
      </Card>

      {error ? <Alert variant="danger">{error}</Alert> : null}

      <Card>
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Active Impound Cases</h2>
          <p className="text-sm text-gray-500">Monitor cases that are accruing storage fees.</p>
        </div>
        <Table
          columns={columns}
          data={cases}
          loading={loading}
          hoverable={false}
        />
      </Card>
    </div>
  )
}
