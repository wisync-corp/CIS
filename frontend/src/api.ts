// Client for authenticated CIS API. Never embed SQL credentials in the browser.
export const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
export async function readJson<T>(path: string, token: string, signal?: AbortSignal): Promise<T> {
  const response = await fetch(API_BASE + path, {
    headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
    signal,
  });
  if (!response.ok) throw new Error(response.status === 403 ? 'Acesso não autorizado.' : 'Erro na consulta ao CIS.');
  return await response.json() as T;
}
