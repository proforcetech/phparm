<template>
  <div>
    <!-- Page Header -->
    <div class="mb-8">
      <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
      <p class="mt-1 text-sm text-gray-500">Overview of your auto repair shop</p>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center py-12">
      <Loading size="xl" text="Loading dashboard..." />
    </div>

    <!-- Error State -->
    <Alert v-else-if="error" variant="danger" class="mb-6">
      {{ error }}
    </Alert>

    <!-- Dashboard Content -->
    <div v-else>
      <!-- KPI Cards -->
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-8">
        <!-- Total Revenue -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-green-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Total Revenue</dt>
                <dd class="flex items-baseline">
                  <div class="text-2xl font-semibold text-gray-900">
                    {{ formatCurrency(stats.totalRevenue) }}
                  </div>
                  <div v-if="stats.revenueChange" class="ml-2 flex items-baseline text-sm font-semibold" :class="stats.revenueChange >= 0 ? 'text-green-600' : 'text-red-600'">
                    <svg v-if="stats.revenueChange >= 0" class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                    <svg v-else class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    {{ Math.abs(stats.revenueChange) }}%
                  </div>
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Pending Invoices -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Pending Invoices</dt>
                <dd class="flex items-baseline">
                  <div class="text-2xl font-semibold text-gray-900">
                    {{ stats.pendingInvoices || 0 }}
                  </div>
                  <div class="ml-2 text-sm text-gray-500">
                    {{ formatCurrency(stats.pendingAmount || 0) }}
                  </div>
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Upcoming Appointments -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-purple-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Today's Appointments</dt>
                <dd class="flex items-baseline">
                  <div class="text-2xl font-semibold text-gray-900">
                    {{ stats.todayAppointments || 0 }}
                  </div>
                  <div class="ml-2 text-sm text-gray-500">
                    {{ stats.upcomingAppointments || 0 }} upcoming
                  </div>
                </dd>
              </dl>
            </div>
          </div>
        </Card>

        <!-- Active Customers -->
        <Card>
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="flex items-center justify-center h-12 w-12 rounded-md bg-orange-500 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Active Customers</dt>
                <dd class="flex items-baseline">
                  <div class="text-2xl font-semibold text-gray-900">
                    {{ stats.activeCustomers || 0 }}
                  </div>
                  <div v-if="stats.newCustomers" class="ml-2 text-sm text-green-600">
                    +{{ stats.newCustomers }} new
                  </div>
                </dd>
              </dl>
            </div>
          </div>
        </Card>
      </div>

      <!-- Inventory Alerts -->
      <Card class="mb-8">
        <template #header>
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Inventory Alerts</h3>
              <p class="text-sm text-gray-500">Low and out-of-stock items that need attention</p>
            </div>
            <Button variant="outline" @click="$router.push('/cp/inventory/alerts')">
              View alerts
            </Button>
          </div>
        </template>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="p-4 rounded-lg bg-red-50 border border-red-100">
            <p class="text-sm font-medium text-red-700">Out of Stock</p>
            <p class="mt-2 text-3xl font-bold text-red-800">
              {{ inventoryAlerts.counts.out_of_stock || 0 }}
            </p>
            <p class="text-sm text-red-600">Items unavailable for sale</p>
          </div>

          <div class="p-4 rounded-lg bg-amber-50 border border-amber-100">
            <p class="text-sm font-medium text-amber-700">Low Stock</p>
            <p class="mt-2 text-3xl font-bold text-amber-800">
              {{ inventoryAlerts.counts.low_stock || 0 }}
            </p>
            <p class="text-sm text-amber-600">Items approaching threshold</p>
          </div>
        </div>

        <div class="mt-6">
          <div v-if="inventoryAlerts.items.length === 0" class="text-sm text-gray-600">
            All tracked items are above their low-stock thresholds.
          </div>
          <div v-else class="divide-y divide-gray-200">
            <div
              v-for="item in inventoryAlerts.items"
              :key="item.id"
              class="py-3 flex items-center justify-between"
            >
              <div>
                <p class="text-sm font-medium text-gray-900">{{ item.name }}</p>
                <p class="text-xs text-gray-500">
                  {{ item.stock_quantity }} in stock • Threshold {{ item.low_stock_threshold }}
                </p>
              </div>
              <Badge :variant="getSeverityVariant(item.severity)">
                {{ item.severity === 'out' ? 'Out of Stock' : 'Low Stock' }}
              </Badge>
            </div>
          </div>
        </div>
      </Card>

      <!-- Charts -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-8">
        <!-- Revenue Trends -->
        <Card>
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Monthly Revenue Trends</h3>
            <p class="text-sm text-gray-500">Last 6 months</p>
          </template>
          <div class="h-64">
            <LineChart v-if="revenueChartData.labels.length" :data="revenueChartData" />
            <div v-else class="flex items-center justify-center h-full text-gray-500 text-sm">
              No data available
            </div>
          </div>
        </Card>

        <!-- Service Types Breakdown -->
        <Card>
          <template #header>
            <h3 class="text-lg font-medium text-gray-900">Top Service Types</h3>
            <p class="text-sm text-gray-500">Last 6 months</p>
          </template>
          <div class="h-64">
            <DoughnutChart v-if="serviceTypeChartData.labels.length" :data="serviceTypeChartData" />
            <div v-else class="flex items-center justify-center h-full text-gray-500 text-sm">
              No data available
            </div>
          </div>
        </Card>
      </div>

      <!-- Recent Activity -->
      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 mb-8">
        <!-- Recent Invoices -->
        <Card title="Recent Invoices">
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">Recent Invoices</h3>
              <router-link to="/invoices" class="text-sm font-medium text-primary-600 hover:text-primary-500">
                View all
              </router-link>
            </div>
          </template>

          <div v-if="recentInvoices.length === 0" class="text-center py-6 text-gray-500">
            No recent invoices
          </div>

          <div v-else class="divide-y divide-gray-200">
            <div
              v-for="invoice in recentInvoices"
              :key="invoice.id"
              class="py-4 flex items-center justify-between hover:bg-gray-50 cursor-pointer px-4 -mx-4"
              @click="$router.push(`/invoices/${invoice.id}`)"
            >
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3">
                  <p class="text-sm font-medium text-gray-900">
                    #{{ invoice.invoice_number }}
                  </p>
                  <Badge :variant="getInvoiceStatusVariant(invoice.status)">
                    {{ invoice.status }}
                  </Badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                  {{ invoice.customer_name }} - {{ formatDate(invoice.created_at) }}
                </p>
              </div>
              <div class="ml-4 flex-shrink-0 text-right">
                <p class="text-sm font-semibold text-gray-900">
                  {{ formatCurrency(invoice.total_amount) }}
                </p>
              </div>
            </div>
          </div>
        </Card>

        <!-- Recent Appointments -->
        <Card title="Upcoming Appointments">
          <template #header>
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">Upcoming Appointments</h3>
              <router-link to="/appointments" class="text-sm font-medium text-primary-600 hover:text-primary-500">
                View all
              </router-link>
            </div>
          </template>

          <div v-if="recentAppointments.length === 0" class="text-center py-6 text-gray-500">
            No upcoming appointments
          </div>

          <div v-else class="divide-y divide-gray-200">
            <div
              v-for="appointment in recentAppointments"
              :key="appointment.id"
              class="py-4 hover:bg-gray-50 cursor-pointer px-4 -mx-4"
              @click="$router.push(`/appointments/${appointment.id}`)"
            >
              <div class="flex items-center justify-between">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-3">
                    <p class="text-sm font-medium text-gray-900">
                      {{ appointment.customer_name }}
                    </p>
                    <Badge :variant="getAppointmentStatusVariant(appointment.status)">
                      {{ appointment.status }}
                    </Badge>
                  </div>
                  <p class="mt-1 text-sm text-gray-500">
                    {{ appointment.service_type }}
                  </p>
                </div>
                <div class="ml-4 flex-shrink-0 text-right">
                  <p class="text-sm font-medium text-gray-900">
                    {{ formatDate(appointment.scheduled_date) }}
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ appointment.scheduled_time }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </Card>
      </div>

      <!-- Quick Actions -->
      <Card title="Quick Actions">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Button
            variant="outline"
            @click="$router.push('/cp/invoices/create')"
            class="justify-center"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New Invoice
          </Button>

          <Button
            variant="outline"
            @click="$router.push('/cp/appointments/create')"
            class="justify-center"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            New Appointment
          </Button>

          <Button
            variant="outline"
            @click="$router.push('/cp/customers/create')"
            class="justify-center"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            New Customer
          </Button>

          <Button
            variant="outline"
            @click="$router.push('/cp/vehicles/create')"
            class="justify-center"
          >
            <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Add Vehicle
          </Button>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import LineChart from '@/components/charts/LineChart.vue'
