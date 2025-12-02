<template>
  <div>
    <div class="mb-6">
      <div class="flex items-center gap-4">
        <Button variant="ghost" @click="$router.push('/portal/invoices')">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </Button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Invoice Details</h1>
          <p class="mt-1 text-sm text-gray-500">View invoice and payment options</p>
        </div>
      </div>
    </div>

    <Card>
      <div class="text-center py-12">
        <p class="text-sm text-gray-500">Invoice details coming soon.</p>
      </div>
    </Card>
  </div>
</template>

<script setup>
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
</script>
