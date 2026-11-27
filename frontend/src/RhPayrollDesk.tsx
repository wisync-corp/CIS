import {useEffect,useState,type FormEvent} from 'react';
import {Calculator,CheckCircle2,Plus,Save} from 'lucide-react';
import {Badge,Chips,Empty,Pager,Person,date,money,monthName} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
type Preview={input:Record<string,string|number|boolean>;amounts:Record<string,string>;rules:{reference:string;tax_table:string|null};signature:string};
type Line={id:number;employee_id:number;name:string;code:string;company:string;status:string;version:number;input:Preview['input'];calculation:Omit<Preview,'amounts'>&{amounts:Preview['amounts']|null}};
type Candidate=Employee&{eligible:boolean;reason:string|null};
type Employee={id:number;name:string;code:string;company:string};
const numbers={
 days_worked:'Dias trabalhados',hours_worked:'Horas trabalhadas',unjustified_absences:'Faltas injustificadas',
 overtime:'Horas extras (MT)',allowances:'Subsídios (MT)',bonus:'Bónus (MT)',other_earnings:'Outros acréscimos (MT)',
 other_deductions:'Outros descontos (MT)',dependents:'Dependentes',
};
const amounts:Record<string,string>={gross:'Vencimento',earnings:'Acréscimos',absence_deduction:'Desconto por faltas',base:'Vencimento base',
 employee_inss:'INSS colaborador',employer_inss:'INSS empresa',total_inss:'Total INSS',irps:'IRPS',net:'Salário líquido'};
const blank={days_worked:'',hours_worked:'0',unjustified_absences:'0',overtime:'0',allowances:'0',bonus:'0',other_earnings:'0',other_deductions:'0',dependents:'0',department:'',contribute:false};
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit'}).format(new Date()).slice(0,7);

