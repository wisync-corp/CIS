import React,{useEffect,useMemo,useRef,useState} from 'react';
import {MessageSquare,Plus,Pencil,Save,X,Trash2,Send,Copy,Users,Search,Bell,AlertTriangle,Receipt,FileText,Bold,Italic,Strikethrough,Smile,Braces,AlertCircle,CheckCircle2,Image as ImageIcon,SkipForward,Puzzle,Square,Loader2,Eye,EyeOff,ExternalLink} from 'lucide-react';
import MessageLog from './MessageLog';
import {onWa,waState,waSend,waStop,waCheck,jobStatus,jobDetail,failKind,getShowPref,setShowPref,type WaImage} from './waBridge';

const API=import.meta.env.VITE_API_URL||'/api';
type Template={id:number;name:string;body:string;updated_at:string|null};
type Preview={contrato:string;cliente:string;contacto:string|null;telefone:string|null;situacao:string;mensagem:string;contacto_alternativo?:string|null;telefone_alternativo?:string|null};
type Recipient={contrato:string;cliente:string;cliente_id:number|null;contacto:string|null;telefone:string|null;contacto_alternativo?:string|null;telefone_alternativo?:string|null;situacao:string;mensagem:string;estado?:'aberta'|'saltada';jobId?:string};
type RowState='por_enviar'|'a_enviar'|'enviada'|'falhada'|'sem_whatsapp'|'sem_contacto';
type QueueFilter='todos'|RowState;
const FILTERS:{id:QueueFilter;label:string}[]=[{id:'todos',label:'Todos'},{id:'por_enviar',label:'Por enviar'},{id:'a_enviar',label:'A enviar'},{id:'enviada',label:'Enviadas'},
 {id:'falhada',label:'Falhadas'},{id:'sem_whatsapp',label:'Sem WhatsApp'},{id:'sem_contacto',label:'Sem contacto'}];
type Group='todos'|'activos'|'inactivos'|'pendentes'|'atrasados';
const GROUPS:{id:Group;label:string;hint:string}[]=[
 {id:'todos',label:'Todos os contactos',hint:'Um por mutuário (contrato mais recente)'},
 {id:'activos',label:'Contactos activos',hint:'Mutuários com contrato em curso'},
 {id:'inactivos',label:'Contactos inactivos',hint:'Mutuários sem contratos em curso'},
 {id:'pendentes',label:'Contratos em curso',hint:'Dentro do prazo'},
 {id:'atrasados',label:'Contratos em atraso',hint:'Com prestações em atraso'},
];
// Categoria visual do modelo (ícone e cor), deduzida do nome/texto.
type Cat={id:string;label:string;Icon:typeof Bell};
const CATS:{test:RegExp;cat:Cat}[]=[
 {test:/recibo/i,cat:{id:'recibo',label:'Recibo',Icon:Receipt}},
 {test:/extracto|extrato/i,cat:{id:'extracto',label:'Extracto',Icon:FileText}},
 {test:/atras|vencid|d[ií]vida/i,cat:{id:'atraso',label:'Cobrança',Icon:AlertTriangle}},
 {test:/lembr|venc|prestação|pagamento/i,cat:{id:'lembrete',label:'Lembrete',Icon:Bell}},
];
const catOf=(t:{name:string;body:string}):Cat=>CATS.find(c=>c.test.test(t.name))?.cat??CATS.find(c=>c.test.test(t.body))?.cat??{id:'geral',label:'Geral',Icon:MessageSquare};
const fieldsIn=(b:string)=>[...new Set([...b.matchAll(/\{([^{}\n]{1,40})\}/g)].map(m=>m[1]))];
// Grupos dos campos para inserir com um clique.
const FIELD_GROUPS:{label:string;test:RegExp}[]=[
 {label:'Mutuário',test:/nome|contacto|cliente|mutu/i},
 {label:'Valores',test:/valor|capital|juro|total|saldo|d[ií]vida|multa|pago/i},
 {label:'Datas e prazos',test:/data|dias|prazo|venc|per[ií]odo/i},
 {label:'Contrato',test:/contract|contrato|presta|parcela|recibo|extracto|c[óo]digo/i},
];
const groupFields=(fs:string[])=>{const out:Record<string,string[]>={};
 for(const f of fs){const g=FIELD_GROUPS.find(x=>x.test.test(f))?.label??'Outros';(out[g]??=[]).push(f);}
 return [...FIELD_GROUPS.map(g=>g.label),'Outros'].filter(g=>out[g]?.length).map(g=>({label:g,fields:out[g]}));};
