import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import BarChart from '../../components/charts/BarChart'
import LineChart from '../../components/charts/LineChart'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Modal from '../../components/ui/Modal'
import financialService from '../../../services/financial.service'
import { useToast } from '../../stores/toast.jsx'

export default function Reports() {
  const toast = useToast()
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState({
    summary: { asset: 0, liability: 0, income: 0, expense: 0, equity: 0 },
    net: 0,
    monthly: [],
  })
  const [reportSummary, setReportSummary] = useState({
    range: [],
    ytd_trends: [],
    tax_collected: 0,
    billable_hours: 0,
    paid_hours: 0,
    warranty_claims: { total: 0, by_status: {} },
    inventory: { on_hand: 0, total_cost: 0, total_value: 0 },
    service_type_stats: [],
  })
  const [cashDrawerLoading, setCashDrawerLoading] = useState(false)
  const [cashDrawer, setCashDrawer] = useState({ active: null, closeouts: [] })
  const [showStartDrawer, setShowStartDrawer] = useState(false)
  const [showCloseDrawer, setShowCloseDrawer] = useState(false)
  const [startDrawerForm, setStartDrawerForm] = useState({ start_float: '', notes: '' })
  const [closeDrawerForm, setCloseDrawerForm] = useState({ end_float: '', notes: '' })
  const [totals, setTotals] = useState({ asset: 0, liability: 0, income: 0, expense: 0, equity: 0 })
  const [filters, setFilters] = useState({ start_date: '', end_date: '', category: '' })
  const [categoryOptions, setCategoryOptions] = useState([])

  useEffect(() => {
    const today = new Date()
    const endDate = today.toISOString().slice(0, 10)
    const monthAgo = new Date()
    monthAgo.setMonth(monthAgo.getMonth() - 5)
    const startDate = monthAgo.toISOString().slice(0, 10)
    setFilters({ start_date: startDate, end_date: endDate, category: '' })
  }, [])

  useEffect(() => {
    financialService
      .listCategories()
      .then((data) => setCategoryOptions(data || []))
      .catch(() => toast.error('Failed to load categories'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (filters.start_date && filters.end_date) {
      fetchReport()
      fetchCashDrawer()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.start_date, filters.end_date])

  const fetchReport = () => {
    if (!filters.start_date || !filters.end_date) {
      toast.error('Start and end date are required')
      return
    }
    setLoading(true)
    Promise.all([financialService.report(filters), financialService.reportSummary(filters)])
      .then(([res, summaryRes]) => {
        setReport({
          summary: res.summary,
          net: res.net,
          monthly: res.monthly || [],
        })
        setTotals({
          asset: res.summary.asset || 0,
          liability: res.summary.liability || 0,
          income: res.summary.income || 0,
          expense: res.summary.expense || 0,
          equity: res.summary.equity || 0,
        })
        setReportSummary({
          range: summaryRes.range || [],
          ytd_trends: summaryRes.ytd_trends || [],
          tax_collected: summaryRes.tax_collected || 0,
          billable_hours: summaryRes.billable_hours || 0,
          paid_hours: summaryRes.paid_hours || 0,
          warranty_claims: summaryRes.warranty_claims || { total: 0, by_status: {} },
          inventory: summaryRes.inventory || { on_hand: 0, total_cost: 0, total_value: 0 },
          service_type_stats: summaryRes.service_type_stats || [],
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

  const fetchCashDrawer = () => {
    setCashDrawerLoading(true)
    Promise.all([
      financialService.cashDrawerActive(),
      financialService.cashDrawerCloseouts({ start_date: filters.start_date, end_date: filters.end_date }),
    ])
      .then(([active, closeouts]) => {
        setCashDrawer({ active: active || null, closeouts: closeouts || [] })
      })
      .catch(() => toast.error('Failed to load cash drawer closeouts'))
      .finally(() => {
        setCashDrawerLoading(false)
      })
  }

  const startCashDrawer = () => {
    financialService
      .cashDrawerStart({ ...startDrawerForm })
      .then(() => {
        toast.success('Cash drawer session started')
        setShowStartDrawer(false)
        setStartDrawerForm({ start_float: '', notes: '' })
        fetchCashDrawer()
      })
      .catch(() => toast.error('Failed to start cash drawer session'))
  }

  const closeCashDrawer = () => {
    if (!cashDrawer.active?.id) {
      toast.error('No active cash drawer session')
      return
    }
    financialService
      .cashDrawerClose(cashDrawer.active.id, { ...closeDrawerForm })
      .then(() => {
        toast.success('Cash drawer session closed')
        setShowCloseDrawer(false)
        setCloseDrawerForm({ end_float: '', notes: '' })
        fetchCashDrawer()
      })
      .catch(() => toast.error('Failed to close cash drawer session'))
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
          label: 'Assets',
          data: report.monthly.map((row) => row.summary.asset),
          backgroundColor: 'rgba(59, 130, 246, 0.8)',
        },
        {
          label: 'Liabilities',
          data: report.monthly.map((row) => row.summary.liability),
          backgroundColor: 'rgba(234, 88, 12, 0.8)',
        },
        {
          label: 'Equity',
          data: report.monthly.map((row) => row.summary.equity),
          backgroundColor: 'rgba(99, 102, 241, 0.8)',
        },
      ],
    }
  }, [report.monthly])

  const ytdChartData = useMemo(() => {
    if (!reportSummary.ytd_trends.length) {
      return { labels: [], datasets: [] }
    }

    return {
      labels: reportSummary.ytd_trends.map((row) => row.month),
      datasets: [
        {
          label: 'Income',
          data: reportSummary.ytd_trends.map((row) => row.income),
          borderColor: 'rgba(16, 185, 129, 0.9)',
          backgroundColor: 'rgba(16, 185, 129, 0.2)',
          tension: 0.3,
        },
        {
          label: 'Expenses',
          data: reportSummary.ytd_trends.map((row) => row.expense),
          borderColor: 'rgba(239, 68, 68, 0.9)',
          backgroundColor: 'rgba(239, 68, 68, 0.2)',
          tension: 0.3,
        },
        {
          label: 'Assets',
          data: reportSummary.ytd_trends.map((row) => row.asset),
          borderColor: 'rgba(59, 130, 246, 0.9)',
          backgroundColor: 'rgba(59, 130, 246, 0.2)',
          tension: 0.3,
        },
        {
          label: 'Liabilities',
          data: reportSummary.ytd_trends.map((row) => row.liability),
          borderColor: 'rgba(234, 88, 12, 0.9)',
          backgroundColor: 'rgba(234, 88, 12, 0.2)',
          tension: 0.3,
        },
        {
          label: 'Equity',
          data: reportSummary.ytd_trends.map((row) => row.equity),
          borderColor: 'rgba(99, 102, 241, 0.9)',
          backgroundColor: 'rgba(99, 102, 241, 0.2)',
          tension: 0.3,
        },
      ],
    }
  }, [reportSummary.ytd_trends])

  const serviceTypeChartData = useMemo(() => {
    if (!reportSummary.service_type_stats.length) {
      return { labels: [], datasets: [] }
    }

    return {
      labels: reportSummary.service_type_stats.map((row) => row.name),
      datasets: [
        {
          label: 'Revenue',
          data: reportSummary.service_type_stats.map((row) => row.revenue),
          backgroundColor: 'rgba(59, 130, 246, 0.8)',
        },
      ],
    }
  }, [reportSummary.service_type_stats])

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

  const formatCurrency = (value) => `$${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  const formatType = (value) => (value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unassigned')
  const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '—')
  const formatUserLabel = (row) => row?.opened_by_name || row?.closed_by_name || '—'

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Financial Reports</h1>
          <p className="text-sm text-gray-600">Monthly breakdowns by account type with CSV export.</p>
        </div>
        <Button variant="secondary" onClick={exportReport}>Export CSV</Button>
      </div>

      <Card className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Customer Retention</h2>
          <p className="text-sm text-gray-600">Review customers without workorders in 6+ months and trigger campaigns.</p>
        </div>
        <Link to="/cp/reports/customer-retention">
          <Button variant="secondary">View Retention Report</Button>
        </Link>
      </Card>

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
            <Select
              modelValue={filters.category}
              options={[
                { label: 'All Categories', value: '' },
                ...categoryOptions.map((category) => ({
                  label: `${category.name} (${formatType(category.type)})`,
                  value: category.name,
                })),
              ]}
              onUpdateModelValue={(value) => setFilters((prev) => ({ ...prev, category: value }))}
            />
          </div>
          <div className="flex items-end">
            <Button className="w-full" onClick={fetchReport}>Refresh</Button>
          </div>
        </div>
      </Card>

      <Card className="space-y-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-gray-900">Cash Drawer Closeouts</h2>
            <p className="text-sm text-gray-600">Track starting floats, reconciliation totals, and over/shorts by shift.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              onClick={() => setShowStartDrawer(true)}
              disabled={!!cashDrawer.active}
            >
              Start Shift
            </Button>
            <Button
              onClick={() => setShowCloseDrawer(true)}
              disabled={!cashDrawer.active}
            >
              Close Shift
            </Button>
          </div>
        </div>

        {cashDrawerLoading ? (
          <p className="text-sm text-gray-500">Loading cash drawer data...</p>
        ) : cashDrawer.active ? (
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <p className="text-xs uppercase text-gray-500">Shift Started</p>
              <p className="text-sm font-medium text-gray-900">{formatDateTime(cashDrawer.active.started_at)}</p>
            </div>
            <div>
              <p className="text-xs uppercase text-gray-500">Start Float</p>
              <p className="text-sm font-medium text-gray-900">{formatCurrency(cashDrawer.active.start_float)}</p>
            </div>
            <div>
              <p className="text-xs uppercase text-gray-500">Cash Sales</p>
              <p className="text-sm font-medium text-gray-900">{formatCurrency(cashDrawer.active.cash_sales)}</p>
            </div>
            <div>
              <p className="text-xs uppercase text-gray-500">Expected Cash</p>
              <p className="text-sm font-medium text-gray-900">{formatCurrency(cashDrawer.active.expected_cash)}</p>
            </div>
          </div>
        ) : (
          <p className="text-sm text-gray-500">No active cash drawer session. Start a shift to track floats.</p>
        )}
      </Card>

      <Card>
        <h3 className="text-lg font-semibold text-gray-900 mb-4">Shift Closeout Report</h3>
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Closed At</th>
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cashier</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Start Float</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash Sales</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expected</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Counted</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Over/Short</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {cashDrawer.closeouts.map((row) => (
              <tr key={row.id} className="hover:bg-gray-50">
                <td className="px-4 py-2 text-sm text-gray-900">{formatDateTime(row.ended_at)}</td>
                <td className="px-4 py-2 text-sm text-gray-600">{formatUserLabel(row)}</td>
                <td className="px-4 py-2 text-sm text-right">{formatCurrency(row.start_float)}</td>
                <td className="px-4 py-2 text-sm text-right">{formatCurrency(row.cash_sales)}</td>
                <td className="px-4 py-2 text-sm text-right">{formatCurrency(row.expected_cash)}</td>
                <td className="px-4 py-2 text-sm text-right">{formatCurrency(row.end_float)}</td>
                <td className={`px-4 py-2 text-sm text-right ${row.over_short >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {formatCurrency(row.over_short)}
                </td>
              </tr>
            ))}
            {!cashDrawer.closeouts.length && !cashDrawerLoading ? (
              <tr>
                <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={7}>No closeouts in range.</td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
        <Card>
          <p className="text-sm text-gray-500">Assets</p>
          <p className="text-2xl font-semibold text-blue-600">{formatCurrency(totals.asset)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Liabilities</p>
          <p className="text-2xl font-semibold text-orange-600">{formatCurrency(totals.liability)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Income</p>
          <p className="text-2xl font-semibold text-green-600">{formatCurrency(totals.income)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Expenses</p>
          <p className="text-2xl font-semibold text-red-600">{formatCurrency(totals.expense)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Equity</p>
          <p className="text-2xl font-semibold text-indigo-600">{formatCurrency(totals.equity)}</p>
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

      {ytdChartData.labels.length ? (
        <Card className="p-6">
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Year-to-Date Trends</h3>
          <div className="h-72">
            <LineChart data={ytdChartData} />
          </div>
        </Card>
      ) : null}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <p className="text-sm text-gray-500">Tax Collected</p>
          <p className="text-2xl font-semibold text-blue-600">{formatCurrency(reportSummary.tax_collected)}</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Billable Hours</p>
          <p className="text-2xl font-semibold text-gray-900">{Number(reportSummary.billable_hours).toFixed(2)} hrs</p>
        </Card>
        <Card>
          <p className="text-sm text-gray-500">Hours Paid to Techs</p>
          <p className="text-2xl font-semibold text-gray-900">{Number(reportSummary.paid_hours).toFixed(2)} hrs</p>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="space-y-3">
          <div>
            <p className="text-sm text-gray-500">Warranty Claims</p>
            <p className="text-2xl font-semibold text-gray-900">{reportSummary.warranty_claims.total}</p>
          </div>
          <div className="space-y-2">
            {Object.keys(reportSummary.warranty_claims.by_status || {}).length ? (
              Object.entries(reportSummary.warranty_claims.by_status).map(([status, count]) => (
                <div key={status} className="flex items-center justify-between text-sm text-gray-600">
                  <span className="capitalize">{status.replace('_', ' ')}</span>
                  <span className="font-medium text-gray-900">{count}</span>
                </div>
              ))
            ) : (
              <p className="text-sm text-gray-500">No warranty activity in range.</p>
            )}
          </div>
        </Card>
        <Card className="space-y-3">
          <div>
            <p className="text-sm text-gray-500">Inventory on Hand</p>
            <p className="text-2xl font-semibold text-gray-900">{reportSummary.inventory.on_hand}</p>
          </div>
          <div className="space-y-2 text-sm text-gray-600">
            <div className="flex items-center justify-between">
              <span>Total Cost</span>
              <span className="font-medium text-gray-900">{formatCurrency(reportSummary.inventory.total_cost)}</span>
            </div>
            <div className="flex items-center justify-between">
              <span>Total Value</span>
              <span className="font-medium text-gray-900">{formatCurrency(reportSummary.inventory.total_value)}</span>
            </div>
          </div>
        </Card>
        <Card className="space-y-3">
          <p className="text-sm text-gray-500">Service Type Stats</p>
          {serviceTypeChartData.labels.length ? (
            <div className="h-48">
              <BarChart
                data={serviceTypeChartData}
                options={{
                  ...chartOptions,
                  plugins: {
                    ...chartOptions.plugins,
                    legend: { display: false },
                  },
                  scales: {
                    y: {
                      beginAtZero: true,
                      ticks: {
                        callback: (value) => `$${value.toLocaleString()}`,
                      },
                    },
                  },
                }}
              />
            </div>
          ) : (
            <p className="text-sm text-gray-500">No service type revenue in range.</p>
          )}
        </Card>
      </div>

      {reportSummary.service_type_stats.length ? (
        <Card>
          <h3 className="text-lg font-semibold text-gray-900 mb-4">Service Type Revenue</h3>
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service Type</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Invoices</th>
                <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {reportSummary.service_type_stats.map((row) => (
                <tr key={row.id ?? row.name} className="hover:bg-gray-50">
                  <td className="px-4 py-2 text-sm text-gray-900">{row.name}</td>
                  <td className="px-4 py-2 text-sm text-right">{row.count}</td>
                  <td className="px-4 py-2 text-sm text-right">{formatCurrency(row.revenue)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      ) : null}

      <Card>
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Assets</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Liabilities</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Equity</th>
              <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {report.monthly.map((row) => (
              <tr key={row.month} className="hover:bg-gray-50">
                <td className="px-4 py-2 text-sm text-gray-900">{row.month}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.income.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.expense.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.asset.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.liability.toFixed(2)}</td>
                <td className="px-4 py-2 text-sm text-right">${row.summary.equity.toFixed(2)}</td>
                <td className={`px-4 py-2 text-sm text-right ${row.net >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                  ${row.net.toFixed(2)}
                </td>
              </tr>
            ))}
            {!report.monthly.length && !loading ? (
              <tr>
                <td className="px-4 py-6 text-center text-sm text-gray-500" colSpan={7}>No data found.</td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </Card>

      <Modal
        open={showStartDrawer}
        title="Start Cash Drawer Shift"
        onClose={() => setShowStartDrawer(false)}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Starting Float</label>
            <Input
              type="number"
              modelValue={startDrawerForm.start_float}
              onUpdateModelValue={(value) => setStartDrawerForm((prev) => ({ ...prev, start_float: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <textarea
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              rows={3}
              value={startDrawerForm.notes}
              onChange={(event) => setStartDrawerForm((prev) => ({ ...prev, notes: event.target.value }))}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setShowStartDrawer(false)}>Cancel</Button>
            <Button onClick={startCashDrawer}>Start Shift</Button>
          </div>
        </div>
      </Modal>

      <Modal
        open={showCloseDrawer}
        title="Close Cash Drawer Shift"
        onClose={() => setShowCloseDrawer(false)}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Ending Cash Count</label>
            <Input
              type="number"
              modelValue={closeDrawerForm.end_float}
              onUpdateModelValue={(value) => setCloseDrawerForm((prev) => ({ ...prev, end_float: value }))}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Notes</label>
            <textarea
              className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
              rows={3}
              value={closeDrawerForm.notes}
              onChange={(event) => setCloseDrawerForm((prev) => ({ ...prev, notes: event.target.value }))}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setShowCloseDrawer(false)}>Cancel</Button>
            <Button onClick={closeCashDrawer}>Close Shift</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
