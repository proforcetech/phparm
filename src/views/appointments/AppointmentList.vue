<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Appointments</h1>
        <p class="mt-1 text-sm text-gray-500">Manage appointment schedule</p>
      </div>
      <div class="flex gap-2">
        <Button variant="ghost" @click="$router.push('/appointments/availability-settings')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 1.343-3 3s1.343 3 3 3 3-1.343 3-3-1.343-3-3-3z M19.4 15a1.65 1.65 0 01-.33.5l1.5 2.6-1.73 1-1.5-2.6a6.99 6.99 0 01-1.7.7V20h-2v-2.8a6.99 6.99 0 01-1.7-.7l-1.5 2.6-1.73-1 1.5-2.6a1.65 1.65 0 01-.33-.5L4 14v-2l2.2-.4c.09-.25.2-.49.33-.72l-1.5-2.6 1.73-1 1.5 2.6c.54-.29 1.11-.51 1.7-.65V4h2v2.63c.59.14 1.16.36 1.7.65l1.5-2.6 1.73 1-1.5 2.6c.13.23.24.47.33.72L20 12v2z" />
          </svg>
          Availability
        </Button>
        <Button @click="$router.push('/appointments/book')">
          <svg class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          New Appointment
        </Button>
      </div>
    </div>

    <Card>
      <div class="text-center py-12">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">Appointments</h3>
        <p class="mt-1 text-sm text-gray-500">Appointment management coming soon.</p>
      </div>
    </Card>
  </div>
</template>

<script setup>
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
</script>
