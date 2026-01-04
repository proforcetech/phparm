import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Table from '../../components/ui/Table'
import { useToast } from '../../stores/toast.jsx'

const noticeRows = [
  {
    id: 1,
    case_number: 'IMP-2024-017',
    notice_type: 'Notice of Claim',
    notice_date: '2024-03-16',
    status: 'draft',
  },
  {
    id: 2,
    case_number: 'IMP-2024-017',
    notice_type: 'Lien Notice',
    notice_date: '2024-03-26',
    status: 'scheduled',
  },
]

const statusVariant = {
  draft: 'secondary',
  scheduled: 'warning',
  sent: 'success',
}

export default function NoticeGeneration() {
  const { success } = useToast()
  const [form, setForm] = useState({
    case_number: '',
    notice_type: 'Notice of Claim',
    due_date: '',
  })

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'notice_type', label: 'Notice Type' },
    { key: 'notice_date', label: 'Notice Date' },
    { key: 'status', label: 'Status' },
  ]), [])

  const generateNotice = (event) => {
    event.preventDefault()
    success(`Generated ${form.notice_type} for ${form.case_number || 'case'}`)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Notice Generation</h1>
          <p className="text-sm text-gray-500">Create Notice of Claim and lien document packages.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/cp/storage/impound-intake">
            <Button variant="secondary">Impound Intake</Button>
          </Link>
          <Link to="/cp/storage/ledger">
            <Button variant="secondary">Fee Ledger</Button>
          </Link>
          <Link to="/cp/storage/release-checklist">
            <Button variant="secondary">Release Checklist</Button>
          </Link>
        </div>
      </div>

      <Card>
        <form className="grid gap-4 md:grid-cols-3" onSubmit={generateNotice}>
          <Input
            label="Case Number"
            modelValue={form.case_number}
            placeholder="IMP-2024-017"
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, case_number: value }))}
          />
          <Select
            label="Notice Type"
            modelValue={form.notice_type}
            options={[
              { value: 'Notice of Claim', label: 'Notice of Claim' },
              { value: 'Lien Notice', label: 'Lien Notice' },
              { value: 'Final Demand', label: 'Final Demand' },
            ]}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, notice_type: value }))}
          />
          <Input
            label="Response Due"
            type="date"
            modelValue={form.due_date}
            onUpdateModelValue={(value) => setForm((prev) => ({ ...prev, due_date: value }))}
          />
          <div className="md:col-span-3 flex justify-end gap-2">
            <Button variant="outline" type="button">Preview PDF</Button>
            <Button type="submit">Generate PDF</Button>
          </div>
        </form>
      </Card>

      <Card>
        <div className="mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Recent Notices</h2>
          <p className="text-sm text-gray-500">Track delivery status for outgoing lien notices.</p>
        </div>
        <Table
          columns={columns}
          data={noticeRows.map((row) => ({
            ...row,
            status: (
              <Badge variant={statusVariant[row.status] || 'default'}>
                {row.status}
              </Badge>
            ),
          }))}
          hoverable={false}
        />
      </Card>
    </div>
  )
}
