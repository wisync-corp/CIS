import React,{useEffect,useMemo,useRef,useState} from 'react';
import {MessageSquare,Plus,Pencil,Save,X,Trash2,Send,Copy,Users,AlertCircle,CheckCircle2,Image as ImageIcon,SkipForward,Puzzle,Square,Loader2,Eye,EyeOff,ExternalLink} from 'lucide-react';
import MessageLog from './MessageLog';
import {onWa,waState,waSend,waStop,waCheck,jobStatus,getShowPref,setShowPref,type WaImage} from './waBridge';

const API=import.meta.env.VITE_API_URL||'/api';
type Template={id:number;name:string;body:string;updated_at:string|null};
type Preview={contrato:string;cliente:string;contacto:string|null;telefone:string|null;situacao:string;mensagem:string};
type Recipient={contrato:string;cliente:string;cliente_id:number|null;contacto:string|null;telefone:string|null;situacao:string;mensagem:string;estado?:'aberta'|'saltada';jobId?:string};
type Group='todos'|'activos'|'inactivos'|'pendentes'|'atrasados';
const GROUPS:{id:Group;label:string;hint:string}[]=[
 {id:'todos',label:'Todos os contactos',hint:'Um por cliente (contrato mais recente)'},
 {id:'activos',label:'Contactos activos',hint:'Clientes com contrato em aberto'},
 {id:'inactivos',label:'Contactos inactivos',hint:'Clientes sem contratos em aberto'},
 {id:'pendentes',label:'Contratos em curso',hint:'Dentro do prazo'},
 {id:'atrasados',label:'Contratos em atraso',hint:'Com prestações em atraso'},
];
const EMOJIS=['😊','🙏','👍','✅','⚠️','📅','💰','📞','📌','🔔','❗','🤝','👋','💳','🏦','📄'];
const situ=(s:string)=>s==='atrasado'?'Em atraso':s==='pendente'?'Em curso':'Liquidado';
// Directo no WhatsApp Web: o wa.me passa pela página de partilha («Open app») e estraga alguns emojis.
const waUrl=(tel:string,msg:string)=>'https://web.whatsapp.com/send?phone='+tel+'&text='+encodeURIComponent(msg);
// Reutiliza sempre o mesmo separador do WhatsApp Web (evita «WhatsApp aberto noutra janela»).
function openWa(url:string){const w=window.open(url,'cis-whatsapp-web');if(w){try{w.opener=null;}catch{/* já noutro domínio */}w.focus();}}
const smsUrl=(tel:string,msg:string)=>'sms:+'+tel+'?body='+encodeURIComponent(msg);

