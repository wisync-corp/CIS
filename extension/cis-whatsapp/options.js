const $ = id => document.getElementById(id);
const say = (t, ok) => { const m = $('msg'); m.textContent = t; m.className = ok ? 'ok' : 'err'; };
const patternOf = origin => { const u = new URL(origin); return u.protocol + '//' + u.hostname + '/*'; }; // sem porta

async function refresh() {
  const {cisPatterns = []} = await chrome.storage.local.get('cisPatterns');
  $('linked').textContent = cisPatterns.length ? 'Ligado a: ' + cisPatterns.map(p => p.replace('/*', '')).join(', ') : 'Ainda não ligado a nenhum endereço do CIS.';
  $('linked').className = cisPatterns.length ? 'ok' : '';
}

// Endereço do separador activo (quando se abre a extensão com o CIS aberto).
chrome.tabs.query({active: true, currentWindow: true}).then(([t]) => {
  try {
    const u = new URL(t?.url || '');
    if (/^https?:$/.test(u.protocol) && !u.hostname.endsWith('whatsapp.com') && !$('origin').value) $('origin').value = u.origin;
  } catch { /* sem separador */ }
});
refresh();

$('save').addEventListener('click', async () => {
  let origin;
  try { const u = new URL($('origin').value.trim()); if (!/^https?:$/.test(u.protocol)) throw 0; origin = u.origin; }
  catch { say('Endereço inválido. Exemplo: http://localhost:8080', false); return; }
  if (origin.includes('whatsapp.com')) { say('Indique o endereço do CIS, não o do WhatsApp.', false); return; }
  const granted = await chrome.permissions.request({origins: [patternOf(origin)]});
  if (!granted) { say('Autorização recusada.', false); return; }
  const r = await chrome.runtime.sendMessage({type: 'cis-register'});
  if (!r?.ok) { say('Não foi possível activar a ligação: ' + (r?.error || ''), false); return; }
  say('Ligado a ' + origin + '. Volte ao CIS: a ligação aparece em segundos (ou recarregue a página).', true);
  refresh();
});
