import {Fragment,useEffect,useRef,useState} from 'react';
import {History,RefreshCw,CheckCircle2,AlertCircle,ExternalLink,ChevronLeft,ChevronRight} from 'lucide-react';
import {onWa} from './waBridge';

const API=import.meta.env.VITE_API_URL||'/api';
type Row={id:number;data:string;contrato:string|null;cliente:string|null;telefone:string|null;canal:string;estado:'enviada'|'falhada'|'aberta';motivo:string|null;grupo:string|null;por:string|null;mensagem:string};
type Resp={linhas:Row[];pagina:number;por_pagina:number;total_linhas:number;totais:{enviadas:number;falhadas:number;abertas:number};estado_disponivel:boolean};
const CANAL:Record<string,string>={whatsapp_web:'WhatsApp Web (automático)',whatsapp:'WhatsApp Web (manual)',sms:'SMS',copia:'Copiada'};
const dt=(v:string)=>{const [d,t]=v.split(/[ T]/);const [y,m,dd]=d.split('-');return `${dd}/${m}/${y} ${String(t??'').slice(0,5)}`;};
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo'}).format(new Date());

/** Registo de mensagens (cis_message_log). Com `contrato`, mostra só as desse contrato. */
export default function MessageLog({token,contrato,refreshKey=0}:{token:string;contrato?:string;refreshKey?:number}){
 const [estado,setEstado]=useState('todos');const [canal,setCanal]=useState('todos');
 const [inicio,setInicio]=useState(contrato?'':today());const [fim,setFim]=useState('');
 const [page,setPage]=useState(1);const [data,setData]=useState<Resp|null>(null);
 const [error,setError]=useState('');const [loading,setLoading]=useState(false);const [open,setOpen]=useState<number|null>(null);
 const [refresh,setRefresh]=useState(0);const timer=useRef<number|undefined>(undefined);
 // Actualiza sozinho quando a extensão devolve resultados (o registo é gravado logo a seguir).
 useEffect(()=>onWa(()=>{window.clearTimeout(timer.current);timer.current=window.setTimeout(()=>setRefresh(v=>v+1),1500);}),[]);
 useEffect(()=>{
  const c=new AbortController();setLoading(true);setError('');
  const p=new URLSearchParams({page:String(page)});if(estado!=='todos')p.set('estado',estado);if(canal!=='todos')p.set('canal',canal);
  if(contrato)p.set('contrato',contrato);if(inicio)p.set('inicio',inicio);if(fim)p.set('fim',fim);
  fetch(API+'/cis/mensagens/registos?'+p,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível ler o registo de mensagens.');return j as Resp;})
   .then(d=>{if(!c.signal.aborted)setData(d);}).catch(e=>{if(!c.signal.aborted)setError(e.message);}).finally(()=>{if(!c.signal.aborted)setLoading(false);});
  return()=>c.abort();
 },[token,contrato,estado,canal,inicio,fim,page,refresh,refreshKey]);
 const last=data?Math.max(1,Math.ceil(data.total_linhas/data.por_pagina)):1;
 return <div className="cis-msg-log">
  <h3><History size={16}/> {contrato?'Mensagens anteriores deste contrato':'Registo de mensagens'}
   <button type="button" onClick={()=>setRefresh(v=>v+1)} disabled={loading} aria-label="Actualizar registo"><RefreshCw size={13} className={loading?'spin':''}/></button></h3>
  {!contrato&&<div className="cis-msg-log-filters">
   <label>De<input type="date" value={inicio} max={fim||undefined} onChange={e=>{setInicio(e.target.value);setPage(1);}}/></label>
   <label>Até<input type="date" value={fim} min={inicio||undefined} onChange={e=>{setFim(e.target.value);setPage(1);}}/></label>
   <label>Estado<select value={estado} onChange={e=>{setEstado(e.target.value);setPage(1);}}><option value="todos">Todos</option><option value="enviada">Enviadas</option><option value="falhada">Falhadas</option><option value="aberta">Abertas (envio manual)</option></select></label>
   <label>Canal<select value={canal} onChange={e=>{setCanal(e.target.value);setPage(1);}}><option value="todos">Todos</option>{Object.entries(CANAL).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></label>
  </div>}
  {data&&<div className="cis-msg-log-tot"><span className="ok"><CheckCircle2 size={13}/> {data.totais.enviadas} enviada(s)</span><span className="fail"><AlertCircle size={13}/> {data.totais.falhadas} falhada(s)</span><span><ExternalLink size={13}/> {data.totais.abertas} aberta(s)</span><small>{inicio||fim?`${inicio?dt(inicio+' ').slice(0,10):'…'} a ${fim?dt(fim+' ').slice(0,10):'hoje'}`:'todo o período'}</small></div>}
  {data&&!data.estado_disponivel&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/> O registo ainda não guarda o estado (enviada/falhada). Na VPS execute: <code>bash deploy/install-message-center.sh</code></div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {data&&(data.total_linhas===0?<p className="cis-msg-log-empty">Sem mensagens registadas {contrato?'para este contrato':'no período escolhido'}.</p>:<>
   <div className="cis-table-scroll"><table><thead><tr><th>Data</th><th>Cliente</th><th>Contrato</th><th>Número</th><th>Canal</th><th>Estado</th><th>Utilizador</th></tr></thead>
    <tbody>{data.linhas.map(r=><Fragment key={r.id}>
     <tr className="cis-extracto-row" tabIndex={0} onClick={()=>setOpen(o=>o===r.id?null:r.id)} onKeyDown={e=>{if(e.key==='Enter')setOpen(o=>o===r.id?null:r.id);}} title="Ver o texto da mensagem">
      <td>{dt(r.data)}</td><td>{r.cliente??'—'}</td><td>{r.contrato??'—'}</td><td>{r.telefone?'+'+r.telefone:'—'}</td><td>{CANAL[r.canal]??r.canal}</td>
      <td><span className={'cis-msg-state '+r.estado}>{r.estado==='enviada'?'Enviada':r.estado==='falhada'?'Falhada':'Aberta'}</span>{r.motivo&&<small className="motivo">{r.motivo}</small>}</td><td>{r.por??'—'}</td></tr>
     {open===r.id&&<tr className="cis-msg-log-body"><td colSpan={7}>{r.mensagem}</td></tr>}
    </Fragment>)}</tbody></table></div>
   {last>1&&<div className="cis-table-footer"><span>{data.total_linhas} mensagem(ns) · Página {page} de {last}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(p=>p-1)}><ChevronLeft size={17}/> Anterior</button><button type="button" disabled={page>=last} onClick={()=>setPage(p=>p+1)}>Seguinte <ChevronRight size={17}/></button></div></div>}
  </>)}
 </div>;
}
