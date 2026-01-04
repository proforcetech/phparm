import { useEffect, useMemo, useState } from 'react'

import BarChart from '../../components/charts/BarChart'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import financialService from '../../../services/financial.service'
import { useToast } from '../../stores/toast.jsx'

export default function Reports() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState({
    summary: { income: 0, expense: 0, purchase: 0 },
    net: 0,
    monthly: [],
  })
  const [totals, setTotals] = useState({ income: 0, expense: 0, purchase: 0 })
  const [filters, setFilters] = useState({ start_date: '', end_date: '', category: '' })

  useEffect(() => {
    const today = new Date()
    const endDate = today.toISOString().slice(0, 10)
    const monthAgo = new Date()
    monthAgo.setMonth(monthAgo.getMonth() - 5)
    const startDate = monthAgo.toISOString().slice(0, 10)
    setFilters({ start_date: startDate, end_date: endDate, category: '' })
  }, [])

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
    financialService
      .report(filters)
      .then((res) => {
        setReport({
          summary: res.summary,
          net: res.net,
          monthly: res.monthly || [],
        })
        setTotals({
          income: res.summary.income || 0,
          expense: res.summary.expense || 0,
          purchase: res.summary.purchase || 0,
        })
      })
      .catch(() => toast.error('Failed to load report'))
      .finally(() => {
        setLoading(false)
      })
  }

  const exportReport = () => {
    financialService
      .exportReport({ ...filters })
      .then((res) => {
        const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', res.filename || 'financial-report.csv')
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(url)
      })
      .catch(() => toast.error('Failed to export report'))
  }

  const chartData = useMemo(() => {
    if (!report.monthly || report.monthly.length === 0) {
      return { labels: [], datasets: [] }
    }

    return {
      labels: report.monthly.map((row) => row.month),
      datasets: [
        {
          label: 'Income',
          data: report.monthly.map((row) => row.summary.income),
          backgroundColor: 'rgba(16, 185, 129, 0.8)',
        },
        {
          label: 'Expenses',
          data: report.monthly.map((row) => row.summary.expense),
          backgroundColor: 'rgba(239, 68, 68, 0.8)',
        },
        {
          label: 'Purchases',
          data: report.monthly.map((row) => row.summary.purchase),
          backgroundColor: 'rgba(249, 115, 22, 0.8)',
        },
      ],
    }
  }, [report.monthly])

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
      },
      tooltip: {
        callbacks: {
          label: (context) => `${context.dataset.label}: $${context.parsed.y.toLocaleString()}`,
        },
      },
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: (value) => `$${value.toLocaleString()}`,
        },
      },
    },
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Financial Reports</h1>
          <p className="text-sm text-gray-600">Monthly breakdowns of income, expenses, and purchases with CSV export.</p>
        </div>
        <Button variant="secondary" onClick={exportReport}>Export CSV</Button>
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
            <label className="block text-sm font-medium text-gray-700">Category</label>
            <Input
              modelValue={filters.category}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, category: value }))}
            />
          </div>
          <div className="flex items-end">
            <Button className="w-full" onClick={fetchReport}>Refresh</Button>
          </div>
        </div>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <p className="text-sm text-gray-500">Income</p>
          <p className="text-2xl font-semibold text-green-600">${totals.income.toFixed(2)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Expenses</p>
          <p className="text-2xl font-semibold text-red-600">${totals.expense.toFixed(2)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Purchases</p>
          <p className="text-2xl font-semibold text-orange-500">${totals.purchase.toFixed(2)}</p>
        </Card>
      </div>

      {chartData.labels.length ? (
        <Card className="p-6 mb-6">
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Monthly Financial Trends</h3>
          <div className="h-80">
            <BarChart data={chartData} options={chartOptions} />
          </div>
        </Card>
      ) : null}

      <Card>
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Purchases</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {report.monthly.map((row) => (
              <tr key={row.month} className="hover:bg-gray-50">
                <td className="px-4 py-2 text-sm text-gray-900">{row.month}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.income.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.expense.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.purchase.toFixed(2)}</td>
                <td className={`px-4 py-2 text-sm text-right ${row.net >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                  ${row.net.toFixed(2)}
                </td>
              </tr>
            ))}
            {!report.monthly.length && !loading ? (
              <tr>
                <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={5}>No data found.</td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </Card>
    </div>
  )
}
