// Dossier do crédito: fiadores, garantias e documentos digitalizados (proposta e ficha do mutuário).
import {useCallback,useEffect,useRef,useState,type FormEvent} from 'react';
import {Paperclip,FileText,Image as ImageIcon,Eye,Trash2,Upload,UserPlus,ShieldCheck,CheckCircle2,AlertCircle,X,Users,Landmark,FolderOpen,Loader2} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
export type Doc={id:number;tipo:string;tipo_label:string;descricao:string|null;nome:string;mime:string;tamanho:number;em:string|null;por:string|null;da_proposta:boolean};
type Guarantor={id:number;nome:string;bi:string|null;contacto:string|null;relacao:string|null;morada:string|null;actividade:string|null;rendimento_mensal:string|null};
type Collateral={id:number;tipo:string;tipo_label:string;descricao:string;valor_estimado:string;localizacao:string|null};
export type Check={itens:{chave:string;label:string;ok:boolean}[];em_falta:string[];cobertura:{valor:string;necessario:string;pct_exigida:number;pct:number|null}|null};
type Dossier={instalado:boolean;fiadores:Guarantor[];garantias:Collateral[];documentos:Doc[];verificacao:Check;pode_editar:boolean;pode_anexar:boolean;tipos_documento:Record<string,string>;tipos_garantia:Record<string,string>;max_kb:number};

const mt=(v:unknown)=>v===null||v===undefined||v===''?'—':Number(v).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const size=(b:number)=>b>=1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB';
const when=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'';

async function req<T>(token:string,path:string,method='GET',body?:unknown):Promise<T>{
 const isForm=body instanceof FormData;
 const r=await fetch(API+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body&&!isForm?{'Content-Type':'application/json'}:{})},body:body?(isForm?body:JSON.stringify(body)):undefined});
 const j=await r.json().catch(()=>({}));
 if(r.status===413)throw new Error('O ficheiro é demasiado grande (máx. 5 MB).');
 if(!r.ok)throw new Error((j.errors?Object.values(j.errors as Record<string,string[]>).flat()[0]:undefined)||j.message||'Operação recusada.');
 return j as T;
}

/** Abre o documento num separador (o pedido leva a autenticação). */
async function openDoc(token:string,id:number){
 const w=window.open('','_blank');
 try{const r=await fetch(API+'/cis/documentos/'+id,{headers:{Authorization:'Bearer '+token}});if(!r.ok)throw new Error();
  const url=URL.createObjectURL(await r.blob());if(w)w.location.href=url;else window.location.href=url;window.setTimeout(()=>URL.revokeObjectURL(url),60_000);}
 catch{w?.close();alert('Não foi possível abrir o documento.');}
}

