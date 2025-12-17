<template>
  <aside
    class="fixed inset-y-0 left-0 bg-gray-900 w-64 transform transition-transform duration-300 ease-in-out z-30"
    :class="{ '-translate-x-full': !isOpen, 'translate-x-0': isOpen }"
  >
    <div class="flex flex-col h-full">
      <!-- Sidebar Header -->
      <div class="flex items-center justify-between h-16 px-4 bg-gray-800">
        <span class="text-lg font-semibold text-white">Menu</span>
        <button
          @click="toggleSidebar"
          class="lg:hidden text-gray-400 hover:text-white focus:outline-none"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
        <router-link
          v-for="item in menuItems"
          :key="item.path"
          :to="item.path"
          class="flex items-center px-4 py-2 text-sm font-medium rounded-md transition-colors"
          :class="isActive(item.path)
            ? 'bg-gray-800 text-white'
            : 'text-gray-300 hover:bg-gray-700 hover:text-white'"
        >
          <component :is="item.icon" class="h-5 w-5 mr-3" />
          {{ item.label }}
        </router-link>
      </nav>
    </div>
  </aside>

  <!-- Overlay for mobile -->
  <div
    v-if="isOpen"
    @click="toggleSidebar"
    class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-20"
  ></div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  HomeIcon,
  DocumentTextIcon,
  CalendarIcon,
  UserGroupIcon,
  TruckIcon,
  CubeIcon,
  RectangleStackIcon,
  ChartBarIcon,
  Cog6ToothIcon,
  ClockIcon,
  CreditCardIcon,
  ShieldCheckIcon,
  GlobeAltIcon,
  DocumentDuplicateIcon,
  RectangleGroupIcon,
  Squares2X2Icon,
  UsersIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  type: {
    type: String,
    default: 'admin', // 'admin' or 'customer'
  },
})

const route = useRoute()
const authStore = useAuthStore()
const isOpen = ref(true)

function handleResize() {
  if (typeof window === 'undefined') return
  if (window.innerWidth >= 1024) {
    isOpen.value = true
  }
}

onMounted(() => {
  if (typeof window !== 'undefined' && window.innerWidth < 1024) {
    isOpen.value = false
  }
  window.addEventListener('resize', handleResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
})

// Admin menu items
const adminMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/cp/invoices', label: 'Invoices', icon: DocumentTextIcon },
  { path: '/cp/estimates', label: 'Estimates', icon: DocumentTextIcon },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/cp/customers', label: 'Customers', icon: UserGroupIcon },
  { path: '/cp/vehicles', label: 'Vehicles', icon: TruckIcon },
  { path: '/cp/bundles', label: 'Preset Bundles', icon: RectangleStackIcon },
  { path: '/cp/inventory/alerts', label: 'Inventory Alerts', icon: CubeIcon },
  { path: '/cp/inventory', label: 'Inventory', icon: CubeIcon },
  { path: '/cp/financial/entries', label: 'Purchases & Expenses', icon: DocumentTextIcon },
  { path: '/cp/reports', label: 'Reports', icon: ChartBarIcon },
  // Inspections Section
  { path: '/cp/inspections/templates', label: 'Inspection Templates', icon: ClipboardDocumentCheckIcon },
  { path: '/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
  // CMS Section
  { path: '/cp/cms', label: 'CMS Dashboard', icon: GlobeAltIcon, section: 'cms' },
  { path: '/cp/cms/pages', label: 'CMS Pages', icon: DocumentDuplicateIcon, section: 'cms' },
  { path: '/cp/cms/components', label: 'CMS Components', icon: Squares2X2Icon, section: 'cms' },
  { path: '/cp/cms/templates', label: 'CMS Templates', icon: RectangleGroupIcon, section: 'cms' },
  { path: '/cp/settings', label: 'Settings', icon: Cog6ToothIcon },
  { path: '/cp/users', label: 'Users', icon: UsersIcon },
]

const technicianMenuItems = [
  { path: '/cp/dashboard', label: 'Dashboard', icon: HomeIcon },
  { path: '/cp/my-time', label: 'My Time', icon: ClockIcon },
  { path: '/cp/time-logs', label: 'Time Logs', icon: ClockIcon },
  { path: '/cp/appointments', label: 'Appointments', icon: CalendarIcon },
  { path: '/cp/inspections/work', label: 'Inspections', icon: ClipboardDocumentListIcon },
]

// Customer menu items
const customerMenuItems = [
  { path: '/portal', label: 'Dashboard', icon: HomeIcon },
  { path: '/portal/invoices', label: 'My Invoices', icon: DocumentTextIcon },
  { path: '/portal/appointments', label: 'My Appointments', icon: CalendarIcon },
  { path: '/portal/vehicles', label: 'My Vehicles', icon: TruckIcon },
  { path: '/portal/inspections', label: 'My Inspections', icon: ClipboardDocumentCheckIcon },
  { path: '/portal/credit', label: 'Credit Account', icon: CreditCardIcon },
  { path: '/portal/warranty-claims', label: 'Warranty Claims', icon: ShieldCheckIcon },
  { path: '/portal/profile', label: 'Profile', icon: Cog6ToothIcon },
]

const menuItems = computed(() => {
  if (props.type === 'customer') {
    return customerMenuItems
  }

  if (authStore.user?.role === 'technician') {
    return technicianMenuItems
  }

  return adminMenuItems
})

function isActive(path) {
  if (path === '/cp/dashboard' || path === '/portal') {
    return route.path === path
  }
  if (path === '/cp/inventory') {
    return route.path === '/cp/inventory'
  }
  // Handle CMS routes - exact match for /cp/cms, startsWith for others
  if (path === '/cp/cms') {
    return route.path === '/cp/cms'
  }
  return route.path.startsWith(path)
}

function toggleSidebar() {
  isOpen.value = !isOpen.value
}

// Expose toggle for parent components
defineExpose({
  toggleSidebar,
  isOpen,
})
</script>
