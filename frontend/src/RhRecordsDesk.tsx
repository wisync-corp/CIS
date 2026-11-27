import RhEmployeeProfile from './RhEmployeeProfile';
import {useCan} from './perms';
import {useEffect,useState,type FormEvent,type ReactNode} from 'react';
import {Plus} from 'lucide-react';
import RhOverview from './rh/RhOverview';
import {Badge,Chips,Empty,Pager,Person,SearchBar,daysBetween,date,money} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
export const rhSections=[
 ['painel','Visão geral de RH'],['colaboradores','Colaboradores'],['contratos','Contratos de trabalho'],['adendas','Adendas'],
 ['ferias','Férias'],['indemnizacoes','Indemnizações'],['prestacoes','Prestações de indemnização'],
] as const;
export type RhSection=typeof rhSections[number][0];
const titles=Object.fromEntries(rhSections);
type Account={id:number;name:string;email:string;active?:boolean|number};
type Employee={user?:Account|null;id:number;name:string;company:string;code:string;admission:string;category:string;status:string};
type Row={payment_totals?:{planned:number;paid:number;balance:number};review_status?:'pendente'|'aprovado'|'rejeitado'|null;revision?:number;decision_note?:string|null;id:number;reference_date:string;parent_id:number|null;name:string;company:string;code:string;details:Record<string,string|null>};
type Page<T>={data:T[];current_page:number;last_page:number};
type Response={employees:Page<Employee>;records:Page<Row>|null;totals:Partial<Record<RhSection,number>>};
const names:Record<string,string>={salary_base:'Vencimento base (MT)',years:'Anos completos',paid_on:'Pago em',payment_reference:'Comprovativo',end:'Data final',regime:'Regime',rate:'Valor unitário (MT)',amount:'Montante (MT)',text:'Descrição'};
const mt=(value:string)=>Number(value).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const details=(row:Row)=>Object.entries(row.details).filter(([key,v])=>key!=='plan_signature'&&v!=null&&v!=='').map(([key,value])=>names[key]+': '+(['rate','amount','salary_base'].includes(key)?mt(value!):value)).join(' · ');
const blank={name:'',company:'',code:'',category:'',admission:'',status:'activo',employee_id:'',parent_id:'',reference_date:'',end:'',regime:'mensal',rate:'',amount:'',text:'',salary_base:'',months:'1',due_day:'',first_month:''};

