import {useEffect,useState,type FormEvent} from 'react';
import {Calculator,CheckCircle2,Save} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Preview={input:Record<string,string|number|boolean>;amounts:Record<string,string>;rules:{reference:string;tax_table:string|null};signature:string};
type Line={id:number;employee_id:number;name:string;code:string;company:string;status:string;version:number;input:Preview['input'];calculation:Preview};
type Employee={id:number;name:string;code:string;company:string};
const numbers={
 days_worked:'Dias trabalhados',hours_worked:'Horas trabalhadas',unjustified_absences:'Faltas injustificadas',
 overtime:'Horas extras (MT)',allowances:'Subsídios (MT)',bonus:'Bónus (MT)',other_earnings:'Outros acréscimos (MT)',
 other_deductions:'Outros descontos (MT)',dependents:'Dependentes',
};
const amounts:Record<string,string>={gross:'Vencimento',earnings:'Acréscimos',absence_deduction:'Desconto por faltas',base:'Vencimento base',
 employee_inss:'INSS colaborador',employer_inss:'INSS empresa',total_inss:'Total INSS',irps:'IRPS',net:'Salário líquido'};
const blank={days_worked:'',hours_worked:'0',unjustified_absences:'0',overtime:'0',allowances:'0',bonus:'0',other_earnings:'0',other_deductions:'0',dependents:'0',department:'',contribute:false};
const money=(v:string)=>Number(v).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit'}).format(new Date()).slice(0,7);

