const $ = id => document.getElementById(id);
const say = (t, ok) => { const m = $('msg'); m.textContent = t; m.className = ok ? 'ok' : 'err'; };

chrome.storage.local.get('cisOrigin').then(({cisOrigin}) => {
  if (!cisOrigin) return;
  $('origin').value = cisOrigin;
  chrome.permissions.contains({origins: [new URL(cisOrigin).protocol + '//' + new URL(cisOrigin).hostname + '/*']}).then(has => has && say('Ligado a ' + cisOrigin, true));
});

$('save').addEventListener('click', async () => {
  let origin;
  try { const u = new URL($('origin').value.trim()); if (!/^https?:$/.test(u.protocol)) throw 0; origin = u.origin; }
  catch { say('Endereço inválido. Exemplo: https://cis.exemplo.co.mz', false); return; }
  if (origin.includes('whatsapp.com')) { say('Indique o endereço do CIS, não o do WhatsApp.', false); return; }
  // Os padrões de permissão não aceitam porta: autoriza-se o anfitrião.
  const u = new URL(origin);
  const pattern = u.protocol + '//' + u.hostname + '/*';
  const granted = await chrome.permissions.request({origins: [pattern]});
  if (!granted) { say('Autorização recusada.', false); return; }
  await chrome.storage.local.set({cisOrigin: origin});
  const r = await chrome.runtime.sendMessage({type: 'cis-register'});
  if (!r?.ok) { say('Não foi possível activar a ligação: ' + (r?.error || ''), false); return; }
  // Liga já os separadores do CIS abertos.
  const tabs = await chrome.tabs.query({url: pattern});
  for (const t of tabs) await chrome.scripting.executeScript({target: {tabId: t.id}, files: ['bridge.js']}).catch(() => {});
  say('Ligado a ' + origin + '. Recarregue a página do CIS.', true);
});
