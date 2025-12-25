<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edit Vendor' : 'Create Vendor' }}</h1>
        <p class="mt-1 text-sm text-gray-500">
          {{ isEditing ? 'Update vendor details and contacts.' : 'Add a new vendor for financial tracking.' }}
        </p>
      </div>
      <Button variant="secondary" @click="goBack">Back to list</Button>
    </div>

    <Card class="max-w-3xl">
      <form class="space-y-6" @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Vendor name *</label>
            <Input v-model="form.name" required placeholder="Acme Supply Co." />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Primary contact</label>
            <Input v-model="form.contact_name" placeholder="Jamie Smith" />
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="block text-sm font-medium text-gray-700">Email</label>
            <Input v-model="form.email" type="email" placeholder="ap@vendor.com" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700">Phone</label>
            <Input v-model="form.phone" placeholder="(555) 555-0199" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Address</label>
          <Input v-model="form.address" placeholder="123 Vendor Lane, Grand Rapids" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700">Notes</label>
          <Textarea v-model="form.notes" :rows="4" placeholder="Payment terms, preferred contacts, or ordering notes." />
        </div>

        <div class="flex items-center gap-3">
          <Button type="submit" :loading="saving">{{ isEditing ? 'Save vendor' : 'Create vendor' }}</Button>
          <Button type="button" variant="secondary" @click="goBack">Cancel</Button>
          <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        </div>
      </form>
    </Card>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import Input from '@/components/ui/Input.vue'
import Textarea from '@/components/ui/Textarea.vue'
import financialVendorService from '@/services/financial-vendor.service'
import { useToast } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToast()

const saving = ref(false)
const error = ref('')

const form = reactive({
  name: '',
  contact_name: '',
  email: '',
  phone: '',
  address: '',
  notes: '',
})

const vendorId = computed(() => route.params.id)
const isEditing = computed(() => Boolean(vendorId.value))

const loadVendor = async () => {
  if (!isEditing.value) return
  try {
    const vendor = await financialVendorService.get(vendorId.value)
    Object.assign(form, {
      name: vendor.name || '',
      contact_name: vendor.contact_name || '',
      email: vendor.email || '',
      phone: vendor.phone || '',
      address: vendor.address || '',
      notes: vendor.notes || '',
    })
  } catch (err) {
    console.error('Failed to load vendor', err)
    toast.error('Unable to load vendor')
  }
}

const submit = async () => {
  saving.value = true
  error.value = ''
  try {
    if (isEditing.value) {
      await financialVendorService.update(vendorId.value, { ...form })
      toast.success('Vendor updated')
    } else {
      await financialVendorService.create({ ...form })
      toast.success('Vendor created')
    }
    router.push('/cp/financial/vendors')
  } catch (err) {
    console.error('Failed to save vendor', err)
    error.value = 'Unable to save vendor'
  } finally {
    saving.value = false
  }
}

const goBack = () => {
  router.push('/cp/financial/vendors')
}

onMounted(loadVendor)
</script>
