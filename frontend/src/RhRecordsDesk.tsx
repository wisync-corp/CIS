import {useCan} from './perms';
import {useEffect,useState,type FormEvent} from 'react';
import {Plus,Search} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
export const rhSections=[
 ['painel','Visão geral de RH'],['colaboradores','Colaboradores'],['contratos','Contratos de trabalho'],['adendas','Adendas'],
 ['ferias','Férias'],['indemnizacoes','Indemnizações'],['prestacoes','Prestações de indemnização'],
] as const;
export type RhSection=typeof rhSections[number][0];
const titles=Object.fromEntries(rhSections);
type Account={id:number;name:string;email:string;active?:boolean|number};
type Employee={user?:Account|null;id:number;name:string;company:string;code:string;admission:string;category:string;status:string};
type Row={review_status?:'pendente'|'aprovado'|'rejeitado'|null;revision?:number;decision_note?:string|null;id:number;reference_date:string;parent_id:number|null;name:string;company:string;code:string;details:Record<string,string|null>};
type Page<T>={data:T[];current_page:number;last_page:number};
type Response={employees:Page<Employee>;records:Page<Row>|null;totals:Partial<Record<RhSection,number>>};
const names:Record<string,string>={end:'Data final',regime:'Regime',rate:'Valor unitário (MT)',amount:'Montante (MT)',text:'Descrição'};
const mt=(value:string)=>Number(value).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const details=(row:Row)=>Object.entries(row.details).filter(([,v])=>v!=null&&v!=='').map(([key,value])=>names[key]+': '+(['rate','amount'].includes(key)?mt(value!):value)).join(' · ');
const blank={name:'',company:'',code:'',category:'',admission:'',status:'activo',employee_id:'',parent_id:'',reference_date:'',end:'',regime:'mensal',rate:'',amount:'',text:''};

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
   const body=editing?{revision:row.revision,reference_date:date,rate,
    ...(section==='contratos'?{regime,end:end||null}:{text:note})}:{revision:row.revision,decision:mode,note:note||null};
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
    <label className="cis-profile-field"><span>Valor unitário (MT)</span><input type="number" min="0" step="0.01" required value={rate} onChange={e=>setRate(e.target.value)}/></label>
    {section==='contratos'&&<><label className="cis-profile-field"><span>Data final</span><input type="date" value={end} onChange={e=>setEnd(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Regime</span><select value={regime} onChange={e=>setRegime(e.target.value)}><option value="mensal">Mensal</option><option value="diario">Diário</option><option value="hora">Hora</option></select></label></>}
   </>}
   {(!editing||section==='adendas')&&<label className="cis-profile-field"><span>{editing?'Descrição':mode==='rejeitar'?'Motivo da rejeição':'Nota da decisão (opcional)'}</span>
    <textarea required={editing||mode==='rejeitar'} maxLength={editing?2000:255} value={note} onChange={e=>setNote(e.target.value)}/></label>}
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy}>{editing?'Guardar revisão':'Confirmar decisão'}</button><button type="button" disabled={busy} onClick={onClose}>Cancelar</button></div>
 </form>;
}

