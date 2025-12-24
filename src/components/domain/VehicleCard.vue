<template>
  <div
    class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
    @click="$emit('click', vehicle)"
  >
    <div class="p-4 sm:p-6">
      <!-- Header -->
      <div class="flex items-start justify-between mb-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-2">
            <svg class="h-6 w-6 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
            </svg>
            <h3 class="text-lg font-semibold text-gray-900 truncate">
              {{ getVehicleTitle(vehicle) }}
            </h3>
          </div>
          <p v-if="vehicle.year || vehicle.make || vehicle.model" class="text-sm text-gray-600">
            {{ vehicle.year }} {{ vehicle.make }} {{ vehicle.model }}
          </p>
        </div>
        <div class="flex-shrink-0 ml-4">
          <slot name="actions">
            <button
              v-if="showActions"
              @click.stop="$emit('action', vehicle)"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
              </svg>
            </button>
          </slot>
        </div>
      </div>

      <!-- VIN & Plate -->
      <div class="space-y-2 mb-4">
        <div v-if="vehicle.vin" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">VIN</span>
          <span class="text-gray-900 font-mono text-xs">{{ vehicle.vin }}</span>
        </div>
        <div v-if="vehicle.plate" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">License Plate</span>
          <span class="text-gray-900 font-semibold">{{ vehicle.plate }}</span>
        </div>
      </div>

      <!-- Vehicle Details -->
      <div class="border-t border-gray-200 pt-3 space-y-2">
        <div v-if="vehicle.engine" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Engine</span>
          <span class="text-gray-900">{{ vehicle.engine }}</span>
        </div>
        <div v-if="vehicle.transmission" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Transmission</span>
          <span class="text-gray-900">{{ vehicle.transmission }}</span>
        </div>
        <div v-if="vehicle.drive" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Drive</span>
          <span class="text-gray-900">{{ vehicle.drive }}</span>
        </div>
        <div v-if="vehicle.trim" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Trim</span>
          <span class="text-gray-900">{{ vehicle.trim }}</span>
        </div>
        <div v-if="vehicle.color" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Color</span>
          <span class="text-gray-900">{{ vehicle.color }}</span>
        </div>
      </div>

      <!-- Customer Info -->
      <div v-if="showCustomer && vehicle.customer_id" class="border-t border-gray-200 pt-3 mt-3">
        <div class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Owner</span>
          <span v-if="vehicle.customer_name" class="text-gray-900 font-medium">
            {{ vehicle.customer_name }}
          </span>
          <span v-else class="text-gray-900">
            Customer #{{ vehicle.customer_id }}
          </span>
        </div>
      </div>

      <!-- Mileage -->
      <div v-if="vehicle.mileage" class="border-t border-gray-200 pt-3 mt-3">
        <div class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Mileage</span>
          <span class="text-gray-900 font-medium">
            {{ formatMileage(vehicle.mileage) }} mi
          </span>
        </div>
      </div>

      <!-- Notes -->
      <div v-if="vehicle.notes && showNotes" class="border-t border-gray-200 pt-3 mt-3">
        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Notes</p>
        <p class="text-sm text-gray-700 line-clamp-2">{{ vehicle.notes }}</p>
      </div>

      <!-- Footer slot -->
      <slot name="footer"></slot>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  vehicle: {
    type: Object,
    required: true
  },
  showActions: {
    type: Boolean,
    default: true
  },
  showCustomer: {
    type: Boolean,
    default: true
  },
  showNotes: {
    type: Boolean,
    default: true
  }
})

defineEmits(['click', 'action'])

function getVehicleTitle(vehicle) {
  if (vehicle.year && vehicle.make && vehicle.model) {
    return `${vehicle.year} ${vehicle.make} ${vehicle.model}`
  }
  if (vehicle.vin) {
    return vehicle.vin.substring(0, 17)
  }
  if (vehicle.plate) {
    return vehicle.plate
  }
  return `Vehicle #${vehicle.id || 'Unknown'}`
}

function formatMileage(mileage) {
  return new Intl.NumberFormat('en-US').format(mileage)
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