const EMOJIS=['😊','🙏','👍','✅','⚠️','📅','💰','📞','📌','🔔','❗','🤝','👋','💳','🏦','📄'];
const situ=(s:string)=>s==='atrasado'?'Em atraso':s==='pendente'?'Em curso':'Liquidado';
// Directo no WhatsApp Web: o wa.me passa pela página de partilha («Open app») e estraga alguns emojis.
const waUrl=(tel:string,msg:string)=>'https://web.whatsapp.com/send?phone='+tel+'&text='+encodeURIComponent(msg);
// Reutiliza sempre o mesmo separador do WhatsApp Web (evita «WhatsApp aberto noutra janela»).
function openWa(url:string){const w=window.open(url,'cis-whatsapp-web');if(w){try{w.opener=null;}catch{/* já noutro domínio */}w.focus();}}
const smsUrl=(tel:string,msg:string)=>'sms:+'+tel+'?body='+encodeURIComponent(msg);

/** Modelo sugerido ao abrir (criado automaticamente se ainda não existir), anexo e campos extra (ex.: dados do recibo). */
export type MessagePreset={modelo?:{nome:string;corpo:string};anexo?:WaImage|null;extras?:Record<string,string>};
const isPdf=(a:WaImage)=>a.type==='application/pdf';
const applyExtras=(t:string,x?:Record<string,string>)=>x?t.replace(/\{([^{}]{1,40})\}/g,(m,k:string)=>k in x?x[k]:m):t;

