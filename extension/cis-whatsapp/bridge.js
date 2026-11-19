// CIS WhatsApp — ponte na página do CIS (só no endereço do CIS autorizado nas opções).
// Página CIS  --window.postMessage({source:'cis-app'})-->  ponte  --porta-->  extensão
// extensão    --porta-->  ponte  --window.postMessage({source:'cis-wa-ext'})-->  página CIS
(() => {
  if (window.__cisBridge) return;
  // Só no CIS: a página tem de se identificar (evita que outro site autorizado use a extensão).
  if (!document.querySelector('meta[name="cis-app"]')) return;
  window.__cisBridge = true;
  let port = null;
  const toPage = msg => window.postMessage({...msg, source: 'cis-wa-ext'}, location.origin);
  function connect() {
    try {
      port = chrome.runtime.connect({name: 'cis'});
      port.onMessage.addListener(toPage);
      port.onDisconnect.addListener(() => { port = null; toPage({type: 'disconnected'}); });
    } catch { port = null; }
    return port;
  }
  window.addEventListener('message', e => {
    if (e.source !== window || e.origin !== location.origin) return;
    const d = e.data;
    if (!d || d.source !== 'cis-app' || typeof d.type !== 'string') return;
    const p = port || connect();
    if (p) { try { p.postMessage(d); } catch { connect()?.postMessage(d); } }
  });
  connect();
  toPage({type: 'hello', version: chrome.runtime.getManifest().version});
})();
