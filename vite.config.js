import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
server: {
    // 1. Listen on all network interfaces (0.0.0.0), not just localhost
    host: true, 
    port: 3000,
    strictPort: true,
    // 2. Explicitly tell the browser client to connect to port 3000 for HMR
    //    This bypasses the Apache proxy for the WebSocket connection
    hmr: {
      clientPort: 3000,
    },
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist', // ✅ now outside 'public'
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'index.html'),
      },
    },
  },
})