export default function MessageCenter({token,contrato,onClose,preset}:{token:string;contrato?:string;onClose?:()=>void;preset?:MessagePreset}){
 const headers={Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'};
 const [templates,setTemplates]=useState<Template[]>([]);const [fields,setFields]=useState<string[]>([]);
 const [selId,setSelId]=useState<number|''>('');
 const [editing,setEditing]=useState<false|'new'|'edit'>(false);
 const [name,setName]=useState('');const [body,setBody]=useState('');
 const [showEmoji,setShowEmoji]=useState(false);
 const [preview,setPreview]=useState<Preview|null>(null);const [text,setText]=useState('');
 const [group,setGroup]=useState<Group|''>('');const [queue,setQueue]=useState<Recipient[]|null>(null);const [queueInfo,setQueueInfo]=useState('');
 const [qFilter,setQFilter]=useState<QueueFilter>('todos');const [qNumero,setQNumero]=useState<'principal'|'alternativo'>('principal');const [qLabel,setQLabel]=useState('');
 const [busy,setBusy]=useState('');const [error,setError]=useState('');const [notice,setNotice]=useState('');
 const ta=useRef<HTMLTextAreaElement>(null);
 const [,tick]=useState(0);useEffect(()=>onWa(()=>tick(v=>v+1)),[]);
 const wa=waState();const [show,setShow]=useState(getShowPref());
 const [lastJob,setLastJob]=useState<string|null>(null);
 const [image,setImage]=useState<WaImage|null>(preset?.anexo??null);const [logKey,setLogKey]=useState(0);const fileRef=useRef<HTMLInputElement>(null);const [checking,setChecking]=useState(false);const [help,setHelp]=useState(false);
 const sendMode=!!contrato;
 const [numero,setNumero]=useState<'principal'|'alternativo'>('principal');
 const sel=useMemo(()=>templates.find(t=>t.id===selId)??null,[templates,selId]);
 const [tplSearch,setTplSearch]=useState('');
 const shownTemplates=useMemo(()=>{const q=tplSearch.trim().toLowerCase();return q?templates.filter(t=>(t.name+' '+t.body).toLowerCase().includes(q)):templates;},[templates,tplSearch]);
 const fieldGroups=useMemo(()=>groupFields(fields),[fields]);
 // Sem número principal válido, usa o alternativo automaticamente.
 useEffect(()=>{if(preview&&!preview.telefone&&preview.telefone_alternativo)setNumero('alternativo');},[preview]);
 // Número de destino escolhido (principal ou alternativo da ficha do mutuário).
 const dest=useMemo(()=>{
  if(!preview)return null;
  const alt=numero==='alternativo'&&!!preview.telefone_alternativo;
  return {...preview,telefone:alt?preview.telefone_alternativo??null:preview.telefone,contacto:alt?preview.contacto_alternativo??null:preview.contacto};
 },[preview,numero]);

 async function call<T>(path:string,init?:RequestInit):Promise<T>{
  const r=await fetch(API+path,{...init,headers});const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error((j as {message?:string;errors?:Record<string,string[]>}).errors?Object.values((j as {errors:Record<string,string[]>}).errors).flat()[0]:(j as {message?:string}).message||(r.status===429?'Muitos pedidos seguidos; aguarde um minuto.':'Operação falhou.'));
  return j as T;
 }
 async function load(keep?:number){
  try{const j=await call<{modelos:Template[];campos:string[]}>('/cis/mensagens/modelos');
   const extra=Object.keys(preset?.extras??{}).map(k=>'{'+k+'}');
   setFields([...j.campos,...extra.filter(f=>!j.campos.includes(f))]);
   let list=j.modelos;
   if(!keep&&!selId&&preset?.modelo){
    const want=preset.modelo;let found=list.find(t=>t.name.trim().toLowerCase()===want.nome.toLowerCase());
    if(!found){const c=await call<{id:number}>('/cis/mensagens/modelos',{method:'POST',body:JSON.stringify({name:want.nome,body:want.corpo})});
     list=(await call<{modelos:Template[]}>('/cis/mensagens/modelos')).modelos;found=list.find(t=>t.id===c.id);
     setNotice('Modelo «'+want.nome+'» criado. Pode editá-lo e fica disponível para os próximos envios.');}
    setTemplates(list);if(found){setSelId(found.id);return;}
   }
   setTemplates(list);
   if(keep)setSelId(keep);else if(!selId&&list.length)setSelId(list[0].id);}
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
   .then(p=>{setPreview(p);setText(applyExtras(p.mensagem,preset?.extras));}).catch(e=>{if(e.name!=='AbortError')setError(e.message);});},editing?400:0);
  return()=>{c.abort();window.clearTimeout(t);};
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[body,contrato,sendMode]);

 function insert(s:string){const el=ta.current;if(!el){setBody(b=>b+s);return;}
  const a=el.selectionStart??body.length,b=el.selectionEnd??body.length;const v=body.slice(0,a)+s+body.slice(b);setBody(v);
  requestAnimationFrame(()=>{el.focus();el.selectionStart=el.selectionEnd=a+s.length;});}
 function startNew(){setEditing('new');setName('');setBody('');setNotice('');setError('');}
 function duplicate(){if(!sel)return;setEditing('new');setName(('Cópia de '+sel.name).slice(0,80));setBody(sel.body);setNotice('');setError('');}
 // Formatação WhatsApp à volta da selecção (*negrito*, _itálico_, ~riscado~).
 function wrap(mark:string){const el=ta.current;if(!el)return;
  const a=el.selectionStart??0,b=el.selectionEnd??0;const inner=body.slice(a,b)||'texto';
  const v=body.slice(0,a)+mark+inner+mark+body.slice(b);setBody(v);
  requestAnimationFrame(()=>{el.focus();el.selectionStart=a+1;el.selectionEnd=a+1+inner.length;});}
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
  if(!dest)return;setError('');
  if(canal==='copia'){try{await navigator.clipboard.writeText(text);setNotice('Mensagem copiada.');}catch{setError('Não foi possível copiar automaticamente; seleccione o texto e copie.');return;}}
  else if(!dest.telefone){setError('Mutuário sem contacto válido.');return;}
  else if(canal==='whatsapp')openWa(waUrl(dest.telefone,text));else window.open(smsUrl(dest.telefone,text),'_blank','noopener');
  await log({contrato:dest.contrato,telefone:dest.telefone,canal,mensagem:text});
  if(canal!=='copia')setNotice(canal==='whatsapp'?'Mensagem aberta no WhatsApp Web: carregue em Enviar nessa página.':'Mensagem aberta no SMS: confirme o envio.');
 }
 function pickImage(f:File|undefined){
  setError('');if(!f)return;
  if(!/^(image\/(jpeg|png|webp)|application\/pdf)$/.test(f.type)){setError('Anexo não suportado: use PDF, JPG, PNG ou WebP.');return;}
  if(f.size>5*1024*1024){setError('Imagem demasiado grande (máximo 5 MB).');return;}
  const rd=new FileReader();rd.onload=()=>setImage({name:f.name,type:f.type,dataUrl:String(rd.result)});rd.onerror=()=>setError('Não foi possível ler o anexo.');rd.readAsDataURL(f);
 }
 function sendWeb(){
  if(!dest?.telefone||!text.trim())return;
  const id='c-'+dest.contrato+'-'+Date.now();setLastJob(id);setError('');
  waSend([{id,phone:dest.telefone,text,meta:{contrato:dest.contrato,cliente:dest.cliente,modelo_id:selId||null}}],show,image);
 }
 // Número usado na fila (principal ou alternativo) e estado de cada destinatário.
 const tel=(r:Recipient)=>qNumero==='alternativo'?(r.telefone_alternativo??null):r.telefone;
 const contactoDe=(r:Recipient)=>qNumero==='alternativo'?(r.contacto_alternativo??null):r.contacto;
 const rowState=(r:Recipient):RowState=>{
  if(!tel(r))return 'sem_contacto';
  const js=r.jobId?jobStatus(r.jobId):undefined;
  if(js==='enviada'||r.estado==='aberta')return 'enviada';
  if(js==='a_enviar')return 'a_enviar';
  if(js==='falhada'){const k=failKind(jobDetail(r.jobId!));return k==='sem_whatsapp'?'sem_whatsapp':k==='parada'?'por_enviar':'falhada';}
  return 'por_enviar';
 };
 // Enviar/continuar: só os por enviar e os que falharam; nunca repete os enviados nem os sem WhatsApp.
 function sendQueueWeb(){
  if(!queue)return;const ts=Date.now();
  const next=queue.map((r,i)=>{const st=rowState(r);return st==='por_enviar'||st==='falhada'?{...r,jobId:'q-'+i+'-'+ts}:r;});
  setQueue(next);
  const list=next.filter(r=>r.jobId?.endsWith('-'+ts)).map(r=>({id:r.jobId!,phone:tel(r)!,text:r.mensagem,meta:{contrato:r.contrato,cliente:r.cliente,cliente_id:r.cliente_id,modelo_id:selId||null,grupo:group||'todos'}}));
  if(!list.length){setNotice('Não há mensagens por enviar nesta fila.');return;}
  setQFilter('todos');
  waSend(list,show,image);
 }
 // Nova fila só com os destinatários do filtro actual (ex.: sem WhatsApp → número alternativo).
 function newQueueFrom(f:RowState){
  if(!queue)return;
  const rows=queue.filter(r=>rowState(r)===f).map(r=>({...r,jobId:undefined,estado:undefined}));
  if(!rows.length)return;
  const hasAlt=rows.some(r=>r.telefone_alternativo);
  setQueue(rows);setQFilter('todos');
  setQLabel(FILTERS.find(x=>x.id===f)!.label);
  setQNumero(f!=='falhada'&&hasAlt?'alternativo':'principal');
  setQueueInfo(`${rows.length} destinatário(s) · ${rows.filter(r=>r.telefone_alternativo).length} com número alternativo`);
  setNotice(`Nova fila com ${rows.length} destinatário(s) «${FILTERS.find(x=>x.id===f)!.label}».${hasAlt&&f!=='falhada'?' Usa o número alternativo; pode mudar para o principal.':''}`);
 }
 async function loadQueue(){
  if(!group)return;setBusy('queue');setError('');setQueue(null);
  try{const p=new URLSearchParams({grupo:group});if(editing)p.set('texto',body);else if(selId)p.set('modelo_id',String(selId));else p.set('texto',body);
   const j=await call<{total:number;com_contacto:number;destinatarios:Recipient[]}>('/cis/mensagens/destinatarios?'+p);
   setQueue(j.destinatarios);setQFilter('todos');setQNumero('principal');setQLabel('');setQueueInfo(`${j.total} destinatário(s) · ${j.com_contacto} com contacto`);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível preparar a fila.');}finally{setBusy('');}
 }
 async function openRecipient(i:number){
  if(!queue)return;const r=queue[i];const t=tel(r);if(!t)return;
  openWa(waUrl(t,r.mensagem));
  setQueue(q=>q&&q.map((x,k)=>k===i?{...x,estado:'aberta'}:x));
  await log({contrato:r.contrato,cliente_id:r.cliente_id,telefone:t,canal:'whatsapp',mensagem:r.mensagem,grupo:group||undefined});
 }
 const nextIdx=queue?queue.findIndex(r=>rowState(r)==='por_enviar'&&!r.estado):-1;
 // Recalculado a cada actualização da extensão (resultados chegam pela ponte).
 const counts:Record<QueueFilter,number>={todos:queue?.length??0,por_enviar:0,a_enviar:0,enviada:0,falhada:0,sem_whatsapp:0,sem_contacto:0};
 for(const r of queue??[])counts[rowState(r)]++;
 const visible=queue?queue.map((r,i)=>({r,i})).filter(({r})=>qFilter==='todos'||rowState(r)===qFilter):[];
 const pending=counts.por_enviar+counts.falhada;

 const statusCell=(r:Recipient,i:number)=>{const st=rowState(r);const det=r.jobId?jobDetail(r.jobId):null;
  if(st==='sem_contacto')return <small>{qNumero==='alternativo'?'Sem número alternativo':'Sem contacto'}</small>;
  if(st==='enviada')return <span className="ok"><CheckCircle2 size={14}/> {r.estado==='aberta'?'Aberta':'Enviada'}</span>;
  if(st==='a_enviar')return <span className="wait"><Loader2 size={14} className="spin"/> Na fila</span>;
  if(st==='sem_whatsapp')return <span className="fail" title={det??''}><AlertCircle size={14}/> Sem WhatsApp</span>;
  if(st==='falhada')return <span className="fail" title={det??''}><AlertCircle size={14}/> Falhou<small className="cis-msg-fail-detail">{det}</small><button type="button" onClick={()=>void openRecipient(i)}><Send size={12}/> Abrir</button></span>;
  return <button type="button" onClick={()=>void openRecipient(i)}><Send size={13}/> WhatsApp</button>;};
 const sending=!!lastJob&&jobStatus(lastJob)==='a_enviar';

 return <section className={'cis-panel cis-msg'+(sendMode?' cis-msg-send':'')}>
  <header className="cis-msg-head">
   <div><h2><MessageSquare size={18}/> {sendMode?'Mensagem ao mutuário':'Centro de mensagens'}</h2>
    <p>{sendMode?<>Contrato <b>{contrato}</b>{preview?<> · {preview.cliente} · {situ(preview.situacao)}</>:null}</>:'Escolha um modelo, ajuste o texto e envie a um mutuário ou a um grupo.'}</p></div>
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
    <div className="cis-msg-list-head"><h3>Modelos <span className="cis-msg-count">{templates.length}</span></h3><button type="button" onClick={startNew} disabled={!!editing}><Plus size={14}/> Novo</button></div>
    {templates.length>4&&<label className="cis-msg-search"><Search size={14}/><input value={tplSearch} onChange={e=>setTplSearch(e.target.value)} placeholder="Procurar modelo" aria-label="Procurar modelo"/></label>}
    <ul>{shownTemplates.map(t=>{const c=catOf(t);const n=fieldsIn(t.body).length;return <li key={t.id}><button type="button" className={'cat-'+c.id+(t.id===selId?' selected':'')} disabled={!!editing} onClick={()=>setSelId(t.id)}>
     <span className="cis-msg-tile"><c.Icon size={16}/></span>
     <span className="cis-msg-item"><b>{t.name}</b><small>{t.body.replace(/[*_~]/g,'').replace(/\{([^{}]+)\}/g,'‹$1›').replace(/\s+/g,' ').slice(0,64)}{t.body.length>64?'…':''}</small>
      <em>{c.label}{n?` · ${n} campo${n>1?'s':''}`:''}</em></span></button></li>;})}</ul>
    {!templates.length&&<div className="cis-msg-empty"><MessageSquare size={22}/><p>Ainda não há modelos.</p><button type="button" onClick={startNew}><Plus size={14}/> Criar o primeiro</button></div>}
    {templates.length>0&&!shownTemplates.length&&<p className="cis-msg-muted">Nenhum modelo com «{tplSearch}».</p>}
   </aside>

   <div className="cis-msg-main">
    <div className={'cis-msg-card cis-msg-editor'+(editing?' editing':'')}>
     {(()=>{const c=sel&&!editing?catOf(sel):editing?catOf({name,body}):null;return <div className="cis-msg-card-head">
      {editing?<label className="cis-msg-field-label">{editing==='new'?'Novo modelo':'Editar modelo'}<input className="cis-msg-name" value={name} onChange={e=>setName(e.target.value)} placeholder="Ex.: Lembrete de vencimento" maxLength={80} autoFocus={editing==='new'} aria-label="Nome do modelo"/></label>
       :<div className="cis-msg-title">{c&&<span className={'cis-msg-tile cat-'+c.id}><c.Icon size={18}/></span>}<div><h3>{sel?.name??'Escolha um modelo'}</h3>{sel&&c&&<small>{c.label}{sel.updated_at?` · actualizado em ${sel.updated_at.slice(0,10).split('-').reverse().join('/')}`:''}</small>}</div></div>}
      <div className="cis-msg-btns">{!editing?<>
       <button type="button" className="primary" onClick={startEdit} disabled={!sel}><Pencil size={14}/> Editar</button>
       <button type="button" onClick={duplicate} disabled={!sel} title="Criar um novo modelo a partir deste"><Copy size={14}/> Duplicar</button>
       <button type="button" className="danger" onClick={()=>void remove()} disabled={!sel||busy==='del'} aria-label="Eliminar modelo" title="Eliminar modelo"><Trash2 size={14}/></button>
      </>:<>
       <button type="button" className="primary" onClick={()=>void save()} disabled={busy==='save'||name.trim().length<3||body.trim().length<3}><Save size={14}/> {busy==='save'?'A guardar…':'Guardar'}</button>
       <button type="button" onClick={cancel}><X size={14}/> Cancelar</button>
      </>}</div>
     </div>;})()}
     {editing?<>
      <div className="cis-msg-toolbar" role="toolbar" aria-label="Formatação">
       <button type="button" onClick={()=>wrap('*')} title="Negrito (*texto*)" aria-label="Negrito"><Bold size={15}/></button>
       <button type="button" onClick={()=>wrap('_')} title="Itálico (_texto_)" aria-label="Itálico"><Italic size={15}/></button>
       <button type="button" onClick={()=>wrap('~')} title="Riscado (~texto~)" aria-label="Riscado"><Strikethrough size={15}/></button>
       <span className="sep"/>
       <button type="button" onClick={()=>setShowEmoji(v=>!v)} aria-expanded={showEmoji} title="Emojis"><Smile size={15}/> Emojis</button>
       <span className="count">{body.length} caracteres</span>
       {showEmoji&&<div className="cis-msg-emojis">{EMOJIS.map(e=><button type="button" key={e} onClick={()=>insert(e)}>{e}</button>)}</div>}
      </div>
      <textarea ref={ta} value={body} onChange={e=>setBody(e.target.value)} rows={sendMode?8:11} placeholder="Escreva a mensagem. Use os campos abaixo para inserir os dados de cada contrato." aria-label="Texto do modelo"/>
      <div className="cis-msg-fields"><p><Braces size={14}/> Campos — clique para inserir no cursor</p>
       {fieldGroups.map(g=><div key={g.label} className="cis-msg-field-group"><span>{g.label}</span><div>{g.fields.map(f=>
        <button type="button" key={f} className={body.includes(f)?'used':''} onClick={()=>insert(f)}>{f.slice(1,-1)}</button>)}</div></div>)}
      </div>
     </>:sel?<>
      <div className="cis-msg-read"><WaText text={sel.body} fields/></div>
      {fieldsIn(sel.body).length>0&&<div className="cis-msg-used"><span>Campos usados</span>{fieldsIn(sel.body).map(f=><span key={f} className="cis-wa-field">{f}</span>)}</div>}
     </>:<div className="cis-msg-empty"><MessageSquare size={22}/><p>Escolha um modelo à esquerda ou crie um novo.</p></div>}
     <div className="cis-msg-image">
      <input ref={fileRef} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" hidden onChange={e=>{pickImage(e.target.files?.[0]);e.target.value='';}}/>
      {image?<>{isPdf(image)?<span className="cis-msg-pdf" aria-hidden="true">PDF</span>:<img src={image.dataUrl} alt="Imagem a enviar"/>}<span><b>{image.name}</b><small>{wa.available?isPdf(image)?'Documento PDF; vai com a mensagem como legenda.':'Vai com cada mensagem, com o texto como legenda.':'Só segue automaticamente com a extensão.'}</small></span>
       {!wa.available&&<a className="cis-msg-download" href={image.dataUrl} download={image.name}>Descarregar para anexar</a>}
       <button type="button" onClick={()=>setImage(null)}><X size={13}/> Remover</button></>
      :<button type="button" onClick={()=>fileRef.current?.click()}><ImageIcon size={14}/> Anexar PDF ou imagem</button>}
     </div>
    </div>
   </div>

   <div className="cis-msg-side">
    <div className="cis-msg-card">
     <h3>Pré-visualização</h3>
     <div className="cis-wa-screen">
      <div className="cis-wa-top"><span className="cis-wa-avatar">{(preview?.cliente??'C').charAt(0)}</span><span><b>{sendMode?(preview?.cliente??'Mutuário'):'Mutuário (exemplo)'}</b><small>{sendMode?(dest?.contacto??'sem contacto'):'campos mostrados entre chavetas'}</small></span></div>
      <div className="cis-wa-chat"><div className="cis-wa-bubble">
       {image&&(isPdf(image)?<div className="cis-wa-doc"><span>PDF</span><b>{image.name}</b></div>:<img src={image.dataUrl} alt=""/>)}
       <WaText text={sendMode?text:body} fields={!sendMode}/>
       <span className="cis-wa-time">{new Date().toLocaleTimeString('pt-PT',{hour:'2-digit',minute:'2-digit'})} ✓✓</span>
      </div></div>
     </div>
     {sendMode&&<>
      <details className="cis-msg-adjust"><summary>Ajustar o texto só para este envio</summary>
       <textarea value={text} onChange={e=>setText(e.target.value)} rows={7} aria-label="Mensagem a enviar"/></details>
      <div className="cis-msg-send-btns">
       {preview&&<div className="cis-msg-number" role="radiogroup" aria-label="Número de destino">
        <label className={numero==='principal'?'on':''}><input type="radio" name="cis-msg-numero" checked={numero==='principal'} onChange={()=>setNumero('principal')}/><span><b>Principal</b><small>{preview.contacto??'sem contacto'}</small></span></label>
        <label className={(numero==='alternativo'?'on':'')+(preview.telefone_alternativo?'':' off')}><input type="radio" name="cis-msg-numero" disabled={!preview.telefone_alternativo} checked={numero==='alternativo'} onChange={()=>setNumero('alternativo')}/><span><b>Alternativo</b><small>{preview.contacto_alternativo??'não registado'}</small></span></label>
       </div>}
       {wa.available&&<button type="button" className="primary big" onClick={sendWeb} disabled={!dest?.telefone||!text.trim()||sending}>{sending?<><Loader2 size={15} className="spin"/> A enviar…</>:<><Send size={15}/> Enviar pelo WhatsApp Web</>}</button>}
       <div className="cis-msg-btns">
        <button type="button" className={wa.available?'':'primary'} onClick={()=>void sendOne('whatsapp')} disabled={!dest?.telefone||!text.trim()}><ExternalLink size={14}/> Abrir no WhatsApp Web</button>
        <button type="button" onClick={()=>void sendOne('sms')} disabled={!dest?.telefone||!text.trim()}>SMS</button>
        <button type="button" onClick={()=>void sendOne('copia')} disabled={!text.trim()}><Copy size={14}/> Copiar</button>
       </div>
       
       <small>{dest?.contacto?`Para ${dest.contacto}`:preview?'Mutuário sem contacto registado neste número.':''}</small>
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
   <div className="cis-msg-queue-head"><h3><Users size={16}/> Fila · {qLabel||GROUPS.find(g=>g.id===group)?.label}</h3><span>{queueInfo}</span>
    <div className="cis-msg-btns">
     {wa.available&&<button type="button" className="primary" disabled={wa.running||pending===0} onClick={sendQueueWeb}><Send size={14}/> {wa.running?`A enviar… (${wa.pendentes} na fila)`:pending===0?'Nada por enviar':counts.enviada+counts.falhada>0?`Continuar (${pending} por enviar)`:`Enviar a fila pelo WhatsApp Web (${pending})`}</button>}
     {wa.running&&<button type="button" onClick={waStop}><Square size={13}/> Parar</button>}
     <button type="button" className={wa.available?'':'primary'} disabled={nextIdx<0} onClick={()=>void openRecipient(nextIdx)}><SkipForward size={14}/> {nextIdx<0?'Fila concluída':`Abrir próxima (${queue[nextIdx].cliente})`}</button>
    </div></div>
   <div className="cis-msg-queue-bar">
    <div className="cis-msg-queue-filters" role="tablist" aria-label="Filtrar a fila">{FILTERS.filter(f=>f.id==='todos'||counts[f.id]>0).map(f=>
     <button key={f.id} type="button" role="tab" aria-selected={qFilter===f.id} className={'q-'+f.id+(qFilter===f.id?' on':'')} onClick={()=>setQFilter(f.id)}>{f.label} <b>{counts[f.id]}</b></button>)}</div>
    <div className="cis-msg-queue-num" role="radiogroup" aria-label="Número usado na fila">
     <span>Número:</span>
     <label><input type="radio" name="cis-q-num" checked={qNumero==='principal'} disabled={wa.running} onChange={()=>setQNumero('principal')}/> Principal</label>
     <label><input type="radio" name="cis-q-num" checked={qNumero==='alternativo'} disabled={wa.running} onChange={()=>setQNumero('alternativo')}/> Alternativo</label>
    </div>
   </div>
   {(qFilter==='sem_whatsapp'||qFilter==='falhada'||qFilter==='sem_contacto')&&counts[qFilter]>0&&!wa.running&&<div className="cis-msg-queue-tip">
    <span>{qFilter==='sem_whatsapp'?'Estes números não têm WhatsApp. Pode criar uma nova fila só com eles e enviar para o número alternativo.':qFilter==='sem_contacto'?'Sem número para este envio. Crie uma fila com eles e experimente o outro número.':'Falharam por outro motivo (veja o detalhe). «Continuar» volta a tentar só estes e os por enviar.'}</span>
    <button type="button" onClick={()=>newQueueFrom(qFilter as RowState)}><Users size={13}/> Nova fila com estes ({counts[qFilter]})</button></div>}
   <div className="cis-table-scroll cis-msg-queue"><table><thead><tr><th>Mutuário</th><th>Contrato</th><th>Contacto</th><th>Situação</th><th>Mensagem</th><th></th></tr></thead>
    <tbody>{visible.map(({r,i})=><tr key={r.contrato+i} className={rowState(r)==='enviada'?'done':''}><td>{r.cliente}</td><td>{r.contrato}</td><td>{contactoDe(r)??'—'}</td><td>{situ(r.situacao)}</td><td className="msg">{r.mensagem}</td><td>{statusCell(r,i)}</td></tr>)}</tbody></table>
    {!visible.length&&<p className="cis-msg-muted" style={{padding:'12px'}}>Nenhum destinatário neste filtro.</p>}</div>
  </div>}

  <div className="cis-msg-card"><MessageLog token={token} contrato={contrato} refreshKey={logKey}/></div>

  <p className="cis-msg-note"><ImageIcon size={14}/> {wa.available?'Com a extensão, o CIS envia pelo seu WhatsApp Web, uma mensagem de cada vez (intervalo de 8–18 s), e confirma o envio; os avisos aparecem no canto superior direito.':'Sem a extensão, o CIS abre cada mensagem já escrita no WhatsApp Web (sempre no mesmo separador) e o envio é confirmado por si.'} Imagens (JPG/PNG/WebP até 5 MB) seguem com a extensão, com o texto como legenda.</p>
 </section>;
}

