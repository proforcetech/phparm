<template>
  <div
    class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
    @click="$emit('click', customer)"
  >
    <div class="p-4 sm:p-6">
      <!-- Header -->
      <div class="flex items-start justify-between mb-4">
        <div class="flex items-start gap-3 flex-1 min-w-0">
          <!-- Avatar -->
          <div class="flex-shrink-0">
            <div class="h-12 w-12 rounded-full bg-primary-100 flex items-center justify-center">
              <svg class="h-6 w-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </div>
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <h3 class="text-lg font-semibold text-gray-900 truncate">
                {{ customer.name || `Customer #${customer.id}` }}
              </h3>
              <Badge v-if="customer.status" :variant="getStatusVariant(customer.status)">
                {{ customer.status }}
              </Badge>
            </div>
            <p v-if="customer.email" class="text-sm text-gray-600 truncate">
              {{ customer.email }}
            </p>
          </div>
        </div>

        <div class="flex-shrink-0 ml-4">
          <slot name="actions">
            <button
              v-if="showActions"
              @click.stop="$emit('action', customer)"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
              </svg>
            </button>
          </slot>
        </div>
      </div>

      <!-- Contact Info -->
      <div class="space-y-2 mb-4">
        <div v-if="customer.phone" class="flex items-center gap-2 text-sm">
          <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
          </svg>
          <span class="text-gray-900">{{ customer.phone }}</span>
        </div>
        <div v-if="customer.address" class="flex items-start gap-2 text-sm">
          <svg class="h-4 w-4 text-gray-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span class="text-gray-900 flex-1">{{ customer.address }}</span>
        </div>
      </div>

      <!-- Stats -->
      <div v-if="showStats" class="border-t border-gray-200 pt-3 grid grid-cols-3 gap-4">
        <div class="text-center">
          <p class="text-2xl font-bold text-gray-900">{{ customer.total_invoices || 0 }}</p>
          <p class="text-xs text-gray-500">Invoices</p>
        </div>
        <div class="text-center">
          <p class="text-2xl font-bold text-gray-900">{{ customer.total_vehicles || 0 }}</p>
          <p class="text-xs text-gray-500">Vehicles</p>
        </div>
        <div class="text-center">
          <p class="text-2xl font-bold text-gray-900">{{ customer.total_appointments || 0 }}</p>
          <p class="text-xs text-gray-500">Visits</p>
        </div>
      </div>

      <!-- Credit Account -->
      <div v-if="customer.credit_account_id" class="border-t border-gray-200 pt-3 mt-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <svg class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="text-sm font-medium text-gray-700">Credit Account</span>
          </div>
          <span v-if="customer.credit_balance !== undefined" class="text-sm font-semibold text-gray-900">
            {{ formatCurrency(customer.credit_balance) }}
          </span>
        </div>
      </div>

      <!-- Membership/Tags -->
      <div v-if="customer.tags && customer.tags.length > 0" class="flex flex-wrap gap-1 mt-3">
        <span
          v-for="tag in customer.tags"
          :key="tag"
          class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
        >
          {{ tag }}
        </span>
      </div>

      <!-- Footer slot -->
      <slot name="footer"></slot>
    </div>
  </div>
</template>

<script setup>
import Badge from '@/components/ui/Badge.vue'

const props = defineProps({
  customer: {
    type: Object,
    required: true
  },
  showActions: {
    type: Boolean,
    default: true
  },
  showStats: {
    type: Boolean,
    default: true
  }
})

defineEmits(['click', 'action'])

function getStatusVariant(status) {
  const variants = {
    'active': 'success',
    'inactive': 'default',
    'suspended': 'danger',
    'pending': 'warning'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0)
}
</script>
