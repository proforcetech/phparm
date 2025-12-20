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

  <!-- CMS content isolated from Tailwind styles -->
  <div v-else class="cms-isolated" v-html="renderedHtml"></div>
</template>

<script setup>
import { ref, computed, onMounted, watch, onUnmounted, nextTick, createApp } from 'vue'
import { useRoute } from 'vue-router'
import { cmsService } from '@/services/cms.service'

const route = useRoute()
const renderedHtml = ref(null)
const pageData = ref(null)
const loading = ref(true)
const error = ref(null)
const originalTitle = document.title
const originalBodyClass = document.body.className
const addedMetaTags = []
const mountedVueApps = []

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

function extractAndInjectMetaTags(html) {
  // Create a temporary DOM parser
  const parser = new DOMParser()
  const doc = parser.parseFromString(html, 'text/html')

  // Extract title
  const titleTag = doc.querySelector('title')
  if (titleTag) {
    document.title = titleTag.textContent
  } else if (pageData.value?.meta_title) {
    document.title = pageData.value.meta_title
  } else if (pageData.value?.title) {
    document.title = pageData.value.title
  }

  // Remove existing CMS meta tags
  addedMetaTags.forEach(tag => tag.remove())
  addedMetaTags.length = 0

  // Extract and inject meta tags
  const metaTags = doc.querySelectorAll('meta[name="description"], meta[name="keywords"]')
  metaTags.forEach(metaTag => {
    const clonedTag = document.createElement('meta')
    clonedTag.setAttribute('name', metaTag.getAttribute('name'))
    clonedTag.setAttribute('content', metaTag.getAttribute('content'))
    document.head.appendChild(clonedTag)
    addedMetaTags.push(clonedTag)
  })

  // Extract style and script tags from the rendered HTML
  const styleTags = doc.querySelectorAll('style')
  const scriptTags = doc.querySelectorAll('script')

  // Inject styles
  styleTags.forEach(styleTag => {
    const clonedStyle = document.createElement('style')
    clonedStyle.textContent = styleTag.textContent
    document.head.appendChild(clonedStyle)
    addedMetaTags.push(clonedStyle)
  })

  // Inject scripts - wrap in try-catch to prevent errors from breaking the page
  scriptTags.forEach(scriptTag => {
    const clonedScript = document.createElement('script')
    if (scriptTag.src) {
      clonedScript.src = scriptTag.src
      clonedScript.onerror = (e) => {
        console.warn('Failed to load external script:', scriptTag.src)
      }
    } else {
      // Wrap inline scripts in try-catch to prevent errors
      const wrappedScript = `
        try {
          ${scriptTag.textContent}
        } catch (e) {
          console.warn('CMS script error:', e);
        }
      `
      clonedScript.textContent = wrappedScript
    }
    // Defer script execution until after page content is rendered
    clonedScript.defer = true
    document.body.appendChild(clonedScript)
    addedMetaTags.push(clonedScript)
  })

  // Add CMS body class to override Tailwind defaults
  document.body.classList.add('cms-page-active')

  // Return HTML without the head tags (they're already injected)
  return doc.body.innerHTML
}

// Mount Vue components embedded in CMS content
async function mountVueComponents() {
  await nextTick()

  // Find all elements with data-vue-component attribute
  const componentElements = document.querySelectorAll('[data-vue-component]')

  for (const element of componentElements) {
    const componentName = element.getAttribute('data-vue-component')

    try {
      let component = null

      // Map component names to their imports
      switch (componentName) {
        case 'EstimateRequestForm':
          component = (await import('@/components/public/EstimateRequestForm.vue')).default
          break
        // Add more components here as needed
        default:
          console.warn(`Unknown Vue component: ${componentName}`)
          continue
      }

      if (component) {
        // Create and mount the Vue app
        const app = createApp(component)
        app.mount(element)
        mountedVueApps.push(app)
      }
    } catch (err) {
      console.error(`Failed to mount Vue component ${componentName}:`, err)
    }
  }
}

async function loadPage() {
  loading.value = true
  error.value = null

  try {
    const data = await cmsService.getRenderedPageBySlug(slug.value)
    console.log('CMS API Response:', {
      hasHtml: !!data.html,
      htmlLength: data.html?.length || 0,
      hasPage: !!data.page,
      pageTitle: data.page?.title
    })

    pageData.value = data.page
    renderedHtml.value = extractAndInjectMetaTags(data.html)

    console.log('Rendered HTML length:', renderedHtml.value?.length || 0)

    // Mount any embedded Vue components after HTML is rendered
    await mountVueComponents()
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

// Cleanup function to restore original title and remove added tags
function cleanup() {
  document.title = originalTitle
  document.body.className = originalBodyClass
  document.body.classList.remove('cms-page-active')
  addedMetaTags.forEach(tag => tag.remove())
  addedMetaTags.length = 0

  // Unmount all Vue components
  mountedVueApps.forEach(app => {
    try {
      app.unmount()
    } catch (err) {
      console.warn('Failed to unmount Vue app:', err)
    }
  })
  mountedVueApps.length = 0
}

// Load page on mount
onMounted(() => {
  loadPage()
})

// Reload when slug changes
watch(slug, () => {
  cleanup()
  loadPage()
})

// Cleanup on unmount
onUnmounted(() => {
  cleanup()
})
</script>

<style>
/* Reset Tailwind styles for CMS content */
.cms-isolated {
  all: unset;
  display: block;
  min-height: 100vh;
}

/* Override Tailwind body styles when CMS page is active */
body.cms-page-active {
  background-color: #0d0f12 !important;
  color: #f5f5f5 !important;
  margin: 0 !important;
  padding: 0 !important;
}

/* Ensure CMS content uses its own box-sizing */
.cms-isolated,
.cms-isolated * {
  box-sizing: border-box;
}
</style>
