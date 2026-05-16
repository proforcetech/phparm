import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// Uncomment the lines below if you get a "__dirname is not defined" error
// import { fileURLToPath } from 'url';
// const __dirname = path.dirname(fileURLToPath(import.meta.url));

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
          if (!id.includes('node_modules')) {
            return undefined
          }

          if (
            id.includes('/react/') ||
            id.includes('/react-dom/') ||
            id.includes('/react-router-dom/') ||
            id.includes('/@remix-run/')
          ) {
            return 'vendor-react'
          }

          if (id.includes('/@heroicons/')) {
            return 'vendor-icons'
          }

          if (id.includes('/@fullcalendar/')) {
            return 'vendor-calendar'
          }

          if (id.includes('/chart.js/') || id.includes('/react-chartjs-2/')) {
            return 'vendor-charts'
          }

          if (id.includes('/@dnd-kit/')) {
            return 'vendor-dnd'
          }

          if (id.includes('/react-quill-new/') || id.includes('/quill/')) {
            return 'vendor-editor'
          }

          if (id.includes('/tesseract.js/')) {
            return 'vendor-ocr'
          }

          return 'vendor'
        },
      },
    },
  },
})
