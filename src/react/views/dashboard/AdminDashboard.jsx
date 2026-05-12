import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import Alert from '../../components/ui/Alert'
import Badge from '../../components/ui/Badge'
import Button from '../../components/ui/Button'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import LineChart from '../../components/charts/LineChart'
import DoughnutChart from '../../components/charts/DoughnutChart'
import Select from '../../components/ui/Select'
import branchesService from '../../../services/branches.service'
import dashboardService from '../../../services/dashboard.service'
import { useAuthStore } from '../../stores/auth.jsx'
import { useToast } from '../../stores/toast.jsx'

const formatCurrency = (value) =>
  new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value || 0)

const formatDate = (date) => {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

const formatTime = (value) => {
  if (!value) return ''
  if (typeof value === 'string' && value.includes(':') && value.length <= 8) {
    return value
  }
  return new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

const formatAgeDays = (days) => {
  if (days === null || days === undefined) return '—'
  return `${days} day${days === 1 ? '' : 's'}`
}

const getInvoiceStatusVariant = (status) => {
  const variants = {
    paid: 'success',
    pending: 'warning',
    overdue: 'danger',
    draft: 'default',
    cancelled: 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const getAppointmentStatusVariant = (status) => {
  const variants = {
    confirmed: 'success',
    pending: 'warning',
    completed: 'info',
    cancelled: 'danger',
    'no-show': 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const getSeverityVariant = (severity) => {
  const variants = {
    out: 'danger',
    low: 'warning',
  }

  return variants[severity] || 'default'
}

const getPullRequestStatusVariant = (status) => {
  const variants = {
    pending: 'warning',
    ordered: 'info',
    received: 'success',
    pulled: 'success',
    cancelled: 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

const formatPullRequestStatus = (status) => {
  const normalized = status?.toLowerCase()
  if (normalized === 'pending') return 'Requested'
  if (!normalized) return ''
  return normalized.charAt(0).toUpperCase() + normalized.slice(1)
}

const formatMonthLabel = (yearMonth) => {
  if (!yearMonth) return ''
  const [year, month] = yearMonth.split('-')
  const date = new Date(Number(year), Number(month) - 1)
  return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
}

function StatCard({ title, value, icon, trend, trendUp, secondaryText, secondaryClassName = '' }) {
  return (
    <Card className="relative overflow-hidden">
      <div className="flex items-center">
        <div className="flex-1">
          <p className="text-sm font-medium text-gray-500">{title}</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
          {secondaryText ? (
            <p className={`mt-1 text-sm text-gray-500 ${secondaryClassName}`}>{secondaryText}</p>
          ) : null}
          {trend ? (
            <p className={`mt-1 text-sm ${trendUp ? 'text-green-600' : 'text-red-600'}`}>
              {trendUp ? '+' : ''}{trend}% from last month
            </p>
          ) : null}
        </div>
        <div className="p-3 bg-primary-100 rounded-lg">
          {icon}
        </div>
      </div>
    </Card>
  )
}

export default function AdminDashboard() {
  const { user } = useAuthStore()
  const toast = useToast()
  const [stats, setStats] = useState({
    totalRevenue: 0,
    revenueChange: 0,
    pendingInvoices: 0,
    pendingAmount: 0,
    todayAppointments: 0,
    upcomingAppointments: 0,
    activeCustomers: 0,
    newCustomers: 0,
  })
  const [recentInvoices, setRecentInvoices] = useState([])
  const [recentAppointments, setRecentAppointments] = useState([])
  const [inventoryAlerts, setInventoryAlerts] = useState({
    counts: { out_of_stock: 0, low_stock: 0 },
    items: [],
  })
  const [inventoryPullRequests, setInventoryPullRequests] = useState({
    counts: { pending: 0, ordered: 0, received: 0 },
    items: [],
  })
  const [wipAging, setWipAging] = useState({
    total: 0,
    oldest_days: null,
    buckets: [],
  })
  const [branches, setBranches] = useState([])
  const [branchScope, setBranchScope] = useState('all')
  const [revenueChartData, setRevenueChartData] = useState({ labels: [], datasets: [] })
  const [serviceTypeChartData, setServiceTypeChartData] = useState({ labels: [], datasets: [] })
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState(null)
  const branchScopeInitialized = useRef(false)

  const technicianId = useMemo(() => (user?.role === 'technician' ? user.id : null), [user])
  const technicianParams = useMemo(() => (technicianId ? { technician_id: technicianId } : {}), [technicianId])
  const branchParams = useMemo(() => (
    branchScope === 'current' && user?.branch_id ? { branch_id: user.branch_id } : {}
  ), [branchScope, user?.branch_id])
  const chartRange = useMemo(() => {
    const endDate = new Date()
    const startDate = new Date()
    startDate.setMonth(startDate.getMonth() - 6)
    return {
      start: startDate.toISOString().split('T')[0],
      end: endDate.toISOString().split('T')[0],
    }
  }, [])

  useEffect(() => {
    branchesService
      .list()
      .then((data) => setBranches(Array.isArray(data) ? data : []))
      .catch(() => toast.error('Failed to load branches'))
  }, [toast])

  useEffect(() => {
    if (branchScopeInitialized.current) {
      return
    }
    if (user?.branch_id) {
      setBranchScope('current')
    }
    branchScopeInitialized.current = true
  }, [user])

  const loadDashboard = useCallback(async () => {
    setLoading(true)
    try {
      setErrorMessage(null)
      const [
        statsRes,
        invoicesRes,
        appointmentsRes,
        lowStockRes,
        pullRequestRes,
        wipAgingRes,
        trendsRes,
        servicesRes,
      ] = await Promise.all([
        dashboardService
          .getStats({ start: chartRange.start, end: chartRange.end, ...technicianParams, ...branchParams })
          .catch(() => ({})),
        dashboardService.getRecentInvoices(5, { ...technicianParams, ...branchParams }).catch(() => []),
        dashboardService.getRecentAppointments(5, { ...technicianParams, ...branchParams }).catch(() => []),
        dashboardService.getInventoryLowStockTile(5, { ...branchParams }).catch(() => null),
        dashboardService.getInventoryPullRequests(5, { ...branchParams }).catch(() => null),
        dashboardService.getWipAging({ ...technicianParams, ...branchParams }).catch(() => null),
        dashboardService
          .getMonthlyTrendsChart({ start: chartRange.start, end: chartRange.end, ...technicianParams, ...branchParams })
          .catch(() => []),
        dashboardService
          .getServiceTypeChart({ start: chartRange.start, end: chartRange.end, limit: 8, ...technicianParams, ...branchParams })
          .catch(() => ({ categories: [], data: [] })),
      ])

      setStats({
        totalRevenue: statsRes.total_revenue || 0,
        revenueChange: statsRes.revenue_change || 0,
        pendingInvoices: statsRes.pending_invoices || 0,
        pendingAmount: statsRes.pending_amount || 0,
        todayAppointments: statsRes.today_appointments || 0,
        upcomingAppointments: statsRes.upcoming_appointments || 0,
        activeCustomers: statsRes.active_customers || 0,
        newCustomers: statsRes.new_customers || 0,
      })

      setRecentInvoices(invoicesRes?.data || invoicesRes || [])
      setRecentAppointments(appointmentsRes?.data || appointmentsRes || [])
      setInventoryAlerts({
        counts: lowStockRes?.counts || { out_of_stock: 0, low_stock: 0 },
        items: lowStockRes?.items || [],
      })
      setInventoryPullRequests({
        counts: pullRequestRes?.counts || { pending: 0, ordered: 0, received: 0 },
        items: pullRequestRes?.items || [],
      })
      setWipAging({
        total: wipAgingRes?.total || 0,
        oldest_days: wipAgingRes?.oldest_days ?? null,
        buckets: wipAgingRes?.buckets || [],
      })

      const trendSeries = Array.isArray(trendsRes)
        ? trendsRes
        : Array.isArray(trendsRes?.data)
          ? trendsRes.data
          : []

      if (trendSeries.length > 0) {
        const categories = Array.isArray(trendsRes?.categories)
          ? trendsRes.categories
          : trendSeries[0]?.categories || []
        setRevenueChartData({
          labels: categories.map(formatMonthLabel),
          datasets: trendSeries.map((series, index) => ({
            label: series.label,
            data: series.data,
            borderColor: index === 0 ? '#3B82F6' : '#10B981',
            backgroundColor: index === 0 ? 'rgba(59, 130, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)',
            tension: 0.4,
            fill: true,
          })),
        })
      } else {
        setRevenueChartData({ labels: [], datasets: [] })
      }

      const serviceCategories = Array.isArray(servicesRes?.categories)
        ? servicesRes.categories
        : Array.isArray(servicesRes?.labels)
          ? servicesRes.labels
          : []
      if (serviceCategories.length > 0) {
        setServiceTypeChartData({
          labels: serviceCategories,
          datasets: [
            {
              data: servicesRes.data || [],
              backgroundColor: [
                '#3B82F6',
                '#10B981',
                '#F59E0B',
                '#EF4444',
                '#8B5CF6',
                '#EC4899',
                '#14B8A6',
                '#F97316',
              ],
            },
          ],
        })
      } else {
        setServiceTypeChartData({ labels: [], datasets: [] })
      }
    } catch {
      setErrorMessage('Failed to load dashboard data. Please try again.')
      toast.error('Failed to load dashboard data')
    } finally {
      setLoading(false)
    }
  }, [branchParams, chartRange.end, chartRange.start, technicianParams, toast])

  const currentBranchLabel = useMemo(() => {
    if (!user?.branch_id) {
      return null
    }
    const match = branches.find((branch) => Number(branch.id) === Number(user.branch_id))
    return match?.label || `#${user.branch_id}`
  }, [branches, user?.branch_id])

  const branchOptions = useMemo(() => {
    const options = [{ value: 'all', label: 'All Locations' }]
    if (user?.branch_id) {
      const label = currentBranchLabel ? `Current Branch (${currentBranchLabel})` : 'Current Branch'
      options.push({ value: 'current', label })
    }
    return options
  }, [currentBranchLabel, user?.branch_id])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  const wipStatusParam = 'parts_pending,awaiting_authorization'
  const buildWipLink = useCallback((bucket) => {
    const params = new URLSearchParams({
      status: wipStatusParam,
      status_age_min_days: String(bucket.min_days ?? 0),
    })
    if (branchScope === 'current' && user?.branch_id) {
      params.set('branch_id', String(user.branch_id))
    }
    if (bucket.max_days !== null && bucket.max_days !== undefined) {
      params.set('status_age_max_days', String(bucket.max_days))
    }
    return `/cp/workorders?${params.toString()}`
  }, [branchScope, user?.branch_id, wipStatusParam])

  return (
    <div>
      <div className="mb-8">
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p className="mt-1 text-sm text-gray-500">Overview of your auto repair shop</p>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Loading size="xl" text="Loading dashboard..." />
        </div>
      ) : errorMessage ? (
        <Alert variant="danger" className="mb-6" closable={false}>
          {errorMessage}
        </Alert>
      ) : (
        <div className="space-y-8">
          <Card>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Location</label>
                <Select
                  modelValue={branchScope}
                  onUpdateModelValue={setBranchScope}
                  options={branchOptions}
                  placeholder=""
                />
              </div>
            </div>
          </Card>
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="Total Revenue"
              value={formatCurrency(stats.totalRevenue)}
              trend={stats.revenueChange}
              trendUp={stats.revenueChange >= 0}
              icon={
                <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              }
            />
            <StatCard
              title="Pending Invoices"
              value={stats.pendingInvoices || 0}
              secondaryText={formatCurrency(stats.pendingAmount || 0)}
              icon={
                <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              }
            />
            <StatCard
              title="Today&apos;s Appointments"
              value={stats.todayAppointments || 0}
              secondaryText={`${stats.upcomingAppointments || 0} upcoming`}
              icon={
                <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              }
            />
            <StatCard
              title="Active Customers"
              value={stats.activeCustomers || 0}
              secondaryText={stats.newCustomers ? `+${stats.newCustomers} new` : ''}
              secondaryClassName={stats.newCustomers ? 'text-green-600' : ''}
              icon={
                <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              }
            />
          </div>

          <Card title="Quick Actions">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Link to="/cp/invoices/create">
                <Button variant="outline" className="justify-center w-full">
                  <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                  </svg>
                  New Invoice
                </Button>
              </Link>
              <Link to="/cp/appointments/create">
                <Button variant="outline" className="justify-center w-full">
                  <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                  New Appointment
                </Button>
              </Link>
              <Link to="/cp/customers/create">
                <Button variant="outline" className="justify-center w-full">
                  <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                  </svg>
                  New Customer
                </Button>
              </Link>
              <Link to="/cp/vehicles/create">
                <Button variant="outline" className="justify-center w-full">
                  <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Add Vehicle
                </Button>
              </Link>
            </div>
          </Card>

          <Card
            header={
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-lg font-medium text-gray-900">Work-in-Progress Aging</h3>
                  <p className="text-sm text-gray-500">
                    Parts pending or authorized workorders awaiting progress
                  </p>
                </div>
                <div className="text-sm text-gray-500">
                  Oldest: <span className="font-medium text-gray-900">{formatAgeDays(wipAging.oldest_days)}</span>
                </div>
              </div>
            }
          >
            {wipAging.buckets.length === 0 ? (
              <div className="text-sm text-gray-600">No workorders currently in parts pending or authorized.</div>
            ) : (
              <div className="divide-y divide-gray-200">
                {wipAging.buckets.map((bucket) => (
                  <Link
                    key={bucket.label}
                    to={buildWipLink(bucket)}
                    className="flex items-center justify-between py-3 px-4 -mx-4 hover:bg-gray-50"
                  >
                    <div>
                      <p className="text-sm font-medium text-gray-900">{bucket.label}</p>
                      <p className="text-xs text-gray-500">Oldest {formatAgeDays(bucket.oldest_days)}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-lg font-semibold text-gray-900">{bucket.count || 0}</p>
                      <p className="text-xs text-gray-500">workorders</p>
                    </div>
                  </Link>
                ))}
              </div>
            )}
          </Card>

          <Card
            className="mb-8"
            header={
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-lg font-medium text-gray-900">Inventory Alerts</h3>
                  <p className="text-sm text-gray-500">Low and out-of-stock items that need attention</p>
                </div>
                <div className="flex gap-2">
                  <Link to="/cp/inventory/stock-orders">
                    <Button variant="outline">Stock orders</Button>
                  </Link>
                  <Link to="/cp/inventory/alerts">
                    <Button variant="outline">View alerts</Button>
                  </Link>
                </div>
              </div>
            }
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="p-4 rounded-lg bg-red-50 border border-red-100">
                <p className="text-sm font-medium text-red-700">Out of Stock</p>
                <p className="mt-2 text-3xl font-bold text-red-800">
                  {inventoryAlerts.counts.out_of_stock || 0}
                </p>
                <p className="text-sm text-red-600">Items unavailable for sale</p>
              </div>
              <div className="p-4 rounded-lg bg-amber-50 border border-amber-100">
                <p className="text-sm font-medium text-amber-700">Low Stock</p>
                <p className="mt-2 text-3xl font-bold text-amber-800">
                  {inventoryAlerts.counts.low_stock || 0}
                </p>
                <p className="text-sm text-amber-600">Items approaching threshold</p>
              </div>
            </div>
            <div className="mt-6">
              {inventoryAlerts.items.length === 0 ? (
                <div className="text-sm text-gray-600">All tracked items are above their low-stock thresholds.</div>
              ) : (
                <div className="divide-y divide-gray-200">
                  {inventoryAlerts.items.map((item) => (
                    <div key={item.id} className="py-3 flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-gray-900">{item.name}</p>
                        <p className="text-xs text-gray-500">
                          {item.stock_quantity} in stock • Threshold {item.low_stock_threshold}
                        </p>
                      </div>
                      <Badge variant={getSeverityVariant(item.severity)}>
                        {item.severity === 'out' ? 'Out of Stock' : 'Low Stock'}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </Card>

          <Card
            header={
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-lg font-medium text-gray-900">Inventory Pull Requests</h3>
                  <p className="text-sm text-gray-500">Recent stock pull and order activity</p>
                </div>
              </div>
            }
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div className="p-4 rounded-lg bg-amber-50 border border-amber-100">
                <p className="text-sm font-medium text-amber-700">Requested</p>
                <p className="mt-2 text-3xl font-bold text-amber-800">
                  {inventoryPullRequests.counts.pending || 0}
                </p>
                <p className="text-sm text-amber-600">Awaiting action</p>
              </div>
              <div className="p-4 rounded-lg bg-blue-50 border border-blue-100">
                <p className="text-sm font-medium text-blue-700">Ordered</p>
                <p className="mt-2 text-3xl font-bold text-blue-800">
                  {inventoryPullRequests.counts.ordered || 0}
                </p>
                <p className="text-sm text-blue-600">On order or en route</p>
              </div>
              <div className="p-4 rounded-lg bg-emerald-50 border border-emerald-100">
                <p className="text-sm font-medium text-emerald-700">Received</p>
                <p className="mt-2 text-3xl font-bold text-emerald-800">
                  {inventoryPullRequests.counts.received || 0}
                </p>
                <p className="text-sm text-emerald-600">Fulfilled items</p>
              </div>
            </div>
            <div className="mt-6">
              {inventoryPullRequests.items.length === 0 ? (
                <div className="text-sm text-gray-600">No recent pull or order activity.</div>
              ) : (
                <div className="divide-y divide-gray-200">
                  {inventoryPullRequests.items.map((request) => (
                    <div key={request.id} className="py-3 flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium text-gray-900">
                          {request.inventory_item_name || request.description}
                        </p>
                        <p className="text-xs text-gray-500">
                          Qty {request.quantity_requested} • Workorder {request.workorder_number || request.workorder_id}
                          {request.request_type ? ` • ${request.request_type === 'order' ? 'Order' : 'Pull'}` : ''}
                        </p>
                      </div>
                      <Badge variant={getPullRequestStatusVariant(request.status)}>
                        {formatPullRequestStatus(request.status)}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </Card>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card
              header={
                <div>
                  <h3 className="text-lg font-medium text-gray-900">Monthly Revenue Trends</h3>
                  <p className="text-sm text-gray-500">Last 6 months</p>
                </div>
              }
            >
              <div className="h-64">
                {revenueChartData.labels.length ? (
                  <LineChart data={revenueChartData} />
                ) : (
                  <div className="flex items-center justify-center h-full text-gray-500 text-sm">No data available</div>
                )}
              </div>
            </Card>

            <Card
              header={
                <div>
                  <h3 className="text-lg font-medium text-gray-900">Top Service Types</h3>
                  <p className="text-sm text-gray-500">Last 6 months</p>
                </div>
              }
            >
              <div className="h-64">
                {serviceTypeChartData.labels.length ? (
                  <DoughnutChart data={serviceTypeChartData} />
                ) : (
                  <div className="flex items-center justify-center h-full text-gray-500 text-sm">No data available</div>
                )}
              </div>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Card
              header={
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-medium text-gray-900">Recent Invoices</h3>
                  <Link to="/cp/invoices" className="text-sm font-medium text-primary-600 hover:text-primary-500">
                    View all
                  </Link>
                </div>
              }
            >
              {recentInvoices.length === 0 ? (
                <div className="text-center py-6 text-gray-500">No recent invoices</div>
              ) : (
                <div className="divide-y divide-gray-200">
                  {recentInvoices.map((invoice) => (
                    <Link
                      key={invoice.id}
                      to={`/cp/invoices/${invoice.id}`}
                      className="block py-4 flex items-center justify-between hover:bg-gray-50 px-4 -mx-4"
                    >
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-3">
                          <p className="text-sm font-medium text-gray-900">
                            #{invoice.invoice_number ?? invoice.number ?? invoice.id}
                          </p>
                          <Badge variant={getInvoiceStatusVariant(invoice.status)}>{invoice.status}</Badge>
                        </div>
                        <p className="mt-1 text-sm text-gray-500">
                          {invoice.customer_name || invoice.customer?.name || 'Unknown'} -{' '}
                          {formatDate(invoice.created_at)}
                        </p>
                      </div>
                      <div className="ml-4 flex-shrink-0 text-right">
                        <p className="text-sm font-semibold text-gray-900">
                          {formatCurrency(invoice.total_amount ?? invoice.total ?? 0)}
                        </p>
                      </div>
                    </Link>
                  ))}
                </div>
              )}
            </Card>

            <Card
              header={
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-medium text-gray-900">Upcoming Appointments</h3>
                  <Link to="/cp/appointments" className="text-sm font-medium text-primary-600 hover:text-primary-500">
                    View all
                  </Link>
                </div>
              }
            >
              {recentAppointments.length === 0 ? (
                <div className="text-center py-6 text-gray-500">No upcoming appointments</div>
              ) : (
                <div className="divide-y divide-gray-200">
                  {recentAppointments.map((appointment) => (
                    <Link
                      key={appointment.id}
                      to={`/cp/appointments/${appointment.id}`}
                      className="block py-4 hover:bg-gray-50 px-4 -mx-4"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-3">
                            <p className="text-sm font-medium text-gray-900">
                              {appointment.customer_name || appointment.customer?.name || 'Unknown'}
                            </p>
                            <Badge variant={getAppointmentStatusVariant(appointment.status)}>{appointment.status}</Badge>
                          </div>
                          <p className="mt-1 text-sm text-gray-500">
                            {appointment.service_type || appointment.service?.name || 'Service'}
                          </p>
                        </div>
                        <div className="ml-4 flex-shrink-0 text-right">
                          <p className="text-sm font-medium text-gray-900">
                            {formatDate(appointment.scheduled_date || appointment.start_time)}
                          </p>
                          <p className="text-sm text-gray-500">
                            {formatTime(appointment.scheduled_time || appointment.start_time)}
                          </p>
                        </div>
                      </div>
                    </Link>
                  ))}
                </div>
              )}
            </Card>
          </div>
        </div>
      )}
    </div>
  )
}