/** Texto como o WhatsApp o mostra: *negrito*, _itálico_, ~riscado~, quebras de linha; campos {…} em destaque. */
function inline(text:string,fields:boolean,key:{k:number}):React.ReactNode[]{
 const parts:React.ReactNode[]=[];
 const re=/(\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~|\{[^{}\n]{1,40}\})/g;let last=0;let m:RegExpExecArray|null;
 while((m=re.exec(text))){if(m.index>last)parts.push(text.slice(last,m.index));const t=m[0];const inner=t.slice(1,-1);
  if(t.startsWith('{'))parts.push(fields?<span key={key.k++} className="cis-wa-field">{inner}</span>:t);
  else if(t.startsWith('*'))parts.push(<strong key={key.k++}>{inline(inner,fields,key)}</strong>);
  else if(t.startsWith('_'))parts.push(<em key={key.k++}>{inline(inner,fields,key)}</em>);
  else parts.push(<s key={key.k++}>{inline(inner,fields,key)}</s>);
  last=m.index+t.length;}
 if(last<text.length)parts.push(text.slice(last));
 return parts;
}
function WaText({text,fields}:{text:string;fields:boolean}){
 const parts=inline(text,fields,{k:0});
 return <div className="cis-wa-text">{parts.length?parts:<span className="cis-msg-muted">Sem texto.</span>}</div>;
}
