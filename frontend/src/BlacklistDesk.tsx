// Central de risco: lista negra interna de mutuários (todas as agências).
import {useEffect,useState} from 'react';
import {Ban,Search,AlertCircle,ArrowUpRight,History} from 'lucide-react';
import ClientProfile from './ClientProfile';

const API=import.meta.env.VITE_API_URL||'/api';
type Row={client_id:number;nome:string;contacto:string|null;residencia:string|null;bi:string|null;motivo:string;desde:string|null;por:string|null;retirado_em:string|null;retirado_por:string|null;motivo_saida:string|null};
type Data={instalado:boolean;data:Row[];retirados:Row[];pode_gerir?:boolean};
const when=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';

export default function BlacklistDesk({token}:{token:string}){
 const [d,setD]=useState<Data|null>(null),[error,setError]=useState(''),[q,setQ]=useState(''),[tab,setTab]=useState<'activos'|'retirados'>('activos');
 const [open,setOpen]=useState<number|null>(null),[refresh,setRefresh]=useState(0);
 useEffect(()=>{const c=new AbortController();setError('');
  fetch(API+'/cis/lista-negra',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível abrir a lista negra.');setD(j as Data);})
   .catch(e=>{if(e.name!=='AbortError')setError(e.message);});
  return()=>c.abort();},[token,refresh]);
 if(open!==null)return <ClientProfile id={open} token={token} onBack={()=>{setOpen(null);setRefresh(v=>v+1);}}/>;
 const rows=(tab==='activos'?d?.data:d?.retirados)??[];
 const t=q.trim().toLowerCase();
 const shown=t?rows.filter(r=>[r.nome,r.bi,r.contacto,r.motivo,r.residencia].some(v=>(v??'').toLowerCase().includes(t))):rows;
 return <section className="cis-panel cis-blacklist">
  <div className="cis-panel-head"><div><h2><Ban size={20}/> Lista negra</h2>
   <p>Mutuários que não podem receber crédito nem ser avalistas, em todas as agências. Para adicionar ou retirar, abra a ficha do mutuário{d?.pode_gerir?'':' (gestor ou administrador)'}.</p></div>
   <span className="cis-blacklist-count"><b>{d?.data.length??'…'}</b> na lista</span></div>
  {d&&!d.instalado&&<div className="cis-contract-notice"><AlertCircle size={17}/><span>A lista negra ainda não está instalada. Na VPS execute: <code>bash deploy/install-client-registry.sh</code></span></div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  <div className="cis-blacklist-tools">
   <div className="cis-home-tabs" role="tablist"><button type="button" role="tab" aria-selected={tab==='activos'} className={tab==='activos'?'selected':''} onClick={()=>setTab('activos')}><Ban size={15}/> Na lista ({d?.data.length??0})</button>
    <button type="button" role="tab" aria-selected={tab==='retirados'} className={tab==='retirados'?'selected':''} onClick={()=>setTab('retirados')}><History size={15}/> Retirados ({d?.retirados.length??0})</button></div>
   <label className="cis-blacklist-search"><Search size={15}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="Procurar por nome, BI, contacto ou motivo" aria-label="Procurar na lista negra"/></label>
  </div>
  {d&&shown.length===0?<p className="cis-msg-muted cis-blacklist-empty">{rows.length?'Nada corresponde à pesquisa.':tab==='activos'?'Nenhum mutuário na lista negra.':'Nenhum mutuário foi retirado da lista.'}</p>:
  <div className="cis-table-scroll"><table><thead><tr><th>Mutuário</th><th>BI / NUIT</th><th>Contacto</th><th>Residência</th><th>Motivo</th><th>{tab==='activos'?'Desde':'Período'}</th><th>Ficha</th></tr></thead>
   <tbody>{shown.map((r,i)=><tr key={r.client_id+'-'+i}><td><b>{r.nome}</b></td><td>{r.bi??'—'}</td><td>{r.contacto??'—'}</td><td>{r.residencia??'—'}</td>
    <td>{r.motivo}{tab==='retirados'&&r.motivo_saida&&<small className="cis-blacklist-out">Saiu: {r.motivo_saida}{r.retirado_por?' ('+r.retirado_por+')':''}</small>}</td>
    <td>{tab==='activos'?<>{when(r.desde)}{r.por&&<small>{r.por}</small>}</>:<>{when(r.desde)} → {when(r.retirado_em)}</>}</td>
    <td><button type="button" className="cis-edit-action" onClick={()=>setOpen(r.client_id)}>Abrir <ArrowUpRight size={13}/></button></td></tr>)}</tbody></table></div>}
 </section>;
}
