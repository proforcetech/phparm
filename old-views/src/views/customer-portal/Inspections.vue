<template>
  <div class="p-6 space-y-4">
    <h1 class="text-2xl font-semibold">My Inspections</h1>
    <p class="text-sm text-gray-600">View inspections shared with your account.</p>

    <div class="bg-white rounded shadow">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-sm font-semibold text-gray-700">ID</th>
            <th class="px-4 py-2 text-left text-sm font-semibold text-gray-700">Template</th>
            <th class="px-4 py-2 text-left text-sm font-semibold text-gray-700">Status</th>
            <th class="px-4 py-2 text-left text-sm font-semibold text-gray-700">Completed</th>
            <th class="px-4 py-2 text-left text-sm font-semibold text-gray-700">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="inspection in inspections" :key="inspection.id">
            <td class="px-4 py-2">{{ inspection.id }}</td>
            <td class="px-4 py-2">{{ inspection.template_id }}</td>
            <td class="px-4 py-2">{{ inspection.status }}</td>
            <td class="px-4 py-2">{{ inspection.completed_at || 'Pending' }}</td>
            <td class="px-4 py-2">
              <button class="text-indigo-600" @click="openDetail(inspection.id)">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="activeReport" class="p-4 bg-white rounded shadow">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold">Inspection #{{ activeReport.report.id }}</h2>
        <button class="text-sm text-gray-600" @click="activeReport = null">Close</button>
      </div>
      <p class="text-sm text-gray-600">Status: {{ activeReport.report.status }}</p>
      <div class="mt-2 space-y-2">
        <div v-for="item in activeReport.items" :key="item.id" class="p-2 border rounded">
          <p class="font-semibold">{{ item.label }}</p>
          <p class="text-sm">Response: {{ item.response }}</p>
          <p class="text-sm text-gray-600" v-if="item.note">Note: {{ item.note }}</p>
        </div>
      </div>
      <div class="mt-3">
        <p class="font-semibold">Media</p>
        <ul class="list-disc pl-5 text-sm">
          <li v-for="media in activeReport.media" :key="media.id">
            <a :href="media.path" class="text-indigo-600" target="_blank">{{ media.type }} - {{ media.path }}</a>
          </li>
        </ul>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import inspectionService from '@/services/inspection.service'

const inspections = ref([])
const activeReport = ref(null)
const error = ref('')

const loadInspections = async () => {
  try {
    inspections.value = await inspectionService.customerList()
  } catch (err) {
    console.error(err)
    error.value = 'Unable to load inspections'
  }
}

const openDetail = async (id) => {
  try {
    activeReport.value = await inspectionService.customerShow(id)
  } catch (err) {
    console.error(err)
    error.value = err.response?.data?.message || 'Unable to load inspection'
  }
}

onMounted(loadInspections)
</script>
