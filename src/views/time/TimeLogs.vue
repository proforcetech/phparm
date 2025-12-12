<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Time Logs</h1>
        <p class="text-sm text-gray-600">Filter technician activity with adjustment history.</p>
      </div>
      <div class="flex gap-2">
        <Button variant="secondary" @click="refresh">Refresh</Button>
      </div>
    </div>

    <Card>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700">Search</label>
          <Input v-model="filters.search" placeholder="Technician, job, or customer" @input="debouncedRefresh" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Technician ID</label>
          <Input v-model="filters.technician_id" placeholder="123" @input="debouncedRefresh" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Start Date</label>
          <Input v-model="filters.start_date" type="date" @change="refresh" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">End Date</label>
          <Input v-model="filters.end_date" type="date" @change="refresh" />
        </div>
      </div>
    </Card>

    <Card>
      <div class="hidden md:block">
        <Table
          :columns="columns"
          :data="entries"
          :pagination="true"
          :per-page="perPage"
          :total="total"
          :current-page="currentPage"
          :loading="loading"
          hoverable
          @page-change="changePage"
          @row-click="selectEntry"
        >
          <template #cell(technician)="{ row }">
            <div>
              <p class="font-semibold text-gray-900">{{ row.technician_name || `Tech #${row.technician_id}` }}</p>
              <p class="text-xs text-gray-500">Job: {{ row.job_title || 'Unassigned' }}</p>
            </div>
          </template>

          <template #cell(window)="{ row }">
            <div class="text-sm text-gray-900">
              <div>Start: {{ formatDate(row.started_at) }}</div>
              <div v-if="row.ended_at">End: {{ formatDate(row.ended_at) }}</div>
              <div v-else class="text-amber-700">Active</div>
            </div>
          </template>

          <template #cell(duration_minutes)="{ value }">
            <span class="font-semibold text-gray-900">{{ Number(value ?? 0).toFixed(2) }} mins</span>
          </template>

          <template #cell(location)="{ row }">
            <div class="text-xs text-gray-700">
              <div v-if="row.is_mobile">
                <div>Start: {{ formatLocation(row.start_latitude, row.start_longitude) }}</div>
                <div>End: {{ row.ended_at ? formatLocation(row.end_latitude, row.end_longitude) : '—' }}</div>
              </div>
              <div v-else class="text-gray-500">In-shop</div>
            </div>
          </template>

          <template #cell(status)="{ value, row }">
            <div class="flex items-center gap-2">
              <Badge :variant="statusVariant(value)" size="sm">{{ statusLabel(value) }}</Badge>
              <span v-if="row.reviewed_at" class="text-xs text-gray-500">
                {{ row.reviewer_name || `User #${row.reviewed_by}` }}
              </span>
            </div>
          </template>

          <template #cell(context)="{ row }">
            <div class="text-sm text-gray-800">
              <div class="text-xs text-gray-500">Estimate: {{ row.estimate_number || '—' }}</div>
              <div class="text-xs text-gray-500">Customer: {{ row.customer_name || '—' }}</div>
              <div class="text-xs text-gray-500">Vehicle: {{ row.vehicle_vin || '—' }}</div>
            </div>
          </template>

          <template #cell(manual_override)="{ value }">
            <Badge :variant="value ? 'warning' : 'secondary'">{{ value ? 'Manual' : 'Timer' }}</Badge>
          </template>

          <template #cell(adjustments)="{ row }">
            <div class="space-y-2">
              <div v-if="row.adjustments?.length === 0" class="text-xs text-gray-500">No adjustments</div>
              <div v-for="adj in row.adjustments" :key="adj.id" class="rounded bg-gray-50 p-2">
                <div class="text-xs font-semibold text-gray-800">{{ adj.actor_name || `User #${adj.actor_id}` }}</div>
                <div class="text-xs text-gray-600">{{ adj.reason }}</div>
                <div class="text-[11px] text-gray-500">{{ formatDate(adj.created_at) }}</div>
              </div>
            </div>
          </template>
        </Table>
      </div>

      <div v-if="entries.length" class="space-y-3 md:hidden">
        <div
          v-for="row in entries"
          :key="row.id"
          class="rounded border border-gray-200 bg-gray-50 p-4 shadow-sm"
          @click="selectEntry(row)"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-base font-semibold text-gray-900">{{ row.technician_name || `Tech #${row.technician_id}` }}</p>
              <p class="text-xs text-gray-600">Job: {{ row.job_title || 'Unassigned' }}</p>
            </div>
            <div class="flex flex-col items-end gap-1">
              <Badge :variant="row.manual_override ? 'warning' : 'secondary'">{{ row.manual_override ? 'Manual' : 'Timer' }}</Badge>
              <Badge :variant="statusVariant(row.status)" size="sm">{{ statusLabel(row.status) }}</Badge>
            </div>
          </div>
          <div class="mt-2 text-sm text-gray-800">
            <div class="font-semibold">{{ Number(row.duration_minutes ?? 0).toFixed(2) }} mins</div>
            <div class="text-xs text-gray-600">Start: {{ formatDate(row.started_at) }}</div>
            <div class="text-xs text-gray-600">
              End: <span :class="row.ended_at ? '' : 'text-amber-700'">{{ row.ended_at ? formatDate(row.ended_at) : 'Active' }}</span>
            </div>
            <div class="text-xs text-gray-600">
              Location:
              <span v-if="row.is_mobile">{{ formatLocation(row.start_latitude, row.start_longitude) }} → {{ row.ended_at ? formatLocation(row.end_latitude, row.end_longitude) : '—' }}</span>
              <span v-else>In-shop</span>
            </div>
          </div>
          <div class="mt-2 space-y-1 text-xs text-gray-600">
            <div>Estimate: {{ row.estimate_number || '—' }}</div>
            <div>Customer: {{ row.customer_name || '—' }}</div>
            <div>Vehicle: {{ row.vehicle_vin || '—' }}</div>
          </div>
          <div class="mt-3 space-y-2">
            <div v-if="row.adjustments?.length === 0" class="text-xs text-gray-500">No adjustments</div>
            <div v-for="adj in row.adjustments" :key="adj.id" class="rounded bg-white p-2">
              <div class="text-xs font-semibold text-gray-800">{{ adj.actor_name || `User #${adj.actor_id}` }}</div>
              <div class="text-xs text-gray-600">{{ adj.reason }}</div>
              <div class="text-[11px] text-gray-500">{{ formatDate(adj.created_at) }}</div>
            </div>
          </div>
        </div>
      </div>
      <div v-else-if="loading" class="py-4 text-center text-sm text-gray-500">Loading...</div>
      <div v-else class="py-4 text-center text-sm text-gray-500">No entries found.</div>
    </Card>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card>
        <h3 class="text-lg font-semibold text-gray-900">Add manual entry</h3>
        <p class="mt-1 text-sm text-gray-600">Manual submissions default to pending until reviewed.</p>
        <form class="mt-4 space-y-3" @submit.prevent="submitManual">
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Input v-model="manualForm.technician_id" label="Technician ID" required />
            <Input v-model="manualForm.estimate_job_id" label="Estimate Job ID" />
          </div>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Input v-model="manualForm.started_at" type="datetime-local" label="Started" required />
            <Input v-model="manualForm.ended_at" type="datetime-local" label="Ended" required />
          </div>
          <Input v-model="manualForm.reason" label="Adjustment Reason" required />
          <textarea
            v-model="manualForm.notes"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            rows="3"
            placeholder="Notes"
          />
          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input v-model="manualForm.manual_override" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600" />
            Manual override
          </label>
          <div class="flex gap-2">
            <Button type="submit" :loading="savingManual">Save Entry</Button>
            <p v-if="manualError" class="text-sm text-red-600">{{ manualError }}</p>
          </div>
        </form>
      </Card>

      <Card>
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-gray-900">Edit selected entry</h3>
          <Badge v-if="selectedEntry" variant="secondary">ID {{ selectedEntry.id }}</Badge>
        </div>
        <p class="mt-1 text-sm text-gray-600">Click a row to load it into the editor.</p>

        <div v-if="selectedEntry" class="mt-3 space-y-2 rounded border border-gray-200 bg-gray-50 p-3">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <Badge :variant="statusVariant(selectedEntry.status)" size="sm" rounded>
                {{ statusLabel(selectedEntry.status) }}
              </Badge>
              <Badge v-if="selectedEntry.manual_override" variant="warning" size="sm">Manual</Badge>
            </div>
            <span v-if="selectedEntry.reviewed_at" class="text-xs text-gray-600">
              {{ selectedEntry.reviewer_name || `User #${selectedEntry.reviewed_by}` }} · {{ formatDate(selectedEntry.reviewed_at) }}
            </span>
          </div>
          <p class="text-xs text-gray-600">
            {{
              selectedEntry.status === 'pending'
                ? 'Pending entries are excluded from billable totals until approval.'
                : selectedEntry.review_notes || 'Reviewed'
            }}
          </p>
          <div v-if="selectedEntry.status === 'pending'" class="space-y-2 pt-1">
            <textarea
              v-model="reviewNotes"
              class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
              rows="2"
              placeholder="Review notes (optional)"
            />
            <div class="flex flex-wrap gap-2">
              <Button variant="primary" size="sm" :loading="reviewing" @click="() => review('approved')">Approve</Button>
              <Button variant="danger" size="sm" :loading="reviewing" @click="() => review('rejected')">Reject</Button>
            </div>
          </div>
          <p v-else-if="selectedEntry.review_notes" class="text-xs text-gray-600">Notes: {{ selectedEntry.review_notes }}</p>
          <p v-if="reviewError" class="text-sm text-red-600">{{ reviewError }}</p>
        </div>

        <form class="mt-4 space-y-3" @submit.prevent="submitUpdate">
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Input v-model="editForm.started_at" type="datetime-local" label="Started" :disabled="!selectedEntry" required />
            <Input v-model="editForm.ended_at" type="datetime-local" label="Ended" :disabled="!selectedEntry" />
          </div>
          <Input v-model="editForm.estimate_job_id" label="Estimate Job ID" :disabled="!selectedEntry" />
          <Input v-model="editForm.reason" label="Adjustment Reason" :disabled="!selectedEntry" required />
          <textarea
            v-model="editForm.notes"
            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            rows="3"
            :disabled="!selectedEntry"
            placeholder="Notes"
          />
          <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input
              v-model="editForm.manual_override"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-indigo-600"
              :disabled="!selectedEntry"
            />
            Manual override
          </label>
          <div class="flex gap-2">
            <Button type="submit" :disabled="!selectedEntry" :loading="savingEdit">Update Entry</Button>
            <p v-if="editError" class="text-sm text-red-600">{{ editError }}</p>
          </div>
        </form>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Table from '@/components/ui/Table.vue'
