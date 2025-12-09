<template>
  <div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-gray-900">Financial Reports</h1>
        <p class="text-sm text-gray-600">Monthly breakdowns of income, expenses, and purchases with CSV export.</p>
      </div>
      <button
        class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
        @click="exportReport"
      >
        Export CSV
      </button>
    </div>

    <div class="bg-white shadow rounded p-4 space-y-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Start Date</label>
          <input v-model="filters.start_date" type="date" class="mt-1 w-full border-gray-300 rounded" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">End Date</label>
          <input v-model="filters.end_date" type="date" class="mt-1 w-full border-gray-300 rounded" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Category</label>
          <input v-model="filters.category" type="text" class="mt-1 w-full border-gray-300 rounded" />
        </div>
        <div class="flex items-end">
          <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 w-full" @click="fetchReport">
            Refresh
          </button>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white shadow rounded p-4">
        <p class="text-sm text-gray-500">Income</p>
        <p class="text-2xl font-semibold text-green-600">${{ totals.income.toFixed(2) }}</p>
      </div>
      <div class="bg-white shadow rounded p-4">
        <p class="text-sm text-gray-500">Expenses</p>
        <p class="text-2xl font-semibold text-red-600">${{ totals.expense.toFixed(2) }}</p>
      </div>
      <div class="bg-white shadow rounded p-4">
        <p class="text-sm text-gray-500">Purchases</p>
        <p class="text-2xl font-semibold text-orange-500">${{ totals.purchase.toFixed(2) }}</p>
      </div>
    </div>

    <!-- Chart -->
    <div v-if="chartData.labels.length" class="bg-white shadow rounded p-6 mb-6">
      <h3 class="text-lg font-semibold text-gray-900 mb-4">Monthly Financial Trends</h3>
      <div class="h-80">
        <BarChart :data="chartData" :options="chartOptions" />
      </div>
    </div>

    <div class="bg-white shadow rounded overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Purchases</th>
            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="row in report.monthly" :key="row.month" class="hover:bg-gray-50">
            <td class="px-4 py-2 text-sm text-gray-900">{{ row.month }}</td>
            <td class="px-4 py-2 text-sm text-right">${{ row.summary.income.toFixed(2) }}</td>
            <td class="px-4 py-2 text-sm text-right">${{ row.summary.expense.toFixed(2) }}</td>
            <td class="px-4 py-2 text-sm text-right">${{ row.summary.purchase.toFixed(2) }}</td>
            <td
              class="px-4 py-2 text-sm text-right"
              :class="row.net >= 0 ? 'text-green-600' : 'text-red-600'"
            >
              ${{ row.net.toFixed(2) }}
            </td>
          </tr>
          <tr v-if="!report.monthly.length && !loading">
            <td class="px-4 py-6 text-center text-sm text-gray-500" colspan="5">No data found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted, computed } from 'vue'
import BarChart from '@/components/charts/BarChart.vue'
import financialService from '@/services/financial.service'
import { useToast } from '@/stores/toast'

const toast = useToast()
const loading = ref(false)
const report = reactive({
  summary: { income: 0, expense: 0, purchase: 0 },
  net: 0,
  monthly: [],
})
const totals = reactive({ income: 0, expense: 0, purchase: 0 })
const filters = reactive({
  start_date: '',
  end_date: '',
  category: '',
})

onMounted(() => {
  const today = new Date()
  filters.end_date = today.toISOString().slice(0, 10)
  const monthAgo = new Date()
  monthAgo.setMonth(monthAgo.getMonth() - 5)
  filters.start_date = monthAgo.toISOString().slice(0, 10)
  fetchReport()
})

function fetchReport() {
  if (!filters.start_date || !filters.end_date) {
    toast.error('Start and end date are required')
    return
  }
  loading.value = true
  financialService
    .report(filters)
    .then((res) => {
      report.summary = res.summary
      report.net = res.net
      report.monthly = res.monthly || []
      totals.income = res.summary.income || 0
      totals.expense = res.summary.expense || 0
      totals.purchase = res.summary.purchase || 0
    })
    .catch(() => toast.error('Failed to load report'))
    .finally(() => {
      loading.value = false
    })
}

function exportReport() {
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

// Chart data
const chartData = computed(() => {
  if (!report.monthly || report.monthly.length === 0) {
    return { labels: [], datasets: [] }
  }

  return {
    labels: report.monthly.map(row => row.month),
    datasets: [
      {
        label: 'Income',
        data: report.monthly.map(row => row.summary.income),
        backgroundColor: 'rgba(16, 185, 129, 0.8)',
      },
      {
        label: 'Expenses',
        data: report.monthly.map(row => row.summary.expense),
        backgroundColor: 'rgba(239, 68, 68, 0.8)',
      },
      {
        label: 'Purchases',
        data: report.monthly.map(row => row.summary.purchase),
        backgroundColor: 'rgba(249, 115, 22, 0.8)',
      }
    ]
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'top',
    },
    tooltip: {
      callbacks: {
        label: function(context) {
          return context.dataset.label + ': $' + context.parsed.y.toLocaleString()
        }
      }
    }
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback: function(value) {
          return '$' + value.toLocaleString()
        }
      }
    }
  }
}
</script>
