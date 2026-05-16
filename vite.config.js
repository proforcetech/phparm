import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// Uncomment the lines below if you get a "__dirname is not defined" error
// import { fileURLToPath } from 'url';
// const __dirname = path.dirname(fileURLToPath(import.meta.url));

const getPackageName = (id) => {
  const normalizedId = id.replaceAll('\\', '/')
  const nodeModulesIndex = normalizedId.lastIndexOf('/node_modules/')

  if (nodeModulesIndex === -1) {
    return null
  }

  const packagePath = normalizedId.slice(nodeModulesIndex + '/node_modules/'.length)
  const [firstSegment, secondSegment] = packagePath.split('/')

  if (!firstSegment) {
    return null
  }

  if (firstSegment.startsWith('@')) {
    return secondSegment ? `${firstSegment}/${secondSegment}` : firstSegment
  }

  return firstSegment
}

export default defineConfig({
  plugins: [react()],
  publicDir: false,
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/react/test/setup.js',
    globals: true,
    include: ['src/react/**/*.test.{js,jsx,ts,tsx}'],
  },
  server: {
    host: true, 
    port: 3000,
    strictPort: true,
    allowedHosts: [
      'fixitfor.us',
    ], // Fixed missing comma
    hmr: {
      clientPort: 443,
    },
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'public',
    emptyOutDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'index.html'),
      },
      output: {
        manualChunks(id) {
          const packageName = getPackageName(id)

          if (!packageName) {
            return undefined
          }

          if (
            packageName === 'react' ||
            packageName === 'react-dom' ||
            packageName === 'react-router' ||
            packageName === 'react-router-dom' ||
            packageName === 'scheduler' ||
            packageName.startsWith('@remix-run/')
          ) {
            return 'vendor-react'
          }

          if (packageName.startsWith('@heroicons/')) {
            return 'vendor-icons'
          }

          if (packageName.startsWith('@fullcalendar/')) {
            return 'vendor-calendar'
          }

          if (packageName === 'chart.js' || packageName === 'react-chartjs-2') {
            return 'vendor-charts'
          }

          if (packageName.startsWith('@dnd-kit/')) {
            return 'vendor-dnd'
          }

          if (packageName === 'react-quill-new' || packageName === 'quill') {
            return 'vendor-editor'
          }

          if (packageName === 'tesseract.js') {
            return 'vendor-ocr'
          }

          return 'vendor'
        },
      },
    },
  },
})
