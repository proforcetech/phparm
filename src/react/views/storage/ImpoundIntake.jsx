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
