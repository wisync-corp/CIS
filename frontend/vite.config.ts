import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
 plugins: [react()],
 base: process.env.GITHUB_PAGES === 'true' ? '/CIS/' : '/',
 server: { port: 5173 },
 // Verificação da interface no CI: a pré-visualização encaminha /api para a API real (mesma origem, como na VPS).
 preview: process.env.CIS_E2E_API ? { proxy: { '/api': { target: process.env.CIS_E2E_API, changeOrigin: true } } } : undefined
});
