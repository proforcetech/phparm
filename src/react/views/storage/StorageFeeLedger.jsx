import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Table from '../../components/ui/Table'

const feeRows = [
  {
    id: 1,
    case_number: 'IMP-2024-017',
    fee_date: '2024-03-15',
    fee_type: 'Daily Storage',
    amount: 45.0,
    status: 'posted',
  },
  {
    id: 2,
    case_number: 'IMP-2024-017',
    fee_date: '2024-03-15',
    fee_type: 'Gate Fee',
    amount: 125.0,
    status: 'posted',
  },
  {
    id: 3,
    case_number: 'IMP-2024-018',
    fee_date: '2024-03-15',
    fee_type: 'Daily Storage',
    amount: 35.0,
    status: 'pending',
  },
]

const statusVariant = {
  posted: 'success',
  pending: 'warning',
  void: 'danger',
}

export default function StorageFeeLedger() {
  const [search, setSearch] = useState('')

  const filtered = useMemo(() => {
    const term = search.toLowerCase()
    if (!term) return feeRows
    return feeRows.filter((row) => row.case_number.toLowerCase().includes(term))
  }, [search])

  const columns = useMemo(() => ([
    { key: 'case_number', label: 'Case #' },
    { key: 'fee_date', label: 'Fee Date' },
    { key: 'fee_type', label: 'Type' },
    { key: 'amount', label: 'Amount' },
    { key: 'status', label: 'Status' },
  ]), [])

  const totals = useMemo(() => {
    return feeRows.reduce((acc, row) => {
      acc.total += row.amount
      if (row.fee_type === 'Daily Storage') {
        acc.daily += row.amount
      }
      if (row.fee_type === 'Gate Fee') {
        acc.gate += row.amount
      }
      return acc
    }, { total: 0, daily: 0, gate: 0 })
  }, [])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Storage Fee Ledger</h1>
          <p className="text-sm text-gray-500">Track daily accruals, gate fees, and adjustments.</p>
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

      <div className="grid gap-4 md:grid-cols-3">
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
      </div>

      <Card>
        <div className="mb-4 grid gap-4 md:grid-cols-3">
          <Input
            label="Search case"
            modelValue={search}
            placeholder="IMP-2024-017"
            onUpdateModelValue={setSearch}
          />
          <div className="flex items-end">
            <Button variant="outline">Export Ledger</Button>
          </div>
        </div>
        <Table
          columns={columns}
          data={filtered.map((row) => ({
            ...row,
            amount: `$${row.amount.toFixed(2)}`,
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