import DoughnutChart from '@/components/charts/DoughnutChart.vue'
import dashboardService from '@/services/dashboard.service'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const loading = ref(true)
const error = ref(null)

const stats = ref({
  totalRevenue: 0,
  revenueChange: 0,
  pendingInvoices: 0,
  pendingAmount: 0,
  todayAppointments: 0,
  upcomingAppointments: 0,
  activeCustomers: 0,
  newCustomers: 0,
})

const recentInvoices = ref([])
const recentAppointments = ref([])
const inventoryAlerts = ref({ counts: { out_of_stock: 0, low_stock: 0 }, items: [] })
const revenueChartData = ref({ labels: [], datasets: [] })
const serviceTypeChartData = ref({ labels: [], datasets: [] })

const technicianId = computed(() => (authStore.user?.role === 'technician' ? authStore.user.id : null))
const technicianParams = computed(() => (technicianId.value ? { technician_id: technicianId.value } : {}))

onMounted(async () => {
  await loadDashboardData()
})

async function loadDashboardData() {
  try {
    loading.value = true
    error.value = null

    // Calculate date range for charts (last 6 months)
    const endDate = new Date()
    const startDate = new Date()
    startDate.setMonth(startDate.getMonth() - 6)

    // Load all dashboard data in parallel
    const [statsData, invoicesData, appointmentsData, lowStockData, trendsData, serviceTypesData] = await Promise.all([
      dashboardService.getStats(technicianParams.value).catch(() => ({})),
      dashboardService.getRecentInvoices(5, technicianParams.value).catch(() => []),
      dashboardService.getRecentAppointments(5, technicianParams.value).catch(() => []),
      dashboardService.getInventoryLowStockTile().catch(() => null),
      dashboardService.getMonthlyTrendsChart({
        start: startDate.toISOString().split('T')[0],
        end: endDate.toISOString().split('T')[0],
        ...technicianParams.value,
      }).catch(() => []),
      dashboardService.getServiceTypeChart({
        start: startDate.toISOString().split('T')[0],
        end: endDate.toISOString().split('T')[0],
        limit: 8,
        ...technicianParams.value,
      }).catch(() => ({ label: '', data: [], categories: [] })),
    ])

    stats.value = {
      totalRevenue: statsData.total_revenue || 0,
      revenueChange: statsData.revenue_change || 0,
      pendingInvoices: statsData.pending_invoices || 0,
      pendingAmount: statsData.pending_amount || 0,
      todayAppointments: statsData.today_appointments || 0,
      upcomingAppointments: statsData.upcoming_appointments || 0,
      activeCustomers: statsData.active_customers || 0,
      newCustomers: statsData.new_customers || 0,
    }

    recentInvoices.value = invoicesData.data || invoicesData || []
    recentAppointments.value = appointmentsData.data || appointmentsData || []
    inventoryAlerts.value = {
      counts: lowStockData?.counts || { out_of_stock: 0, low_stock: 0 },
      items: lowStockData?.items || [],
    }

    // Process chart data
    const trendSeries = Array.isArray(trendsData)
      ? trendsData
      : Array.isArray(trendsData?.data)
        ? trendsData.data
        : []

    if (trendSeries.length > 0) {
      const categories = Array.isArray(trendsData?.categories)
        ? trendsData.categories
        : trendSeries[0]?.categories || []
      revenueChartData.value = {
        labels: categories.map(formatMonthLabel),
        datasets: trendSeries.map((series, index) => ({
          label: series.label,
          data: series.data,
          borderColor: index === 0 ? '#3B82F6' : '#10B981',
          backgroundColor: index === 0 ? 'rgba(59, 130, 246, 0.1)' : 'rgba(16, 185, 129, 0.1)',
          tension: 0.4,
          fill: true
        }))
      }
    }

    if (serviceTypesData && serviceTypesData.categories && serviceTypesData.categories.length > 0) {
      serviceTypeChartData.value = {
        labels: serviceTypesData.categories,
        datasets: [{
          data: serviceTypesData.data,
          backgroundColor: [
            '#3B82F6',
            '#10B981',
            '#F59E0B',
            '#EF4444',
            '#8B5CF6',
            '#EC4899',
            '#14B8A6',
            '#F97316'
          ]
        }]
      }
    }
  } catch (err) {
    console.error('Failed to load dashboard data:', err)
    error.value = 'Failed to load dashboard data. Please try again.'
  } finally {
    loading.value = false
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount || 0)
}

function formatDate(date) {
  if (!date) return ''
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

function getInvoiceStatusVariant(status) {
  const variants = {
    'paid': 'success',
    'pending': 'warning',
    'overdue': 'danger',
    'draft': 'default',
    'cancelled': 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getAppointmentStatusVariant(status) {
  const variants = {
    'confirmed': 'success',
    'pending': 'warning',
    'completed': 'info',
    'cancelled': 'danger',
    'no-show': 'default',
  }
  return variants[status?.toLowerCase()] || 'default'
}

function getSeverityVariant(severity) {
  const variants = {
    'out': 'danger',
    'low': 'warning',
  }

  return variants[severity] || 'default'
}

function formatMonthLabel(yearMonth) {
  if (!yearMonth) return ''
  const [year, month] = yearMonth.split('-')
  const date = new Date(parseInt(year), parseInt(month) - 1)
  return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
}
</script>
