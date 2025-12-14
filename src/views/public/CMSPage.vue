<template>
  <div v-if="loading" class="flex justify-center items-center min-h-screen">
    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
  </div>

  <div v-else-if="error" class="min-h-screen flex items-center justify-center">
    <div class="text-center">
      <h1 class="text-4xl font-bold text-gray-800 mb-4">404</h1>
      <p class="text-xl text-gray-600 mb-4">{{ error }}</p>
      <router-link to="/" class="text-blue-600 hover:text-blue-800">
        Return to Home
      </router-link>
    </div>
  </div>

  <div v-else-if="page" class="cms-page">
    <!-- Header Component -->
    <div v-if="page.header_content" class="cms-header" v-html="renderContent(page.header_content)"></div>

    <!-- Page Content -->
    <div class="cms-page-content">
      <div class="container mx-auto px-4 py-8">
        <h1 class="text-4xl font-bold mb-6">{{ page.title }}</h1>
        <div class="prose max-w-none" v-html="renderContent(page.content)"></div>
      </div>
    </div>

    <!-- Footer Component -->
    <div v-if="page.footer_content" class="cms-footer" v-html="renderContent(page.footer_content)"></div>

    <!-- Custom Styles -->
    <component :is="'style'" v-if="customStyles">
      {{ customStyles }}
    </component>

    <!-- Custom Scripts -->
    <component :is="'script'" v-if="customScripts" type="text/javascript">
      {{ customScripts }}
    </component>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import cmsService from '@/services/cms.service'

const route = useRoute()
const page = ref(null)
const loading = ref(true)
const error = ref(null)

// Get slug from route - either from params or use 'home' for root path
const slug = computed(() => {
  if (route.path === '/') {
    return 'home'
  }
  // Get slug from pathMatch (for catch-all route) or path
  if (route.params.pathMatch) {
    // pathMatch is an array, join with '/' or use as string
    return Array.isArray(route.params.pathMatch)
      ? route.params.pathMatch.join('/')
      : route.params.pathMatch
  }
  // Fallback: remove leading slash from path
  return route.path.substring(1)
})

const customStyles = computed(() => {
  if (!page.value) return ''

  let styles = ''

  // Template CSS
  if (page.value.template_css) {
    styles += page.value.template_css + '\n'
  }

  // Page custom CSS
  if (page.value.custom_css) {
    styles += page.value.custom_css + '\n'
  }

  // Header component CSS
  if (page.value.header_css) {
    styles += page.value.header_css + '\n'
  }

  // Footer component CSS
  if (page.value.footer_css) {
    styles += page.value.footer_css + '\n'
  }

  return styles
})

const customScripts = computed(() => {
  if (!page.value) return ''

  let scripts = ''

  // Template JS
  if (page.value.template_js) {
    scripts += page.value.template_js + '\n'
  }

  // Page custom JS
  if (page.value.custom_js) {
    scripts += page.value.custom_js + '\n'
  }

  // Header component JS
  if (page.value.header_js) {
    scripts += page.value.header_js + '\n'
  }

  // Footer component JS
  if (page.value.footer_js) {
    scripts += page.value.footer_js + '\n'
  }

  return scripts
})

function renderContent(content) {
  if (!content) return ''
  return content
}

async function loadPage() {
  loading.value = true
  error.value = null

  try {
    const data = await cmsService.getPageBySlug(slug.value)
    page.value = data
  } catch (err) {
    if (err.response?.status === 404) {
      error.value = 'Page not found'
    } else {
      error.value = 'Failed to load page'
      console.error('Failed to load CMS page:', err)
    }
  } finally {
    loading.value = false
  }
}

// Load page on mount
onMounted(() => {
  loadPage()
})

// Reload when slug changes
watch(slug, () => {
  loadPage()
})
</script>

<style scoped>
.cms-page {
  min-height: 100vh;
}

.cms-page-content {
  flex: 1;
}

/* Prose styles for content */
.prose {
  color: #374151;
  max-width: 65ch;
}

.prose h1 {
  font-size: 2.25em;
  margin-top: 0;
  margin-bottom: 0.8888889em;
  line-height: 1.1111111;
}

.prose h2 {
  font-size: 1.5em;
  margin-top: 2em;
  margin-bottom: 1em;
  line-height: 1.3333333;
}

.prose h3 {
  font-size: 1.25em;
  margin-top: 1.6em;
  margin-bottom: 0.6em;
  line-height: 1.6;
}

.prose p {
  margin-top: 1.25em;
  margin-bottom: 1.25em;
}

.prose a {
  color: #2563eb;
  text-decoration: underline;
}

.prose a:hover {
  color: #1d4ed8;
}

.prose ul, .prose ol {
  margin-top: 1.25em;
  margin-bottom: 1.25em;
  padding-left: 1.625em;
}

.prose li {
  margin-top: 0.5em;
  margin-bottom: 0.5em;
}

.prose img {
  max-width: 100%;
  height: auto;
}
</style>