import timeTrackingService from '@/services/time-tracking.service'

const loading = ref(false)
const entries = ref([])
const total = ref(0)
const currentPage = ref(1)
const perPage = 25
const manualError = ref('')
const editError = ref('')
const savingManual = ref(false)
const savingEdit = ref(false)
const selectedEntry = ref(null)

const filters = reactive({
  search: '',
  start_date: '',
  end_date: '',
  technician_id: '',
})

const columns = [
  { key: 'technician', label: 'Technician' },
  { key: 'window', label: 'Window' },
  { key: 'duration_minutes', label: 'Duration' },
  { key: 'location', label: 'Location' },
  { key: 'status', label: 'Status' },
  { key: 'context', label: 'Context' },
  { key: 'manual_override', label: 'Source' },
  { key: 'adjustments', label: 'Adjustments' },
]

const manualForm = reactive({
  technician_id: '',
  estimate_job_id: '',
  started_at: '',
  ended_at: '',
  notes: '',
  manual_override: true,
  reason: '',
})

const editForm = reactive({
  id: null,
  started_at: '',
  ended_at: '',
  estimate_job_id: '',
  notes: '',
  manual_override: true,
  reason: '',
})

const reviewNotes = ref('')
const reviewError = ref('')
const reviewing = ref(false)

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

