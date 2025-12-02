<template>
  <nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between h-16">
        <div class="flex">
          <!-- Logo -->
          <div class="flex-shrink-0 flex items-center">
            <router-link to="/dashboard" class="text-xl font-bold text-primary-600">
              Auto Repair Shop
            </router-link>
          </div>
        </div>

        <!-- Right side -->
        <div class="flex items-center">
          <!-- User menu -->
          <div class="ml-3 relative">
            <div>
              <button
                @click="userMenuOpen = !userMenuOpen"
                type="button"
                class="flex items-center max-w-xs text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                id="user-menu-button"
                aria-expanded="false"
                aria-haspopup="true"
              >
                <span class="sr-only">Open user menu</span>
                <div class="flex items-center space-x-3">
                  <div class="hidden md:block text-right">
                    <div class="text-sm font-medium text-gray-900">
                      {{ user?.name || `${user?.first_name} ${user?.last_name}` }}
                    </div>
                    <div class="text-xs text-gray-500 capitalize">
                      {{ user?.role }}
                    </div>
                  </div>
                  <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center">
                    <span class="text-sm font-medium text-white">
                      {{ getUserInitials() }}
                    </span>
                  </div>
                  <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                  </svg>
                </div>
              </button>
            </div>

            <!-- Dropdown menu -->
            <transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 scale-95"
              enter-to-class="transform opacity-100 scale-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100 scale-100"
              leave-to-class="transform opacity-0 scale-95"
            >
              <div
                v-if="userMenuOpen"
                class="origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50"
                role="menu"
                aria-orientation="vertical"
                aria-labelledby="user-menu-button"
                tabindex="-1"
              >
                <router-link
                  v-if="isCustomer"
                  to="/portal/profile"
                  class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                  role="menuitem"
                  @click="userMenuOpen = false"
                >
                  My Profile
                </router-link>
                <router-link
                  v-if="isAdmin"
                  to="/settings"
                  class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                  role="menuitem"
                  @click="userMenuOpen = false"
                >
                  Settings
                </router-link>
                <button
                  @click="handleLogout"
                  class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                  role="menuitem"
                >
                  Sign out
                </button>
              </div>
            </transition>
          </div>
        </div>
      </div>
    </div>
  </nav>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const userMenuOpen = ref(false)

const user = computed(() => authStore.user)
const isCustomer = computed(() => authStore.isCustomer)
const isAdmin = computed(() => authStore.isAdmin)

function getUserInitials() {
  if (!user.value) return '?'

  const firstName = user.value.first_name || user.value.name?.split(' ')[0] || ''
  const lastName = user.value.last_name || user.value.name?.split(' ')[1] || ''

  return (firstName.charAt(0) + lastName.charAt(0)).toUpperCase() || '?'
}

function handleLogout() {
  userMenuOpen.value = false
  authStore.logout()
}

// Close dropdown when clicking outside
function handleClickOutside(event) {
  const userMenu = document.getElementById('user-menu-button')
  if (userMenu && !userMenu.contains(event.target)) {
    userMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
