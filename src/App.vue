<template>
  <div id="app" class="min-h-screen">
    <!-- Guest routes (login, register, etc.) - no layout -->
    <router-view v-if="isGuestRoute" :key="route.fullPath" />

    <!-- Customer portal routes - use CustomerLayout -->
    <CustomerLayout v-else-if="isCustomerRoute">
      <router-view :key="route.fullPath" />
    </CustomerLayout>

    <!-- Admin/Staff routes - use AdminLayout -->
    <AdminLayout v-else-if="isStaffRoute">
      <router-view :key="route.fullPath" />
    </AdminLayout>

    <!-- Fallback for unauthenticated users -->
    <router-view v-else :key="route.fullPath" />
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '@/components/layout/AdminLayout.vue'
import CustomerLayout from '@/components/layout/CustomerLayout.vue'

const route = useRoute()
const authStore = useAuthStore()

onMounted(() => {
  // Check if user is logged in on app mount
  authStore.checkAuth()
})

// Determine which layout to use based on route
const isGuestRoute = computed(() => route.meta.guest === true)

const isCustomerRoute = computed(() => authStore.isCustomer && route.meta.requiresCustomer)

const isStaffRoute = computed(() => authStore.isAuthenticated && authStore.isStaff)
</script>
