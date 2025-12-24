<template>
  <div
    class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
    :class="{ 'opacity-75': appointment.status === 'cancelled' }"
    @click="$emit('click', appointment)"
  >
    <div class="p-4 sm:p-6">
      <!-- Header with status color bar -->
      <div class="flex items-start gap-3 mb-4">
        <div
          class="w-1 h-16 rounded-full flex-shrink-0"
          :style="{ backgroundColor: getStatusColor(appointment.status) }"
        ></div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <Badge :variant="getStatusVariant(appointment.status)">
              {{ appointment.status }}
            </Badge>
            <span v-if="appointment.service_type" class="text-sm text-gray-600">
              {{ appointment.service_type }}
            </span>
          </div>
          <p v-if="appointment.customer_name" class="text-base font-semibold text-gray-900 truncate">
            {{ appointment.customer_name }}
          </p>
          <p v-else-if="appointment.customer_id" class="text-base font-semibold text-gray-900">
            Customer #{{ appointment.customer_id }}
          </p>
          <p v-else class="text-base font-semibold text-gray-500">
            Walk-in
          </p>
        </div>
        <div class="flex-shrink-0">
          <slot name="actions">
            <button
              v-if="showActions"
              @click.stop="$emit('action', appointment)"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
              </svg>
            </button>
          </slot>
        </div>
      </div>

      <!-- Date & Time -->
      <div class="space-y-2 mb-4">
        <div class="flex items-center gap-2 text-sm">
          <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <span class="font-medium text-gray-900">{{ formatDate(appointment.start_time) }}</span>
        </div>
        <div class="flex items-center gap-2 text-sm">
          <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span class="text-gray-600">
            {{ formatTime(appointment.start_time) }} - {{ formatTime(appointment.end_time) }}
          </span>
        </div>
      </div>

      <!-- Additional Details -->
      <div class="space-y-2 border-t border-gray-200 pt-3">
        <div v-if="appointment.vehicle_id" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Vehicle</span>
          <span class="text-gray-900">Vehicle #{{ appointment.vehicle_id }}</span>
        </div>
        <div v-if="appointment.technician_id" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Technician</span>
          <span class="text-gray-900">Tech {{ appointment.technician_id }}</span>
        </div>
        <div v-if="showDuration" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Duration</span>
          <span class="text-gray-900">{{ getDuration(appointment) }}</span>
        </div>
      </div>

      <!-- Notes preview -->
      <div v-if="appointment.notes && showNotes" class="mt-3 border-t border-gray-200 pt-3">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Notes</p>
        <p class="text-sm text-gray-700 line-clamp-2">{{ appointment.notes }}</p>
      </div>

      <!-- Footer slot -->
      <slot name="footer"></slot>
    </div>
  </div>
</template>

<script setup>
import Badge from '@/components/ui/Badge.vue'

const props = defineProps({
  appointment: {
    type: Object,
    required: true
  },
  showActions: {
    type: Boolean,
    default: true
  },
  showNotes: {
    type: Boolean,
    default: true
  },
  showDuration: {
    type: Boolean,
    default: true
  }
})

defineEmits(['click', 'action'])

function getStatusColor(status) {
  const colors = {
    'pending': '#F59E0B',
    'confirmed': '#3B82F6',
    'in_progress': '#8B5CF6',
    'completed': '#10B981',
    'cancelled': '#EF4444',
    'no_show': '#6B7280'
  }
  return colors[status?.toLowerCase()] || '#6B7280'
}

function getStatusVariant(status) {
  const variants = {
    'pending': 'warning',
    'confirmed': 'info',
    'in_progress': 'default',
    'completed': 'success',
    'cancelled': 'danger',
    'no_show': 'default'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function formatDate(dateTime) {
  if (!dateTime) return 'N/A'
  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }).format(new Date(dateTime))
}

function formatTime(dateTime) {
  if (!dateTime) return ''
  return new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit'
  }).format(new Date(dateTime))
}

function getDuration(appointment) {
  if (!appointment.start_time || !appointment.end_time) return 'N/A'

  const start = new Date(appointment.start_time)
  const end = new Date(appointment.end_time)
  const diffMs = end - start
  const diffMins = Math.floor(diffMs / 60000)

  if (diffMins < 60) {
    return `${diffMins} min`
  } else {
    const hours = Math.floor(diffMins / 60)
    const mins = diffMins % 60
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`
  }
}
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