export function DocumentsBox({token,clientId,proposalId,docs,types,canAttach,maxKb,onChanged,title='Documentos digitalizados'}:{token:string;clientId:number;proposalId?:number;docs:Doc[];types:Record<string,string>;canAttach:boolean;maxKb:number;onChanged:()=>void;title?:string}){
 const [tipo,setTipo]=useState('bi'),[desc,setDesc]=useState(''),[file,setFile]=useState<File|null>(null),[scope,setScope]=useState<'mutuario'|'proposta'>('mutuario');
 const [busy,setBusy]=useState(false),[error,setError]=useState(''),[open,setOpen]=useState(false);
 const input=useRef<HTMLInputElement>(null);
 useEffect(()=>{if(proposalId)setScope(['bi','residencia','fotografia'].includes(tipo)?'mutuario':'proposta');},[tipo,proposalId]);
 async function send(e:FormEvent){e.preventDefault();if(!file){setError('Escolha o ficheiro.');return;}
  if(file.size>maxKb*1024){setError('O ficheiro tem mais de '+Math.round(maxKb/1024)+' MB.');return;}
  setBusy(true);setError('');
  try{const f=new FormData();f.append('client_id',String(clientId));f.append('tipo',tipo);if(desc.trim())f.append('descricao',desc.trim());
   if(proposalId&&scope==='proposta')f.append('proposal_id',String(proposalId));f.append('ficheiro',file);
   await req(token,'/cis/documentos','POST',f);setFile(null);setDesc('');if(input.current)input.current.value='';setOpen(false);onChanged();}
  catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 async function remove(d:Doc){if(!window.confirm(`Retirar «${d.tipo_label}» (${d.nome})? Fica registado quem o retirou.`))return;
  try{await req(token,'/cis/documentos/'+d.id,'DELETE');onChanged();}catch(err){setError((err as Error).message);}}
 return <article className="cis-dossier-card cis-dossier-docs">
  <header><h4><FolderOpen size={15}/> {title} <b>{docs.length}</b></h4>{canAttach&&!open&&<button type="button" onClick={()=>setOpen(true)}><Paperclip size={14}/> Anexar</button>}</header>
  {open&&<form className="cis-dossier-form" onSubmit={send}>
   <div className="cis-dossier-row">
    <label>Tipo<select value={tipo} onChange={e=>setTipo(e.target.value)}>{Object.entries(types).map(([k,l])=><option key={k} value={k}>{l}</option>)}</select></label>
    <label>Descrição (opcional)<input value={desc} maxLength={160} onChange={e=>setDesc(e.target.value)} placeholder="Ex.: BI frente e verso"/></label></div>
   <label className={'cis-dossier-drop'+(file?' has':'')}><Upload size={18}/><span>{file?file.name+' · '+size(file.size):'Escolher PDF ou fotografia (máx. '+Math.round(maxKb/1024)+' MB)'}</span>
    <input ref={input} type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={e=>setFile(e.target.files?.[0]??null)}/></label>
   {proposalId&&<div className="cis-dossier-scope" role="radiogroup" aria-label="Onde guardar">
    <button type="button" role="radio" aria-checked={scope==='mutuario'} className={scope==='mutuario'?'on':''} onClick={()=>setScope('mutuario')}>Na ficha do mutuário <small>serve para as próximas propostas</small></button>
    <button type="button" role="radio" aria-checked={scope==='proposta'} className={scope==='proposta'?'on':''} onClick={()=>setScope('proposta')}>Só nesta proposta</button></div>}
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy||!file}>{busy?<><Loader2 size={14} className="cis-spin"/> A enviar…</>:<><Upload size={14}/> Anexar</>}</button><button type="button" onClick={()=>{setOpen(false);setError('');}}>Cancelar</button></div>
  </form>}
  {!open&&error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
  {docs.length===0?<p className="cis-msg-muted">Nenhum documento anexado.</p>:<ul className="cis-dossier-list">{docs.map(d=><li key={d.id}>
   <span className={'cis-dossier-ico'+(d.mime.startsWith('image/')?' img':'')}>{d.mime.startsWith('image/')?<ImageIcon size={16}/>:<FileText size={16}/>}</span>
   <div><b>{d.tipo_label}</b>{d.da_proposta&&<em>proposta</em>}<small>{d.descricao?d.descricao+' · ':''}{d.nome} · {size(d.tamanho)} · {when(d.em)}{d.por?' · '+d.por:''}</small></div>
   <span className="cis-dossier-acts"><button type="button" title="Ver" aria-label={'Ver '+d.tipo_label} onClick={()=>void openDoc(token,d.id)}><Eye size={15}/></button>
    {canAttach&&<button type="button" title="Retirar" aria-label={'Retirar '+d.tipo_label} onClick={()=>void remove(d)}><Trash2 size={15}/></button>}</span></li>)}</ul>}
 </article>;
}

export function ChecklistView({c}:{c:Check|null|undefined}){
 if(!c||c.itens.length===0)return null;
 const ok=c.em_falta.length===0;
 return <div className={'cis-dossier-check '+(ok?'ok':'warn')}>
  <strong>{ok?<><ShieldCheck size={16}/> Dossier completo para aprovar</>:<><AlertCircle size={16}/> Falta no dossier para poder aprovar</>}</strong>
  <ul>{c.itens.map(i=><li key={i.chave} className={i.ok?'ok':'no'}>{i.ok?<CheckCircle2 size={14}/>:<X size={14}/>} {i.label}</li>)}</ul>
 </div>;
}

export default function DossierPanel({token,proposalId,clientId,onChange}:{token:string;proposalId:number;clientId:number;onChange?:()=>void}){
 const [d,setD]=useState<Dossier|null>(null),[error,setError]=useState(''),[form,setForm]=useState<'fiador'|'garantia'|null>(null),[busy,setBusy]=useState(false);
 const [g,setG]=useState<Record<string,string>>({}),[c,setC]=useState<Record<string,string>>({tipo:'viatura'});
 const load=useCallback(async()=>{try{setD(await req<Dossier>(token,'/cis/propostas/'+proposalId+'/dossier'));}catch(e){setError((e as Error).message);}},[token,proposalId]);
 useEffect(()=>{void load();},[load]);
 const changed=()=>{void load();onChange?.();};
 async function post(path:string,body:unknown,reset:()=>void){setBusy(true);setError('');
  try{setD(await req<Dossier>(token,'/cis/propostas/'+proposalId+path,'POST',body));reset();setForm(null);onChange?.();}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 async function del(path:string,what:string){if(!window.confirm('Retirar '+what+'?'))return;
  try{setD(await req<Dossier>(token,'/cis/propostas/'+proposalId+path,'DELETE'));onChange?.();}catch(e){setError((e as Error).message);}}
 if(!d)return error?<div className="cis-table-error"><AlertCircle size={16}/>{error}</div>:null;
 if(!d.instalado)return <div className="cis-contract-notice"><AlertCircle size={17}/><span>O dossier do crédito (fiadores, garantias e documentos) ainda não está instalado. Na VPS execute: <code>bash deploy/install-credit-dossier.sh</code></span></div>;
 const cov=d.verificacao.cobertura;const pct=cov?.pct??0;const need=cov?.pct_exigida??0;
 const G=(k:string,l:string,x:Record<string,unknown>={})=><label>{l}<input value={g[k]??''} onChange={e=>setG(v=>({...v,[k]:e.target.value}))} {...x}/></label>;
 const C=(k:string,l:string,x:Record<string,unknown>={})=><label>{l}<input value={c[k]??''} onChange={e=>setC(v=>({...v,[k]:e.target.value}))} {...x}/></label>;
 return <section className="cis-dossier" aria-label="Dossier do crédito">
  <h3 className="cis-dossier-title"><ShieldCheck size={17}/> Dossier do crédito</h3>
  <ChecklistView c={d.verificacao}/>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  <div className="cis-dossier-grid">
   <article className="cis-dossier-card">
    <header><h4><Users size={15}/> Fiadores <b>{d.fiadores.length}</b></h4>{d.pode_editar&&form!=='fiador'&&<button type="button" onClick={()=>setForm('fiador')}><UserPlus size={14}/> Adicionar</button>}</header>
    {form==='fiador'&&<form className="cis-dossier-form" onSubmit={e=>{e.preventDefault();void post('/fiadores',{...g,rendimento_mensal:g.rendimento_mensal||null},()=>setG({}));}}>
     <div className="cis-dossier-row">{G('nome','Nome completo',{required:true,minLength:3,maxLength:160,autoFocus:true})}{G('bi','N.º do BI',{maxLength:40})}</div>
     <div className="cis-dossier-row">{G('contacto','Contacto',{maxLength:40,inputMode:'tel'})}{G('relacao','Relação com o mutuário',{maxLength:60,placeholder:'Ex.: irmão, colega'})}</div>
     <div className="cis-dossier-row">{G('actividade','Actividade',{maxLength:160})}{G('rendimento_mensal','Rendimento mensal (MT)',{type:'number',min:0,step:'0.01'})}</div>
     {G('morada','Morada',{maxLength:255})}
     <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}>Guardar fiador</button><button type="button" onClick={()=>setForm(null)}>Cancelar</button></div></form>}
    {d.fiadores.length===0?<p className="cis-msg-muted">Sem fiadores.</p>:<ul className="cis-dossier-list">{d.fiadores.map(f=><li key={f.id}>
     <span className="cis-dossier-ico"><Users size={16}/></span>
     <div><b>{f.nome}</b>{f.relacao&&<em>{f.relacao}</em>}<small>{[f.bi&&'BI '+f.bi,f.contacto,f.actividade,f.rendimento_mensal&&'rend. '+mt(f.rendimento_mensal),f.morada].filter(Boolean).join(' · ')||'—'}</small></div>
     {d.pode_editar&&<span className="cis-dossier-acts"><button type="button" aria-label={'Retirar '+f.nome} onClick={()=>void del('/fiadores/'+f.id,'o fiador '+f.nome)}><Trash2 size={15}/></button></span>}</li>)}</ul>}
   </article>
   <article className="cis-dossier-card">
    <header><h4><Landmark size={15}/> Garantias <b>{d.garantias.length}</b></h4>{d.pode_editar&&form!=='garantia'&&<button type="button" onClick={()=>setForm('garantia')}><Paperclip size={14}/> Adicionar</button>}</header>
    {cov&&(d.garantias.length>0||need>0)&&<div className="cis-dossier-cov"><div><span>Cobertura</span><b>{mt(cov.valor)} · {pct}%</b>{need>0&&<small>exigido {need}% ({mt(cov.necessario)})</small>}</div>
     <i><span className={need>0&&pct<need?'low':''} style={{width:Math.min(100,need>0?pct/need*100:Math.min(pct,100))+'%'}}/></i></div>}
    {form==='garantia'&&<form className="cis-dossier-form" onSubmit={e=>{e.preventDefault();void post('/garantias',{...c,localizacao:c.localizacao||null},()=>setC({tipo:'viatura'}));}}>
     <div className="cis-dossier-row"><label>Tipo<select value={c.tipo} onChange={e=>setC(v=>({...v,tipo:e.target.value}))}>{Object.entries(d.tipos_garantia).map(([k,l])=><option key={k} value={k}>{l}</option>)}</select></label>
      {C('valor_estimado','Valor estimado (MT)',{type:'number',min:1,step:'0.01',required:true})}</div>
     {C('descricao','Descrição',{required:true,minLength:3,maxLength:255,placeholder:'Ex.: Toyota Corolla 2008, matrícula ABC-123-MP',autoFocus:true})}
     {C('localizacao','Onde está (opcional)',{maxLength:160})}
     <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}>Guardar garantia</button><button type="button" onClick={()=>setForm(null)}>Cancelar</button></div></form>}
    {d.garantias.length===0?<p className="cis-msg-muted">Sem garantias.</p>:<ul className="cis-dossier-list">{d.garantias.map(x=><li key={x.id}>
     <span className="cis-dossier-ico"><Landmark size={16}/></span>
     <div><b>{x.descricao}</b><em>{x.tipo_label}</em><small>{mt(x.valor_estimado)}{x.localizacao?' · '+x.localizacao:''}</small></div>
     {d.pode_editar&&<span className="cis-dossier-acts"><button type="button" aria-label={'Retirar '+x.descricao} onClick={()=>void del('/garantias/'+x.id,'a garantia «'+x.descricao+'»')}><Trash2 size={15}/></button></span>}</li>)}</ul>}
   </article>
   <DocumentsBox token={token} clientId={clientId} proposalId={proposalId} docs={d.documentos} types={d.tipos_documento} canAttach={d.pode_anexar} maxKb={d.max_kb} onChanged={changed}/>
  </div>
 </section>;
}

/** Documentos na ficha do mutuário. */
export function ClientDocuments({token,clientId}:{token:string;clientId:number}){
 const [d,setD]=useState<{instalado:boolean;documentos:Doc[];pode_anexar?:boolean;tipos_documento?:Record<string,string>;max_kb?:number}|null>(null);
 const load=useCallback(async()=>{try{setD(await req(token,'/cis/mutuarios/'+clientId+'/documentos'));}catch{setD(null);}},[token,clientId]);
 useEffect(()=>{void load();},[load]);
 if(!d||!d.instalado)return null;
 return <div className="cis-dossier cis-client-docs"><DocumentsBox token={token} clientId={clientId} docs={d.documentos} types={d.tipos_documento??{}} canAttach={!!d.pode_anexar} maxKb={d.max_kb??5120} onChanged={()=>void load()} title="Documentos do mutuário"/></div>;
}
