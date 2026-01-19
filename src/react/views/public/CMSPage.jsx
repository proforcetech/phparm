import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { createRoot } from 'react-dom/client'

import EstimateRequestForm from '../../components/public/EstimateRequestForm'
import { cmsService } from '@/services/cms.service'

const embeddedComponentMap = {
  EstimateRequestForm,
}

const defaultTitleMatchers = ['Auto Repair Shop Management', 'fixitfor.us']
const reactBasename = (import.meta.env.VITE_REACT_BASE || '').replace(/\/$/, '')

export default function CMSPage() {
  const location = useLocation()
  const params = useParams()
  const [renderedHtml, setRenderedHtml] = useState('')
  const [pageData, setPageData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const originalTitleRef = useRef(null)
  const originalBodyClassRef = useRef(null)
  const addedTagsRef = useRef([])
  const mountedRootsRef = useRef([])

  const slug = useMemo(() => {
    const pathMatch = params['*']

    if (typeof pathMatch === 'string') {
      return pathMatch || 'home'
    }

    let path = location.pathname

    if (reactBasename && path.startsWith(reactBasename)) {
      path = path.slice(reactBasename.length) || '/'
    }

    const trimmed = path.replace(/^\/+/, '')
    return trimmed || 'home'
  }, [location.pathname, params['*']])

  useEffect(() => {
    if (originalTitleRef.current === null) {
      originalTitleRef.current = document.title
    }

    if (originalBodyClassRef.current === null) {
      originalBodyClassRef.current = document.body.className
    }
  }, [])

  const cleanup = () => {
    const originalTitle = originalTitleRef.current ?? document.title
    const originalBodyClass = originalBodyClassRef.current ?? document.body.className

    document.title = originalTitle
    document.body.className = originalBodyClass
    document.body.classList.remove('cms-page-active')

    addedTagsRef.current.forEach((tag) => tag.remove())
    addedTagsRef.current = []

    mountedRootsRef.current.forEach((root) => {
      try {
        root.unmount()
      } catch (err) {
        console.warn('Failed to unmount embedded component:', err)
      }
    })
    mountedRootsRef.current = []
  }

  const extractAndInjectMetaTags = (html, page) => {
    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')

    const titleTag = doc.querySelector('title')

    if (titleTag?.textContent) {
      document.title = titleTag.textContent
    } else if (page?.meta_title) {
      document.title = page.meta_title
    } else if (page?.title) {
      document.title = page.title
    }

    addedTagsRef.current.forEach((tag) => tag.remove())
    addedTagsRef.current = []

    const metaTags = doc.querySelectorAll(
      'meta[name="description"], meta[name="keywords"], meta[property^="og:"]'
    )
    metaTags.forEach((metaTag) => {
      const clonedTag = document.createElement('meta')
      const name = metaTag.getAttribute('name')
      const property = metaTag.getAttribute('property')
      const content = metaTag.getAttribute('content')

      if (name) {
        clonedTag.setAttribute('name', name)
      }
      if (property) {
        clonedTag.setAttribute('property', property)
      }
      if (content) {
        clonedTag.setAttribute('content', content)
      }

      document.head.appendChild(clonedTag)
      addedTagsRef.current.push(clonedTag)
    })

    const canonicalLink = doc.querySelector('link[rel="canonical"]')
    if (canonicalLink?.getAttribute('href')) {
      const clonedLink = document.createElement('link')
      clonedLink.setAttribute('rel', 'canonical')
      clonedLink.setAttribute('href', canonicalLink.getAttribute('href'))
      document.head.appendChild(clonedLink)
      addedTagsRef.current.push(clonedLink)
    }

    const styleTags = doc.querySelectorAll('style')
    const scriptTags = doc.querySelectorAll('script')

    styleTags.forEach((styleTag) => {
      const clonedStyle = document.createElement('style')
      clonedStyle.textContent = styleTag.textContent
      document.head.appendChild(clonedStyle)
      addedTagsRef.current.push(clonedStyle)
    })

    scriptTags.forEach((scriptTag) => {
      const clonedScript = document.createElement('script')

      if (scriptTag.src) {
        clonedScript.src = scriptTag.src
        clonedScript.onerror = () => {
          console.warn('Failed to load external script:', scriptTag.src)
        }
      } else {
        const wrappedScript = `\n        try {\n          ${scriptTag.textContent}\n        } catch (e) {\n          console.warn('CMS script error:', e);\n        }\n      `
        clonedScript.textContent = wrappedScript
      }

      clonedScript.defer = true
      document.body.appendChild(clonedScript)
      addedTagsRef.current.push(clonedScript)
    })

    document.body.classList.add('cms-page-active')

    return doc.body.innerHTML
  }

  const ensureTitle = (page) => {
    const matchesDefaultTitle = defaultTitleMatchers.some((matcher) =>
      document.title?.includes(matcher)
    )

    if (!matchesDefaultTitle) {
      return
    }

    if (page?.meta_title) {
      document.title = page.meta_title
    } else if (page?.title) {
      document.title = page.title
    }
  }

  const mountEmbeddedComponents = () => {
    const componentElements = document.querySelectorAll('[data-react-component], [data-vue-component]')

    componentElements.forEach((element) => {
      const componentName = element.getAttribute('data-react-component') || element.getAttribute('data-vue-component')

      if (!componentName) {
        return
      }

      const Component = embeddedComponentMap[componentName]

      if (!Component) {
        console.warn(`Unknown embedded component: ${componentName}`)
        return
      }

      let props = {}
      const propsJson = element.getAttribute('data-component-props')
      if (propsJson) {
        try {
          props = JSON.parse(propsJson)
        } catch (e) {
          console.warn('Failed to parse component props:', e)
        }
      }

      const root = createRoot(element)
      root.render(<Component {...props} />)
      mountedRootsRef.current.push(root)
    })
  }

  useEffect(() => {
    let isActive = true

    const loadPage = async () => {
      cleanup()
      setLoading(true)
      setError(null)

      try {
        const data = await cmsService.getRenderedPageBySlug(slug)

        if (!isActive) return

        setPageData(data.page)
        const bodyContent = extractAndInjectMetaTags(data.html || '', data.page)
        setRenderedHtml(bodyContent)
      } catch (err) {
        if (!isActive) return

        if (err.response?.status === 404) {
          setError('Page not found')
        } else {
          setError('Failed to load page')
          console.error('Failed to load CMS page:', err)
        }
      } finally {
        if (isActive) {
          setLoading(false)
        }
      }
    }

    loadPage()

    return () => {
      isActive = false
      cleanup()
    }
  }, [slug])

  useEffect(() => {
    if (!renderedHtml) return

    const timeout = window.setTimeout(() => {
      ensureTitle(pageData)
      mountEmbeddedComponents()
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [pageData, renderedHtml])

  let content = null

  if (loading) {
    content = (
      <div className="flex justify-center items-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    )
  } else if (error) {
    content = (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <h1 className="text-4xl font-bold text-gray-800 mb-4">404</h1>
          <p className="text-xl text-gray-600 mb-4">{error}</p>
          <Link to="/" className="text-blue-600 hover:text-blue-800">
            Return to Home
          </Link>
        </div>
      </div>
    )
  } else {
    content = (
      <div
        className="cms-isolated"
        dangerouslySetInnerHTML={{ __html: renderedHtml }}
      />
    )
  }

  return (
    <>
      {content}
      <style>{`
        .cms-isolated {
          display: block;
          width: 100%;
          min-height: 100vh;
          margin: 0;
          padding: 0;
          position: relative;
          z-index: 1;
        }

        body.cms-page-active {
          background-color: #0d0f12 !important;
          color: #f5f5f5 !important;
          margin: 0 !important;
          padding: 0 !important;
        }

        .cms-isolated,
        .cms-isolated * {
          box-sizing: border-box;
        }
      `}</style>
    </>
  )
}
