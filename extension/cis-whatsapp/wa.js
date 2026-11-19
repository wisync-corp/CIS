// CIS WhatsApp — corre DENTRO do WhatsApp Web.
// Recebe uma mensagem já preparada pelo CIS (a página foi aberta em /send?phone=…&text=…),
// confirma que a sessão está iniciada, carrega em Enviar e verifica o visto de enviada.
// Os selectores do WhatsApp Web mudam com o tempo: estão todos aqui, com alternativas.
(() => {
  if (window.__cisWa) return; window.__cisWa = true;

  const SEL = {
    ready: ['#pane-side', '[data-testid="chat-list"]', 'div[aria-label="Lista de conversas"]', 'div[aria-label="Chat list"]', '#side'],
    qr: ['canvas[aria-label*="QR" i]', '[data-testid="qrcode"]', 'div[data-ref] canvas', '[data-ref]'],
    compose: ['footer div[contenteditable="true"][role="textbox"]', 'footer div[contenteditable="true"]', 'div[contenteditable="true"][data-tab="10"]'],
    send: ['button[aria-label="Enviar"]', 'button[aria-label="Send"]', 'span[data-icon="send"]', 'span[data-icon="wds-ic-send-filled"]', '[data-testid="send"]'],
    dialog: ['div[role="dialog"]', '[data-animate-modal-popup="true"]'],
    outgoing: ['div.message-out', '[data-testid="msg-container"] .message-out'],
  };
  const INVALID = /(inv[aá]lid|invalid|n[aã]o est[aá] no whatsapp|not on whatsapp|isn.t on whatsapp)/i;
  const q = (list, root = document) => { for (const s of list) { const el = root.querySelector(s); if (el) return el; } return null; };
  const qa = (list, root = document) => { for (const s of list) { const els = root.querySelectorAll(s); if (els.length) return [...els]; } return []; };
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  function state() {
    if (q(SEL.ready)) return 'ready';
    if (q(SEL.qr)) return 'login';
    return 'loading';
  }

  async function waitFor(fn, ms) {
    const end = Date.now() + ms;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(400); }
    return null;
  }

  const norm = s => (s || '').replace(/\s+/g, ' ').trim();

  async function run(job) {
    // 1) Sessão: esperar pela página principal ou pelo código QR.
    const st = await waitFor(() => { const s = state(); return s === 'loading' ? null : s; }, 60000);
    if (st === 'login') return {status: 'login_required'};
    if (!st) return {status: 'error', detail: 'O WhatsApp Web não carregou a tempo.'};

    // 2) Conversa aberta com o texto preenchido, ou aviso de número inválido.
    const res = await waitFor(() => {
      const d = q(SEL.dialog);
      if (d && INVALID.test(d.textContent || '')) return {invalid: d};
      const c = q(SEL.compose);
      if (c && norm(c.textContent).length > 0) return {compose: c};
      return null;
    }, 40000);
    if (!res) return {status: 'error', detail: 'A conversa não abriu (verifique o número).'};
    if (res.invalid) {
      const ok = res.invalid.querySelector('button'); if (ok) ok.click();
      return {status: 'error', detail: 'Número inválido ou sem WhatsApp.'};
    }

    // 3) Enviar.
    const before = qa(SEL.outgoing).length;
    const btn = await waitFor(() => { const b = q(SEL.send); return b ? (b.closest('button') || b) : null; }, 10000);
    if (!btn) return {status: 'error', detail: 'Botão Enviar não encontrado.'};
    btn.click();

    // 4) Confirmar: nova mensagem enviada, com o início do texto, sem o relógio de pendente.
    const probe = norm(job.text).slice(0, 25);
    const sent = await waitFor(() => {
      const outs = qa(SEL.outgoing);
      if (outs.length <= before) return null;
      const last = outs[outs.length - 1];
      if (probe && !norm(last.textContent).includes(probe.slice(0, 15))) return null;
      if (last.querySelector('span[data-icon="msg-time"]')) return null; // ainda a enviar
      if (last.querySelector('span[data-icon^="msg-"]') || last.querySelector('[data-icon*="check"]')) return 'ok';
      return null;
    }, 45000);
    if (sent) return {status: 'sent'};
    return qa(SEL.outgoing).length > before
      ? {status: 'sent', detail: 'Enviada; confirmação do visto ainda pendente.'}
      : {status: 'error', detail: 'A mensagem não apareceu na conversa.'};
  }

  chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
    if (msg?.type === 'cis-wa-state') { reply({state: state()}); return false; }
    if (msg?.type === 'cis-wa-run') { run(msg.job).then(reply, e => reply({status: 'error', detail: String(e?.message || e)})); return true; }
    return false;
  });
})();
