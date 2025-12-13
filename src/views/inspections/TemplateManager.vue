<template>
  <div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold">Inspection Templates</h1>
      <button
        class="px-4 py-2 text-white bg-indigo-600 rounded hover:bg-indigo-700"
        @click="resetForm"
      >
        New Template
      </button>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
      <div class="p-4 bg-white rounded shadow">
        <h2 class="text-lg font-semibold mb-4">{{ form.id ? 'Edit Template' : 'Create Template' }}</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700">Name</label>
            <input v-model="form.name" type="text" class="w-full p-2 border rounded" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Description</label>
            <textarea v-model="form.description" class="w-full p-2 border rounded" rows="2"></textarea>
          </div>
          <div class="flex items-center space-x-2">
            <input v-model="form.active" type="checkbox" id="active" />
            <label for="active" class="text-sm">Active</label>
          </div>

          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold">Sections</h3>
              <button class="text-indigo-600" @click="addSection">+ Add Section</button>
            </div>
            <div
              v-for="(section, sIndex) in form.sections"
              :key="sIndex"
              class="p-3 border rounded space-y-3"
            >
              <div class="flex items-center space-x-2">
                <input
                  v-model="section.name"
                  placeholder="Section name"
                  class="flex-1 p-2 border rounded"
                  type="text"
                />
                <button class="text-sm text-red-600" @click="removeSection(sIndex)">Remove</button>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm font-medium">Items</span>
                <button class="text-indigo-600 text-sm" @click="addItem(sIndex)">+ Add Item</button>
              </div>
              <div v-for="(item, iIndex) in section.items" :key="iIndex" class="p-2 border rounded space-y-2">
                <input
                  v-model="item.name"
                  placeholder="Item name"
                  class="w-full p-2 border rounded"
                  type="text"
                />
                <select v-model="item.input_type" class="w-full p-2 border rounded">
                  <option value="text">Text</option>
                  <option value="textarea">Textarea</option>
                  <option value="boolean">Yes/No</option>
                  <option value="number">Number</option>
                </select>
                <input
                  v-model="item.default_value"
                  placeholder="Default value (optional)"
                  class="w-full p-2 border rounded"
                  type="text"
                />
                <button class="text-xs text-red-600" @click="removeItem(sIndex, iIndex)">Remove Item</button>
              </div>
            </div>
          </div>

          <div class="flex space-x-2">
            <button class="px-4 py-2 text-white bg-indigo-600 rounded" @click="submit">Save</button>
            <button class="px-4 py-2 text-gray-700 bg-gray-100 rounded" @click="resetForm">Cancel</button>
          </div>
          <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        </div>
      </div>

      <div class="p-4 bg-white rounded shadow">
        <h2 class="text-lg font-semibold mb-4">Existing Templates</h2>
        <div class="space-y-4">
          <div v-for="template in templates" :key="template.id" class="p-3 border rounded">
            <div class="flex items-center justify-between">
              <div>
                <p class="font-semibold">{{ template.name }}</p>
                <p class="text-sm text-gray-600">{{ template.description }}</p>
              </div>
              <div class="space-x-2">
                <button class="text-indigo-600" @click="loadTemplate(template)">Edit</button>
                <button class="text-red-600" @click="deleteTemplate(template.id)">Delete</button>
              </div>
            </div>
            <div class="mt-2 space-y-2">
              <div v-for="section in template.sections || []" :key="section.id" class="p-2 bg-gray-50 rounded">
                <p class="font-semibold text-sm">{{ section.name }}</p>
                <ul class="pl-4 list-disc text-sm text-gray-700">
                  <li v-for="item in section.items" :key="item.id">{{ item.name }} ({{ item.input_type }})</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import inspectionService from '@/services/inspection.service'

const templates = ref([])
const error = ref('')

const emptyForm = () => ({
  id: null,
  name: '',
  description: '',
  active: true,
  sections: [
    {
      name: 'General',
      items: [
        { name: 'Notes', input_type: 'text', default_value: '' },
      ],
    },
  ],
})

const form = reactive(emptyForm())

const loadTemplates = async () => {
  error.value = ''
  try {
    templates.value = await inspectionService.listTemplates()
  } catch (err) {
    console.error(err)
    error.value = 'Unable to load templates'
  }
}

onMounted(loadTemplates)

const addSection = () => {
  form.sections.push({ name: 'New Section', items: [{ name: 'New Item', input_type: 'text', default_value: '' }] })
}

const removeSection = (index) => {
  form.sections.splice(index, 1)
}

const addItem = (sectionIndex) => {
  form.sections[sectionIndex].items.push({ name: 'Item', input_type: 'text', default_value: '' })
}

const removeItem = (sectionIndex, itemIndex) => {
  form.sections[sectionIndex].items.splice(itemIndex, 1)
}

const resetForm = () => {
  Object.assign(form, emptyForm())
}

const loadTemplate = (template) => {
  Object.assign(form, JSON.parse(JSON.stringify(template)))
}

const submit = async () => {
  error.value = ''
  try {
    if (form.id) {
      await inspectionService.updateTemplate(form.id, form)
    } else {
      await inspectionService.createTemplate(form)
    }
    resetForm()
    await loadTemplates()
  } catch (err) {
    console.error(err)
    error.value = err.response?.data?.message || 'Unable to save template'
  }
}

const deleteTemplate = async (id) => {
  if (!confirm('Delete this template?')) return
  try {
    await inspectionService.deleteTemplate(id)
    await loadTemplates()
  } catch (err) {
    console.error(err)
    error.value = 'Unable to delete template'
  }
}
</script>
