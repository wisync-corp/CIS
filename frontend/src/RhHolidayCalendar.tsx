import {useEffect,useState,type FormEvent} from 'react';
import {Plus,Save,X} from 'lucide-react';
import {Badge,Empty,Pager,date} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
type Holiday={date:string;name:string};
type Calendar={id:number;year:number;company:string;reason:string;author:string|null;holidays:Holiday[]};
type Page={data:Calendar[];last_page:number};

export default function RhHolidayCalendar({token}:{token:string}){
 const [year,setYear]=useState(''),[company,setCompany]=useState(''),[reason,setReason]=useState('');
 const [holidays,setHolidays]=useState<Holiday[]>([]),[confirmEmpty,setConfirmEmpty]=useState(false);
 const [rows,setRows]=useState<Page|null>(null),[page,setPage]=useState(1),[reload,setReload]=useState(0);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[adding,setAdding]=useState(false);
 const url=API+'/cis/rh/definicoes/calendarios';
 useEffect(()=>{
  const c=new AbortController();
  fetch(url+'?page='+page,{signal:c.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d as Page;})
   .then(d=>{if(!c.signal.aborted)setRows(d);}).catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[token,url,page,reload]);
 async function save(e:FormEvent){
  e.preventDefault();if(busy||(!holidays.length&&!confirmEmpty))return;
  setBusy(true);setError('');setNotice('');
  try{
   const r=await fetch(url,{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({year:Number(year),company:company.trim()||null,reason,holidays})});
   const d=await r.json();if(!r.ok)throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message);
   setPage(1);setReload(x=>x+1);setNotice('Versão do calendário guardada.');setAdding(false);setHolidays([]);
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 const latest=rows?.data[0];
 const weekday=(d:string)=>{const t=Date.parse(d+'T00:00:00Z');return Number.isFinite(t)?new Date(t).toLocaleDateString('pt-PT',{weekday:'long',timeZone:'UTC'}):'';};
 const copyLatest=()=>{if(!latest)return;const y=year||String(latest.year+1);setYear(y);setConfirmEmpty(false);
  setHolidays([...latest.holidays].sort((a,b)=>a.date.localeCompare(b.date)).map(h=>({date:y+h.date.slice(4),name:h.name})));};
 return <>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  <div className="rh-toolbar"><div className="rh-toolbar-end">{!adding?<button type="button" className="rh-primary" onClick={()=>setAdding(true)}><Plus size={16}/> Novo calendário</button>
   :<button type="button" className="cis-edit-action" onClick={()=>setAdding(false)}><X size={15}/> Fechar formulário</button>}</div></div>
  {adding&&<form className="rh-form" onSubmit={e=>void save(e)}>
   <div className="rh-form-head"><h4>Novo calendário de feriados</h4>{latest&&<button type="button" className="cis-edit-action" onClick={copyLatest}>Copiar feriados de {latest.year}{latest.company?' · '+latest.company:''}</button>}</div>
   <p className="rh-note">Empresa em branco = calendário geral. O calendário de uma empresa substitui o geral nesse ano e deve incluir todos os feriados aplicáveis.</p>
   <div className="rh-fields">
    <label className="cis-profile-field"><span>Ano</span><input type="number" required min="1900" max="2200" value={year} onChange={e=>setYear(e.target.value)}/></label>
    <label className="cis-profile-field"><span>Empresa (opcional)</span><input value={company} maxLength={80} placeholder="Geral" onChange={e=>setCompany(e.target.value)}/></label>
    <label className="cis-profile-field" style={{gridColumn:'span 2'}}><span>Justificação</span><input value={reason} required maxLength={255} onChange={e=>setReason(e.target.value)}/></label>
   </div>
   {holidays.length>0&&<div className="cis-table-scroll" style={{marginTop:12}}><table className="rh-table rh-scale"><thead><tr><th>Data</th><th>Dia da semana</th><th>Feriado</th><th></th></tr></thead><tbody>
    {holidays.map((h,i)=><tr key={i}><td><input aria-label={'Data do feriado '+(i+1)} type="date" required value={h.date} onChange={e=>setHolidays(holidays.map((x,j)=>j===i?{...x,date:e.target.value}:x))}/></td>
     <td className="muted">{h.date?weekday(h.date):'—'}</td>
     <td><input aria-label={'Nome do feriado '+(i+1)} required maxLength={120} value={h.name} onChange={e=>setHolidays(holidays.map((x,j)=>j===i?{...x,name:e.target.value}:x))}/></td>
     <td><button type="button" className="cis-edit-action" aria-label={'Remover feriado '+(i+1)} onClick={()=>setHolidays(holidays.filter((_,j)=>i!==j))}><X size={14}/></button></td></tr>)}
   </tbody></table></div>}
   {!holidays.length&&<label className="cis-collections-check"><input type="checkbox" checked={confirmEmpty} onChange={e=>setConfirmEmpty(e.target.checked)}/> Confirmo que este calendário fica sem feriados.</label>}
   <div className="cis-prop-actions" style={{marginTop:12}}><button type="button" className="cis-edit-action" disabled={busy||holidays.length>=366} onClick={()=>{setConfirmEmpty(false);setHolidays([...holidays,{date:'',name:''}]);}}><Plus size={15}/> Adicionar feriado</button>
    <button type="submit" className="rh-primary" disabled={busy||(!holidays.length&&!confirmEmpty)}><Save size={15}/> Guardar calendário</button></div>
  </form>}
  {rows&&rows.data.length===0&&<Empty title="Sem calendários registados." text="Sem calendário, os dias úteis da folha não descontam feriados."/>}
  {rows?.data.map((c,k)=><details key={c.id} className="rh-card rh-details" open={k===0}><summary><strong>{c.year} · {c.company||'Geral'}</strong> <Badge tone="info">{c.holidays.length} feriado(s)</Badge> <span className="rh-note" style={{display:'inline'}}>· versão {c.id} · {c.author??'—'}</span></summary>
   <p className="rh-note">{c.reason}</p>{c.holidays.length?<div className="cis-table-scroll"><table className="rh-table rh-cards"><thead><tr><th>Data</th><th>Dia da semana</th><th>Feriado</th></tr></thead><tbody>
    {[...c.holidays].sort((a,b)=>a.date.localeCompare(b.date)).map(h=><tr key={h.date}><td className="nowrap"><strong>{date(h.date)}</strong></td><td data-label="Dia" className="muted">{weekday(h.date)}</td><td data-label="Feriado">{h.name}</td></tr>)}
   </tbody></table></div>:<p>Calendário sem feriados.</p>}</details>)}
  <Pager page={page} last={rows?.last_page??1} onPage={setPage}/>
 </>;
}
