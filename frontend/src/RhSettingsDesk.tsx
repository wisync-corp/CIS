import {useEffect,useState,type FormEvent} from 'react';
import {Save,Settings} from 'lucide-react';

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
 const [scales,setScales]=useState<Scale[]>([first]),[tab,setTab]=useState<'parametros'|'irps'>('parametros');
 const [reload,setReload]=useState(0);
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
   setNotice('Versão guardada com a identificação do utilizador.');setPage(1);setReload(x=>x+1);
  }catch(err){setError((err as Error).message);}finally{setBusy(false);}
 }
 const field=(name:keyof typeof form,label:string,type='text',required=true)=>
  <label className="cis-profile-field"><span>{label}</span><input type={type} required={required} value={form[name]} onChange={e=>setForm({...form,[name]:e.target.value})}/></label>;
 return <section className="cis-panel cis-proposals">
  <div className="cis-prop-head"><div><h3><Settings size={17}/> Definições de RH</h3><p>Parâmetros por âmbito e tabelas de IRPS com vigência e histórico.</p></div></div>
  <div className="cis-prop-actions">
   <button type="button" aria-pressed={tab==='parametros'} onClick={()=>setTab('parametros')}>Parâmetros</button>
   <button type="button" aria-pressed={tab==='irps'} onClick={()=>setTab('irps')}>Escalões de IRPS</button>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {!data&&!error&&<p role="status">A carregar Definições de RH…</p>}
  {data&&tab==='parametros'&&<>
   <form className="cis-prop-decision" onSubmit={e=>void write(e,'parametros',{...form,effective_to:form.effective_to||null})}>
    <h4>Registar versão de um parâmetro</h4>
    <div className="cis-profile-fields">
     <label className="cis-profile-field"><span>Parâmetro</span><select required value={form.code} onChange={e=>setForm({...form,code:e.target.value})}><option value="">Seleccione</option>{Object.entries(data.catalogue).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label>
     <label className="cis-profile-field"><span>Âmbito</span><select value={form.scope} onChange={e=>setForm({...form,scope:e.target.value,company:'',category:'',employee_code:''})}><option value="global">Geral</option><option value="empresa">Empresa</option><option value="categoria">Categoria</option><option value="colaborador">Colaborador</option></select></label>
     {form.scope!=='global'&&field('company','Empresa')}{form.scope==='categoria'&&field('category','Categoria')}{form.scope==='colaborador'&&field('employee_code','Código de colaborador')}
     {field('value','Valor')}{field('effective_from','Vigente desde','date')}{field('effective_to','Vigente até','date',false)}{field('reason','Justificação')}
    </div>
    <button type="submit" className="cis-edit-action" disabled={busy}><Save size={15}/> Guardar versão</button>
   </form>
   {data.parameters.data.length===0?<p>Sem parâmetros guardados.</p>:<div className="cis-table-scroll"><table>
    <thead><tr><th>Parâmetro</th><th>Âmbito</th><th>Valor</th><th>Vigência</th><th>Registado por</th><th>Justificação</th></tr></thead>
    <tbody>{data.parameters.data.map(p=><tr key={p.id}><td>{data.catalogue[p.code]}</td><td>{[p.scope,p.company,p.category,p.employee_code].filter(Boolean).join(' · ')}</td><td>{p.value}</td><td>{p.effective_from}{p.effective_to?' a '+p.effective_to:''}</td><td>{p.author}</td><td>{p.reason}</td></tr>)}</tbody>
   </table></div>}
   {data.parameters.last_page>1&&<div className="cis-prop-actions"><button type="button" disabled={page<=1} onClick={()=>setPage(x=>x-1)}>Anterior</button><span>{page} / {data.parameters.last_page}</span><button type="button" disabled={page>=data.parameters.last_page} onClick={()=>setPage(x=>x+1)}>Seguinte</button></div>}
  </>}
  {data&&tab==='irps'&&<>
   <form className="cis-prop-decision" onSubmit={e=>void write(e,'irps',{effective_from:taxDate,reason:taxReason,scales})}>
    <h4>Registar tabela de IRPS</h4><p>Introduza escalões contínuos. Deixe em branco o limite superior do último escalão.</p>
    <div className="cis-profile-fields">
     <label className="cis-profile-field"><span>Vigente desde</span><input type="date" required value={taxDate} onChange={e=>setTaxDate(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Justificação</span><input required maxLength={255} value={taxReason} onChange={e=>setTaxReason(e.target.value)}/></label>
    </div>
    {scales.map((row,i)=><fieldset key={i} className="cis-prop-decision"><legend>Escalão {i+1}</legend>
     <div className="cis-profile-fields">{(Object.keys(labels) as (keyof Scale)[]).map(key=><label className="cis-profile-field" key={key}><span>{labels[key]}</span><input type="number" min="0" step={key==='coefficient'?'0.00000001':'0.01'} required={key!=='upper'||i<scales.length-1}
      value={row[key]??''} onChange={e=>setScales(scales.map((r,j)=>j===i?{...r,[key]:e.target.value||null}:r))}/></label>)}</div>
     {scales.length>1&&<button type="button" className="cis-edit-action" onClick={()=>setScales(scales.filter((_,j)=>i!==j))}>Remover escalão</button>}
    </fieldset>)}
    <div className="cis-prop-actions"><button type="button" disabled={scales.length>=100} onClick={()=>setScales([...scales,{...first,lower:''}])}>Adicionar escalão</button>
     <button type="submit" className="primary" disabled={busy}><Save size={15}/> Guardar tabela</button></div>
   </form>
   {data.tax_tables.length===0?<p>Sem tabelas de IRPS guardadas.</p>:data.tax_tables.map(t=><details key={t.id}><summary>Vigente desde {t.effective_from} · {t.author} · {t.scales.length} escalão(ões)</summary>
    <p>{t.reason}</p><div className="cis-table-scroll"><table><thead><tr>{Object.values(labels).map(label=><th key={label}>{label}</th>)}</tr></thead>
     <tbody>{t.scales.map((row,i)=><tr key={i}>{(Object.keys(labels) as (keyof Scale)[]).map(k=><td key={k}>{row[k]??'Sem limite'}</td>)}</tr>)}</tbody></table></div>
   </details>)}
  </>}
 </section>;
}
