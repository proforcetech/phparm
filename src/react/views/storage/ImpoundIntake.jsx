import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import { useToast } from '../../stores/toast.jsx'

const initialForm = {
  case_number: '',
  intake_date: '',
  state_code: 'CA',
  tow_agency: '',
  intake_location: '',
  gate_fee: '125.00',
  daily_rate: '45.00',
  hold_release_contact: '',
}

const sampleCases = [
  {
    id: 1,
    case_number: 'IMP-2024-017',
    vehicle: '2019 Ford F-150',
    intake_date: '2024-03-12',
    state_code: 'CA',
    status: 'open',
  },
  {
    id: 2,
    case_number: 'IMP-2024-018',
    vehicle: '2021 Honda Accord',
    intake_date: '2024-03-14',
    state_code: 'TX',
    status: 'hold',
  },
]

export default function ImpoundIntake() {
  const { success } = useToast()
  const [form, setForm] = useState(initialForm)

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'vehicle', label: 'Vehicle' },
    { key: 'intake_date', label: 'Impound Date' },
    { key: 'state_code', label: 'State' },
    { key: 'status', label: 'Status' },
  ]), [])

  const submitIntake = (event) => {
    event.preventDefault()
    success(`Impound case ${form.case_number || 'draft'} saved`)
    setForm(initialForm)
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

      <Card>
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Active Impound Cases</h2>
          <p className="text-sm text-gray-500">Monitor cases that are accruing storage fees.</p>
        </div>
        <Table
          columns={columns}
          data={sampleCases}
          hoverable={false}
        />
      </Card>
    </div>
  )
}
