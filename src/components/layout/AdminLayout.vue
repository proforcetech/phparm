<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navbar -->
    <Navbar />

    <div class="flex">
      <!-- Sidebar -->
      <Sidebar ref="sidebarRef" type="admin" />

      <!-- Main Content -->
      <div class="flex-1 lg:ml-64">
        <!-- Mobile menu button -->
        <div class="lg:hidden flex items-center justify-between p-4 bg-white border-b border-gray-200">
          <button
            @click="toggleSidebar"
            class="text-gray-500 hover:text-gray-700 focus:outline-none"
          >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <span class="text-lg font-semibold text-gray-900">Auto Repair Shop</span>
          <div class="w-6"></div> <!-- Spacer for centering -->
        </div>

        <!-- Page Content -->
        <main class="p-4 sm:p-6 lg:p-8">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Navbar from './Navbar.vue'
import Sidebar from './Sidebar.vue'

const sidebarRef = ref(null)

function toggleSidebar() {
  if (sidebarRef.value) {
    sidebarRef.value.toggleSidebar()
  }
}
</script>
