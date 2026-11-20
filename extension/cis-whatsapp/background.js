// CIS WhatsApp — serviço da extensão.
// Fila de mensagens vinda do CIS → separador do WhatsApp Web (nunca minimizado)
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

// Nunca minimiza: numa janela minimizada o navegador pára o WhatsApp Web e os envios
// (sobretudo com imagem) ficam parados. Sem «Mostrar», o separador abre ao lado, sem roubar o foco.
async function ensureTab(url) {
  let tab = await findWaTab();
  if (tab) {
    const w = await chrome.windows.get(tab.windowId).catch(() => null);
    if (w?.state === 'minimized' && opts.show) await chrome.windows.update(tab.windowId, {state: 'normal', focused: true}).catch(() => {});
    await chrome.tabs.update(tab.id, {url, ...(opts.show ? {active: true} : {})});
    if (opts.show) await chrome.windows.update(tab.windowId, {focused: true}).catch(() => {});
    return tab.id;
  }
  const win = await chrome.windows.getLastFocused({windowTypes: ['normal']}).catch(() => null);
  tab = await chrome.tabs.create({url, active: !!opts.show, ...(win && win.state !== 'minimized' ? {windowId: win.id} : {})});
  return tab.id;
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
  return ok;
}

async function sendOne(job) {
  const url = WA + 'send?phone=' + encodeURIComponent(job.phone) + '&text=' + encodeURIComponent(job.text);
  const tabId = await ensureTab(url);
  await waitComplete(tabId);
  // «Mostrar» desligado = o WhatsApp Web NUNCA vem para a frente. Com anexo, o separador de
  // fundo trabalha com foco emulado (barra «a depurar» do navegador durante o envio).
  const bg = job.image && !opts.show;
  if (bg) await background(tabId, true).catch(() => {});
  try { return await runJob(tabId, url, job); }
  finally { if (bg) await background(tabId, false); }
}

async function runJob(tabId, url, job) {
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
      // Imagem opcional (uma para todas as mensagens deste envio): data URL até ~5 MB.
      const img = msg.image && /^data:(image\/(jpeg|png|webp)|application\/pdf);base64,/.test(String(msg.image.dataUrl || '')) && String(msg.image.dataUrl).length < 7_500_000
        ? {name: String(msg.image.name || 'imagem').slice(0, 120), type: String(msg.image.type || ''), dataUrl: String(msg.image.dataUrl)} : null;
      for (const j of msg.jobs) {
        const phone = String(j.phone || '').replace(/\D/g, '');
        if (!phone || !j.text) { broadcast({type: 'result', id: j.id, ok: false, detail: 'Sem número ou sem texto.', meta: j.meta || null}); continue; }
        queue.push({id: String(j.id), phone, text: String(j.text).slice(0, 4000), meta: j.meta || null, image: img});
      }
      broadcast(status());
      processQueue();
    }
  });
  port.postMessage(status());
});

// Ponte com o CIS: activa em todos os endereços específicos autorizados (opções da extensão OU
// menu «Permitir neste site» do navegador). Nunca em padrões genéricos (todos os sites) nem no
// WhatsApp. A ponte só actua se a página se identificar como CIS (<meta name="cis-app">).
const isCisPattern = o => o && !/whatsapp\.com/.test(o) && !/^\*:|:\/\/\*\/|<all_urls>|:\/\/\*\./.test(o) && /^https?:\/\/[^/*]+\/\*$/.test(o);

async function syncBridges() {
  const {origins = []} = await chrome.permissions.getAll();
  const patterns = [...new Set(origins.filter(isCisPattern))];
  const existing = await chrome.scripting.getRegisteredContentScripts({ids: ['cis-bridge']});
  if (existing.length) await chrome.scripting.unregisterContentScripts({ids: ['cis-bridge']});
  if (patterns.length) {
    await chrome.scripting.registerContentScripts([{id: 'cis-bridge', matches: patterns, js: ['bridge.js'], runAt: 'document_idle', persistAcrossSessions: true}]);
    // Liga já os separadores abertos desses endereços (a ponte ignora páginas que não são o CIS).
    const tabs = await chrome.tabs.query({url: patterns});
    for (const t of tabs) await chrome.scripting.executeScript({target: {tabId: t.id}, files: ['bridge.js']}).catch(() => {});
  }
  await chrome.storage.local.set({cisPatterns: patterns});
  return patterns;
}
chrome.runtime.onInstalled.addListener(() => { syncBridges(); });
chrome.runtime.onStartup.addListener(() => { syncBridges(); });
chrome.permissions.onAdded.addListener(() => { syncBridges(); });
chrome.permissions.onRemoved.addListener(() => { syncBridges(); });
// Teclas/cliques REAIS (isTrusted) no separador do WhatsApp Web, pelo protocolo de depuração do Chrome.
// Usado só quando o WhatsApp ignora o clique simulado no botão Enviar. Enquanto dura (~1 s) o
// navegador mostra a barra «CIS WhatsApp está a depurar este navegador».
// Sessões de depuração abertas (separadores em envio «em segundo plano»).
const attached = new Set();
async function attach(tabId) {
  if (attached.has(tabId)) return;
  await chrome.debugger.attach({tabId}, '1.3');
  attached.add(tabId);
}
async function detach(tabId) {
  if (!attached.has(tabId)) return;
  attached.delete(tabId);
  await chrome.debugger.detach({tabId}).catch(() => {});
}
chrome.debugger.onDetach.addListener(src => { if (src.tabId) attached.delete(src.tabId); });

// Separador escondido a trabalhar como se estivesse à vista: foco emulado e página activa
// (sem isto o navegador abranda/congela separadores de fundo e a imagem/PDF não sai).
async function background(tabId, on) {
  if (!on) { await detach(tabId); return; }
  await attach(tabId);
  const send = (m, p) => chrome.debugger.sendCommand({tabId}, m, p).catch(() => {});
  await send('Emulation.setFocusEmulationEnabled', {enabled: true});
  await send('Page.enable');
  await send('Page.setWebLifecycleState', {state: 'active'});
}

async function trusted(tabId, action) {
  const keep = attached.has(tabId);
  await attach(tabId);
  try {
    const send = (m, p) => chrome.debugger.sendCommand({tabId}, m, p);
    if (action.kind === 'enter') {
      const k = {key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13};
      await send('Input.dispatchKeyEvent', {type: 'rawKeyDown', ...k});
      await send('Input.dispatchKeyEvent', {type: 'char', text: '\r', ...k});
      await send('Input.dispatchKeyEvent', {type: 'keyUp', ...k});
    } else if (action.kind === 'click') {
      const p = {x: action.x, y: action.y, button: 'left', clickCount: 1};
      await send('Input.dispatchMouseEvent', {type: 'mouseMoved', x: p.x, y: p.y});
      await send('Input.dispatchMouseEvent', {type: 'mousePressed', ...p});
      await send('Input.dispatchMouseEvent', {type: 'mouseReleased', ...p});
    }
  } finally {
    if (!keep) await detach(tabId);
  }
}
chrome.runtime.onMessage.addListener((msg, s, reply) => {
  if (msg?.type === 'cis-wa-trusted' && s.tab?.id) {
    trusted(s.tab.id, msg.action).then(() => reply({ok: true}), e => reply({ok: false, error: String(e?.message || e)}));
    return true;
  }
  if (msg?.type === 'cis-register') { syncBridges().then(p => reply({ok: true, patterns: p}), e => reply({ok: false, error: String(e)})); return true; }
  return false;
});
