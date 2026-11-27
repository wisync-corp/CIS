import RhHolidayCalendar from './RhHolidayCalendar';
import {useEffect,useState,type FormEvent} from 'react';
import {Plus,Save,X} from 'lucide-react';
import {Badge,Empty,Pager,date} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
type Audit={id:number;effective_from:string;reason:string;author:string};
type Parameter=Audit&{code:string;scope:string;company:string;category:string;employee_code:string;value:string;effective_to:string|null};
type Scale={lower:string;upper:string|null;coefficient:string;d0:string;d1:string;d2:string;d3:string;d4:string};
type Data={catalogue:Record<string,string>;parameters:{data:Parameter[];current_page:number;last_page:number};tax_tables:(Audit&{scales:Scale[]})[]};
const labels:Record<keyof Scale,string>={lower:'Limite inferior (MT)',upper:'Limite superior (MT)',coefficient:'Coeficiente decimal',d0:'0 dependentes',d1:'1 dependente',d2:'2 dependentes',d3:'3 dependentes',d4:'4 ou mais'};
const first:Scale={lower:'0.00',upper:null,coefficient:'',d0:'',d1:'',d2:'',d3:'',d4:''};
const empty={code:'',scope:'global',company:'',category:'',employee_code:'',value:'',effective_from:'',effective_to:'',reason:''};