export default function MessageCenter({token,contrato,onClose}:{token:string;contrato?:string;onClose?:()=>void}){
 const headers={Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'};
 const [templates,setTemplates]=useState<Template[]>([]);const [fields,setFields]=useState<string[]>([]);
 const [selId,setSelId]=useState<number|''>('');
 const [editing,setEditing]=useState<false|'new'|'edit'>(false);
 const [name,setName]=useState('');const [body,setBody]=useState('');
 const [field,setField]=useState('');const [showEmoji,setShowEmoji]=useState(false);
 const [preview,setPreview]=useState<Preview|null>(null);const [text,setText]=useState('');
 const [group,setGroup]=useState<Group|''>('');const [queue,setQueue]=useState<Recipient[]|null>(null);const [queueInfo,setQueueInfo]=useState('');
 const [busy,setBusy]=useState('');const [error,setError]=useState('');const [notice,setNotice]=useState('');
 const ta=useRef<HTMLTextAreaElement>(null);
 const [,tick]=useState(0);useEffect(()=>onWa(()=>tick(v=>v+1)),[]);
 const wa=waState();const [show,setShow]=useState(getShowPref());
 const [lastJob,setLastJob]=useState<string|null>(null);
 const [image,setImage]=useState<WaImage|null>(null);const [logKey,setLogKey]=useState(0);const fileRef=useRef<HTMLInputElement>(null);const [checking,setChecking]=useState(false);const [help,setHelp]=useState(false);
 const sendMode=!!contrato;
 const sel=useMemo(()=>templates.find(t=>t.id===selId)??null,[templates,selId]);

 async function call<T>(path:string,init?:RequestInit):Promise<T>{
  const r=await fetch(API+path,{...init,headers});const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error((j as {message?:string;errors?:Record<string,string[]>}).errors?Object.values((j as {errors:Record<string,string[]>}).errors).flat()[0]:(j as {message?:string}).message||(r.status===429?'Muitos pedidos seguidos; aguarde um minuto.':'Operação falhou.'));
  return j as T;
 }
 async function load(keep?:number){
  try{const j=await call<{modelos:Template[];campos:string[]}>('/cis/mensagens/modelos');setTemplates(j.modelos);setFields(j.campos);
   if(keep)setSelId(keep);else if(!selId&&j.modelos.length)setSelId(j.modelos[0].id);}
  catch(e){setError(e instanceof Error?e.message:'Erro ao carregar os modelos.');}
 }
 // eslint-disable-next-line react-hooks/exhaustive-deps
 useEffect(()=>{void load();},[token]);
 useEffect(()=>{if(!editing)setBody(sel?.body??'');},[sel,editing]);

 // Pré-visualização para o contrato (modo envio), com o texto do modelo/edição.
 useEffect(()=>{
  if(!sendMode||!body.trim())return;const c=new AbortController();
  const t=window.setTimeout(()=>{fetch(API+'/cis/mensagens/preview?'+new URLSearchParams({contrato:contrato!,texto:body}),{headers,signal:c.signal})
   .then(async r=>{const j=await r.json();if(!r.ok)throw new Error(j.message||'Pré-visualização indisponível.');return j as Preview;})
   .then(p=>{setPreview(p);setText(p.mensagem);}).catch(e=>{if(e.name!=='AbortError')setError(e.message);});},editing?400:0);
  return()=>{c.abort();window.clearTimeout(t);};
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[body,contrato,sendMode]);

 function insert(s:string){const el=ta.current;if(!el){setBody(b=>b+s);return;}
  const a=el.selectionStart??body.length,b=el.selectionEnd??body.length;const v=body.slice(0,a)+s+body.slice(b);setBody(v);
  requestAnimationFrame(()=>{el.focus();el.selectionStart=el.selectionEnd=a+s.length;});}
 function startNew(){setEditing('new');setName('');setBody('');setNotice('');setError('');}
 function startEdit(){if(!sel)return;setEditing('edit');setName(sel.name);setBody(sel.body);setNotice('');setError('');}
 function cancel(){setEditing(false);setBody(sel?.body??'');setError('');}
 async function save(){
  setBusy('save');setError('');
  try{const payload=JSON.stringify({name,body});
   const j=editing==='new'?await call<{id:number;notice:string}>('/cis/mensagens/modelos',{method:'POST',body:payload})
    :await call<{id:number;notice:string}>('/cis/mensagens/modelos/'+selId,{method:'PUT',body:payload});
   setEditing(false);setNotice(j.notice);await load(j.id);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível guardar.');}finally{setBusy('');}
 }
 async function remove(){
  if(!sel||!window.confirm(`Eliminar o modelo «${sel.name}»?`))return;
  setBusy('del');setError('');
  try{await call('/cis/mensagens/modelos/'+sel.id,{method:'DELETE'});setSelId('');setNotice('Modelo eliminado.');await load();}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível eliminar.');}finally{setBusy('');}
 }
 async function log(r:{contrato:string|null;cliente_id?:number|null;telefone:string|null;canal:'whatsapp'|'sms'|'copia';mensagem:string;grupo?:Group}){
  try{await call('/cis/mensagens/registo',{method:'POST',body:JSON.stringify({...r,modelo_id:selId||null})});setLogKey(k=>k+1);}
  catch(e){setError('A mensagem foi aberta, mas não ficou registada no CIS: '+(e instanceof Error?e.message:'erro'));}
 }
 async function sendOne(canal:'whatsapp'|'sms'|'copia'){
  if(!preview)return;setError('');
  if(canal==='copia'){try{await navigator.clipboard.writeText(text);setNotice('Mensagem copiada.');}catch{setError('Não foi possível copiar automaticamente; seleccione o texto e copie.');return;}}
  else if(!preview.telefone){setError('Cliente sem contacto válido.');return;}
  else if(canal==='whatsapp')openWa(waUrl(preview.telefone,text));else window.open(smsUrl(preview.telefone,text),'_blank','noopener');
  await log({contrato:preview.contrato,telefone:preview.telefone,canal,mensagem:text});
  if(canal!=='copia')setNotice(canal==='whatsapp'?'Mensagem aberta no WhatsApp Web: carregue em Enviar nessa página.':'Mensagem aberta no SMS: confirme o envio.');
 }
 function pickImage(f:File|undefined){
  setError('');if(!f)return;
  if(!/^image\/(jpeg|png|webp)$/.test(f.type)){setError('Imagem não suportada: use JPG, PNG ou WebP.');return;}
  if(f.size>5*1024*1024){setError('Imagem demasiado grande (máximo 5 MB).');return;}
  const rd=new FileReader();rd.onload=()=>setImage({name:f.name,type:f.type,dataUrl:String(rd.result)});rd.onerror=()=>setError('Não foi possível ler a imagem.');rd.readAsDataURL(f);
 }
 function sendWeb(){
  if(!preview?.telefone||!text.trim())return;
  const id='c-'+preview.contrato+'-'+Date.now();setLastJob(id);setError('');
  waSend([{id,phone:preview.telefone,text,meta:{contrato:preview.contrato,cliente:preview.cliente,modelo_id:selId||null}}],show,image);
 }
 function sendQueueWeb(){
  if(!queue)return;const ts=Date.now();
  const next=queue.map((r,i)=>r.telefone&&!r.estado&&!(r.jobId&&jobStatus(r.jobId)==='enviada')?{...r,jobId:'q-'+i+'-'+ts}:r);
  setQueue(next);
  const list=next.filter(r=>r.jobId?.endsWith('-'+ts)).map(r=>({id:r.jobId!,phone:r.telefone!,text:r.mensagem,meta:{contrato:r.contrato,cliente:r.cliente,cliente_id:r.cliente_id,modelo_id:selId||null,grupo:group||null}}));
  if(!list.length){setNotice('Não há mensagens por enviar na fila.');return;}
  waSend(list,show,image);
 }
 async function loadQueue(){
  if(!group)return;setBusy('queue');setError('');setQueue(null);
  try{const p=new URLSearchParams({grupo:group});if(editing)p.set('texto',body);else if(selId)p.set('modelo_id',String(selId));else p.set('texto',body);
   const j=await call<{total:number;com_contacto:number;destinatarios:Recipient[]}>('/cis/mensagens/destinatarios?'+p);
   setQueue(j.destinatarios);setQueueInfo(`${j.total} destinatário(s) · ${j.com_contacto} com contacto`);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível preparar a fila.');}finally{setBusy('');}
 }
 async function openRecipient(i:number){
  if(!queue)return;const r=queue[i];if(!r.telefone)return;
  openWa(waUrl(r.telefone,r.mensagem));
  setQueue(q=>q&&q.map((x,k)=>k===i?{...x,estado:'aberta'}:x));
  await log({contrato:r.contrato,cliente_id:r.cliente_id,telefone:r.telefone,canal:'whatsapp',mensagem:r.mensagem,grupo:group||undefined});
 }
 const nextIdx=queue?queue.findIndex(r=>r.telefone&&!r.estado&&!(r.jobId&&jobStatus(r.jobId))):-1;
 const done=queue?queue.filter(r=>r.estado==='aberta').length:0;

 const statusCell=(r:Recipient,i:number)=>{const js=r.jobId?jobStatus(r.jobId):undefined;
  if(!r.telefone)return <small>Sem contacto</small>;
  if(js==='enviada')return <span className="ok"><CheckCircle2 size={14}/> Enviada</span>;
  if(js==='a_enviar')return <span className="wait"><Loader2 size={14} className="spin"/> Na fila</span>;
  if(js==='falhada')return <span className="fail"><AlertCircle size={14}/> Falhou</span>;
  if(r.estado==='aberta')return <span className="ok"><CheckCircle2 size={14}/> Aberta</span>;
  return <button type="button" onClick={()=>void openRecipient(i)}><Send size={13}/> WhatsApp</button>;};
 const sending=!!lastJob&&jobStatus(lastJob)==='a_enviar';

 return <section className={'cis-panel cis-msg'+(sendMode?' cis-msg-send':'')}>
  <header className="cis-msg-head">
   <div><h2><MessageSquare size={18}/> {sendMode?'Mensagem ao cliente':'Centro de mensagens'}</h2>
    <p>{sendMode?<>Contrato <b>{contrato}</b>{preview?<> · {preview.cliente} · {situ(preview.situacao)}</>:null}</>:'Escolha um modelo, ajuste o texto e envie a um cliente ou a um grupo.'}</p></div>
   <div className="cis-msg-head-right">
    {wa.available?<span className="cis-msg-chip on"><Puzzle size={14}/> WhatsApp Web automático{wa.version?` · v${wa.version}`:''}{wa.running?` · a enviar (${wa.pendentes})`:''}{wa.aguardaLogin?' · aguarda login':''}</span>
     :<button type="button" className="cis-msg-chip off" onClick={()=>setHelp(v=>!v)} aria-expanded={help}><Puzzle size={14}/> Extensão não detectada · como ligar</button>}
    {wa.available&&<label className="cis-msg-toggle" title="Mostrar o WhatsApp Web durante o envio"><input type="checkbox" checked={show} onChange={e=>{setShow(e.target.checked);setShowPref(e.target.checked);}}/>{show?<Eye size={14}/>:<EyeOff size={14}/>} Mostrar WhatsApp Web</label>}
    {wa.running&&<button type="button" className="cis-msg-stop" onClick={waStop}><Square size={13}/> Parar</button>}
    {onClose&&<button type="button" className="cis-msg-close" onClick={onClose} aria-label="Fechar"><X size={17}/></button>}
   </div>
  </header>
  {!wa.available&&help&&<div className="cis-msg-help">
   <p><b>Já instalada?</b> Com esta página aberta, clique no ícone <b>CIS WhatsApp</b> na barra do navegador e carregue em <b>«Ligar este endereço»</b> (o endereço aparece preenchido).</p>
   <p><b>Ainda não instalada?</b> <a href="/cis-whatsapp-extension.zip" download>Descarregar a extensão</a> → extrair → <code>edge://extensions</code> ou <code>chrome://extensions</code> → Modo de programador → «Carregar expandida» → escolher a pasta.</p>
   <p>Sem a extensão, as mensagens abrem já escritas no WhatsApp Web e o envio é confirmado por si.</p>
   <button type="button" onClick={()=>{setChecking(true);waCheck();setTimeout(()=>setChecking(false),1500);}} disabled={checking}>{checking?<><Loader2 size={13} className="spin"/> A verificar…</>:'Verificar novamente'}</button>
  </div>}

  <div className="cis-msg-grid">
   <aside className="cis-msg-list" aria-label="Modelos">
    <div className="cis-msg-list-head"><h3>Modelos</h3><button type="button" onClick={startNew} disabled={!!editing}><Plus size={14}/> Novo</button></div>
    <ul>{templates.map(t=><li key={t.id}><button type="button" className={t.id===selId?'selected':''} disabled={!!editing} onClick={()=>setSelId(t.id)}>
     <b>{t.name}</b><small>{t.body.replace(/[*_~]/g,'').replace(/\s+/g,' ').slice(0,70)}{t.body.length>70?'…':''}</small></button></li>)}</ul>
    {!templates.length&&<p className="cis-msg-muted">Ainda não há modelos.</p>}
   </aside>

   <div className="cis-msg-main">
    <div className="cis-msg-card">
     <div className="cis-msg-card-head">
      {editing?<input className="cis-msg-name" value={name} onChange={e=>setName(e.target.value)} placeholder="Nome do modelo" maxLength={80} autoFocus={editing==='new'} aria-label="Nome do modelo"/>
       :<h3>{sel?.name??'Escolha um modelo'}</h3>}
      <div className="cis-msg-btns">{!editing?<>
       <button type="button" onClick={startEdit} disabled={!sel}><Pencil size={14}/> Editar</button>
       <button type="button" onClick={()=>void remove()} disabled={!sel||busy==='del'} aria-label="Eliminar modelo"><Trash2 size={14}/></button>
      </>:<>
       <button type="button" className="primary" onClick={()=>void save()} disabled={busy==='save'||name.trim().length<3||body.trim().length<3}><Save size={14}/> {busy==='save'?'A guardar…':'Guardar'}</button>
       <button type="button" onClick={cancel}><X size={14}/> Cancelar</button>
      </>}</div>
     </div>
     <textarea ref={ta} value={body} onChange={e=>setBody(e.target.value)} readOnly={!editing} rows={sendMode?9:13} className={editing?'':'locked'} placeholder="Escreva a mensagem e insira campos como {Nome Completo}" aria-label="Texto do modelo"/>
     {editing?<div className="cis-msg-tools">
      <select value={field} onChange={e=>setField(e.target.value)} aria-label="Campo"><option value="">Inserir campo…</option>{fields.map(f=><option key={f} value={f}>{f}</option>)}</select>
      <button type="button" onClick={()=>field&&insert(field)} disabled={!field}>Inserir</button>
      <button type="button" onClick={()=>setShowEmoji(v=>!v)} aria-expanded={showEmoji}>😊 Emojis</button>
      {showEmoji&&<div className="cis-msg-emojis">{EMOJIS.map(e=><button type="button" key={e} onClick={()=>insert(e)}>{e}</button>)}</div>}
     </div>:<p className="cis-msg-muted">Carregue em <b>Editar</b> para alterar o modelo. Os campos entre chavetas são preenchidos com os dados de cada contrato.</p>}
     <div className="cis-msg-image">
      <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={e=>{pickImage(e.target.files?.[0]);e.target.value='';}}/>
      {image?<><img src={image.dataUrl} alt="Imagem a enviar"/><span><b>{image.name}</b><small>{wa.available?'Vai com cada mensagem, com o texto como legenda.':'Só segue automaticamente com a extensão.'}</small></span>
       <button type="button" onClick={()=>setImage(null)}><X size={13}/> Remover</button></>
      :<button type="button" onClick={()=>fileRef.current?.click()}><ImageIcon size={14}/> Anexar imagem</button>}
     </div>
    </div>
   </div>

   <div className="cis-msg-side">
    <div className="cis-msg-card">
     <h3>Pré-visualização</h3>
     <div className="cis-wa-screen">
      <div className="cis-wa-top"><span className="cis-wa-avatar">{(preview?.cliente??'C').charAt(0)}</span><span><b>{sendMode?(preview?.cliente??'Cliente'):'Cliente (exemplo)'}</b><small>{sendMode?(preview?.contacto??'sem contacto'):'campos mostrados entre chavetas'}</small></span></div>
      <div className="cis-wa-chat"><div className="cis-wa-bubble">
       {image&&<img src={image.dataUrl} alt=""/>}
       <WaText text={sendMode?text:body} fields={!sendMode}/>
       <span className="cis-wa-time">{new Date().toLocaleTimeString('pt-PT',{hour:'2-digit',minute:'2-digit'})} ✓✓</span>
      </div></div>
     </div>
     {sendMode&&<>
      <details className="cis-msg-adjust"><summary>Ajustar o texto só para este envio</summary>
       <textarea value={text} onChange={e=>setText(e.target.value)} rows={7} aria-label="Mensagem a enviar"/></details>
      <div className="cis-msg-send-btns">
       {wa.available&&<button type="button" className="primary big" onClick={sendWeb} disabled={!preview?.telefone||!text.trim()||sending}>{sending?<><Loader2 size={15} className="spin"/> A enviar…</>:<><Send size={15}/> Enviar pelo WhatsApp Web</>}</button>}
       <div className="cis-msg-btns">
        <button type="button" className={wa.available?'':'primary'} onClick={()=>void sendOne('whatsapp')} disabled={!preview?.telefone||!text.trim()}><ExternalLink size={14}/> Abrir no WhatsApp Web</button>
        <button type="button" onClick={()=>void sendOne('sms')} disabled={!preview?.telefone||!text.trim()}>SMS</button>
        <button type="button" onClick={()=>void sendOne('copia')} disabled={!text.trim()}><Copy size={14}/> Copiar</button>
       </div>
       <small>{preview?.contacto?`Para ${preview.contacto}`:preview?'Cliente sem contacto registado.':''}</small>
      </div>
     </>}
    </div>

    {!sendMode&&<div className="cis-msg-card cis-msg-bulk">
     <h3><Users size={16}/> Mandar para todos</h3>
     <select value={group} onChange={e=>{setGroup(e.target.value as Group);setQueue(null);}} aria-label="Grupo"><option value="">Escolher destinatários…</option>{GROUPS.map(g=><option key={g.id} value={g.id}>{g.label}</option>)}</select>
     {group&&<small>{GROUPS.find(g=>g.id===group)?.hint}</small>}
     <button type="button" className="primary" onClick={()=>void loadQueue()} disabled={!group||(!selId&&!body.trim())||busy==='queue'}>{busy==='queue'?'A preparar…':'Preparar fila'}</button>
    </div>}
   </div>
  </div>

  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={17}/>{error}</div>}
  {notice&&!error&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}

  {!sendMode&&queue&&<div className="cis-msg-card cis-msg-queue-card">
   <div className="cis-msg-queue-head"><h3><Users size={16}/> Fila · {GROUPS.find(g=>g.id===group)?.label}</h3><span>{queueInfo} · {done} aberta(s)</span>
    <div className="cis-msg-btns">
     {wa.available&&<button type="button" className="primary" disabled={wa.running} onClick={sendQueueWeb}><Send size={14}/> {wa.running?`A enviar… (${wa.pendentes} na fila)`:'Enviar a fila pelo WhatsApp Web'}</button>}
     {wa.running&&<button type="button" onClick={waStop}><Square size={13}/> Parar</button>}
     <button type="button" className={wa.available?'':'primary'} disabled={nextIdx<0} onClick={()=>void openRecipient(nextIdx)}><SkipForward size={14}/> {nextIdx<0?'Fila concluída':`Abrir próxima (${queue[nextIdx].cliente})`}</button>
    </div></div>
   <div className="cis-table-scroll cis-msg-queue"><table><thead><tr><th>Cliente</th><th>Contrato</th><th>Contacto</th><th>Situação</th><th>Mensagem</th><th></th></tr></thead>
    <tbody>{queue.map((r,i)=><tr key={r.contrato+i} className={r.estado==='aberta'?'done':''}><td>{r.cliente}</td><td>{r.contrato}</td><td>{r.contacto??'—'}</td><td>{situ(r.situacao)}</td><td className="msg">{r.mensagem}</td><td>{statusCell(r,i)}</td></tr>)}</tbody></table></div>
  </div>}

  <div className="cis-msg-card"><MessageLog token={token} contrato={contrato} refreshKey={logKey}/></div>

  <p className="cis-msg-note"><ImageIcon size={14}/> {wa.available?'Com a extensão, o CIS envia pelo seu WhatsApp Web, uma mensagem de cada vez (intervalo de 8–18 s), e confirma o envio; os avisos aparecem no canto superior direito.':'Sem a extensão, o CIS abre cada mensagem já escrita no WhatsApp Web (sempre no mesmo separador) e o envio é confirmado por si.'} Imagens (JPG/PNG/WebP até 5 MB) seguem com a extensão, com o texto como legenda.</p>
 </section>;
}

/** Texto como o WhatsApp o mostra: *negrito*, _itálico_, ~riscado~, quebras de linha; campos {…} em destaque. */
function WaText({text,fields}:{text:string;fields:boolean}){
 const parts:React.ReactNode[]=[];
 const re=/(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~|\{[^{}\n]{1,40}\})/g;let last=0;let m:RegExpExecArray|null;let k=0;
 while((m=re.exec(text))){if(m.index>last)parts.push(text.slice(last,m.index));const t=m[0];
  if(t.startsWith('{'))parts.push(fields?<span key={k++} className="cis-wa-field">{t.slice(1,-1)}</span>:t);
  else if(t.startsWith('*'))parts.push(<strong key={k++}>{t.slice(1,-1)}</strong>);
  else if(t.startsWith('_'))parts.push(<em key={k++}>{t.slice(1,-1)}</em>);
  else parts.push(<s key={k++}>{t.slice(1,-1)}</s>);
  last=m.index+t.length;}
 if(last<text.length)parts.push(text.slice(last));
 return <div className="cis-wa-text">{parts.length?parts:<span className="cis-msg-muted">Sem texto.</span>}</div>;
}