function RhUserLink({employee,token,onClose,onSaved}:{employee:Employee;token:string;onClose:()=>void;onSaved:()=>void}){
 const [query,setQuery]=useState(''),[users,setUsers]=useState<Account[]>([]),[userId,setUserId]=useState(employee.user?String(employee.user.id):'');
 const [error,setError]=useState(''),[loading,setLoading]=useState(true),[busy,setBusy]=useState(false);
 const url=API+'/cis/rh/registos/colaboradores/'+employee.id;
 useEffect(()=>{
  const ctrl=new AbortController();setLoading(true);setError('');
  const timeout=window.setTimeout(()=>{
   fetch(url+'/utilizadores?'+new URLSearchParams({q:query.trim()}),{
    signal:ctrl.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}
   }).then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d as {users:Account[]};})
    .then(d=>{if(!ctrl.signal.aborted)setUsers(d.users);})
    .catch(e=>{if(!ctrl.signal.aborted)setError(e.message);})
    .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  },250);
  return ()=>{ctrl.abort();window.clearTimeout(timeout);};
 },[url,token,query]);
 const current=employee.user?String(employee.user.id):'';
 async function save(e:FormEvent){
  e.preventDefault();if(busy||loading||userId===current)return;
  setBusy(true);setError('');
  try{
   const r=await fetch(url+'/utilizador',{method:'PUT',
    headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({user_id:userId?Number(userId):null,previous_user_id:employee.user?.id??null})});
   const d=await r.json();if(!r.ok)throw new Error(d.message||'Não foi possível guardar a associação.');
   onSaved();
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <form className="cis-prop-decision" onSubmit={e=>void save(e)}>
  <h4>Conta do CIS · {employee.name}</h4>
  <div className="cis-profile-fields">
   {employee.status==='activo'&&<label className="cis-profile-field"><span>Pesquisar conta activa</span><input type="search" value={query} maxLength={160} disabled={busy} onChange={e=>{setUserId(current);setQuery(e.target.value);}}/></label>}
   <label className="cis-profile-field"><span>Conta associada</span><select value={userId} disabled={busy||loading} onChange={e=>setUserId(e.target.value)}>
    <option value="">Sem associação</option>
    {employee.user&&<option value={employee.user.id}>{employee.user.name} · {employee.user.email} (actual)</option>}
    {employee.status==='activo'&&users.map(u=><option key={u.id} value={u.id}>{u.name} · {u.email}</option>)}
   </select></label>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {loading&&<p role="status">A pesquisar contas…</p>}
  {employee.status==='activo'&&!loading&&!error&&!users.length&&<p>Sem contas activas disponíveis para esta pesquisa.</p>}
  <div className="cis-prop-actions"><button className="primary" type="submit" disabled={busy||loading||userId===current}>Guardar associação</button>
   <button type="button" disabled={busy} onClick={onClose}>Cancelar</button></div>
 </form>;
}

function TermsReview({row,section,token,mode,onClose,onSaved}:{row:Row;section:RhSection;token:string;mode:'editar'|'aprovar'|'rejeitar';onClose:()=>void;onSaved:()=>void}){
 const [date,setDate]=useState(row.reference_date),[end,setEnd]=useState(row.details.end??'');
 const [rate,setRate]=useState(row.details.rate??''),[regime,setRegime]=useState(row.details.regime??'mensal');
 const [note,setNote]=useState(mode==='editar'?(row.details.text??''):'');
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const editing=mode==='editar';
 async function save(e:FormEvent){
  e.preventDefault();if(busy)return;
  setBusy(true);setError('');
  try{
   const base=API+'/cis/rh/registos/termos/'+row.id;
   const body=editing?{revision:row.revision,reference_date:date,...(section==='ferias'?{}:{rate}),
    ...(section==='contratos'?{regime,end:end||null}:section==='ferias'?{end,text:note||null}:{text:note})}:{revision:row.revision,decision:mode,note:note||null};
   const r=await fetch(base+(editing?'':'/decisao'),{method:editing?'PUT':'POST',
    headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(body)});
   const d=await r.json();if(!r.ok)throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message);
   onSaved();
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <form className="cis-prop-decision" onSubmit={e=>void save(e)}>
  <h4>{editing?'Editar termos':mode==='aprovar'?'Aprovar termos':'Rejeitar termos'} · {row.name} · #{row.id}</h4>
  {!editing&&<p>Referência {row.reference_date} · {details(row)}</p>}
  <div className="cis-profile-fields">
   {editing&&<><label className="cis-profile-field"><span>Data de referência</span><input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
    {section!=='ferias'&&<label className="cis-profile-field"><span>Valor unitário (MT)</span><input type="number" min="0" step="0.01" required value={rate} onChange={e=>setRate(e.target.value)}/></label>}
    {section==='ferias'&&<label className="cis-profile-field"><span>Data final</span><input type="date" required value={end} onChange={e=>setEnd(e.target.value)}/></label>}
    {section==='contratos'&&<><label className="cis-profile-field"><span>Data final</span><input type="date" value={end} onChange={e=>setEnd(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Regime</span><select value={regime} onChange={e=>setRegime(e.target.value)}><option value="mensal">Mensal</option><option value="diario">Diário</option><option value="hora">Hora</option></select></label></>}
   </>}
   {(!editing||section==='adendas'||section==='ferias')&&<label className="cis-profile-field"><span>{editing?'Descrição':mode==='rejeitar'?'Motivo da rejeição':'Nota da decisão (opcional)'}</span>
    <textarea required={(editing&&section==='adendas')||mode==='rejeitar'} maxLength={editing?2000:255} value={note} onChange={e=>setNote(e.target.value)}/></label>}
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy}>{editing?'Guardar revisão':'Confirmar decisão'}</button><button type="button" disabled={busy} onClick={onClose}>Cancelar</button></div>
 </form>;
}

function InstallmentPayment({row,token,onClose,onSaved}:{row:Row;token:string;onClose:()=>void;onSaved:()=>void}){
 const today=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const [date,setDate]=useState(today),[reference,setReference]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 async function save(e:FormEvent){
  e.preventDefault();if(busy)return;
  setBusy(true);setError('');
  try{
   const r=await fetch(API+'/cis/rh/registos/prestacoes/'+row.id+'/pagar',{method:'POST',
    headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({revision:row.revision,paid_on:date,reference:reference.trim(),confirm:true})});
   const data=await r.json();if(!r.ok)throw new Error(Object.values(data.errors??{}).flat().join(' ')||data.message);
   onSaved();
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return <form className="cis-prop-decision" onSubmit={e=>void save(e)}>
  <h4>Confirmar pagamento da prestação #{row.id}</h4>
  <p>{row.name} · {mt(row.details.amount??'')} · Registo em RH de um pagamento já efectuado.</p>
  <div className="cis-profile-fields">
   <label className="cis-profile-field"><span>Data efectiva</span><input type="date" required max={today} value={date} onChange={e=>setDate(e.target.value)}/></label>
   <label className="cis-profile-field"><span>Referência do comprovativo</span><input required minLength={4} maxLength={120} value={reference} onChange={e=>setReference(e.target.value)}/></label>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy}>Confirmar pagamento</button>
   <button type="button" disabled={busy} onClick={onClose}>Cancelar</button></div>
 </form>;
}

const NEW_LABEL:Record<RhSection,string>={painel:'',colaboradores:'Novo colaborador',contratos:'Novo contrato',adendas:'Nova adenda',ferias:'Registar férias',indemnizacoes:'Nova indemnização',prestacoes:'Nova prestação'};
type Filter='todos'|'pendente'|'aprovado'|'rejeitado'|'activo'|'inactivo'|'por_pagar'|'paga';
export default function RhRecordsDesk({token,section,onNavigate,onOpen}:{token:string;section:RhSection;onNavigate:(s:RhSection)=>void;onOpen?:(page:'Folha salarial'|'Relatórios de RH')=>void}){
 const [filter,setFilter]=useState<Filter>('todos');
 const can=useCan();
 const [plan,setPlan]=useState<{signature:string;years:number;total_cents:number;installments:{date:string;cents:number}[]}|null>(null);
 const [payment,setPayment]=useState<Row|null>(null);
 const [employeeProfile,setEmployeeProfile]=useState<{id:number|null}|null>(null);
 const [review,setReview]=useState<{row:Row;mode:'editar'|'aprovar'|'rejeitar'}|null>(null);
 const [linking,setLinking]=useState<Employee|null>(null);
 const [data,setData]=useState<Response|null>(null),[error,setError]=useState(''),[status,setStatus]=useState(''),[page,setPage]=useState(1),[rev,setRev]=useState(0);
 const [form,setForm]=useState(blank),[editing,setEditing]=useState(false),[busy,setBusy]=useState(false);
 const [query,setQuery]=useState(''),[search,setSearch]=useState(''),[candidate,setCandidate]=useState(''),[matches,setMatches]=useState<Employee[]>([]),[chosen,setChosen]=useState<Employee|null>(null);
 const [parents,setParents]=useState<{id:number;reference_date:string}[]>([]);
 const worker=section==='colaboradores',overview=section==='painel';
 const reviewed=section==='contratos'||section==='adendas'||section==='ferias';
 const parentKind=section==='adendas'?'contratos':section==='prestacoes'?'indemnizacoes':'';
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 useEffect(()=>{
  const c=new AbortController();setData(null);
  const params=new URLSearchParams({page:String(page),q:search,...(!worker&&!overview?{kind:section}:{})});
  fetch(API+'/cis/rh/registos?'+params,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const y=await r.json();if(!r.ok)throw new Error(y.message||'Não foi possível carregar o RH.');return y as Response;})
   .then(y=>{if(!c.signal.aborted)setData(y);}).catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[token,section,worker,overview,page,search,rev]);
 useEffect(()=>{
  if(!editing||worker||!candidate.trim()||chosen){setMatches([]);return;}
  const c=new AbortController();
  const timer=window.setTimeout(()=>{
   fetch(API+'/cis/rh/registos?'+new URLSearchParams({q:candidate.trim()}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
    .then(async r=>{const y=await r.json();if(!r.ok)throw new Error(y.message);return y as Response;})
    .then(y=>{if(!c.signal.aborted)setMatches(y.employees.data);})
    .catch(e=>{if(!c.signal.aborted)setError(e.message);});
  },300);
  return ()=>{c.abort();window.clearTimeout(timer);};
 },[token,worker,editing,candidate,chosen]);
 useEffect(()=>{
  setParents([]);
  if(!parentKind||!chosen)return;
  const c=new AbortController();
  fetch(API+'/cis/rh/registos/origens?'+new URLSearchParams({employee_id:String(chosen.id),kind:parentKind}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const y=await r.json();if(!r.ok)throw new Error(y.message);return y;})
   .then(y=>{if(!c.signal.aborted)setParents(y);})
   .catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[token,parentKind,chosen]);
 const field=(key:keyof typeof blank,label:string,type='text',required=true)=><label className="cis-profile-field"><span>{label}</span><input type={type} required={required}
  {...(type==='number'?{min:'0',step:'0.01'}:{})} value={form[key]} onChange={e=>{setForm({...form,[key]:e.target.value});setPlan(null);}}/></label>;
 async function save(e:FormEvent){
  e.preventDefault();if(busy||(!worker&&!chosen))return;
  const d=worker?{name:form.name,company:form.company,code:form.code,category:form.category,admission:form.admission,status:form.status}
   :{employee_id:chosen?.id,reference_date:form.reference_date,...(parentKind?{parent_id:Number(form.parent_id)}:{}),
   ...(section==='contratos'?{end:form.end||null,rate:form.rate,regime:form.regime}:{}),
   ...(section==='adendas'?{text:form.text,rate:form.rate}:{}),
   ...(section==='ferias'?{end:form.end,text:form.text}:{}),
   ...(['indemnizacoes','prestacoes'].includes(section)?{amount:form.amount,text:form.text}:{})};
  setError('');setStatus('');setBusy(true);
  try{
   const calculating=section==='indemnizacoes';
   const payload=calculating?{employee_id:chosen?.id,reference_date:form.reference_date,text:form.text,salary_base:form.salary_base,months:Number(form.months),due_day:Number(form.due_day),first_month:form.first_month,...(plan?{signature:plan.signature,confirm:true}:{})}:d;
   const r=await fetch(API+'/cis/rh/registos/'+section+(calculating?(plan?'/plano':'/previa'):''),{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify(payload)});
   const y=await r.json();if(!r.ok)throw new Error(Object.values(y.errors??{}).flat().join(' ')||y.message||'Não foi possível guardar.');
   if(calculating&&!plan){setPlan(y);return;}
   setPlan(null);setEditing(false);setStatus('Registo guardado.');setPage(1);setRev(x=>x+1);
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 const pages=worker?data?.employees:data?.records;
 if(overview)return <section className="cis-panel cis-proposals">{error&&<p className="cis-table-error" role="alert">{error}</p>}
  {!data&&!error&&<p role="status">A carregar…</p>}
  {data&&<RhOverview token={token} totals={data.totals} onNavigate={onNavigate} onOpen={p=>onOpen?.(p)}/>}</section>;
 const busyRow=editing||!!linking||!!review||!!payment;

 const reviewOf=(x:Row)=>x.review_status??'pendente';
 const records=data?.records?.data??[],employees=data?.employees.data??[];
 const statusOf=(x:Row|Employee):Filter=>worker?(x as Employee).status as Filter:section==='prestacoes'?((x as Row).details.paid_on?'paga':'por_pagar'):reviewOf(x as Row);
 const list:(Row|Employee)[]=worker?employees:records;
 const shown=filter==='todos'?list:list.filter(x=>statusOf(x)===filter);
 const count=(f:Filter)=>list.filter(x=>statusOf(x)===f).length;
 const chips:{id:Filter;label:string;n:number}[]=worker?[{id:'todos',label:'Todos',n:list.length},{id:'activo',label:'Activos',n:count('activo')},{id:'inactivo',label:'Inactivos',n:count('inactivo')}]
  :reviewed?[{id:'todos',label:'Todos',n:list.length},{id:'pendente',label:'Pendentes',n:count('pendente')},{id:'aprovado',label:'Aprovados',n:count('aprovado')},{id:'rejeitado',label:'Rejeitados',n:count('rejeitado')}]
  :section==='prestacoes'?[{id:'todos',label:'Todas',n:list.length},{id:'por_pagar',label:'Por pagar',n:count('por_pagar')},{id:'paga',label:'Pagas',n:count('paga')}]:[];
 const reviewCell=(x:Row)=><><Badge state={reviewOf(x)}/>{x.decision_note&&<span className="rh-note">{x.decision_note}</span>}</>;
 const reviewActions=(x:Row)=><div className="rh-row-actions">{x.review_status!=='aprovado'&&<button type="button" className="cis-edit-action" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'editar'})}>Editar</button>}
  {reviewOf(x)==='pendente'&&can('utilizadores')&&<><button type="button" className="cis-edit-action" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'aprovar'})}>Aprovar</button>
   <button type="button" className="cis-edit-action" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'rejeitar'})}>Rejeitar</button></>}</div>;
 type Col={h:string;v:(x:Row&Employee)=>ReactNode;num?:boolean;wrap?:boolean};
 const cols:Col[]=worker?[
  {h:'Categoria',v:x=>x.category||'—'},{h:'Admissão',v:x=>date(x.admission)},{h:'Estado',v:x=><Badge state={x.status}/>},
  {h:'Ficha',v:x=><button type="button" className="cis-edit-action" disabled={busyRow||!!employeeProfile} onClick={()=>setEmployeeProfile({id:x.id})}>Abrir ficha</button>},
  ...(can('utilizadores')?[{h:'Conta do CIS',v:(x:Employee)=><div className="rh-row-actions" style={{justifyContent:'flex-start'}}><span className={x.user?'':'muted'}>{x.user?.name??'Sem conta associada'}</span>
   <button type="button" className="cis-edit-action" disabled={editing||!!linking||!!employeeProfile} onClick={()=>setLinking(x)}>{x.user?'Alterar':'Associar'}</button></div>}]:[])]
 :section==='contratos'?[
  {h:'Início',v:x=>date(x.reference_date)},{h:'Fim',v:x=>x.details.end?date(x.details.end):<span className="muted">Sem termo</span>},
  {h:'Regime',v:x=>({mensal:'Mensal',diario:'Diário',hora:'Hora'} as Record<string,string>)[x.details.regime??'']??x.details.regime??'—'},
  {h:'Valor unitário',v:x=>money(x.details.rate),num:true},{h:'Estado',v:reviewCell},{h:'',v:reviewActions}]
 :section==='adendas'?[
  {h:'Contrato',v:x=>x.parent_id?'#'+x.parent_id:'—'},{h:'Efeito a partir de',v:x=>date(x.reference_date)},{h:'Novo valor',v:x=>money(x.details.rate),num:true},
  {h:'Descrição',v:x=>x.details.text||'—',wrap:true},{h:'Estado',v:reviewCell},{h:'',v:reviewActions}]
 :section==='ferias'?[
  {h:'De',v:x=>date(x.reference_date)},{h:'Até',v:x=>date(x.details.end)},{h:'Dias',v:x=>daysBetween(x.reference_date,x.details.end)??'—',num:true},
  {h:'Observação',v:x=>x.details.text||'—',wrap:true},{h:'Estado',v:reviewCell},{h:'',v:reviewActions}]
 :section==='indemnizacoes'?[
  {h:'Data',v:x=>date(x.reference_date)},{h:'Anos',v:x=>x.details.years??'—',num:true},{h:'Motivo',v:x=>x.details.text||'—',wrap:true},
  {h:'Planeado',v:x=>money((x.payment_totals?.planned??0)/100),num:true},{h:'Pago',v:x=>money((x.payment_totals?.paid??0)/100),num:true},
  {h:'Saldo',v:x=>(x.payment_totals?.balance??0)>0?<strong>{money((x.payment_totals?.balance??0)/100)}</strong>:<Badge tone="ok">Liquidada</Badge>,num:true}]
 :[
  {h:'Indemnização',v:x=>x.parent_id?'#'+x.parent_id:'—'},{h:'Data prevista',v:x=>date(x.reference_date)},{h:'Montante',v:x=>money(x.details.amount),num:true},
  {h:'Pagamento',v:x=>x.details.paid_on?<><Badge state="paga"/><span className="rh-note">{date(x.details.paid_on)}{x.details.payment_reference?' · '+x.details.payment_reference:''}</span></>
   :<button type="button" className="cis-edit-action" disabled={editing||!!payment} onClick={()=>setPayment(x)}>Registar pagamento</button>}];
 return <section className="cis-panel cis-proposals">
  {error&&<p className="cis-table-error" role="alert">{error}</p>}{status&&<p className="cis-msg-notice" role="status">{status}</p>}
  {!data&&!error&&<p role="status">A carregar…</p>}
  {worker&&employeeProfile&&<RhEmployeeProfile key={employeeProfile.id??'novo'} token={token} id={employeeProfile.id} onClose={()=>setEmployeeProfile(null)}
   onSaved={()=>{setEmployeeProfile(null);setPage(1);setRev(v=>v+1);setStatus('Ficha guardada.');}}/>}
  {worker&&linking&&<RhUserLink key={linking.id} employee={linking} token={token} onClose={()=>setLinking(null)}
   onSaved={()=>{setLinking(null);setRev(v=>v+1);setStatus('Associação actualizada.');}}/>}
  {payment&&<InstallmentPayment key={payment.id} row={payment} token={token} onClose={()=>setPayment(null)} onSaved={()=>{setPayment(null);setRev(v=>v+1);setStatus('Pagamento registado em RH.');}}/>}
  {review&&<TermsReview key={review.row.id+'-'+review.mode} row={review.row} section={section} token={token} mode={review.mode} onClose={()=>setReview(null)}
   onSaved={()=>{setReview(null);setRev(v=>v+1);setStatus('Revisão registada.');}}/>}
  {!overview&&editing&&<form className="cis-prop-decision" onSubmit={e=>void save(e)}>
   <div className="cis-profile-fields">{worker?<>{field('name','Nome completo')}{field('company','Empresa')}{field('code','Código')}{field('category','Categoria','text',false)}{field('admission','Admissão','date')}
    <label className="cis-profile-field"><span>Estado</span><select value={form.status} onChange={e=>setForm({...form,status:e.target.value})}><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></label>
   </>:<>
    <label className="cis-profile-field"><span>Colaborador</span><input value={candidate} required placeholder="Pesquise por nome ou código" onChange={e=>{setCandidate(e.target.value);setChosen(null);setPlan(null);setForm({...form,parent_id:''});}}/></label>
    {!chosen&&matches.length>0&&<div className="cis-prop-actions">{matches.map(x=><button type="button" key={x.id} onClick={()=>{setChosen(x);setCandidate(x.name+' · '+x.code+' · '+x.company);}}>{x.name} · {x.code} · {x.company}</button>)}</div>}
    {parentKind&&chosen&&<label className="cis-profile-field"><span>{parentKind==='contratos'?'Contrato associado':'Indemnização associada'}</span><select required value={form.parent_id} onChange={e=>setForm({...form,parent_id:e.target.value})}><option value="">Seleccione</option>{parents.map(x=><option key={x.id} value={x.id}>#{x.id} · {x.reference_date}</option>)}</select></label>}
    {field('reference_date',section==='prestacoes'?'Data prevista':'Data de referência','date')}
    {['contratos','ferias'].includes(section)&&field('end','Data final','date',section==='ferias')}
    {section==='contratos'&&<label className="cis-profile-field"><span>Regime de pagamento</span><select value={form.regime} onChange={e=>setForm({...form,regime:e.target.value})}><option value="mensal">Mensal</option><option value="diario">Diário</option><option value="hora">Hora</option></select></label>}
    {['contratos','adendas'].includes(section)&&field('rate','Valor unitário (MT)','number')}
    {section==='prestacoes'&&field('amount','Montante (MT)','number')}
    {section==='indemnizacoes'&&<>{field('salary_base','Vencimento base (MT)','number')}{field('months','Número de prestações','number')}{field('due_day','Dia de vencimento','number')}{field('first_month','Primeiro mês','month')}</>}
    {section!=='contratos'&&field('text','Descrição','text',['adendas','indemnizacoes'].includes(section))}
   </>}</div>
   {section==='indemnizacoes'&&plan&&<div className="cis-table-scroll"><strong>{plan.years} ano(s) completos · Total {mt(String(plan.total_cents/100))}</strong>
    <table><thead><tr><th>Vencimento</th><th>Valor</th></tr></thead><tbody>{plan.installments.map(row=><tr key={row.date}><td>{row.date}</td><td>{mt(String(row.cents/100))}</td></tr>)}</tbody></table></div>}
   <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy||(!worker&&!chosen)}>{section==='indemnizacoes'?(plan?'Guardar plano':'Calcular prévia'):'Guardar'}</button><button type="button" disabled={busy} onClick={()=>setEditing(false)}>Cancelar</button></div>
  </form>}
  {data&&<>
   <div className="rh-toolbar">
    <SearchBar value={query} onChange={setQuery} onSubmit={()=>{setPage(1);setSearch(query.trim());}} placeholder="Pesquisar por nome ou código do colaborador"/>
    <div className="rh-toolbar-end">{chips.length>0&&<Chips label="Filtrar por estado" items={chips} value={filter} onChange={setFilter}/>}
     <button className="rh-primary" type="button" disabled={!data||busyRow||!!employeeProfile} onClick={()=>{if(worker){setEmployeeProfile({id:null});setError('');setStatus('');return;}setForm(blank);setPlan(null);setCandidate('');setChosen(null);setError('');setStatus('');setEditing(true);}}><Plus size={16}/> {NEW_LABEL[section]}</button></div>
   </div>
   {(pages?.last_page??0)>1&&filter!=='todos'&&<p className="rh-note">O filtro aplica-se à página apresentada.</p>}
   {!pages?.data.length?<Empty title={search?'Nenhum resultado para «'+search+'».':'Ainda não há registos.'} text={search?'Verifique o nome ou o código.':'Use «'+NEW_LABEL[section]+'» para criar o primeiro.'}/>
   :!shown.length?<Empty title="Nenhum registo com este estado nesta página."/>
   :<div className="cis-table-scroll rh-scroll"><table className="rh-table rh-cards">
    <thead><tr><th>Colaborador</th>{cols.map(c=><th key={c.h} className={c.num?'num':undefined}>{c.h}</th>)}</tr></thead>
    <tbody>{shown.map(x=><tr key={x.id}><td><Person name={x.name} code={x.code} company={x.company}/></td>{cols.map(c=><td key={c.h} data-label={c.h} className={c.num?'num':c.wrap?'wrap':'nowrap'}>{c.v(x as never)}</td>)}</tr>)}</tbody>
   </table></div>}
   <Pager page={page} last={pages?.last_page??1} onPage={setPage}/>
  </>}
 </section>;
}
