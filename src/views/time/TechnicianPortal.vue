<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">My Time</h1>
        <p class="text-sm text-gray-600">Review assignments and control your timer.</p>
      </div>
      <Button variant="secondary" @click="loadPortal">Refresh</Button>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <Card class="md:col-span-2 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">Active timer</p>
            <p class="text-xl font-semibold text-gray-900">
              {{ portal.active_entry ? `Started ${formatTime(portal.active_entry.started_at)}` : 'No active timer' }}
            </p>
            <p v-if="portal.active_entry?.is_mobile" class="text-xs text-amber-700">Mobile repair — location required on stop.</p>
          </div>
          <div class="flex gap-2">
            <Button v-if="!portal.active_entry" :loading="saving" @click="startTimer">Start</Button>
            <Button v-else variant="danger" :loading="saving" @click="stopTimer">Stop</Button>
          </div>
        </div>

        <p v-if="locationError" class="text-sm text-red-600">{{ locationError }}</p>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Assign to job</label>
            <select
              v-model="selectedJobId"
              class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
              <option value="">Unassigned</option>
              <option v-for="job in portal.jobs" :key="job.id" :value="job.id">
                {{ job.title }} ({{ job.estimate_number }})
              </option>
            </select>
          </div>
          <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">
            <p class="font-semibold">Today</p>
            <p>{{ Number(portal.totals.today_minutes || 0).toFixed(2) }} minutes</p>
            <p class="font-semibold mt-2">This week</p>
            <p>{{ Number(portal.totals.week_minutes || 0).toFixed(2) }} minutes</p>
            <p class="mt-2 text-[11px] text-gray-500">Pending manual entries are excluded until approved.</p>
          </div>
        </div>

        <div>
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Assigned jobs</h3>
          <div v-if="portal.jobs.length === 0" class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600">
            No jobs assigned.
          </div>
          <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Card v-for="job in portal.jobs" :key="job.id" class="border border-gray-200">
              <p class="font-semibold text-gray-900">{{ job.title }}</p>
              <p class="text-xs text-gray-600">Estimate {{ job.estimate_number }}</p>
              <p v-if="job.is_mobile" class="text-[11px] font-medium text-amber-700">Mobile repair</p>
              <p class="text-xs text-gray-500">Customer: {{ job.customer_name }}</p>
              <p class="text-xs text-gray-500">Vehicle: {{ job.vehicle_vin || '—' }}</p>
            </Card>
          </div>
        </div>
      </Card>

      <Card>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Recent entries</h3>
        <div v-if="portal.history.length === 0" class="text-sm text-gray-600">No recorded time entries yet.</div>
        <ul v-else class="divide-y divide-gray-200">
          <li v-for="entry in portal.history" :key="entry.id" class="py-2">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-sm font-semibold text-gray-900">{{ formatTime(entry.started_at) }}</p>
                <p class="text-xs text-gray-500">{{ entry.ended_at ? formatTime(entry.ended_at) : 'Active' }}</p>
              </div>
              <div class="flex flex-col items-end gap-1">
                <Badge :variant="entry.manual_override ? 'warning' : 'secondary'">
                  {{ entry.manual_override ? 'Manual' : 'Timer' }}
                </Badge>
                <Badge :variant="statusVariant(entry.status)" size="sm">{{ statusLabel(entry.status) }}</Badge>
              </div>
            </div>
            <p class="text-xs text-gray-500">Duration: {{ Number(entry.duration_minutes || 0).toFixed(2) }} mins</p>
          </li>
        </ul>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import Badge from '@/components/ui/Badge.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import timeTrackingService from '@/services/time-tracking.service'

const portal = reactive({ jobs: [], history: [], totals: {}, active_entry: null })
const selectedJobId = ref('')
const saving = ref(false)
const locationError = ref('')

function formatTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return date.toLocaleString()
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

async function loadPortal() {
  const data = await timeTrackingService.technicianPortal()
  portal.jobs = data.jobs || []
  portal.history = data.history || []
  portal.totals = data.totals || {}
  portal.active_entry = data.active_entry || null
  if (!selectedJobId.value && portal.jobs.length > 0) {
    selectedJobId.value = portal.jobs[0].id
  }
}

async function captureLocation() {
  locationError.value = ''

  if (!navigator.geolocation) {
    locationError.value = 'Geolocation is not supported in this browser.'
    return null
  }

  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (position) => {
        resolve({
          lat: position.coords.latitude,
          lng: position.coords.longitude,
          accuracy: position.coords.accuracy,
          altitude: position.coords.altitude,
          speed: position.coords.speed,
          heading: position.coords.heading,
          recorded_at: new Date(position.timestamp).toISOString(),
          source: 'browser_geolocation',
        })
      },
      (error) => {
        locationError.value = error.message || 'Unable to capture location.'
        resolve(null)
      },
      { enableHighAccuracy: true, timeout: 10000 }
    )
  })
}

async function startTimer() {
  locationError.value = ''
  saving.value = true
  try {
    let location = null
    const selectedJob = portal.jobs.find((job) => String(job.id) === String(selectedJobId.value))
    if (selectedJob?.is_mobile) {
      location = await captureLocation()
      if (!location) return
    }

    await timeTrackingService.start({ estimate_job_id: selectedJobId.value ? Number(selectedJobId.value) : null, location })
    locationError.value = ''
    await loadPortal()
  } finally {
    saving.value = false
  }
}

async function stopTimer() {
  if (!portal.active_entry) return
  locationError.value = ''
  saving.value = true
  try {
    let location = null
    if (portal.active_entry?.is_mobile) {
      location = await captureLocation()
      if (!location) return
    }

    const payload = location ? { location } : {}
    await timeTrackingService.stop(portal.active_entry.id, payload)
    locationError.value = ''
    await loadPortal()
  } finally {
    saving.value = false
  }
}

loadPortal()
</script>
