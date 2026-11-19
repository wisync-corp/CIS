import {useEffect,useMemo,useRef,useState} from 'react';
import {MessageSquare,Plus,Pencil,Save,X,Trash2,Send,Copy,Users,AlertCircle,CheckCircle2,Image as ImageIcon,SkipForward} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Template={id:number;name:string;body:string;updated_at:string|null};
type Preview={contrato:string;cliente:string;contacto:string|null;telefone:string|null;situacao:string;mensagem:string};
type Recipient={contrato:string;cliente:string;cliente_id:number|null;contacto:string|null;telefone:string|null;situacao:string;mensagem:string;estado?:'aberta'|'saltada'};
type Group='todos'|'activos'|'inactivos'|'pendentes'|'atrasados';
const GROUPS:{id:Group;label:string;hint:string}[]=[
 {id:'todos',label:'Todos os contactos',hint:'Um por cliente (contrato mais recente)'},
 {id:'activos',label:'Contactos activos',hint:'Clientes com contrato em aberto'},
 {id:'inactivos',label:'Contactos inactivos',hint:'Clientes sem contratos em aberto'},
 {id:'pendentes',label:'Contratos pendentes',hint:'Em aberto, dentro do prazo'},
 {id:'atrasados',label:'Contratos com pagamento atrasado',hint:'Com prestações vencidas'},
];
const EMOJIS=['😊','🙏','👍','✅','⚠️','📅','💰','📞','📌','🔔','❗','🤝','👋','💳','🏦','📄'];
const situ=(s:string)=>s==='atrasado'?'Pagamento atrasado':s==='pendente'?'Pendente':'Finalizado';
const waUrl=(tel:string,msg:string)=>'https://wa.me/'+tel+'?text='+encodeURIComponent(msg);
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
  try{await call('/cis/mensagens/registo',{method:'POST',body:JSON.stringify({...r,modelo_id:selId||null})});}catch{/* o registo não bloqueia o envio */}
 }
 async function sendOne(canal:'whatsapp'|'sms'|'copia'){
  if(!preview)return;setError('');
  if(canal==='copia'){try{await navigator.clipboard.writeText(text);setNotice('Mensagem copiada.');}catch{setError('Não foi possível copiar automaticamente; seleccione o texto e copie.');return;}}
  else if(!preview.telefone){setError('Cliente sem contacto válido.');return;}
  else window.open(canal==='whatsapp'?waUrl(preview.telefone,text):smsUrl(preview.telefone,text),'_blank','noopener');
  await log({contrato:preview.contrato,telefone:preview.telefone,canal,mensagem:text});
  if(canal!=='copia')setNotice('Mensagem aberta no '+(canal==='whatsapp'?'WhatsApp':'SMS')+'. Confirme o envio na aplicação.');
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
  window.open(waUrl(r.telefone,r.mensagem),'_blank','noopener');
  setQueue(q=>q&&q.map((x,k)=>k===i?{...x,estado:'aberta'}:x));
  await log({contrato:r.contrato,cliente_id:r.cliente_id,telefone:r.telefone,canal:'whatsapp',mensagem:r.mensagem,grupo:group||undefined});
 }
 const nextIdx=queue?queue.findIndex(r=>r.telefone&&!r.estado):-1;
 const done=queue?queue.filter(r=>r.estado==='aberta').length:0;

 return <section className={'cis-panel cis-msg'+(sendMode?' cis-msg-send':'')}>
  <div className="cis-panel-head"><div><h2><MessageSquare size={18}/> Centro de mensagens</h2>
   <p>{sendMode?<>Mensagem para o contrato <b>{contrato}</b>{preview?<> · {preview.cliente} · {situ(preview.situacao)}</>:null}</>:'Modelos de mensagem com campos do contrato e envio pelo WhatsApp do operador.'}</p></div>
   {onClose&&<button type="button" className="cis-msg-close" onClick={onClose} aria-label="Fechar"><X size={17}/></button>}</div>

  <div className="cis-msg-templates">
   <label>Modelo{editing==='new'?<input value={name} onChange={e=>setName(e.target.value)} placeholder="Nome do novo modelo" maxLength={80} autoFocus/>
    :<select value={selId} onChange={e=>setSelId(e.target.value?Number(e.target.value):'')} disabled={!!editing}><option value="">— escolher —</option>{templates.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select>}</label>
   {editing==='edit'&&<label>Nome<input value={name} onChange={e=>setName(e.target.value)} maxLength={80}/></label>}
   <div className="cis-msg-btns">{!editing?<>
    <button type="button" onClick={startNew}><Plus size={14}/> Novo</button>
    <button type="button" onClick={startEdit} disabled={!sel}><Pencil size={14}/> Editar</button>
    <button type="button" onClick={()=>void remove()} disabled={!sel||busy==='del'}><Trash2 size={14}/> Eliminar</button>
   </>:<>
    <button type="button" className="primary" onClick={()=>void save()} disabled={busy==='save'||name.trim().length<3||body.trim().length<3}><Save size={14}/> {busy==='save'?'A guardar…':'Guardar'}</button>
    <button type="button" onClick={cancel}><X size={14}/> Cancelar</button>
   </>}</div>
  </div>

  <label className="cis-msg-editor">{editing?'Texto do modelo':'Texto do modelo (clique em Editar para alterar)'}
   <textarea ref={ta} value={body} onChange={e=>setBody(e.target.value)} readOnly={!editing} rows={6} className={editing?'':'locked'} placeholder="Escreva a mensagem e insira campos como {Nome Completo}"/></label>
  {editing&&<div className="cis-msg-tools">
   <select value={field} onChange={e=>setField(e.target.value)} aria-label="Campo"><option value="">Inserir campo…</option>{fields.map(f=><option key={f} value={f}>{f}</option>)}</select>
   <button type="button" onClick={()=>field&&insert(field)} disabled={!field}>Inserir</button>
   <button type="button" onClick={()=>setShowEmoji(v=>!v)} aria-expanded={showEmoji}>😊 Emojis</button>
   {showEmoji&&<div className="cis-msg-emojis">{EMOJIS.map(e=><button type="button" key={e} onClick={()=>insert(e)}>{e}</button>)}</div>}
  </div>}

  {sendMode&&<div className="cis-msg-preview"><h3>Pré-visualização</h3>
   <textarea value={text} onChange={e=>setText(e.target.value)} rows={7} aria-label="Mensagem a enviar"/>
   <div className="cis-msg-btns">
    <button type="button" className="primary" onClick={()=>void sendOne('whatsapp')} disabled={!preview?.telefone||!text.trim()}><Send size={14}/> Enviar pelo WhatsApp</button>
    <button type="button" onClick={()=>void sendOne('sms')} disabled={!preview?.telefone||!text.trim()}>SMS</button>
    <button type="button" onClick={()=>void sendOne('copia')} disabled={!text.trim()}><Copy size={14}/> Copiar</button>
    <small>{preview?.contacto?`Para ${preview.contacto}`:preview?'Cliente sem contacto registado.':''}</small>
   </div></div>}

  {!sendMode&&<div className="cis-msg-bulk"><h3><Users size={16}/> Mandar para todos</h3>
   <div className="cis-msg-btns">
    <select value={group} onChange={e=>{setGroup(e.target.value as Group);setQueue(null);}} aria-label="Grupo"><option value="">Escolher destinatários…</option>{GROUPS.map(g=><option key={g.id} value={g.id}>{g.label}</option>)}</select>
    <button type="button" className="primary" onClick={()=>void loadQueue()} disabled={!group||(!selId&&!body.trim())||busy==='queue'}>{busy==='queue'?'A preparar…':'Preparar fila'}</button>
    {group&&<small>{GROUPS.find(g=>g.id===group)?.hint}</small>}
   </div>
   {queue&&<>
    <div className="cis-msg-queue-head"><span>{queueInfo} · {done} aberta(s)</span>
     <button type="button" className="primary" disabled={nextIdx<0} onClick={()=>void openRecipient(nextIdx)}><SkipForward size={14}/> {nextIdx<0?'Fila concluída':`Abrir próxima (${queue[nextIdx].cliente})`}</button></div>
    <div className="cis-table-scroll cis-msg-queue"><table><thead><tr><th>Cliente</th><th>Contrato</th><th>Contacto</th><th>Situação</th><th>Mensagem</th><th></th></tr></thead>
     <tbody>{queue.map((r,i)=><tr key={r.contrato+i} className={r.estado==='aberta'?'done':''}><td>{r.cliente}</td><td>{r.contrato}</td><td>{r.contacto??'—'}</td><td>{situ(r.situacao)}</td><td className="msg">{r.mensagem}</td>
      <td>{!r.telefone?<small>Sem contacto</small>:r.estado==='aberta'?<span className="ok"><CheckCircle2 size={14}/> Aberta</span>:<button type="button" onClick={()=>void openRecipient(i)}><Send size={13}/> WhatsApp</button>}</td></tr>)}</tbody></table></div>
   </>}
  </div>}

  <p className="cis-msg-note"><ImageIcon size={14}/> O CIS prepara cada mensagem e abre-a no WhatsApp do seu dispositivo; o envio é confirmado por si. Para mandar imagem, anexe-a na conversa depois de abrir. Cada mensagem aberta fica registada no CIS.</p>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={17}/>{error}</div>}
  {notice&&!error&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
 </section>;
}