export default function RhPayrollDesk({token}:{token:string}){
 const [period,setPeriod]=useState(today),[rows,setRows]=useState<Line[]>([]),[page,setPage]=useState(1),[lastPage,setLastPage]=useState(1),[reload,setReload]=useState(0);
 const [search,setSearch]=useState(''),[found,setFound]=useState<Employee[]>([]),[employee,setEmployee]=useState<Employee|null>(null),[edit,setEdit]=useState<Line|null>(null);
 const [form,setForm]=useState(blank),[preview,setPreview]=useState<Preview|null>(null),[closing,setClosing]=useState<Line|null>(null);
 const [periodStatus,setPeriodStatus]=useState<'por_abrir'|'aberto'|'fechado'|null>(null);
 const [monthConfirm,setMonthConfirm]=useState(false),[restoreRow,setRestoreRow]=useState<Line|null>(null),[progress,setProgress]=useState('');
 const [issues,setIssues]=useState<{id:number;message:string}[]>([]);
 const [checking,setChecking]=useState(false),[candidatePage,setCandidatePage]=useState(1),[candidates,setCandidates]=useState<{data:Candidate[];last_page:number}|null>(null);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 const [formOpen,setFormOpen]=useState(false),[stateFilter,setStateFilter]=useState<'todos'|'por_conferir'|'pendente'|'alterado'|'finalizado'>('todos');
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 useEffect(()=>{
  if(!period)return;
  const c=new AbortController();setRows([]);setPeriodStatus(null);
  fetch(API+'/cis/rh/folha?'+new URLSearchParams({period,page:String(page)}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d;})
   .then(d=>{if(!c.signal.aborted){setRows(d.data);setLastPage(d.last_page);setPeriodStatus(d.period_status);}})
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
 useEffect(()=>{
  if(!checking||periodStatus!=='aberto')return;
  const controller=new AbortController();setCandidates(null);
  const qs=new URLSearchParams({period,page:String(candidatePage)});
  fetch(API+'/cis/rh/folha/mes/candidatos?'+qs,{
   signal:controller.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}
  }).then(async r=>{const body=await r.json();if(!r.ok)throw new Error(body.message);return body;})
   .then(body=>{if(!controller.signal.aborted)setCandidates(body);})
   .catch(e=>{if(!controller.signal.aborted)setError(e.message);});
  return ()=>controller.abort();
 },[token,period,periodStatus,checking,candidatePage,reload]);
 const reset=()=>{setFormOpen(false);setEdit(null);setEmployee(null);setSearch('');setForm(blank);setPreview(null);setClosing(null);};
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
 async function periodAction(action:'abrir'|'fechar'){
  if(busy||!period)return;
  setBusy(true);setError('');setNotice('');
  try{await call('/mes/'+action,{period,...(action==='fechar'?{confirm:true}:{})});setMonthConfirm(false);reset();setReload(v=>v+1);setNotice(action==='abrir'?'Mês aberto.':'Mês fechado.');}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function generate(){
  if(busy||periodStatus!=='aberto')return;
  setBusy(true);setError('');setNotice('');setIssues([]);setProgress('A preparar lançamentos…');
  let next:number|null=0,total=0;
  const errors:{id:number;message:string}[]=[];
  try{
   while(next!==null){
    const batch=await call('/mes/gerar',{period,after:next});
    total+=batch.created;errors.push(...batch.issues);next=batch.next;
    setProgress(total+' lançamento(s) preparados.');
   }
   setNotice(total+' lançamento(s) por conferir · '+errors.length+' colaborador(es) por resolver.');
  }catch(e){setError((e as Error).message);}
  finally{setIssues(errors);setProgress('');setReload(v=>v+1);setBusy(false);}
 }
 async function recalculate(){
  if(busy||periodStatus!=='aberto')return;
  setBusy(true);setError('');setNotice('');setIssues([]);setProgress('A carregar os lançamentos pendentes…');
  let next:number|null=0,changed=0,skipped=0;
  const errors:{id:number;message:string}[]=[];
  try{
   while(next!==null){
    const d=await call('/mes/recalcular',{period,after:next});
    changed+=d.changed;skipped+=d.skipped;errors.push(...d.issues);next=d.next;
    setProgress('Pendentes verificados '+(changed+skipped+errors.length));
   }
   setNotice(changed+' recalculado(s) · '+skipped+' sem alteração · '+errors.length+' por conferir.');
  }catch(e){setError((e as Error).message);}
  finally{setIssues(errors);setProgress('');setReload(v=>v+1);setBusy(false);}
 }
 async function restore(){
  if(!restoreRow||busy)return;
  setBusy(true);setError('');setNotice('');
  try{await call('/'+restoreRow.id+'/repor',{version:restoreRow.version,confirm:true});setRestoreRow(null);reset();setReload(v=>v+1);setNotice('Versão inicial reposta. O lançamento voltou a Pendente.');}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 function loadEdit(row:Line){
  setEdit(row);setEmployee({id:row.employee_id,name:row.name,code:row.code,company:row.company});setSearch(row.name+' · '+row.code);
  const values={...blank};for(const k of Object.keys(blank) as (keyof typeof blank)[]){
   if(k==='contribute')values.contribute=Boolean(row.input[k]);
   else values[k]=String(row.input[k]??(!row.calculation.amounts&&k==='hours_worked'?'':blank[k]));
  }setForm(values);setPreview(null);setClosing(null);setFormOpen(true);
 }
 const keyOf=(r:Line)=>r.calculation.amounts?r.status:'por_conferir';
 const count=(k:string)=>rows.filter(r=>keyOf(r)===k).length;
 const n={por_conferir:count('por_conferir'),pendente:count('pendente'),alterado:count('alterado'),finalizado:count('finalizado')};
 const net=rows.reduce((a,r)=>a+(r.calculation.amounts?Number(r.calculation.amounts.net):0),0);
 const base=rows.reduce((a,r)=>a+(r.calculation.amounts?Number(r.calculation.amounts.base):0),0);
 const shown=stateFilter==='todos'?rows:rows.filter(r=>keyOf(r)===stateFilter);
 const open=periodStatus==='aberto',closed=periodStatus==='fechado';
 const steps=[
  {t:'Abrir o mês',d:periodStatus==='por_abrir'?'O mês ainda não foi aberto.':'Mês aberto.',done:periodStatus!=null&&periodStatus!=='por_abrir',
   act:periodStatus==='por_abrir'&&<button type="button" className="rh-primary" disabled={busy} onClick={()=>void periodAction('abrir')}>Abrir mês</button>},
  {t:'Gerar lançamentos',d:rows.length?rows.length+' lançamento(s) criados.':'A partir dos contratos aprovados.',done:closed||rows.length>0,
   act:open&&<button type="button" className="cis-edit-action" disabled={busy} onClick={()=>void generate()}>Gerar lançamentos</button>},
  {t:'Conferir e calcular',d:n.por_conferir+n.pendente?(n.por_conferir+' por conferir · '+n.pendente+' pendente(s)'):'Tudo conferido.',done:closed||(rows.length>0&&n.por_conferir+n.pendente===0),
   act:open&&<button type="button" className="cis-edit-action" disabled={busy} onClick={()=>void recalculate()}>Recalcular pendentes</button>},
  {t:'Finalizar',d:rows.length?(n.finalizado+' de '+rows.length+' finalizados'):'—',done:closed||(rows.length>0&&n.finalizado===rows.length),act:null},
  {t:'Fechar o mês',d:closed?'Mês fechado.':'Exige todos os lançamentos finalizados.',done:closed,
   act:open&&<button type="button" className="cis-edit-action" disabled={busy||!rows.length||n.finalizado!==rows.length} title={n.finalizado!==rows.length?'Finalize todos os lançamentos primeiro':undefined} onClick={()=>setMonthConfirm(true)}>Fechar mês</button>},
 ];
 const current=steps.findIndex(x=>!x.done);
 const field=(k:keyof typeof numbers)=><label key={k} className="cis-profile-field"><span>{numbers[k]}</span><input type="number" min="0" step={k==='dependents'?'1':'0.01'} required value={form[k]} onChange={e=>{setForm({...form,[k]:e.target.value});setPreview(null);}}/></label>;
 return <section className="cis-panel cis-proposals">
  <div className="rh-toolbar">
   <label className="cis-profile-field" style={{margin:0}}><span>Mês de referência</span><input type="month" value={period} disabled={busy} onChange={e=>{setPeriod(e.target.value);setPage(1);reset();setError('');setNotice('');setIssues([]);setMonthConfirm(false);setRestoreRow(null);setChecking(false);setCandidatePage(1);setStateFilter('todos');}}/></label>
   <div><strong style={{display:'block',fontSize:18}}>{monthName(period)}</strong><small className="rh-note">Período de 21 do mês anterior a 20 de {monthName(period).split(' de ')[0].toLowerCase()}.</small></div>
   <div className="rh-toolbar-end">{periodStatus&&<Badge state={periodStatus}/>}</div>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {progress&&<p role="status" className="cis-msg-notice">{progress}</p>}
  {!!issues.length&&<div className="cis-prop-decision" role="alert"><h4>{issues.length} lançamento(s) por resolver</h4><ul>{issues.map(x=><li key={x.id}>Lançamento #{x.id}: {x.message}</li>)}</ul></div>}
  {periodStatus&&<ol className="rh-steps" aria-label="Etapas do processamento do mês">{steps.map((x,i)=><li key={x.t} className={x.done?'done':i===current?'current':''} aria-current={i===current?'step':undefined}>
   <strong>{x.t}</strong><small>{x.d}</small>{x.act}</li>)}</ol>}
  {open&&<div className="rh-toolbar"><button type="button" className="cis-edit-action" disabled={busy} aria-expanded={checking} onClick={()=>{setCandidatePage(1);setChecking(x=>!x);}}>{checking?'Ocultar colaboradores sem lançamento':'Colaboradores sem lançamento'}</button>
   <button type="button" className="cis-edit-action" disabled={busy||formOpen} onClick={()=>{reset();setFormOpen(true);}}><Plus size={15}/> Lançamento manual</button></div>}
  {checking&&open&&<div className="cis-prop-decision">
   <h4>Colaboradores sem lançamento no mês</h4>
   {candidates?<>{candidates.data.length===0?<p>Todos os colaboradores elegíveis têm lançamento.</p>:<div className="cis-table-scroll"><table className="rh-table">
    <thead><tr><th>Colaborador</th><th>Situação</th><th></th></tr></thead>
    <tbody>{candidates.data.map(e=><tr key={e.id}><td><Person name={e.name} code={e.code} company={e.company}/></td>
     <td>{e.eligible?<Badge tone="info">Aguarda dados de presença</Badge>:<Badge tone="bad" title={e.reason??''}>{e.reason}</Badge>}</td><td>{e.eligible&&<div className="rh-row-actions"><button type="button" className="cis-edit-action" disabled={busy}
      onClick={()=>{reset();setEmployee(e);setSearch(e.name+' · '+e.code);setChecking(false);setFormOpen(true);}}>Preparar lançamento</button></div>}</td></tr>)}</tbody>
   </table></div>}
   <Pager page={candidatePage} last={candidates.last_page} onPage={setCandidatePage}/></>:!error&&<p role="status">A conferir contratos e lançamentos…</p>}
  </div>}
  {monthConfirm&&<div className="cis-prop-decision"><p>Fechar a folha de {monthName(period)}? Depois de fechada, os lançamentos ficam só para consulta.</p>
   <div className="cis-prop-actions"><button type="button" className="primary" disabled={busy} onClick={()=>void periodAction('fechar')}>Confirmar fecho mensal</button><button type="button" disabled={busy} onClick={()=>setMonthConfirm(false)}>Cancelar</button></div>
  </div>}
  {open&&formOpen&&<form className="rh-form" onSubmit={e=>void calculate(e)}>
   <div className="rh-form-head"><h4>{edit?'Editar lançamento de '+edit.name:'Lançamento manual'}</h4><button type="button" className="cis-edit-action" onClick={reset}>Fechar</button></div>
   <fieldset disabled={busy}>
    <fieldset><legend>Colaborador</legend><div className="rh-fields">
     <label className="cis-profile-field" style={{gridColumn:'span 2'}}><span>Nome ou código</span><input value={search} required disabled={!!edit} onChange={e=>{setSearch(e.target.value);setEmployee(null);setPreview(null);}} placeholder="Pesquise por nome ou código"/></label>
     <label className="cis-profile-field"><span>Departamento</span><input required value={form.department} onChange={e=>{setForm({...form,department:e.target.value});setPreview(null);}}/></label>
     {field('dependents')}
    </div>
    {!employee&&found.length>0&&<div className="cis-prop-actions">{found.map(x=><button type="button" key={x.id} onClick={()=>{setEmployee(x);setSearch(x.name+' · '+x.code+' · '+x.company);setPreview(null);}}>{x.name} · {x.code} · {x.company}</button>)}</div>}
    <label className="cis-collections-check"><input type="checkbox" checked={form.contribute} onChange={e=>{setForm({...form,contribute:e.target.checked});setPreview(null);}}/> Canalizar INSS</label></fieldset>
    <fieldset><legend>Presença</legend><div className="rh-fields">{(['days_worked','hours_worked','unjustified_absences'] as const).map(field)}</div></fieldset>
    <fieldset><legend>Acréscimos</legend><div className="rh-fields">{(['overtime','allowances','bonus','other_earnings'] as const).map(field)}</div></fieldset>
    <fieldset><legend>Descontos</legend><div className="rh-fields">{field('other_deductions')}</div></fieldset>
    <div className="cis-prop-actions"><button type="submit" className="rh-primary" disabled={!employee||!period}><Calculator size={15}/> Calcular prévia</button><button type="button" className="cis-edit-action" onClick={()=>{setForm(blank);setPreview(null);}}>Limpar valores</button></div>
   </fieldset>
   {preview&&<div style={{marginTop:16}}>
    <h4>Prévia do cálculo</h4><p className="rh-note">Regras de {date(preview.rules.reference)} · Contrato #{String(preview.input.contract_id)}{preview.input.amendment_id?' · Adenda #'+preview.input.amendment_id:''}</p>
    <dl className="rh-preview">{Object.entries(amounts).map(([k,label])=><div key={k} className={k==='net'?'net':undefined}><dt>{label}</dt><dd>{money(preview.amounts[k])}</dd></div>)}</dl>
    <button className="rh-primary" type="button" disabled={busy} onClick={()=>void save()}><Save size={15}/> Guardar lançamento</button>
   </div>}
  </form>}
  {rows.length>0&&<div className="rh-sum" aria-label="Resumo do mês">
   <div><small>Lançamentos</small><b>{rows.length}</b></div><div><small>Por conferir</small><b>{n.por_conferir}</b></div><div><small>Pendentes</small><b>{n.pendente}</b></div>
   <div><small>Alterados</small><b>{n.alterado}</b></div><div><small>Finalizados</small><b>{n.finalizado}</b></div><div><small>Salário líquido{lastPage>1?' (esta página)':''}</small><b>{money(net)}</b></div>
  </div>}
  {rows.length>0&&<div className="rh-toolbar"><Chips label="Filtrar por estado" value={stateFilter} onChange={setStateFilter} items={[
   {id:'todos',label:'Todos',n:rows.length},{id:'por_conferir',label:'Por conferir',n:n.por_conferir},{id:'pendente',label:'Pendentes',n:n.pendente},{id:'alterado',label:'Alterados',n:n.alterado},{id:'finalizado',label:'Finalizados',n:n.finalizado}]}/></div>}
  {!rows.length?<Empty title={periodStatus==='por_abrir'?'O mês ainda não foi aberto.':'Sem lançamentos neste mês.'} text={periodStatus==='aberto'?'Use «Gerar lançamentos» para criar os lançamentos a partir dos contratos aprovados.':undefined}/>
  :<div className="cis-table-scroll rh-scroll"><table className="rh-table rh-cards"><thead><tr><th>Colaborador</th><th>Estado</th><th className="num">Vencimento base</th><th className="num">Salário líquido</th><th></th></tr></thead>
   <tbody>{shown.map(row=><tr key={row.id}><td><Person name={row.name} code={row.code} company={row.company}/></td><td data-label="Estado"><Badge state={keyOf(row)}/></td>
    <td className="num" data-label="Vencimento base">{row.calculation.amounts?money(row.calculation.amounts.base):'—'}</td><td className="num" data-label="Salário líquido"><strong>{row.calculation.amounts?money(row.calculation.amounts.net):'—'}</strong></td>
    <td>{open&&row.status!=='finalizado'&&<div className="rh-row-actions">{row.calculation.amounts?<><button type="button" className="cis-edit-action" disabled={busy} onClick={()=>loadEdit(row)}>Editar</button>
     <button type="button" className="cis-edit-action" disabled={busy} onClick={()=>setClosing(row)}><CheckCircle2 size={15}/> Finalizar</button>
     {row.status==='alterado'&&<button type="button" className="cis-edit-action" disabled={busy} onClick={()=>setRestoreRow(row)}>Repor inicial</button>}</>
     :<button type="button" className="rh-primary" disabled={busy} onClick={()=>loadEdit(row)}>Conferir e calcular</button>}</div>}</td></tr>)}</tbody>
   {stateFilter==='todos'&&<tfoot><tr><td>Totais{lastPage>1?' (esta página)':''}</td><td></td><td className="num" data-label="Vencimento base">{money(base)}</td><td className="num" data-label="Salário líquido">{money(net)}</td><td></td></tr></tfoot>}
  </table></div>}
  <Pager page={page} last={lastPage} onPage={setPage}/>
  {restoreRow&&<div className="cis-prop-decision"><p>Repor os valores da primeira versão de {restoreRow.name}? O lançamento volta a Pendente.</p><div className="cis-prop-actions"><button className="primary" type="button" disabled={busy} onClick={()=>void restore()}>Confirmar reposição</button><button type="button" disabled={busy} onClick={()=>setRestoreRow(null)}>Cancelar</button></div></div>}
  {closing&&<div className="cis-prop-decision"><h4>Finalizar lançamento de {closing.name}</h4><p>Salário líquido {closing.calculation.amounts?money(closing.calculation.amounts.net):'—'}. O lançamento ficará fechado para edição.</p>
   <div className="cis-prop-actions"><button type="button" className="primary" disabled={busy} onClick={()=>void finalize()}>Confirmar finalização</button><button type="button" disabled={busy} onClick={()=>setClosing(null)}>Cancelar</button></div></div>}
 </section>;
}
