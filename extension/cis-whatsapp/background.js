// CIS WhatsApp — serviço da extensão.
// Fila de mensagens vinda do CIS → separador do WhatsApp Web (visível ou janela minimizada)
// → espera pelo login → envia uma a uma com intervalo → devolve o resultado ao CIS.
const WA = 'https://web.whatsapp.com/';
const ports = new Set();
let queue = [];
let running = false;
let stopFlag = false;
let opts = {show: false, minGap: 8, maxGap: 18};
let waitingLogin = false;

const sleep = ms => new Promise(r => setTimeout(r, ms));
const broadcast = msg => { for (const p of ports) { try { p.postMessage(msg); } catch { /* porta fechada */ } } };
const status = () => ({type: 'status', running, pendentes: queue.length, aguardaLogin: waitingLogin, version: chrome.runtime.getManifest().version});

async function findWaTab() {
  const tabs = await chrome.tabs.query({url: WA + '*'});
  return tabs[0] || null;
}

async function ensureTab(url) {
  let tab = await findWaTab();
  if (tab) {
    await chrome.tabs.update(tab.id, {url, ...(opts.show ? {active: true} : {})});
    if (opts.show) await chrome.windows.update(tab.windowId, {focused: true, state: 'normal'}).catch(() => {});
    return tab.id;
  }
  if (opts.show) {
    tab = await chrome.tabs.create({url, active: true});
    return tab.id;
  }
  const w = await chrome.windows.create({url, state: 'minimized', focused: false});
  return w.tabs[0].id;
}

function waitComplete(tabId, ms = 60000) {
  return new Promise(resolve => {
    const end = setTimeout(() => { chrome.tabs.onUpdated.removeListener(fn); resolve(false); }, ms);
    function fn(id, info) {
      if (id === tabId && info.status === 'complete') { clearTimeout(end); chrome.tabs.onUpdated.removeListener(fn); resolve(true); }
    }
    chrome.tabs.onUpdated.addListener(fn);
  });
}

async function tell(tabId, msg, tries = 20) {
  for (let i = 0; i < tries; i++) {
    try { return await chrome.tabs.sendMessage(tabId, msg); }
    catch { await sleep(1000); }
  }
  throw new Error('O WhatsApp Web não respondeu.');
}

async function showForLogin(tabId) {
  const tab = await chrome.tabs.get(tabId);
  await chrome.windows.update(tab.windowId, {focused: true, state: 'normal'}).catch(() => {});
  await chrome.tabs.update(tabId, {active: true}).catch(() => {});
}

async function waitLogin(tabId) {
  waitingLogin = true; broadcast({type: 'login', estado: 'aguarda'}); broadcast(status());
  await showForLogin(tabId);
  const end = Date.now() + 10 * 60 * 1000;
  while (Date.now() < end && !stopFlag) {
    await sleep(3000);
    try { const r = await chrome.tabs.sendMessage(tabId, {type: 'cis-wa-state'}); if (r?.state === 'ready') break; }
    catch { /* a recarregar */ }
    broadcast({type: 'ping'});
  }
  const ok = !stopFlag && Date.now() < end;
  waitingLogin = false; broadcast({type: 'login', estado: ok ? 'feito' : 'expirou'}); broadcast(status());
  // Depois do login volta a esconder, se o operador escolheu não ver a página.
  if (ok && !opts.show) {
    const tab = await chrome.tabs.get(tabId);
    await chrome.windows.update(tab.windowId, {state: 'minimized'}).catch(() => {});
  }
  return ok;
}

async function sendOne(job) {
  const url = WA + 'send?phone=' + encodeURIComponent(job.phone) + '&text=' + encodeURIComponent(job.text);
  const tabId = await ensureTab(url);
  await waitComplete(tabId);
  let r = await tell(tabId, {type: 'cis-wa-run', job});
  if (r?.status === 'login_required') {
    if (!(await waitLogin(tabId))) return {status: 'error', detail: 'Sessão do WhatsApp Web não iniciada.'};
    await chrome.tabs.update(tabId, {url});
    await waitComplete(tabId);
    r = await tell(tabId, {type: 'cis-wa-run', job});
  }
  return r || {status: 'error', detail: 'Sem resposta do WhatsApp Web.'};
}

