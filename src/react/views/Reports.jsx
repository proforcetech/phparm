import { useEffect, useMemo, useState } from 'react'

import BarChart from '../components/charts/BarChart'
import LineChart from '../components/charts/LineChart'
import Button from '../components/ui/Button'
import Card from '../components/ui/Card'
import Input from '../components/ui/Input'
import Select from '../components/ui/Select'
import FinancialReports from './financial/Reports'
import branchesService from '../../services/branches.service'
import reportsService from '../../services/reports.service'
import { useToast } from '../stores/toast.jsx'

const tabs = [
  { key: 'technician', label: 'Technician Margins' },
  { key: 'financial', label: 'Financial Reports' },
]

function formatCurrency(value) {
  return `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatPercent(value) {
  return `${Number(value || 0).toFixed(2)}%`
}

function TechnicianMargins() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [filters, setFilters] = useState({ start_date: '', end_date: '', branch_id: '' })
  const [branches, setBranches] = useState([])
  const [report, setReport] = useState({
    data: [],
    summary: {
      total_billed_labor: 0,
      total_actual_cost: 0,
      total_actual_minutes: 0,
      total_margin: 0,
      overall_margin_percentage: 0,
      default_labor_rate: 0,
    },
  })

  useEffect(() => {
    const today = new Date()
    const endDate = today.toISOString().slice(0, 10)
    const monthAgo = new Date()
    monthAgo.setDate(monthAgo.getDate() - 30)
    const startDate = monthAgo.toISOString().slice(0, 10)
    setFilters({ start_date: startDate, end_date: endDate, branch_id: '' })
  }, [])

  useEffect(() => {
    branchesService
      .list()
      .then((data) => setBranches(Array.isArray(data) ? data : []))
      .catch(() => toast.error('Failed to load branches'))
  }, [toast])

  useEffect(() => {
    if (filters.start_date && filters.end_date) {
      fetchReport()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.start_date, filters.end_date])

  const fetchReport = () => {
    if (!filters.start_date || !filters.end_date) {
      toast.error('Start and end date are required')
      return
    }

    setLoading(true)
    reportsService
      .technicianMargins({
        start_date: filters.start_date,
        end_date: filters.end_date,
        branch_id: filters.branch_id || undefined,
      })
      .then((data) => {
        setReport({
          data: data.data || [],
          summary: data.summary || report.summary,
        })
      })
      .catch(() => toast.error('Failed to load technician margin report'))
      .finally(() => setLoading(false))
  }

  const technicianChartData = useMemo(() => {
    if (!report.data.length) {
      return { labels: [], datasets: [] }
    }

    return {
      labels: report.data.map((row) => row.technician_name || `Tech #${row.technician_id}`),
      datasets: [
        {
          label: 'Billed Labor',
          data: report.data.map((row) => row.billed_labor),
          backgroundColor: 'rgba(59, 130, 246, 0.8)',
        },
        {
          label: 'Actual Labor Cost',
          data: report.data.map((row) => row.actual_labor_cost),
          backgroundColor: 'rgba(239, 68, 68, 0.75)',
        },
        {
          label: 'Margin',
          data: report.data.map((row) => row.margin),
          backgroundColor: 'rgba(16, 185, 129, 0.75)',
        },
      ],
    }
  }, [report.data])

  const marginPercentageData = useMemo(() => {
    if (!report.data.length) {
      return { labels: [], datasets: [] }
    }

    return {
      labels: report.data.map((row) => row.technician_name || `Tech #${row.technician_id}`),
      datasets: [
        {
          label: 'Margin %',
          data: report.data.map((row) => row.margin_percentage),
          borderColor: 'rgba(16, 185, 129, 0.9)',
          backgroundColor: 'rgba(16, 185, 129, 0.2)',
          tension: 0.3,
        },
      ],
    }
  }, [report.data])

  const currencyChartOptions = {
    plugins: {
      tooltip: {
        callbacks: {
          label: (context) => `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`,
        },
      },
    },
    scales: {
      y: {
        ticks: {
          callback: (value) => formatCurrency(value),
        },
      },
    },
  }

  const marginChartOptions = {
    plugins: {
      tooltip: {
        callbacks: {
          label: (context) => `${context.dataset.label}: ${formatPercent(context.parsed.y)}`,
        },
      },
    },
    scales: {
      y: {
        ticks: {
          callback: (value) => formatPercent(value),
        },
      },
    },
  }

  const branchOptions = useMemo(
    () => [
      { value: '', label: 'All branches' },
      ...branches.map((branch) => ({
        value: branch.id,
        label: branch.label,
      })),
    ],
    [branches],
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-gray-900">Technician Efficiency Dashboard</h1>
        <p className="text-sm text-gray-600">Track billed labor vs. actual labor cost by technician.</p>
      </div>

      <Card className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Start Date</label>
            <Input
              modelValue={filters.start_date}
              type="date"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, start_date: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">End Date</label>
            <Input
              modelValue={filters.end_date}
              type="date"
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, end_date: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Branch (optional)</label>
            <Select
              modelValue={filters.branch_id}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, branch_id: value }))}
              options={branchOptions}
              placeholder=""
            />
          </div>
          <div className="flex items-end">
            <Button className="w-full" onClick={fetchReport} disabled={loading}>
              {loading ? 'Loading...' : 'Refresh'}
            </Button>
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <Card>
          <p className="text-sm text-gray-600">Total Billed Labor</p>
          <p className="text-2xl font-semibold text-gray-900">{formatCurrency(report.summary.total_billed_labor)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-600">Actual Labor Cost</p>
          <p className="text-2xl font-semibold text-gray-900">{formatCurrency(report.summary.total_actual_cost)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-600">Total Margin</p>
          <p className="text-2xl font-semibold text-gray-900">{formatCurrency(report.summary.total_margin)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-600">Overall Margin %</p>
          <p className="text-2xl font-semibold text-gray-900">{formatPercent(report.summary.overall_margin_percentage)}</p>
          <p className="text-xs text-gray-500">Default labor rate: {formatCurrency(report.summary.default_labor_rate)}/hr</p>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card className="h-96">
          <h3 className="text-lg font-semibold text-gray-900 mb-2">Billed vs. Actual Labor</h3>
          <BarChart data={technicianChartData} options={currencyChartOptions} />
        </Card>
        <Card className="h-96">
          <h3 className="text-lg font-semibold text-gray-900 mb-2">Margin Percentage by Technician</h3>
          <LineChart data={marginPercentageData} options={marginChartOptions} />
        </Card>
      </div>

      <Card>
        <h3 className="text-lg font-semibold text-gray-900 mb-4">Technician Margin Details</h3>
        {report.data.length ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Technician</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Billed Labor</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Cost</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Margin</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Margin %</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Minutes</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {report.data.map((row) => (
                  <tr key={row.technician_id}>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900">
                      {row.technician_name || `Tech #${row.technician_id}`}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-700 text-right">{formatCurrency(row.billed_labor)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 text-right">{formatCurrency(row.actual_labor_cost)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 text-right">{formatCurrency(row.margin)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 text-right">{formatPercent(row.margin_percentage)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 text-right">{Number(row.actual_minutes || 0).toFixed(0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-gray-500">No technician labor data found for the selected range.</p>
        )}
      </Card>
    </div>
  )
}

export default function Reports() {
  const [activeTab, setActiveTab] = useState('technician')

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap gap-2">
        {tabs.map((tab) => (
          <Button
            key={tab.key}
            variant={activeTab === tab.key ? 'primary' : 'secondary'}
            onClick={() => setActiveTab(tab.key)}
          >
            {tab.label}
          </Button>
        ))}
      </div>

      {activeTab === 'technician' ? <TechnicianMargins /> : null}
      {activeTab === 'financial' ? <FinancialReports /> : null}
    </div>
  )
}
