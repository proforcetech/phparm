<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">My Vehicles</h1>
      <p class="mt-1 text-sm text-gray-500">View your vehicle service history</p>
    </div>

    <Card>
      <div class="text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">No Vehicles</h3>
        <p class="mt-1 text-sm text-gray-500">No vehicles are associated with your account.</p>
      </div>
    </Card>
  </div>
</template>

<script setup>
import Card from '@/components/ui/Card.vue'
</script>