function statusVariant(value) {
  if (value === 'pending') return 'warning'
  if (value === 'rejected') return 'danger'
  return 'success'
}

function statusLabel(value) {
  if (!value) return 'Unknown'
  return value.charAt(0).toUpperCase() + value.slice(1)
}

function formatLocation(lat, lng) {
  if (lat == null || lng == null) return '—'
  return `${Number(lat).toFixed(5)}, ${Number(lng).toFixed(5)}`
}

let debounceTimer
function debouncedRefresh() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => refresh(), 300)
}

async function refresh() {
  loading.value = true
  try {
    const response = await timeTrackingService.list({
      ...filters,
      page: currentPage.value,
      per_page: perPage,
    })
    entries.value = response.data
    total.value = response.pagination.total
    currentPage.value = Math.floor(response.pagination.offset / response.pagination.limit) + 1
    if (selectedEntry.value) {
      const updated = response.data.find((entry) => entry.id === selectedEntry.value.id)
      if (updated) {
        selectEntry(updated)
      }
    }
  } finally {
    loading.value = false
  }
}

function changePage(nextPage) {
  currentPage.value = nextPage
  refresh()
}

function selectEntry(row) {
  selectedEntry.value = row
  editForm.id = row.id
  editForm.started_at = row.started_at?.slice(0, 16) || ''
  editForm.ended_at = row.ended_at?.slice(0, 16) || ''
  editForm.estimate_job_id = row.estimate_job_id || ''
  editForm.notes = row.notes || ''
  editForm.manual_override = row.manual_override
  editForm.reason = ''
  reviewNotes.value = ''
  reviewError.value = ''
}

