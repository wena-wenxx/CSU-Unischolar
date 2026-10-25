import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Lets a Cloudflare quick-tunnel link (https://....trycloudflare.com) open the dev server.
  server: { allowedHosts: ['.trycloudflare.com'] },
})
