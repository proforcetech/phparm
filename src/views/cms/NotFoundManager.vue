<template>
  <div class="p-6">
    <div class="mb-6">
      <h1 class="text-3xl font-bold text-gray-900">404 Errors & Redirects</h1>
      <p class="mt-2 text-sm text-gray-600">Monitor 404 errors and create redirects to fix broken links</p>
    </div>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-6">
      <nav class="-mb-px flex space-x-8">
        <button
          @click="activeTab = '404-logs'"
          :class="[
            activeTab === '404-logs'
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
          ]"
        >
          404 Logs
          <span v-if="statistics" class="ml-2 py-0.5 px-2.5 rounded-full text-xs font-medium bg-gray-100">
            {{ statistics.total_unique_uris }}
          </span>
        </button>
        <button
          @click="activeTab = 'redirects'"
          :class="[
            activeTab === 'redirects'
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
          ]"
        >
          Redirects
          <span v-if="redirectsPagination" class="ml-2 py-0.5 px-2.5 rounded-full text-xs font-medium bg-gray-100">
            {{ redirectsPagination.total }}
          </span>
        </button>
      </nav>
    </div>

    <!-- 404 Logs Tab -->
    <div v-if="activeTab === '404-logs'">
      <!-- Statistics Cards -->
      <div v-if="statistics" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow">
          <div class="text-sm text-gray-600">Unique URIs</div>
          <div class="text-2xl font-bold text-gray-900">{{ statistics.total_unique_uris }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
          <div class="text-sm text-gray-600">Total Hits</div>
          <div class="text-2xl font-bold text-gray-900">{{ statistics.total_hits }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
          <div class="text-sm text-gray-600">Avg Hits</div>
          <div class="text-2xl font-bold text-gray-900">{{ Number(statistics.avg_hits).toFixed(1) }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
          <div class="text-sm text-gray-600">Max Hits</div>
          <div class="text-2xl font-bold text-gray-900">{{ statistics.max_hits }}</div>
        </div>
      </div>

      <!-- Filters and Actions -->
      <div class="bg-white p-4 rounded-lg shadow mb-4 flex justify-between items-center">
        <div class="flex space-x-4">
          <input
            v-model="logsSearch"
            @keyup.enter="loadLogs"
            type="text"
            placeholder="Search URIs..."
            class="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
          />
          <select
            v-model="minHits"
            @change="loadLogs"
            class="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="">All hits</option>
            <option value="5">5+ hits</option>
            <option value="10">10+ hits</option>
            <option value="50">50+ hits</option>
            <option value="100">100+ hits</option>
          </select>
        </div>
        <button
          @click="clearAllLogs"
          class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
          :disabled="loading"
        >
          Clear All Logs
        </button>
      </div>

      <!-- 404 Logs Table -->
      <div class="bg-white shadow overflow-hidden sm:rounded-lg">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URI</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hits</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">First Seen</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Seen</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-if="loading">
              <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">Loading...</td>
            </tr>
            <tr v-else-if="logs.length === 0">
              <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No 404 errors logged</td>
            </tr>
            <tr v-else v-for="log in logs" :key="log.id" class="hover:bg-gray-50">
              <td class="px-6 py-4">
                <div class="text-sm font-medium text-gray-900 font-mono">{{ log.uri }}</div>
                <div v-if="log.referrer" class="text-xs text-gray-500 mt-1">
                  From: {{ log.referrer }}
                </div>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                  {{ log.hits }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-500">
                {{ formatDate(log.first_seen) }}
              </td>
              <td class="px-6 py-4 text-sm text-gray-500">
                {{ formatDate(log.last_seen) }}
              </td>
              <td class="px-6 py-4 text-sm font-medium space-x-2">
                <button
                  @click="createRedirectFrom(log)"
                  class="text-indigo-600 hover:text-indigo-900"
                >
                  Create Redirect
                </button>
                <button
                  @click="deleteLog(log.id)"
                  class="text-red-600 hover:text-red-900"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="logsPagination && logsPagination.total_pages > 1" class="bg-gray-50 px-4 py-3 flex items-center justify-between border-t border-gray-200">
          <div class="text-sm text-gray-700">
            Page {{ logsPagination.page }} of {{ logsPagination.total_pages }}
          </div>
          <div class="flex space-x-2">
            <button
              @click="logsPage--; loadLogs()"
              :disabled="logsPage === 1"
              class="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
            >
              Previous
            </button>
            <button
              @click="logsPage++; loadLogs()"
              :disabled="logsPage >= logsPagination.total_pages"
              class="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Redirects Tab -->
    <div v-if="activeTab === 'redirects'">
      <!-- Actions -->
      <div class="mb-4 flex justify-between items-center">
        <input
          v-model="redirectsSearch"
          @keyup.enter="loadRedirects"
          type="text"
          placeholder="Search redirects..."
          class="px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
        />
        <button
          @click="showRedirectForm = true; editingRedirect = null"
          class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
        >
          + Add Redirect
        </button>
      </div>

      <!-- Redirects Table -->
      <div class="bg-white shadow overflow-hidden sm:rounded-lg">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Destination</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hits</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-if="loading">
              <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">Loading...</td>
            </tr>
            <tr v-else-if="redirects.length === 0">
              <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No redirects configured</td>
            </tr>
            <tr v-else v-for="redirect in redirects" :key="redirect.id" class="hover:bg-gray-50">
              <td class="px-6 py-4">
                <div class="text-sm font-medium text-gray-900 font-mono">{{ redirect.source_path }}</div>
                <div v-if="redirect.description" class="text-xs text-gray-500 mt-1">{{ redirect.description }}</div>
              </td>
              <td class="px-6 py-4">
                <div class="text-sm text-gray-900 font-mono">{{ redirect.destination_path }}</div>
              </td>
              <td class="px-6 py-4">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                  {{ redirect.redirect_type }} ({{ redirect.match_type }})
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-gray-500">
                {{ redirect.hits }}
              </td>
              <td class="px-6 py-4">
                <span
                  :class="[
                    redirect.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800',
                    'px-2 inline-flex text-xs leading-5 font-semibold rounded-full'
                  ]"
                >
                  {{ redirect.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm font-medium space-x-2">
                <button
                  @click="editRedirect(redirect)"
                  class="text-indigo-600 hover:text-indigo-900"
                >
                  Edit
                </button>
                <button
                  @click="deleteRedirect(redirect.id)"
                  class="text-red-600 hover:text-red-900"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div v-if="redirectsPagination && redirectsPagination.total_pages > 1" class="bg-gray-50 px-4 py-3 flex items-center justify-between border-t border-gray-200">
          <div class="text-sm text-gray-700">
            Page {{ redirectsPagination.page }} of {{ redirectsPagination.total_pages }}
          </div>
          <div class="flex space-x-2">
            <button
              @click="redirectsPage--; loadRedirects()"
              :disabled="redirectsPage === 1"
              class="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
            >
              Previous
            </button>
            <button
              @click="redirectsPage++; loadRedirects()"
              :disabled="redirectsPage >= redirectsPagination.total_pages"
              class="px-3 py-1 border border-gray-300 rounded-md text-sm disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Redirect Form Modal -->
    <div v-if="showRedirectForm" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">
            {{ editingRedirect ? 'Edit Redirect' : 'Create Redirect' }}
          </h3>
        </div>

        <div class="px-6 py-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Source Path *</label>
            <input
              v-model="redirectForm.source_path"
              type="text"
              required
              placeholder="/old-page"
              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Destination Path *</label>
            <input
              v-model="redirectForm.destination_path"
              type="text"
              required
              placeholder="/new-page"
              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700">Redirect Type</label>
              <select
                v-model="redirectForm.redirect_type"
                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              >
                <option value="301">301 (Permanent)</option>
                <option value="302">302 (Temporary)</option>
                <option value="307">307 (Temporary, Keep Method)</option>
                <option value="308">308 (Permanent, Keep Method)</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700">Match Type</label>
              <select
                v-model="redirectForm.match_type"
                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
              >
                <option value="exact">Exact Match</option>
                <option value="prefix">Prefix Match</option>
                <option value="regex">Regular Expression</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700">Description (optional)</label>
            <input
              v-model="redirectForm.description"
              type="text"
              placeholder="What this redirect is for..."
              class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div class="flex items-center">
            <input
              v-model="redirectForm.is_active"
              type="checkbox"
              id="is_active"
              class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
            />
            <label for="is_active" class="ml-2 block text-sm text-gray-700">
              Active (redirect immediately)
            </label>
          </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
          <button
            @click="showRedirectForm = false; editingRedirect = null"
            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            @click="saveRedirect"
            :disabled="submitting"
            class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
          >
            {{ submitting ? 'Saving...' : 'Save Redirect' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const activeTab = ref('404-logs')

// 404 Logs
const logs = ref([])
const logsPagination = ref(null)
const logsPage = ref(1)
const logsSearch = ref('')
const minHits = ref('')
const statistics = ref(null)

// Redirects
const redirects = ref([])
const redirectsPagination = ref(null)
const redirectsPage = ref(1)
const redirectsSearch = ref('')
const showRedirectForm = ref(false)
const editingRedirect = ref(null)
const redirectForm = ref({
  source_path: '',
  destination_path: '',
  redirect_type: '301',
  match_type: 'exact',
  description: '',
  is_active: true,
})

const loading = ref(false)
const submitting = ref(false)

onMounted(() => {
  loadLogs()
  loadRedirects()
})

async function loadLogs() {
  loading.value = true
  try {
    const params = new URLSearchParams({
      page: logsPage.value,
      per_page: 50,
    })

    if (logsSearch.value) params.append('uri', logsSearch.value)
    if (minHits.value) params.append('min_hits', minHits.value)

    const response = await api.get(`/404-logs?${params}`)
    logs.value = response.data.logs
    logsPagination.value = response.data.pagination
    statistics.value = response.data.statistics
  } catch (error) {
    console.error('Failed to load 404 logs:', error)
  } finally {
    loading.value = false
  }
}

async function loadRedirects() {
  loading.value = true
  try {
    const params = new URLSearchParams({
      page: redirectsPage.value,
      per_page: 50,
    })

    if (redirectsSearch.value) params.append('search', redirectsSearch.value)

    const response = await api.get(`/redirects?${params}`)
    redirects.value = response.data.redirects
    redirectsPagination.value = response.data.pagination
  } catch (error) {
    console.error('Failed to load redirects:', error)
  } finally {
    loading.value = false
  }
}

async function deleteLog(id) {
  if (!confirm('Delete this 404 log entry?')) return

  try {
    await api.delete(`/404-logs/${id}`)
    await loadLogs()
  } catch (error) {
    console.error('Failed to delete log:', error)
    alert('Failed to delete log')
  }
}

async function clearAllLogs() {
  if (!confirm('Clear ALL 404 logs? This cannot be undone.')) return

  try {
    await api.post('/404-logs/clear')
    await loadLogs()
  } catch (error) {
    console.error('Failed to clear logs:', error)
    alert('Failed to clear logs')
  }
}

function createRedirectFrom(log) {
  redirectForm.value = {
    source_path: log.uri,
    destination_path: '',
    redirect_type: '301',
    match_type: 'exact',
    description: `Redirect from 404 (${log.hits} hits)`,
    is_active: true,
  }
  showRedirectForm.value = true
  activeTab.value = 'redirects'
}

function editRedirect(redirect) {
  editingRedirect.value = redirect
  redirectForm.value = {
    source_path: redirect.source_path,
    destination_path: redirect.destination_path,
    redirect_type: redirect.redirect_type,
    match_type: redirect.match_type,
    description: redirect.description || '',
    is_active: redirect.is_active,
  }
  showRedirectForm.value = true
}

async function saveRedirect() {
  if (!redirectForm.value.source_path || !redirectForm.value.destination_path) {
    alert('Source and destination paths are required')
    return
  }

  submitting.value = true
  try {
    if (editingRedirect.value) {
      await api.put(`/redirects/${editingRedirect.value.id}`, redirectForm.value)
    } else {
      await api.post('/redirects', redirectForm.value)
    }

    showRedirectForm.value = false
    editingRedirect.value = null
    await loadRedirects()
  } catch (error) {
    console.error('Failed to save redirect:', error)
    alert(error.response?.data?.error || 'Failed to save redirect')
  } finally {
    submitting.value = false
  }
}

async function deleteRedirect(id) {
  if (!confirm('Delete this redirect?')) return

  try {
    await api.delete(`/redirects/${id}`)
    await loadRedirects()
  } catch (error) {
    console.error('Failed to delete redirect:', error)
    alert('Failed to delete redirect')
  }
}

function formatDate(dateString) {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString()
}
</script>