export default function RhSettingsDesk({token}:{token:string}){
 const [data,setData]=useState<Data|null>(null),[page,setPage]=useState(1);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 const [form,setForm]=useState(empty),[taxDate,setTaxDate]=useState(''),[taxReason,setTaxReason]=useState('');
 const [scales,setScales]=useState<Scale[]>([first]),[tab,setTab]=useState<'parametros'|'irps'|'feriados'>('parametros');
 const [reload,setReload]=useState(0),[adding,setAdding]=useState(false);
 const today=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo'}).format(new Date());
 const vigencia=(from:string,to:string|null)=>from>today?<Badge tone="info">A partir de {date(from)}</Badge>:to&&to<today?<Badge tone="neutral">Terminado</Badge>:<Badge tone="ok">Em vigor</Badge>;
 useEffect(()=>{
  const controller=new AbortController();
  fetch(API+'/cis/rh/definicoes?page='+page,{signal:controller.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const body=await r.json();if(!r.ok)throw new Error(body.message||'Não foi possível carregar as definições.');return body as Data;})
   .then(body=>setData(body)).catch(e=>{if(!controller.signal.aborted)setError(e.message);});
  return ()=>controller.abort();
 },[token,page,reload]);
 async function write(e:FormEvent,path:string,payload:unknown){
  e.preventDefault();setBusy(true);setError('');setNotice('');
  try{
   const r=await fetch(API+'/cis/rh/definicoes/'+path,{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(payload)});
   const body=await r.json().catch(()=>({}));
   if(!r.ok)throw new Error(Object.values(body.errors??{}).flat().join(' ')||body.message||'Não foi possível guardar.');
   setNotice('Versão guardada com a identificação do utilizador.');setPage(1);setReload(x=>x+1);setAdding(false);setForm(empty);
  }catch(err){setError((err as Error).message);}finally{setBusy(false);}
 }
 const field=(name:keyof typeof form,label:string,type='text',required=true)=>
  <label className="cis-profile-field"><span>{label}</span><input type={type} required={required} value={form[name]} onChange={e=>setForm({...form,[name]:e.target.value})}/></label>;
 return <section className="cis-panel cis-proposals">
  <div className="rh-toolbar">
   <div className="cis-home-tabs rh-tabs" role="tablist" aria-label="Definições de RH" style={{margin:0}}>
    {([['parametros','Parâmetros'],['irps','Escalões de IRPS'],['feriados','Feriados']] as const).map(([k,l])=><button key={k} type="button" role="tab" aria-selected={tab===k} className={tab===k?'selected':''} onClick={()=>{setTab(k);setAdding(false);}}>{l}</button>)}
   </div>
   {tab!=='feriados'&&<div className="rh-toolbar-end">{!adding?<button type="button" className="rh-primary" disabled={!data} onClick={()=>setAdding(true)}><Plus size={16}/> {tab==='parametros'?'Nova versão de parâmetro':'Nova tabela de IRPS'}</button>
    :<button type="button" className="cis-edit-action" onClick={()=>setAdding(false)}><X size={15}/> Fechar formulário</button>}</div>}
  </div>
  <p className="rh-note" style={{marginBottom:14}}>{tab==='parametros'?'Cada alteração cria uma nova versão com data de início; as anteriores ficam no histórico. Um parâmetro de colaborador prevalece sobre o de categoria, que prevalece sobre o de empresa e o geral.'
   :tab==='irps'?'Cada tabela vale a partir da sua data; a folha usa a tabela em vigor na data do período.':'O calendário de uma empresa substitui o geral nesse ano.'}</p>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {!data&&!error&&<p role="status">A carregar Definições de RH…</p>}
  {data&&tab==='feriados'&&<RhHolidayCalendar token={token}/>}
  {data&&tab==='parametros'&&<>
   {adding&&<form className="rh-form" onSubmit={e=>void write(e,'parametros',{...form,effective_to:form.effective_to||null})}>
    <div className="rh-form-head"><h4>Nova versão de parâmetro</h4></div>
    <div className="rh-fields">
     <label className="cis-profile-field"><span>Parâmetro</span><select required value={form.code} onChange={e=>setForm({...form,code:e.target.value,value:''})}><option value="">Seleccione</option>{Object.entries(data.catalogue).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label>
     <label className="cis-profile-field"><span>Âmbito</span><select value={form.scope} onChange={e=>setForm({...form,scope:e.target.value,company:'',category:'',employee_code:''})}><option value="global">Geral</option><option value="empresa">Empresa</option><option value="categoria">Categoria</option><option value="colaborador">Colaborador</option></select></label>
     {form.scope!=='global'&&field('company','Empresa')}{form.scope==='categoria'&&field('category','Categoria')}{form.scope==='colaborador'&&field('employee_code','Código de colaborador')}
     {form.code==='DIAS_TRABALHADOS_MODO'?<label className="cis-profile-field"><span>Preenchimento</span><select required value={form.value} onChange={e=>setForm({...form,value:e.target.value})}><option value="">Seleccione</option><option value="confirmar">Confirmar os dias manualmente</option><option value="base_mensal">Pré-preencher dias base nos períodos completos</option><option value="dias_uteis">Calcular dias úteis pelo calendário de feriados</option></select></label>:field('value','Valor')}{field('effective_from','Vigente desde','date')}{field('effective_to','Vigente até','date',false)}{field('reason','Justificação')}
    </div>
    <div className="cis-prop-actions" style={{marginTop:12}}><button type="submit" className="rh-primary" disabled={busy}><Save size={15}/> Guardar versão</button></div>
   </form>}
   {data.parameters.data.length===0?<Empty title="Sem parâmetros guardados." text="A folha usa os valores por omissão até registar a primeira versão."/>:<div className="cis-table-scroll"><table className="rh-table rh-cards">
    <thead><tr><th>Parâmetro</th><th>Âmbito</th><th>Valor</th><th>Vigência</th><th>Estado</th><th>Registado por</th><th>Justificação</th></tr></thead>
    <tbody>{data.parameters.data.map(p=><tr key={p.id}><td><strong>{data.catalogue[p.code]??p.code}</strong></td><td data-label="Âmbito">{({global:'Geral',empresa:'Empresa',categoria:'Categoria',colaborador:'Colaborador'} as Record<string,string>)[p.scope]??p.scope}{[p.company,p.category,p.employee_code].filter(Boolean).length?<span className="rh-note">{[p.company,p.category,p.employee_code].filter(Boolean).join(' · ')}</span>:null}</td>
     <td data-label="Valor"><strong>{p.value}</strong></td><td data-label="Vigência" className="nowrap">{date(p.effective_from)}{p.effective_to?' a '+date(p.effective_to):' em diante'}</td><td data-label="Estado">{vigencia(p.effective_from,p.effective_to)}</td><td data-label="Registado por">{p.author}</td><td data-label="Justificação" className="wrap">{p.reason}</td></tr>)}</tbody>
   </table></div>}
   <Pager page={page} last={data.parameters.last_page} onPage={setPage}/>
  </>}
  {data&&tab==='irps'&&<>
   {adding&&<form className="rh-form" onSubmit={e=>void write(e,'irps',{effective_from:taxDate,reason:taxReason,scales})}>
    <div className="rh-form-head"><h4>Nova tabela de IRPS</h4></div><p className="rh-note">Escalões contínuos (o limite inferior de cada um é o superior do anterior). Deixe em branco o limite superior do último.</p>
    <div className="rh-fields">
     <label className="cis-profile-field"><span>Vigente desde</span><input type="date" required value={taxDate} onChange={e=>setTaxDate(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Justificação</span><input required maxLength={255} value={taxReason} onChange={e=>setTaxReason(e.target.value)}/></label>
    </div>
    <div className="cis-table-scroll" style={{marginTop:12}}><table className="rh-table rh-scale"><thead><tr><th>#</th>{Object.values(labels).map(l=><th key={l}>{l}</th>)}<th></th></tr></thead><tbody>
     {scales.map((row,i)=><tr key={i}><td>{i+1}</td>{(Object.keys(labels) as (keyof Scale)[]).map(key=><td key={key}><input aria-label={labels[key]+' do escalão '+(i+1)} type="number" min="0" step={key==='coefficient'?'0.00000001':'0.01'} required={key!=='upper'||i<scales.length-1}
      value={row[key]??''} onChange={e=>setScales(scales.map((r,j)=>j===i?{...r,[key]:e.target.value||null}:r))}/></td>)}
      <td>{scales.length>1&&<button type="button" className="cis-edit-action" aria-label={'Remover escalão '+(i+1)} onClick={()=>setScales(scales.filter((_,j)=>i!==j))}><X size={14}/></button>}</td></tr>)}
    </tbody></table></div>
    <div className="cis-prop-actions" style={{marginTop:12}}><button type="button" className="cis-edit-action" disabled={scales.length>=100} onClick={()=>setScales([...scales,{...first,lower:scales[scales.length-1]?.upper??''}])}><Plus size={15}/> Adicionar escalão</button>
     <button type="submit" className="rh-primary" disabled={busy}><Save size={15}/> Guardar tabela</button></div>
   </form>}
   {data.tax_tables.length===0?<Empty title="Sem tabelas de IRPS guardadas."/>:data.tax_tables.map((t,k)=><details key={t.id} className="rh-card rh-details" open={k===0}><summary><strong>Tabela de {date(t.effective_from)}</strong> {k===0&&t.effective_from<=today?<Badge tone="ok">Mais recente</Badge>:null} <span className="rh-note" style={{display:'inline'}}>· {t.author} · {t.scales.length} escalão(ões)</span></summary>
    <p className="rh-note">{t.reason}</p><div className="cis-table-scroll"><table className="rh-table"><thead><tr>{Object.values(labels).map(label=><th key={label}>{label}</th>)}</tr></thead>
     <tbody>{t.scales.map((row,i)=><tr key={i}>{(Object.keys(labels) as (keyof Scale)[]).map(k=><td key={k} className="num">{row[k]??'Sem limite'}</td>)}</tr>)}</tbody></table></div>
   </details>)}
  </>}
 </section>;
}
