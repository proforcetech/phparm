<template>
  <div
    class="bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 cursor-pointer"
    :class="{ 'opacity-75': invoice.status === 'cancelled' }"
    @click="$emit('click', invoice)"
  >
    <div class="p-4 sm:p-6">
      <!-- Header -->
      <div class="flex items-start justify-between mb-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold text-gray-900 truncate">
              #{{ invoice.invoice_number }}
            </h3>
            <Badge :variant="getStatusVariant(invoice.status)">
              {{ invoice.status }}
            </Badge>
          </div>
          <p v-if="invoice.customer_name" class="text-sm text-gray-600 truncate">
            {{ invoice.customer_name }}
          </p>
          <p v-else-if="invoice.customer_id" class="text-sm text-gray-600">
            Customer #{{ invoice.customer_id }}
          </p>
        </div>
        <div class="flex-shrink-0 ml-4">
          <slot name="actions">
            <button
              v-if="showActions"
              @click.stop="$emit('action', invoice)"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
              </svg>
            </button>
          </slot>
        </div>
      </div>

      <!-- Details -->
      <div class="space-y-2 mb-4">
        <div class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Issue Date</span>
          <span class="text-gray-900">{{ formatDate(invoice.issue_date) }}</span>
        </div>
        <div v-if="invoice.due_date" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Due Date</span>
          <span class="text-gray-900" :class="{ 'text-red-600 font-medium': isOverdue(invoice) }">
            {{ formatDate(invoice.due_date) }}
            <span v-if="isOverdue(invoice)" class="text-xs ml-1">(Overdue)</span>
          </span>
        </div>
        <div v-if="invoice.vehicle_id" class="flex items-center justify-between text-sm">
          <span class="text-gray-500">Vehicle</span>
          <span class="text-gray-900">Vehicle #{{ invoice.vehicle_id }}</span>
        </div>
      </div>

      <!-- Amount -->
      <div class="border-t border-gray-200 pt-4">
        <div class="flex items-center justify-between">
          <span class="text-sm font-medium text-gray-500">Total Amount</span>
          <span class="text-xl font-bold text-gray-900">
            {{ formatCurrency(invoice.total || invoice.total_amount || 0) }}
          </span>
        </div>
        <div v-if="showPaymentInfo && invoice.amount_paid > 0" class="flex items-center justify-between mt-2">
          <span class="text-sm text-gray-500">Paid</span>
          <span class="text-sm font-medium text-green-600">
            {{ formatCurrency(invoice.amount_paid) }}
          </span>
        </div>
        <div v-if="showPaymentInfo && invoice.balance > 0" class="flex items-center justify-between mt-1">
          <span class="text-sm text-gray-500">Balance</span>
          <span class="text-sm font-medium text-red-600">
            {{ formatCurrency(invoice.balance) }}
          </span>
        </div>
      </div>

      <!-- Footer slot -->
      <slot name="footer"></slot>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import Badge from '@/components/ui/Badge.vue'

const props = defineProps({
  invoice: {
    type: Object,
    required: true
  },
  showActions: {
    type: Boolean,
    default: true
  },
  showPaymentInfo: {
    type: Boolean,
    default: true
  }
})

defineEmits(['click', 'action'])

function getStatusVariant(status) {
  const variants = {
    'paid': 'success',
    'pending': 'warning',
    'overdue': 'danger',
    'draft': 'default',
    'cancelled': 'default',
    'partial': 'info'
  }
  return variants[status?.toLowerCase()] || 'default'
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0)
}

function formatDate(date) {
  if (!date) return 'N/A'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }).format(new Date(date))
}

function isOverdue(invoice) {
  if (!invoice.due_date || invoice.status === 'paid' || invoice.status === 'cancelled') {
    return false
  }
  return new Date(invoice.due_date) < new Date()
}
</script>
