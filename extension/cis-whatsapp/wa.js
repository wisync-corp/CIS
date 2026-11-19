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
  // Texto sem formatação do WhatsApp, emojis e espaços, para comparar o enviado com o mostrado.
  const plain = s => (s || '').normalize('NFC')
    .replace(/[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}\uFE0F\u200D\u20E3]/gu, '')
    .replace(/[*_~`]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();

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
    const existing = new Set(qa(SEL.outgoing));
    const btn = await waitFor(() => { const b = q(SEL.send); return b ? (b.closest('button') || b) : null; }, 10000);
    if (!btn) return {status: 'error', detail: 'Botão Enviar não encontrado.'};
    btn.click();

    // 4) Confirmar. O WhatsApp mostra *negrito*/_itálico_ sem os símbolos e os emojis como imagens,
    //    por isso compara-se só o texto «simples» (letras, números, pontuação básica).
    const want = plain(job.text).slice(0, 40);
    const findMine = () => {
      const outs = qa(SEL.outgoing).slice(-6).reverse();
      return outs.find(el => {
        const got = plain(el.innerText || el.textContent);
        return got && (got.includes(want.slice(0, 25)) || (!existing.has(el) && want.startsWith(got.slice(0, 25))));
      }) || null;
    };
    const composeEmpty = () => { const c = q(SEL.compose); return !c || norm(c.textContent) === ''; };

    // O WhatsApp aceitou o envio quando a caixa de escrita esvazia ou a mensagem aparece.
    const accepted = await waitFor(() => (composeEmpty() || findMine()) ? true : null, 15000);
    if (!accepted) return {status: 'error', detail: 'O texto ficou na caixa de escrita: a mensagem não saiu.'};

    const mine = await waitFor(findMine, 15000);
    if (!mine) return {status: 'sent', detail: 'Enviada (confirmação visual não encontrada na conversa).'};
    const delivered = await waitFor(() => {
      const el = findMine() || mine;
      if (el.querySelector('span[data-icon="msg-time"], [data-icon*="clock" i]')) return null; // ainda a enviar
      return true;
    }, 30000);
    return delivered ? {status: 'sent'} : {status: 'sent', detail: 'Enviada; a aguardar rede (relógio no WhatsApp).'};
  }

  chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
    if (msg?.type === 'cis-wa-state') { reply({state: state()}); return false; }
    if (msg?.type === 'cis-wa-run') { run(msg.job).then(reply, e => reply({status: 'error', detail: String(e?.message || e)})); return true; }
    return false;
  });
})();
