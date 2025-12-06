<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Customer Portal</h1>
      <p class="mt-1 text-sm text-gray-500">Welcome to your customer portal</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <Card class="hover:shadow-lg transition-shadow cursor-pointer" @click="$router.push('/portal/invoices')">
        <div class="text-center py-6">
          <svg class="mx-auto h-12 w-12 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">My Invoices</h3>
          <p class="mt-1 text-sm text-gray-500">View and pay invoices</p>
        </div>
      </Card>

      <Card class="hover:shadow-lg transition-shadow cursor-pointer" @click="$router.push('/portal/appointments')">
        <div class="text-center py-6">
          <svg class="mx-auto h-12 w-12 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">Appointments</h3>
          <p class="mt-1 text-sm text-gray-500">Schedule and manage appointments</p>
        </div>
      </Card>

      <Card class="hover:shadow-lg transition-shadow cursor-pointer" @click="$router.push('/portal/vehicles')">
        <div class="text-center py-6">
          <svg class="mx-auto h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">My Vehicles</h3>
          <p class="mt-1 text-sm text-gray-500">View vehicle service history</p>
        </div>
      </Card>

      <Card class="hover:shadow-lg transition-shadow cursor-pointer" @click="$router.push('/portal/credit')">
        <div class="text-center py-6">
          <svg class="mx-auto h-12 w-12 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.343-4 3s1.79 3 4 3 4-1.343 4-3M4 12c0 3.866 3.582 7 8 7s8-3.134 8-7-3.582-7-8-7-8 3.134-8 7z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">Credit Account</h3>
          <p class="mt-1 text-sm text-gray-500">Check balance and submit payments</p>
        </div>
      </Card>

      <Card class="hover:shadow-lg transition-shadow cursor-pointer" @click="$router.push('/portal/profile')">
        <div class="text-center py-6">
          <svg class="mx-auto h-12 w-12 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
          </svg>
          <h3 class="mt-4 text-lg font-medium text-gray-900">My Profile</h3>
          <p class="mt-1 text-sm text-gray-500">Update your information</p>
        </div>
      </Card>
    </div>
  </div>
</template>

<script setup>
import Card from '@/components/ui/Card.vue'
</script>
