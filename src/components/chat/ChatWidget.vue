<template>
  <div v-if="isStaff" class="fixed bottom-6 right-6 z-50">
    <button
      type="button"
      class="relative flex h-14 w-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg transition hover:bg-blue-700"
      @click="toggleOpen"
    >
      <span class="sr-only">Open chat</span>
      <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12c0 4.418-4.03 8-9 8a9.72 9.72 0 0 1-4-.84L3 20l1.09-3.27A7.8 7.8 0 0 1 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8Z" />
      </svg>
      <span
        v-if="unreadTotal > 0"
        class="absolute -right-1 -top-1 flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-red-500 px-1 text-xs font-semibold"
      >
        {{ unreadTotal }}
      </span>
    </button>

    <div
      v-if="isOpen"
      class="mt-4 flex h-[32rem] w-[22rem] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl"
    >
      <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
        <div>
          <p class="text-sm font-semibold text-gray-900">Staff Chat</p>
          <p class="text-xs text-gray-500">Internal conversations</p>
        </div>
        <button
          type="button"
          class="rounded-full p-1 text-gray-500 transition hover:bg-gray-100"
          @click="isOpen = false"
        >
          <span class="sr-only">Close</span>
          <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path
              fill-rule="evenodd"
              d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z"
              clip-rule="evenodd"
            />
          </svg>
        </button>
      </div>

      <div class="flex flex-1 overflow-hidden">
        <aside class="w-32 border-r border-gray-200 bg-gray-50">
          <div class="flex items-center justify-between px-3 py-2">
            <span class="text-xs font-semibold uppercase text-gray-500">Threads</span>
            <button
              type="button"
              class="text-xs text-blue-600"
              @click="refreshThreads"
            >
              Refresh
            </button>
          </div>
          <div class="h-full overflow-y-auto">
            <button
              v-for="thread in threads"
              :key="thread.id"
              type="button"
              class="flex w-full flex-col gap-1 border-b border-gray-100 px-3 py-2 text-left text-xs transition hover:bg-white"
              :class="{
                'bg-white text-gray-900': selectedThreadId === thread.id,
                'text-gray-600': selectedThreadId !== thread.id,
              }"
              @click="selectThread(thread)"
            >
              <span class="font-semibold">
                {{ threadLabel(thread) }}
              </span>
              <span class="truncate text-[11px] text-gray-500">
                {{ thread.last_message || 'No messages yet' }}
              </span>
              <span
                v-if="thread.unread_count"
                class="mt-1 inline-flex w-fit items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700"
              >
                {{ thread.unread_count }} new
              </span>
            </button>
            <div v-if="!threads.length" class="px-3 py-6 text-center text-xs text-gray-400">
              No conversations yet.
            </div>
          </div>
        </aside>

        <section class="flex flex-1 flex-col">
          <div class="flex-1 space-y-4 overflow-y-auto px-4 py-3">
            <div v-if="!selectedThread" class="flex h-full items-center justify-center text-sm text-gray-400">
              Select a thread to view messages.
            </div>
            <div
              v-for="message in messages"
              :key="message.id"
              class="flex"
              :class="message.sender_id === currentUserId ? 'justify-end' : 'justify-start'"
            >
              <div
                class="max-w-[75%] rounded-2xl px-3 py-2 text-sm"
                :class="message.sender_id === currentUserId
                  ? 'bg-blue-600 text-white'
                  : 'bg-gray-100 text-gray-800'"
              >
                <p class="text-[11px] font-semibold opacity-80">
                  {{ message.sender_id === currentUserId ? 'You' : senderName(message) }}
                </p>
                <p class="whitespace-pre-wrap">{{ message.body }}</p>
                <p class="mt-1 text-[10px] opacity-70">{{ formatTimestamp(message.created_at) }}</p>
              </div>
            </div>
          </div>

          <form class="border-t border-gray-200 px-3 py-2" @submit.prevent="sendMessage">
            <div class="flex items-center gap-2">
              <input
                v-model="newMessage"
                type="text"
                :disabled="!selectedThread"
                class="flex-1 rounded-full border border-gray-200 px-4 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                placeholder="Type a message"
              />
              <button
                type="submit"
                class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
                :disabled="!selectedThread || sending"
              >
                Send
              </button>
            </div>
          </form>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { messagingService } from '@/services/messages.service'

const authStore = useAuthStore()
const isOpen = ref(false)
const threads = ref([])
const selectedThreadId = ref(null)
const messages = ref([])
const newMessage = ref('')
const unreadTotal = ref(0)
const sending = ref(false)
const pollingId = ref(null)

const isStaff = computed(() => authStore.isStaff)
const currentUserId = computed(() => authStore.user?.id)
const selectedThread = computed(() => threads.value.find(thread => thread.id === selectedThreadId.value) || null)

function toggleOpen() {
  isOpen.value = !isOpen.value
}

function senderName(message) {
  return `${message.first_name ?? ''} ${message.last_name ?? ''}`.trim() || 'Staff'
}

function threadLabel(thread) {
  if (thread.subject) {
    return thread.subject
  }

  const participants = thread.participants || []
  const otherNames = participants
    .filter(participant => participant.id !== currentUserId.value)
    .map(participant => participant.name || `${participant.first_name ?? ''} ${participant.last_name ?? ''}`.trim())
    .filter(Boolean)

  return otherNames.join(', ') || `Thread #${thread.id}`
}

function formatTimestamp(value) {
  if (!value) return ''
  const date = new Date(value)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

async function refreshThreads() {
  const data = await messagingService.listThreads()
  threads.value = data
  if (!selectedThreadId.value && threads.value.length) {
    selectedThreadId.value = threads.value[0].id
  }
}

async function refreshUnreadCounts() {
  const data = await messagingService.unreadCounts()
  unreadTotal.value = data.total || 0

  if (Array.isArray(data.threads)) {
    const lookup = new Map(data.threads.map(item => [item.thread_id, item.unread_count]))
    threads.value = threads.value.map(thread => ({
      ...thread,
      unread_count: lookup.get(thread.id) ?? thread.unread_count ?? 0,
    }))
  }
}

async function loadMessages(threadId) {
  if (!threadId) {
    messages.value = []
    return
  }

  const data = await messagingService.listMessages(threadId)
  messages.value = data
}

async function selectThread(thread) {
  selectedThreadId.value = thread.id
  await loadMessages(thread.id)
  await markRead(thread.id)
}

async function markRead(threadId) {
  if (!threadId) return
  await messagingService.markRead(threadId)
  await refreshUnreadCounts()
}

async function sendMessage() {
  if (!selectedThreadId.value || !newMessage.value.trim()) return
  sending.value = true
  try {
    await messagingService.postMessage(selectedThreadId.value, { body: newMessage.value.trim() })
    newMessage.value = ''
    await loadMessages(selectedThreadId.value)
    await refreshThreads()
    await refreshUnreadCounts()
  } finally {
    sending.value = false
  }
}

watch(isOpen, async (open) => {
  if (!open) return
  await refreshThreads()
  if (selectedThread.value) {
    await loadMessages(selectedThread.value.id)
    await markRead(selectedThread.value.id)
  }
})

onMounted(async () => {
  if (!isStaff.value) return
  await refreshThreads()
  await refreshUnreadCounts()

  pollingId.value = setInterval(async () => {
    await refreshUnreadCounts()
    if (isOpen.value) {
      await refreshThreads()
      if (selectedThreadId.value) {
        await loadMessages(selectedThreadId.value)
      }
    }
  }, 15000)
})

onBeforeUnmount(() => {
  if (pollingId.value) {
    clearInterval(pollingId.value)
  }
})
</script>
