import {useEffect,useState,type FormEvent} from 'react';
import {Plus,Save} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Holiday={date:string;name:string};
type Calendar={id:number;year:number;company:string;reason:string;author:string|null;holidays:Holiday[]};
type Page={data:Calendar[];last_page:number};

export default function RhHolidayCalendar({token}:{token:string}){
 const [year,setYear]=useState(''),[company,setCompany]=useState(''),[reason,setReason]=useState('');
 const [holidays,setHolidays]=useState<Holiday[]>([]),[confirmEmpty,setConfirmEmpty]=useState(false);
 const [rows,setRows]=useState<Page|null>(null),[page,setPage]=useState(1),[reload,setReload]=useState(0);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
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
   setPage(1);setReload(x=>x+1);setNotice('Versão do calendário guardada.');
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  <form className="cis-prop-decision" onSubmit={e=>void save(e)}>
   <h4>Calendário anual de feriados</h4>
   <p>Deixe a empresa em branco para criar o calendário geral. O calendário específico de uma empresa substitui o geral nesse ano e deve incluir todos os feriados aplicáveis.</p>
   <div className="cis-profile-fields">
    <label className="cis-profile-field"><span>Ano</span><input type="number" required min="1900" max="2200" value={year} onChange={e=>setYear(e.target.value)}/></label>
    <label className="cis-profile-field"><span>Empresa</span><input value={company} maxLength={80} onChange={e=>setCompany(e.target.value)}/></label>
    <label className="cis-profile-field"><span>Justificação</span><input value={reason} required maxLength={255} onChange={e=>setReason(e.target.value)}/></label>
   </div>
   {holidays.map((h,i)=><div className="cis-profile-fields" key={i}>
    <label className="cis-profile-field"><span>Data</span><input type="date" required value={h.date} onChange={e=>setHolidays(holidays.map((x,j)=>j===i?{...x,date:e.target.value}:x))}/></label>
    <label className="cis-profile-field"><span>Feriado</span><input required maxLength={120} value={h.name} onChange={e=>setHolidays(holidays.map((x,j)=>j===i?{...x,name:e.target.value}:x))}/></label>
    <button type="button" className="cis-edit-action" onClick={()=>setHolidays(holidays.filter((_,j)=>i!==j))}>Remover</button>
   </div>)}
   {!holidays.length&&<label className="cis-collections-check"><input type="checkbox" checked={confirmEmpty} onChange={e=>setConfirmEmpty(e.target.checked)}/> Confirmo que este calendário fica sem feriados.</label>}
   <div className="cis-prop-actions"><button type="button" disabled={busy||holidays.length>=366} onClick={()=>{setConfirmEmpty(false);setHolidays([...holidays,{date:'',name:''}]);}}><Plus size={15}/> Adicionar feriado</button>
    <button type="submit" className="primary" disabled={busy||(!holidays.length&&!confirmEmpty)}><Save size={15}/> Guardar versão</button></div>
  </form>
  {rows&&rows.data.length===0&&<p>Sem calendários registados.</p>}
  {rows?.data.map(c=><details key={c.id}><summary>{c.year} · {c.company||'Geral'} · v{c.id} · {c.author??'—'}</summary>
   <p>{c.reason}</p>{c.holidays.length?<ul>{[...c.holidays].sort((a,b)=>a.date.localeCompare(b.date)).map(h=><li key={h.date}>{h.date} · {h.name}</li>)}</ul>:<p>Calendário sem feriados.</p>}</details>)}
  {(rows?.last_page??0)>1&&<div className="cis-prop-actions"><button type="button" disabled={page<=1} onClick={()=>setPage(v=>v-1)}>Anterior</button>
   <span>{page} / {rows?.last_page}</span><button type="button" disabled={page>=(rows?.last_page??1)} onClick={()=>setPage(v=>v+1)}>Seguinte</button></div>}
 </>;
}
