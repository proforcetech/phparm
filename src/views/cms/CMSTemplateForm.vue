<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Alert from '@/components/ui/Alert.vue'
import Loading from '@/components/ui/Loading.vue'
import cmsService from '@/services/cms.service'
import { ArrowLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const error = ref(null)
const showPlaceholders = ref(false)
const templateData = ref(null)

const templateId = computed(() => route.params.id)
const isEditing = computed(() => !!templateId.value && templateId.value !== 'create')

const form = ref({
  name: '',
  slug: '',
  description: '',
  structure: '',
  default_css: '',
  default_js: '',
  is_active: true,
})

const structurePlaceholder = [
  '<!DOCTYPE html>',
  '<html>',
  '<head>',
  '    <title>{{title}}</title>',
  '    <meta name="description" content="{{meta_description}}">',
  '    <style>{{custom_css}}</style>',
  '</head>',
  '<body>',
  '    {{header}}',
  '    <main>{{content}}</main>',
  '    {{footer}}',
  '    <script>{{custom_js}}<\\/script>',
  '</body>',
  '</html>',
].join('\n')

const placeholders = [
  { token: '{{header}}', label: 'Header component' },
  { token: '{{content}}', label: 'Page content' },
  { token: '{{footer}}', label: 'Footer component' },
  { token: '{{title}}', label: 'Page title' },
  { token: '{{meta_description}}', label: 'Meta description' },
  { token: '{{custom_css}}', label: 'Page custom CSS' },
  { token: '{{custom_js}}', label: 'Page custom JavaScript' },
]

onMounted(async () => {
  await loadData()
})

async function loadData() {
  try {
    loading.value = true
    error.value = null

    // Load template if editing
    if (isEditing.value) {
      templateData.value = await cmsService.getTemplate(templateId.value)
      form.value = {
        name: templateData.value.name || '',
        slug: templateData.value.slug || '',
        description: templateData.value.description || '',
        structure: templateData.value.structure || '',
        default_css: templateData.value.default_css || '',
        default_js: templateData.value.default_js || '',
        is_active: !!templateData.value.is_active,
      }
    }
  } catch (err) {
    console.error('Failed to load data:', err)
    error.value = err.response?.data?.message || 'Failed to load data'
  } finally {
    loading.value = false
  }
}

function generateSlug() {
  if (isEditing.value) return // Don't auto-generate when editing

  const slug = form.value.name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

  form.value.slug = slug
}

async function saveTemplate() {
  try {
    saving.value = true
    error.value = null

    if (isEditing.value) {
      await cmsService.updateTemplate(templateId.value, form.value)
    } else {
      const newTemplate = await cmsService.createTemplate(form.value)
      router.push(`/cp/cms/templates/${newTemplate.id}`)
      return
    }

    // Reload data to get fresh state
    await loadData()
  } catch (err) {
    console.error('Failed to save template:', err)
    
    // Check for specific status codes to provide better feedback
    if (err.response?.status === 409) {
      // 409 Conflict - Duplicate slug/name
      error.value = err.response.data.message || 'A template with this name or slug already exists. Please choose another.'
    } else if (err.response?.status === 403) {
      // 403 Forbidden - Permission denied
      error.value = 'You do not have permission to perform this action.'
    } else {
      // General error fallback
      error.value = err.response?.data?.message || 'Failed to save template. Please try again.'
    }
  } finally {
    saving.value = false
  }
}

function formatDate(date) {
  if (!date) return '-'
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(date))
}
</script>