async function processQueue() {
  if (running) return;
  running = true; stopFlag = false; broadcast(status());
  try {
    while (queue.length && !stopFlag) {
      const job = queue.shift();
      broadcast({type: 'progress', id: job.id, estado: 'a_enviar'}); broadcast(status());
      let r;
      try { r = await sendOne(job); } catch (e) { r = {status: 'error', detail: String(e?.message || e)}; }
      broadcast({type: 'result', id: job.id, ok: r.status === 'sent', detail: r.detail || null, meta: job.meta || null});
      if (queue.length && !stopFlag) {
        // Intervalo variável entre mensagens (reduz o risco de bloqueio por envio em massa).
        const gap = (opts.minGap + Math.random() * Math.max(0, opts.maxGap - opts.minGap)) * 1000;
        const until = Date.now() + gap;
        while (Date.now() < until && !stopFlag) { await sleep(Math.min(5000, until - Date.now())); broadcast({type: 'ping'}); }
      }
    }
  } finally {
    if (stopFlag) for (const j of queue.splice(0)) broadcast({type: 'result', id: j.id, ok: false, detail: 'Envio parado pelo operador.', meta: j.meta || null});
    running = false; stopFlag = false; broadcast(status()); broadcast({type: 'done'});
  }
}

chrome.runtime.onConnect.addListener(port => {
  if (port.name !== 'cis') return;
  ports.add(port);
  port.onDisconnect.addListener(() => ports.delete(port));
  port.onMessage.addListener(msg => {
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'hello' || msg.type === 'status') { port.postMessage(status()); return; }
    if (msg.type === 'stop') { stopFlag = true; return; }
    if (msg.type === 'send' && Array.isArray(msg.jobs)) {
      opts = {
        show: !!msg.show,
        minGap: Math.max(3, Math.min(120, Number(msg.minGap) || 8)),
        maxGap: Math.max(5, Math.min(180, Number(msg.maxGap) || 18)),
      };
      for (const j of msg.jobs) {
        const phone = String(j.phone || '').replace(/\D/g, '');
        if (!phone || !j.text) { broadcast({type: 'result', id: j.id, ok: false, detail: 'Sem número ou sem texto.', meta: j.meta || null}); continue; }
        queue.push({id: String(j.id), phone, text: String(j.text).slice(0, 4000), meta: j.meta || null});
      }
      broadcast(status());
      processQueue();
    }
  });
  port.postMessage(status());
});

// Repor a ponte do CIS depois de reiniciar o navegador/extensão.
async function registerBridge() {
  const {cisOrigin} = await chrome.storage.local.get('cisOrigin');
  if (!cisOrigin) return;
  const u = new URL(cisOrigin);
  const pattern = u.protocol + '//' + u.hostname + '/*'; // padrões de permissão não levam porta
  const has = await chrome.permissions.contains({origins: [pattern]});
  if (!has) return;
  const existing = await chrome.scripting.getRegisteredContentScripts({ids: ['cis-bridge']});
  if (existing.length) await chrome.scripting.unregisterContentScripts({ids: ['cis-bridge']});
  await chrome.scripting.registerContentScripts([{id: 'cis-bridge', matches: [pattern], js: ['bridge.js'], runAt: 'document_start', persistAcrossSessions: true}]);
}
chrome.runtime.onInstalled.addListener(registerBridge);
chrome.runtime.onStartup.addListener(registerBridge);
chrome.runtime.onMessage.addListener((msg, _s, reply) => {
  if (msg?.type === 'cis-register') { registerBridge().then(() => reply({ok: true}), e => reply({ok: false, error: String(e)})); return true; }
  return false;
});
