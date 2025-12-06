<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Availability Settings</h1>
        <p class="mt-1 text-sm text-gray-500">Configure weekly hours, slot lengths, and holiday closures.</p>
      </div>
      <Button :loading="saving" @click="save">Save Settings</Button>
    </div>

    <Card class="mb-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Weekly Hours</h2>
        <Button variant="ghost" :loading="saving" @click="resetToDefaults">Reset to defaults</Button>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Open</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Close</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slot (mins)</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Buffer (mins)</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Closed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="(row, index) in hours" :key="row.day_of_week">
              <td class="px-4 py-2 text-sm text-gray-900">{{ dayLabels[row.day_of_week] }}</td>
              <td class="px-4 py-2"><Input v-model="row.opens_at" type="time" :disabled="row.is_closed" /></td>
              <td class="px-4 py-2"><Input v-model="row.closes_at" type="time" :disabled="row.is_closed" /></td>
              <td class="px-4 py-2"><Input v-model.number="row.slot_minutes" type="number" min="5" /></td>
              <td class="px-4 py-2"><Input v-model.number="row.buffer_minutes" type="number" min="0" /></td>
              <td class="px-4 py-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                  <input v-model="row.is_closed" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                  Closed
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <Card>
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Holiday Closures</h2>
        <Button variant="ghost" size="sm" @click="addHoliday">Add holiday</Button>
      </div>
      <div class="space-y-3">
        <div
          v-for="(holiday, index) in holidays"
          :key="index"
          class="flex flex-col md:flex-row md:items-center md:space-x-3 space-y-2 md:space-y-0 border border-gray-200 rounded-lg p-3"
        >
          <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700">Date</label>
            <Input v-model="holiday.holiday_date" type="date" class="mt-1" />
          </div>
          <div class="flex-1">
            <label class="block text-xs font-medium text-gray-700">Label</label>
            <Input v-model="holiday.label" class="mt-1" placeholder="Holiday name" />
          </div>
          <div class="flex items-center">
            <Button variant="ghost" size="sm" @click="removeHoliday(index)">Remove</Button>
          </div>
        </div>
        <p v-if="!holidays.length" class="text-sm text-gray-500">No holidays defined.</p>
      </div>
    </Card>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import { fetchAvailabilityConfig, saveAvailabilityConfig } from '@/services/appointment.service'

const dayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const saving = ref(false)
const hours = reactive([])
const holidays = reactive([])

const defaultHours = () =>
  dayLabels.map((_, index) => ({
    day_of_week: index,
    opens_at: '08:00',
    closes_at: '17:00',
    slot_minutes: 30,
    buffer_minutes: 0,
    is_closed: index === 0 ? 1 : 0,
  }))

const resetToDefaults = () => {
  hours.splice(0, hours.length, ...defaultHours())
}

const hydrate = async () => {
  const data = await fetchAvailabilityConfig()
  hours.splice(0, hours.length, ...(data.hours?.length ? data.hours : defaultHours()))
  holidays.splice(0, holidays.length, ...(data.holidays || []))
}

const save = async () => {
  saving.value = true
  try {
    await saveAvailabilityConfig({ hours: [...hours], holidays: [...holidays] })
  } finally {
    saving.value = false
  }
}

const addHoliday = () => {
  holidays.push({ holiday_date: '', label: '' })
}

const removeHoliday = (index) => {
  holidays.splice(index, 1)
}

onMounted(hydrate)
</script>
