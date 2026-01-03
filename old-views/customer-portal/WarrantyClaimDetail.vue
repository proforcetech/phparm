<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Claim #{{ id }}</h1>
        <p class="mt-1 text-sm text-gray-500">Review the claim details and conversation.</p>
      </div>
      <Button variant="ghost" @click="$router.push('/portal/warranty-claims')">Back to claims</Button>
    </div>

    <Card>
      <div v-if="loading" class="py-10 flex justify-center">
        <Loading label="Loading claim..." />
      </div>
      <div v-else>
        <div class="flex items-center justify-between mb-4">
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ claim.subject }}</h2>
            <p class="text-sm text-gray-500">Invoice: {{ claim.invoice_id || '—' }} · Vehicle: {{ claim.vehicle_id || '—' }}</p>
          </div>
          <span class="px-3 py-1 text-xs rounded-full" :class="statusClass(claim.status)">{{ claim.status }}</span>
        </div>

        <div class="bg-gray-50 border rounded-md p-4 mb-6">
          <p class="text-sm text-gray-700 whitespace-pre-line">{{ claim.description }}</p>
        </div>

        <h3 class="text-sm font-semibold text-gray-900 mb-3">Messages</h3>
        <div class="space-y-3">
          <div
            v-for="message in claim.messages"
            :key="message.id"
            class="p-3 rounded-lg border"
            :class="message.actor_type === 'customer' ? 'bg-blue-50 border-blue-100' : 'bg-gray-50 border-gray-200'"
          >
            <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
              <span class="font-medium text-gray-700">{{ message.actor_type === 'customer' ? 'You' : 'Shop' }}</span>
              <span>{{ formatDate(message.created_at) }}</span>
            </div>
            <p class="text-sm text-gray-800 whitespace-pre-line">{{ message.message }}</p>
          </div>
        </div>

        <div class="mt-6">
          <h3 class="text-sm font-semibold text-gray-900 mb-2">Add a reply</h3>
          <form class="space-y-3" @submit.prevent="submitReply">
            <Textarea v-model="reply" label="Message" placeholder="Share more details or updates" required />
            <div class="flex justify-end">
              <Button variant="primary" type="submit" :disabled="replying">
                <span v-if="replying">Sending...</span>
                <span v-else>Send Reply</span>
              </Button>
            </div>
          </form>
        </div>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Loading from '@/components/ui/Loading.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { warrantyService } from '@/services/warranty.service'

const route = useRoute()
const id = Number(route.params.id)
const claim = ref({ subject: '', description: '', status: '', invoice_id: null, vehicle_id: null, messages: [] })
const loading = ref(false)
const replying = ref(false)
const reply = ref('')

const loadClaim = async () => {
  loading.value = true
  try {
    claim.value = await warrantyService.getCustomerClaim(id)
  } finally {
    loading.value = false
  }
}

const submitReply = async () => {
  if (!reply.value) return
  replying.value = true
  try {
    claim.value = await warrantyService.replyToClaim(id, reply.value)
    reply.value = ''
  } finally {
    replying.value = false
  }
}

const statusClass = (status) => {
  switch (status) {
    case 'resolved':
      return 'bg-green-100 text-green-800'
    case 'rejected':
      return 'bg-red-100 text-red-800'
    case 'in_review':
      return 'bg-yellow-100 text-yellow-800'
    default:
      return 'bg-blue-100 text-blue-800'
  }
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

onMounted(() => {
  loadClaim()
})
</script>