export default function RhPayrollDesk({token}:{token:string}){
 const [period,setPeriod]=useState(today),[rows,setRows]=useState<Line[]>([]),[page,setPage]=useState(1),[lastPage,setLastPage]=useState(1),[reload,setReload]=useState(0);
 const [search,setSearch]=useState(''),[found,setFound]=useState<Employee[]>([]),[employee,setEmployee]=useState<Employee|null>(null),[edit,setEdit]=useState<Line|null>(null);
 const [form,setForm]=useState(blank),[preview,setPreview]=useState<Preview|null>(null),[closing,setClosing]=useState<Line|null>(null);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 useEffect(()=>{
  if(!period)return;
  const c=new AbortController();setRows([]);
  fetch(API+'/cis/rh/folha?'+new URLSearchParams({period,page:String(page)}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d;})
   .then(d=>{if(!c.signal.aborted){setRows(d.data);setLastPage(d.last_page);}})
   .catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[token,period,page,reload]);
 useEffect(()=>{
  if(!search.trim()||employee){setFound([]);return;}
  const c=new AbortController();
  const timer=window.setTimeout(()=>{
   fetch(API+'/cis/rh/registos?'+new URLSearchParams({q:search.trim()}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
    .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d;})
    .then(d=>{if(!c.signal.aborted)setFound(d.employees.data);}).catch(e=>{if(!c.signal.aborted)setError(e.message);});
  },300);
  return ()=>{c.abort();window.clearTimeout(timer);};
 },[token,search,employee]);
 const reset=()=>{setEdit(null);setEmployee(null);setSearch('');setForm(blank);setPreview(null);setClosing(null);};
 async function call(path:string,body:unknown){
  const r=await fetch(API+'/cis/rh/folha'+path,{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify(body)});
  const d=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message||'Não foi possível concluir a operação.');
  return d;
 }
 async function calculate(e:FormEvent){
  e.preventDefault();if(!employee||busy)return;
  setBusy(true);setError('');setNotice('');setPreview(null);
  try{setPreview(await call('/previa',{...form,period,employee_id:employee.id}));}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function save(){
  if(!preview||!employee||busy)return;
  setBusy(true);setError('');
  try{await call('',{...form,period,employee_id:employee.id,version:edit?.version,signature:preview.signature});reset();setReload(v=>v+1);setNotice('Lançamento guardado com as regras aplicadas.');}
  catch(e){setError((e as Error).message);setPreview(null);}finally{setBusy(false);}
 }
 async function finalize(){
  if(!closing||busy)return;
  setBusy(true);setError('');
  try{await call('/'+closing.id+'/finalizar',{version:closing.version,confirm:true});setClosing(null);setReload(v=>v+1);setNotice('Lançamento finalizado.');}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 function loadEdit(row:Line){
  setEdit(row);setEmployee({id:row.employee_id,name:row.name,code:row.code,company:row.company});setSearch(row.name+' · '+row.code);
  const values={...blank};for(const k of Object.keys(blank) as (keyof typeof blank)[]){
   if(k==='contribute')values.contribute=Boolean(row.input[k]);
   else values[k]=String(row.input[k]??blank[k]);
  }setForm(values);setPreview(null);setClosing(null);
 }
 return <section className="cis-panel cis-proposals">
  <div className="cis-prop-head"><div><h3>Folha salarial</h3><p>Lançamentos por colaborador para o período de 21 a 20.</p></div>
   <label className="cis-profile-field"><span>Mês de referência</span><input type="month" value={period} disabled={busy} onChange={e=>{setPeriod(e.target.value);setPage(1);reset();setError('');}}/></label>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  <form className="cis-prop-decision" onSubmit={e=>void calculate(e)}>
   <h4>{edit?'Editar lançamento':'Novo lançamento'}</h4>
   <fieldset disabled={busy} style={{border:0,padding:0,minWidth:0}}>
    <div className="cis-profile-fields">
     <label className="cis-profile-field"><span>Colaborador</span><input value={search} required disabled={!!edit} onChange={e=>{setSearch(e.target.value);setEmployee(null);setPreview(null);}} placeholder="Pesquise por nome ou código"/></label>
     {!employee&&found.length>0&&<div className="cis-prop-actions">{found.map(x=><button type="button" key={x.id} onClick={()=>{setEmployee(x);setSearch(x.name+' · '+x.code+' · '+x.company);setPreview(null);}}>{x.name} · {x.code} · {x.company}</button>)}</div>}
     {(Object.keys(numbers) as (keyof typeof numbers)[]).map(k=><label key={k} className="cis-profile-field"><span>{numbers[k]}</span><input type="number" min="0" step={k==='dependents'?'1':'0.01'} required value={form[k]} onChange={e=>{setForm({...form,[k]:e.target.value});setPreview(null);}}/></label>)}
     <label className="cis-profile-field"><span>Departamento</span><input required value={form.department} onChange={e=>{setForm({...form,department:e.target.value});setPreview(null);}}/></label>
     <label className="cis-collections-check"><input type="checkbox" checked={form.contribute} onChange={e=>{setForm({...form,contribute:e.target.checked});setPreview(null);}}/> Canalizar INSS</label>
    </div>
    <div className="cis-prop-actions"><button type="submit" disabled={!employee||!period}><Calculator size={15}/> Calcular prévia</button><button type="button" onClick={reset}>Limpar</button></div>
   </fieldset>
  </form>
  {preview&&<div className="cis-prop-decision">
   <h4>Prévia do cálculo</h4><p>Data das regras {preview.rules.reference} · Contrato #{String(preview.input.contract_id)}{preview.input.amendment_id?' · Adenda #'+preview.input.amendment_id:''}</p>
   <dl>{Object.entries(amounts).map(([k,label])=><div key={k}><dt>{label}</dt><dd>{money(preview.amounts[k])}</dd></div>)}</dl>
   <button className="cis-edit-action" type="button" disabled={busy} onClick={()=>void save()}><Save size={15}/> Guardar lançamento</button>
  </div>}
  {!rows.length?<p>Sem lançamentos neste mês.</p>:<div className="cis-table-scroll"><table><thead><tr><th>Colaborador</th><th>Empresa</th><th>Estado</th><th>Vencimento base</th><th>Salário líquido</th><th>Acções</th></tr></thead>
   <tbody>{rows.map(row=><tr key={row.id}><td>{row.name} · {row.code}</td><td>{row.company}</td><td>{row.status}</td><td>{money(row.calculation.amounts.base)}</td><td>{money(row.calculation.amounts.net)}</td>
    <td>{row.status!=='finalizado'&&<div className="cis-prop-actions"><button type="button" disabled={busy} onClick={()=>loadEdit(row)}>Editar</button><button type="button" disabled={busy} onClick={()=>setClosing(row)}><CheckCircle2 size={15}/> Finalizar</button></div>}</td></tr>)}</tbody></table></div>}
  {lastPage>1&&<div className="cis-prop-actions"><button type="button" disabled={page===1} onClick={()=>setPage(v=>v-1)}>Anterior</button><span>{page} / {lastPage}</span><button type="button" disabled={page===lastPage} onClick={()=>setPage(v=>v+1)}>Seguinte</button></div>}
  {closing&&<div className="cis-prop-decision"><h4>Finalizar lançamento de {closing.name}</h4><p>Salário líquido {money(closing.calculation.amounts.net)}. O lançamento ficará fechado para edição.</p>
   <div className="cis-prop-actions"><button type="button" className="primary" disabled={busy} onClick={()=>void finalize()}>Confirmar fecho</button><button type="button" disabled={busy} onClick={()=>setClosing(null)}>Cancelar</button></div></div>}
 </section>;
}
