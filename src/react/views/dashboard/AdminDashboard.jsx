import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

import Badge from '../../components/ui/Badge'
import Card from '../../components/ui/Card'
import Loading from '../../components/ui/Loading'
import LineChart from '../../components/charts/LineChart'
import DoughnutChart from '../../components/charts/DoughnutChart'
import dashboardService from '../../../services/dashboard.service'
import { useToast } from '../../stores/toast.jsx'

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(value || 0)
}

const formatDate = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString()
}

const statusVariant = (status) => {
  switch (status) {
    case 'paid':
    case 'completed':
      return 'success'
    case 'pending':
    case 'scheduled':
      return 'warning'
    case 'sent':
    case 'confirmed':
      return 'info'
    case 'cancelled':
    case 'overdue':
      return 'danger'
    default:
      return 'default'
  }
}

function StatCard({ title, value, icon, trend, trendUp }) {
  return (
    <Card className="relative overflow-hidden">
      <div className="flex items-center">
        <div className="flex-1">
          <p className="text-sm font-medium text-gray-500">{title}</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
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
  const [stats, setStats] = useState(null)
  const [recentInvoices, setRecentInvoices] = useState([])
  const [recentAppointments, setRecentAppointments] = useState([])
  const [chartData, setChartData] = useState(null)
  const [serviceData, setServiceData] = useState(null)
  const [loading, setLoading] = useState(true)
  const { error } = useToast()

  const loadDashboard = useCallback(async () => {
    setLoading(true)
    try {
      const [statsRes, invoicesRes, appointmentsRes, trendsRes, servicesRes] = await Promise.all([
        dashboardService.getStats(),
        dashboardService.getRecentInvoices(5),
        dashboardService.getRecentAppointments(5),
        dashboardService.getMonthlyTrendsChart().catch(() => null),
        dashboardService.getServiceTypeChart().catch(() => null),
      ])

      setStats(statsRes)
      setRecentInvoices(Array.isArray(invoicesRes) ? invoicesRes : invoicesRes?.data || [])
      setRecentAppointments(Array.isArray(appointmentsRes) ? appointmentsRes : appointmentsRes?.data || [])

      if (trendsRes?.labels && trendsRes?.datasets) {
        setChartData({
          labels: trendsRes.labels,
          datasets: trendsRes.datasets.map((ds) => ({
            ...ds,
            borderColor: ds.borderColor || '#3b82f6',
            backgroundColor: ds.backgroundColor || 'rgba(59,130,246,0.3)',
            tension: 0.4,
          })),
        })
      }

      if (servicesRes?.labels && servicesRes?.data) {
        setServiceData({
          labels: servicesRes.labels,
          datasets: [
            {
              data: servicesRes.data,
              backgroundColor: servicesRes.colors || [
                '#3b82f6',
                '#10b981',
                '#f59e0b',
                '#ef4444',
                '#8b5cf6',
                '#ec4899',
              ],
            },
          ],
        })
      }
    } catch {
      error('Failed to load dashboard data')
    } finally {
      setLoading(false)
    }
  }, [error])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  if (loading) {
    return (
      <div className="flex justify-center items-center min-h-96">
        <Loading text="Loading dashboard..." />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p className="mt-1 text-sm text-gray-500">Welcome back! Here&apos;s what&apos;s happening.</p>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard
          title="Total Revenue"
          value={formatCurrency(stats?.total_revenue)}
          trend={stats?.revenue_trend}
          trendUp={stats?.revenue_trend > 0}
          icon={
            <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          }
        />
        <StatCard
          title="Appointments Today"
          value={stats?.appointments_today ?? 0}
          icon={
            <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          }
        />
        <StatCard
          title="Pending Invoices"
          value={stats?.pending_invoices ?? 0}
          icon={
            <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
          }
        />
        <StatCard
          title="Total Customers"
          value={stats?.total_customers ?? 0}
          trend={stats?.customers_trend}
          trendUp={stats?.customers_trend > 0}
          icon={
            <svg className="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
          }
        />
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Revenue Trends</h3>
          <div className="h-64">
            {chartData ? (
              <LineChart
                data={chartData}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { position: 'bottom' } },
                }}
              />
            ) : (
              <div className="flex items-center justify-center h-full text-gray-400">
                No trend data available
              </div>
            )}
          </div>
        </Card>

        <Card>
          <h3 className="text-lg font-medium text-gray-900 mb-4">Services Breakdown</h3>
          <div className="h-64">
            {serviceData ? (
              <DoughnutChart
                data={serviceData}
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { position: 'bottom' } },
                }}
              />
            ) : (
              <div className="flex items-center justify-center h-full text-gray-400">
                No service data available
              </div>
            )}
          </div>
        </Card>
      </div>

      {/* Tables Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Recent Invoices */}
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Recent Invoices</h3>
            <Link to="/cp/invoices" className="text-sm text-primary-600 hover:text-primary-500">
              View all
            </Link>
          </div>
          {recentInvoices.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No recent invoices</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {recentInvoices.map((invoice) => (
                    <tr key={invoice.id}>
                      <td className="px-3 py-2 text-sm">
                        <Link to={`/cp/invoices/${invoice.id}`} className="text-primary-600 hover:text-primary-500">
                          {invoice.number}
                        </Link>
                      </td>
                      <td className="px-3 py-2 text-sm">
                        <Badge size="sm" variant={statusVariant(invoice.status)}>
                          {invoice.status}
                        </Badge>
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-900">
                        {formatCurrency(invoice.total)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        {/* Recent Appointments */}
        <Card>
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Upcoming Appointments</h3>
            <Link to="/cp/appointments" className="text-sm text-primary-600 hover:text-primary-500">
              View all
            </Link>
          </div>
          {recentAppointments.length === 0 ? (
            <p className="text-gray-500 text-sm py-4">No upcoming appointments</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200">
                  {recentAppointments.map((appointment) => (
                    <tr key={appointment.id}>
                      <td className="px-3 py-2 text-sm">
                        <Link to={`/cp/appointments/${appointment.id}`} className="text-primary-600 hover:text-primary-500">
                          {appointment.customer?.name || 'Unknown'}
                        </Link>
                      </td>
                      <td className="px-3 py-2 text-sm text-gray-500">
                        {formatDate(appointment.scheduled_date)}
                      </td>
                      <td className="px-3 py-2 text-sm">
                        <Badge size="sm" variant={statusVariant(appointment.status)}>
                          {appointment.status}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}
