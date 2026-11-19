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
    // Ecrã de pré-visualização de imagem (abre depois de colar a imagem na conversa).
    caption: ['div[contenteditable="true"][aria-label*="legenda" i]', 'div[contenteditable="true"][aria-label*="caption" i]', 'div[contenteditable="true"][aria-placeholder*="legenda" i]', 'div[contenteditable="true"][aria-placeholder*="caption" i]'],
    outImage: ['img[src^="blob:"]', 'img[src^="data:"]', '[data-testid="image-thumb"]', 'div[role="button"][aria-label*="imagem" i]', 'div[role="button"][aria-label*="image" i]'],
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

    if (job.image) return sendImage(job, res.compose);

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


  function dataUrlToFile(img) {
    const [head, b64] = String(img.dataUrl).split(',');
    const type = (head.match(/data:([^;]+)/) || [])[1] || img.type || 'image/jpeg';
    const bin = atob(b64); const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return new File([bytes], img.name || 'imagem.jpg', {type});
  }

  // Caixa de legenda = editor que aparece depois de colar e que não é a caixa de escrita da conversa.
  const captionBox = compose => {
    const byLabel = q(SEL.caption);
    if (byLabel && byLabel !== compose) return byLabel;
    const all = [...document.querySelectorAll('div[contenteditable="true"]')].filter(el => el !== compose && !el.closest('footer') && el.offsetParent !== null);
    return all.length ? all[all.length - 1] : null;
  };
  // Botão Enviar do ecrã de pré-visualização: o mais próximo da caixa de legenda.
  const previewSend = cap => {
    for (let el = cap; el && el !== document.body; el = el.parentElement) {
      const b = q(SEL.send, el);
      if (b && !b.closest('footer')) return b.closest('button') || b;
    }
    return null;
  };

  async function sendImage(job, compose) {
    const file = dataUrlToFile(job.image);
    const before = new Set(qa(SEL.outgoing));
    // Colar a imagem na conversa (como Ctrl+V); se não abrir, larga-a sobre a conversa.
    compose.focus();
    const dt = new DataTransfer(); dt.items.add(file);
    compose.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true}));
    let cap = await waitFor(() => captionBox(compose), 8000);
    if (!cap) {
      const target = document.querySelector('#main') || compose;
      const dt2 = new DataTransfer(); dt2.items.add(file);
      for (const t of ['dragenter', 'dragover', 'drop']) target.dispatchEvent(new DragEvent(t, {dataTransfer: dt2, bubbles: true, cancelable: true}));
      cap = await waitFor(() => captionBox(compose), 8000);
    }
    if (!cap) return {status: 'error', detail: 'O WhatsApp Web não abriu a pré-visualização da imagem.'};

    // Legenda = texto da mensagem (se o WhatsApp não a trouxe da caixa de escrita).
    if (plain(cap.textContent) !== plain(job.text)) {
      cap.focus();
      document.execCommand('selectAll', false);
      document.execCommand('insertText', false, job.text);
      if (!plain(cap.textContent)) { cap.textContent = job.text; cap.dispatchEvent(new InputEvent('input', {bubbles: true})); }
    }
    const btn = await waitFor(() => previewSend(cap), 8000);
    if (!btn) return {status: 'error', detail: 'Botão Enviar da imagem não encontrado.'};
    btn.click();

    // Aceite quando a pré-visualização fecha; confirmado quando aparece uma mensagem nova com imagem.
    const closed = await waitFor(() => (!cap.isConnected || cap.offsetParent === null) ? true : null, 20000);
    if (!closed) return {status: 'error', detail: 'A pré-visualização da imagem não fechou: a imagem não saiu.'};
    const mine = await waitFor(() => qa(SEL.outgoing).slice(-4).reverse().find(el => !before.has(el) && q(SEL.outImage, el)) || null, 20000);
    if (!mine) return {status: 'sent', detail: 'Imagem enviada (confirmação visual não encontrada).'};
    const delivered = await waitFor(() => (mine.isConnected && mine.querySelector('span[data-icon="msg-time"]')) ? null : true, 60000);
    // Limpa o texto que tenha ficado na caixa de escrita da conversa (o /send?text= preenche-a).
    const c = q(SEL.compose); if (c && plain(c.textContent) === plain(job.text)) { c.focus(); document.execCommand('selectAll', false); document.execCommand('delete', false); }
    return delivered ? {status: 'sent', detail: 'Com imagem.'} : {status: 'sent', detail: 'Com imagem; a aguardar rede.'};
  }

  chrome.runtime.onMessage.addListener((msg, _sender, reply) => {
    if (msg?.type === 'cis-wa-state') { reply({state: state()}); return false; }
    if (msg?.type === 'cis-wa-run') { run(msg.job).then(reply, e => reply({status: 'error', detail: String(e?.message || e)})); return true; }
    return false;
  });
})();
