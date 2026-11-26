// Central de risco: lista negra interna de mutuários (todas as agências).
import {useEffect,useState} from 'react';
import {Ban,Search,AlertCircle,ArrowUpRight,History} from 'lucide-react';
import ClientProfile from './ClientProfile';
import LongDelinquency,{type Candidates} from './LongDelinquency';

const API=import.meta.env.VITE_API_URL||'/api';
type Row={origem?:string;dias_atraso?:number|null;divida?:number;contratos?:number;client_id:number;nome:string;contacto:string|null;residencia:string|null;bi:string|null;motivo:string;desde:string|null;por:string|null;retirado_em:string|null;retirado_por:string|null;motivo_saida:string|null};
type Data={instalado:boolean;data:Row[];retirados:Row[];pode_gerir?:boolean;credito_lista_negra?:{mutuarios:number;contratos:number;valor:number};dias_criterio?:number};
const mt=(v:number)=>v.toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const when=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';

export default function BlacklistDesk({token,onProfileChange,onCount}:{token:string;onProfileChange?:(open:boolean)=>void;onCount?:(n:number)=>void}){
 const [d,setD]=useState<Data|null>(null),[error,setError]=useState(''),[q,setQ]=useState(''),[tab,setTab]=useState<'activos'|'retirados'>('activos');
 const [open,setOpen]=useState<number|null>(null),[refresh,setRefresh]=useState(0);
 const [cand,setCand]=useState<Candidates|null>(null),[review,setReview]=useState(false),[done,setDone]=useState('');
 useEffect(()=>{const c=new AbortController();
  fetch(API+'/cis/lista-negra/candidatos',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j)setCand(j as Candidates);}).catch(()=>{});
  return()=>c.abort();},[token,refresh]);
 useEffect(()=>{const c=new AbortController();setError('');
  fetch(API+'/cis/lista-negra',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível abrir a lista negra.');setD(j as Data);onCount?.((j as Data).data?.length??0);})
   .catch(e=>{if(e.name!=='AbortError')setError(e.message);});
  return()=>c.abort();},[token,refresh]);
 useEffect(()=>{onProfileChange?.(open!==null);return()=>onProfileChange?.(false);},[open]);// eslint-disable-line react-hooks/exhaustive-deps
 if(open!==null)return <ClientProfile id={open} token={token} onBack={()=>{setOpen(null);setRefresh(v=>v+1);}} backLabel="Voltar à lista negra"/>;
 const rows=(tab==='activos'?d?.data:d?.retirados)??[];
 const t=q.trim().toLowerCase();
 const shown=t?rows.filter(r=>[r.nome,r.bi,r.contacto,r.motivo,r.residencia].some(v=>(v??'').toLowerCase().includes(t))):rows;
 return <section className="cis-panel cis-blacklist">
  <div className="cis-panel-head"><div><h2><Ban size={20}/> Lista negra</h2>
   <p>Mutuários que não podem receber crédito nem ser avalistas, em todas as agências. Para mudar, abra a ficha do mutuário › Administração › Conduta{d?.pode_gerir?'':' (gestor ou administrador)'}.</p></div>
   <span className="cis-blacklist-count"><b>{d?.data.length??'…'}</b> na lista</span></div>
  {d&&!d.instalado&&<div className="cis-contract-notice"><AlertCircle size={17}/><span>A lista negra ainda não está instalada. Na VPS execute: <code>bash deploy/install-client-registry.sh</code></span></div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {done&&<div className="cis-secondary-success" role="status"><Ban size={16}/>{done}</div>}
  {d?.credito_lista_negra&&d.credito_lista_negra.mutuarios>0&&<div className="cis-ld-credit"><span>Crédito em lista negra (fora da carteira corrente)</span><b>{mt(d.credito_lista_negra.valor)}</b><small>{d.credito_lista_negra.mutuarios} mutuário(s) · {d.credito_lista_negra.contratos} contrato(s) em aberto</small></div>}
  {cand?.pode_decidir&&cand.candidatos.length>0&&<div className="cis-ld-banner"><AlertCircle size={17}/><span><b>{cand.candidatos.length} mutuário(s)</b> com prestações em atraso há mais de {cand.dias} dias ainda fora da lista negra.</span>
   <button type="button" onClick={()=>setReview(true)}>Rever e transferir</button></div>}
  {review&&cand&&<LongDelinquency token={token} data={cand} onClose={()=>setReview(false)} onDone={m=>{setReview(false);setDone(m);setRefresh(v=>v+1);}}/>}
  <div className="cis-blacklist-tools">
   <div className="cis-home-tabs" role="tablist"><button type="button" role="tab" aria-selected={tab==='activos'} className={tab==='activos'?'selected':''} onClick={()=>setTab('activos')}><Ban size={15}/> Na lista ({d?.data.length??0})</button>
    <button type="button" role="tab" aria-selected={tab==='retirados'} className={tab==='retirados'?'selected':''} onClick={()=>setTab('retirados')}><History size={15}/> Retirados ({d?.retirados.length??0})</button></div>
   <label className="cis-blacklist-search"><Search size={15}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="Procurar por nome, BI, contacto ou motivo" aria-label="Procurar na lista negra"/></label>
  </div>
  {d&&shown.length===0?<p className="cis-msg-muted cis-blacklist-empty">{rows.length?'Nada corresponde à pesquisa.':tab==='activos'?'Nenhum mutuário na lista negra.':'Nenhum mutuário foi retirado da lista.'}</p>:
  <div className="cis-table-scroll"><table><thead><tr><th>Mutuário</th><th>BI / NUIT</th><th>Contacto</th><th>Residência</th><th>Motivo</th>{tab==='activos'&&<th>Em dívida</th>}<th>{tab==='activos'?'Desde':'Período'}</th><th>Ficha</th></tr></thead>
   <tbody>{shown.map((r,i)=><tr key={r.client_id+'-'+i}><td><b>{r.nome}</b></td><td>{r.bi??'—'}</td><td>{r.contacto??'—'}</td><td>{r.residencia??'—'}</td>
    <td>{r.origem==='automatica'&&<em className="cis-ld-tag">Automática</em>}{r.origem==='cadastro'&&<em className="cis-ld-tag">Cadastro</em>}{r.motivo}{tab==='retirados'&&r.motivo_saida&&<small className="cis-blacklist-out">Saiu: {r.motivo_saida}{r.retirado_por?' ('+r.retirado_por+')':''}</small>}</td>
    {tab==='activos'&&<td>{r.divida?mt(r.divida):'—'}{r.contratos?<small>{r.contratos} contrato(s)</small>:null}</td>}
    <td>{tab==='activos'?<>{when(r.desde)}{r.por&&<small>{r.por}</small>}</>:<>{when(r.desde)} → {when(r.retirado_em)}</>}</td>
    <td><button type="button" className="cis-edit-action" onClick={()=>setOpen(r.client_id)}>Abrir <ArrowUpRight size={13}/></button></td></tr>)}</tbody></table></div>}
 </section>;
}