export default function RhRecordsDesk({token,section,onNavigate}:{token:string;section:RhSection;onNavigate:(s:RhSection)=>void}){
 const can=useCan();
 const [review,setReview]=useState<{row:Row;mode:'editar'|'aprovar'|'rejeitar'}|null>(null);
 const [linking,setLinking]=useState<Employee|null>(null);
 const [data,setData]=useState<Response|null>(null),[error,setError]=useState(''),[status,setStatus]=useState(''),[page,setPage]=useState(1),[rev,setRev]=useState(0);
 const [form,setForm]=useState(blank),[editing,setEditing]=useState(false),[busy,setBusy]=useState(false);
 const [query,setQuery]=useState(''),[search,setSearch]=useState(''),[candidate,setCandidate]=useState(''),[matches,setMatches]=useState<Employee[]>([]),[chosen,setChosen]=useState<Employee|null>(null);
 const [parents,setParents]=useState<{id:number;reference_date:string}[]>([]);
 const worker=section==='colaboradores',overview=section==='painel';
 const reviewed=section==='contratos'||section==='adendas';
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
  {...(type==='number'?{min:'0',step:'0.01'}:{})} value={form[key]} onChange={e=>setForm({...form,[key]:e.target.value})}/></label>;
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
   const r=await fetch(API+'/cis/rh/registos/'+section,{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify(d)});
   const y=await r.json();if(!r.ok)throw new Error(Object.values(y.errors??{}).flat().join(' ')||y.message||'Não foi possível guardar.');
   setEditing(false);setStatus('Registo guardado.');setPage(1);setRev(x=>x+1);
  }catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 const pages=worker?data?.employees:data?.records;
 return <section className="cis-panel cis-proposals">
  <div className="cis-prop-head"><div><h3>{titles[section]}</h3></div>
   {!overview&&<button className="cis-edit-action" type="button" disabled={!data||editing||!!linking||!!review} onClick={()=>{setForm(blank);setCandidate('');setChosen(null);setError('');setStatus('');setEditing(true);}}><Plus size={16}/> Novo registo</button>}
  </div>
  {error&&<p className="cis-table-error" role="alert">{error}</p>}{status&&<p className="cis-msg-notice" role="status">{status}</p>}
  {!data&&!error&&<p role="status">A carregar…</p>}
  {overview&&data&&<div className="cis-stat-grid">{rhSections.filter(([key])=>key!=='painel').map(([key,title])=><button key={key} type="button" className="cis-panel cis-stat cis-stat-button" onClick={()=>onNavigate(key)}><div className="cis-stat-top">{title}</div><strong>{data.totals[key]??0}</strong></button>)}</div>}
  {worker&&linking&&<RhUserLink key={linking.id} employee={linking} token={token} onClose={()=>setLinking(null)}
   onSaved={()=>{setLinking(null);setRev(v=>v+1);setStatus('Associação actualizada.');}}/>}
  {review&&<TermsReview key={review.row.id+'-'+review.mode} row={review.row} section={section} token={token} mode={review.mode} onClose={()=>setReview(null)}
   onSaved={()=>{setReview(null);setRev(v=>v+1);setStatus('Revisão registada.');}}/>}
  {!overview&&editing&&<form className="cis-prop-decision" onSubmit={e=>void save(e)}>
   <div className="cis-profile-fields">{worker?<>{field('name','Nome completo')}{field('company','Empresa')}{field('code','Código')}{field('category','Categoria','text',false)}{field('admission','Admissão','date')}
    <label className="cis-profile-field"><span>Estado</span><select value={form.status} onChange={e=>setForm({...form,status:e.target.value})}><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></label>
   </>:<>
    <label className="cis-profile-field"><span>Colaborador</span><input value={candidate} required placeholder="Pesquise por nome ou código" onChange={e=>{setCandidate(e.target.value);setChosen(null);setForm({...form,parent_id:''});}}/></label>
    {!chosen&&matches.length>0&&<div className="cis-prop-actions">{matches.map(x=><button type="button" key={x.id} onClick={()=>{setChosen(x);setCandidate(x.name+' · '+x.code+' · '+x.company);}}>{x.name} · {x.code} · {x.company}</button>)}</div>}
    {parentKind&&chosen&&<label className="cis-profile-field"><span>{parentKind==='contratos'?'Contrato associado':'Indemnização associada'}</span><select required value={form.parent_id} onChange={e=>setForm({...form,parent_id:e.target.value})}><option value="">Seleccione</option>{parents.map(x=><option key={x.id} value={x.id}>#{x.id} · {x.reference_date}</option>)}</select></label>}
    {field('reference_date',section==='prestacoes'?'Data prevista':'Data de referência','date')}
    {['contratos','ferias'].includes(section)&&field('end','Data final','date',section==='ferias')}
    {section==='contratos'&&<label className="cis-profile-field"><span>Regime de pagamento</span><select value={form.regime} onChange={e=>setForm({...form,regime:e.target.value})}><option value="mensal">Mensal</option><option value="diario">Diário</option><option value="hora">Hora</option></select></label>}
    {['contratos','adendas'].includes(section)&&field('rate','Valor unitário (MT)','number')}
    {['indemnizacoes','prestacoes'].includes(section)&&field('amount','Montante (MT)','number')}
    {section!=='contratos'&&field('text','Descrição','text',['adendas','indemnizacoes'].includes(section))}
   </>}</div>
   <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy||(!worker&&!chosen)}>Guardar</button><button type="button" disabled={busy} onClick={()=>setEditing(false)}>Cancelar</button></div>
  </form>}
  {!overview&&data&&<>
   <form className="cis-prop-actions" onSubmit={e=>{e.preventDefault();setPage(1);setSearch(query.trim());}}>
    <input type="search" aria-label="Nome ou código do colaborador" placeholder="Nome ou código do colaborador" value={query} onChange={e=>setQuery(e.target.value)}/><button type="submit"><Search size={15}/> Pesquisar</button>
   </form>
   {!pages?.data.length?<p>Sem registos.</p>:<div className="cis-table-scroll"><table>
    <thead><tr><th>Colaborador</th><th>Empresa</th><th>Código</th>{worker?<><th>Categoria</th><th>Admissão</th><th>Estado</th>{can('utilizadores')&&<th>Conta do CIS</th>}</>:<><th>Referência</th><th>Data</th><th>Detalhes</th>{reviewed&&<><th>Estado</th><th>Acções</th></>}</>}</tr></thead>
    <tbody>{worker?data.employees.data.map(x=><tr key={x.id}><td>{x.name}</td><td>{x.company}</td><td>{x.code}</td><td>{x.category||'—'}</td><td>{x.admission}</td><td>{x.status==='activo'?'Activo':'Inactivo'}</td>{can('utilizadores')&&<td><span>{x.user?.name??'Sem associação'}</span> <button type="button" className="cis-edit-action" disabled={editing||!!linking} onClick={()=>setLinking(x)}>{x.user?'Gerir associação':'Associar'}</button></td>}</tr>)
    :data.records?.data.map(x=><tr key={x.id}><td>{x.name}</td><td>{x.company}</td><td>{x.code}</td><td>#{x.id}{x.parent_id?' · origem #'+x.parent_id:''}</td><td>{x.reference_date}</td><td>{details(x)||'—'}</td>{reviewed&&<>
     <td>{x.review_status==='aprovado'?'Aprovado':x.review_status==='rejeitado'?'Rejeitado':'Pendente'}{x.decision_note&&<small>{x.decision_note}</small>}</td>
     <td><div className="cis-prop-actions">{x.review_status!=='aprovado'&&<button type="button" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'editar'})}>Editar</button>}
      {x.review_status==='pendente'&&can('utilizadores')&&<><button type="button" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'aprovar'})}>Aprovar</button>
       <button type="button" disabled={!!review||editing} onClick={()=>setReview({row:x,mode:'rejeitar'})}>Rejeitar</button></>}
     </div></td>
    </>}</tr>)}</tbody>
   </table></div>}
   {(pages?.last_page??0)>1&&<div className="cis-prop-actions"><button type="button" disabled={page===1} onClick={()=>setPage(x=>x-1)}>Anterior</button><span>{page} / {pages?.last_page}</span><button type="button" disabled={page>=(pages?.last_page??1)} onClick={()=>setPage(x=>x+1)}>Seguinte</button></div>}
  </>}
 </section>;
}