async function submitManual() {
  manualError.value = ''
  savingManual.value = true
  try {
    await timeTrackingService.create({
      technician_id: manualForm.technician_id,
      estimate_job_id: manualForm.estimate_job_id || null,
      started_at: manualForm.started_at,
      ended_at: manualForm.ended_at,
      notes: manualForm.notes,
      manual_override: manualForm.manual_override,
      reason: manualForm.reason,
    })
    refresh()
    Object.assign(manualForm, {
      technician_id: '',
      estimate_job_id: '',
      started_at: '',
      ended_at: '',
      notes: '',
      manual_override: true,
      reason: '',
    })
  } catch (error) {
    manualError.value = error.response?.data?.message || 'Unable to save entry'
  } finally {
    savingManual.value = false
  }
}

async function submitUpdate() {
  if (!editForm.id) return
  editError.value = ''
  savingEdit.value = true
  try {
    await timeTrackingService.update(editForm.id, {
      started_at: editForm.started_at,
      ended_at: editForm.ended_at || null,
      estimate_job_id: editForm.estimate_job_id || null,
      notes: editForm.notes,
      manual_override: editForm.manual_override,
      reason: editForm.reason,
    })
    refresh()
    editForm.reason = ''
  } catch (error) {
    editError.value = error.response?.data?.message || 'Unable to update entry'
  } finally {
    savingEdit.value = false
  }
}

async function review(decision) {
  if (!selectedEntry.value) return
  reviewError.value = ''
  reviewing.value = true
  try {
    const payload = reviewNotes.value ? { notes: reviewNotes.value } : {}
    if (decision === 'approved') {
      await timeTrackingService.approve(selectedEntry.value.id, payload)
    } else {
      await timeTrackingService.reject(selectedEntry.value.id, payload)
    }
    reviewNotes.value = ''
    await refresh()
  } catch (error) {
    reviewError.value = error.response?.data?.message || 'Unable to update status'
  } finally {
    reviewing.value = false
  }
}

refresh()
</script>
