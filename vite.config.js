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
    },
  },
})
