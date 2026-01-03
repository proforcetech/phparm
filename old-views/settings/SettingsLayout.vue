<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
      <p class="mt-1 text-sm text-gray-500">Manage your shop configuration and integrations.</p>
    </div>

    <!-- Sub-navigation -->
    <div class="border-b border-gray-200 mb-6">
      <nav class="-mb-px flex space-x-8 overflow-x-auto" aria-label="Settings sections">
        <router-link
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors"
          :class="[
            isActive(item.to)
              ? 'border-indigo-500 text-indigo-600'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
          ]"
        >
          {{ item.label }}
        </router-link>
      </nav>
    </div>

    <!-- Child route content -->
    <router-view />
  </div>
</template>

<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()

const navItems = [
  { to: '/cp/settings', label: 'Shop Profile' },
  { to: '/cp/settings/terms', label: 'Terms & Documents' },
  { to: '/cp/settings/templates', label: 'Sharing Templates' },
  { to: '/cp/settings/rejection-reasons', label: 'Rejection Reasons' },
  { to: '/cp/settings/pricing', label: 'Pricing Defaults' },
  { to: '/cp/settings/notifications', label: 'Notifications & Mail' },
  { to: '/cp/settings/payments', label: 'Payments' },
  { to: '/cp/settings/integrations', label: 'Integrations' },
  { to: '/cp/settings/services', label: 'Service Types' },
]

function isActive(path) {
  if (path === '/cp/settings') {
    return route.path === '/cp/settings'
  }
  return route.path.startsWith(path)
}
</script>
