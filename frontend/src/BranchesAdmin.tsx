// Definições › Agências: cadastro das agências e atribuição de contratos (por cidade) e contas da Caixa.
import {useCallback,useEffect,useState,type FormEvent} from 'react';
import {Building2,Plus,Pencil,MapPin,Phone,Users,FileText,Landmark,Star,X,AlertCircle,CheckCircle2,Shuffle} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Branch={id:number;codigo:string;nome:string;provincia:string|null;cidade:string|null;morada:string|null;telefone:string|null;email:string|null;principal:number|boolean;activo:number|boolean;contratos:number;utilizadores:number;contas:number};
type Account={id:number;codigo:string;nome:string;estado:string;branch_id:number|null};
type City={cidade:string;contratos:number};

export default function BranchesAdmin({token}:{token:string}){
 const [branches,setBranches]=useState<Branch[]>([]),[accounts,setAccounts]=useState<Account[]>([]),[cities,setCities]=useState<City[]>([]),[globalUsers,setGlobalUsers]=useState(0);
 const [loading,setLoading]=useState(true),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const [editing,setEditing]=useState<Branch|'nova'|null>(null);
 const [assign,setAssign]=useState<Branch|null>(null);
 const call=useCallback(async<T,>(path:string,init?:RequestInit)=>{
  const r=await fetch(API+path,{...init,headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'}});
  const j=await r.json().catch(()=>({})) as T&{message?:string;errors?:Record<string,string[]>};
  if(!r.ok)throw new Error(Object.values(j.errors??{}).flat()[0]||j.message||'Pedido recusado.');return j;},[token]);
 const load=useCallback(async()=>{setError('');
  try{const j=await call<{agencias:Branch[];contas:Account[];cidades:City[];utilizadores_todas:number}>('/cis/agencias');
   setBranches(j.agencias);setAccounts(j.contas);setCities(j.cidades);setGlobalUsers(j.utilizadores_todas);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível carregar as agências.');}finally{setLoading(false);}},[call]);
 useEffect(()=>{void load();},[load]);
 async function setAccount(a:Account,branch:string){setError('');setNotice('');
  try{const j=await call<{notice:string}>('/cis/agencias/contas/'+a.id,{method:'PUT',body:JSON.stringify({branch_id:branch?Number(branch):null})});setNotice(j.notice);await load();}
  catch(e){setError(e instanceof Error?e.message:'Falhou.');}}

 return <section className="cis-panel cis-users cis-branches">
  <div className="cis-users-head"><div><h3><Building2 size={18}/> Agências</h3><p>Cada utilizador pode trabalhar numa agência (vê só os contratos e as contas dessa agência) ou em todas. Os contratos novos ficam na agência de quem os abre; os antigos ficam na agência principal até os atribuir.</p></div>
   <button type="button" className="primary" onClick={()=>setEditing('nova')}><Plus size={15}/> Nova agência</button></div>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {notice&&!error&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
  {loading?<p className="cis-msg-muted">A carregar…</p>:<div className="cis-branch-grid">
   {branches.map(b=><article key={b.id} className={'cis-branch-card'+(b.activo?'':' off')}>
    <header><span className="cis-branch-code">{b.codigo}</span>{!!b.principal&&<span className="cis-branch-main"><Star size={12}/> Principal</span>}{!b.activo&&<span className="cis-branch-off">Inactiva</span>}</header>
    <h4>{b.nome}</h4>
    <p className="cis-branch-where">{(b.cidade||b.provincia)?<><MapPin size={13}/> {[b.cidade,b.provincia].filter(Boolean).join(', ')}</>:<span className="cis-msg-muted">Sem localização</span>}{b.telefone&&<><Phone size={13}/> {b.telefone}</>}</p>
    <dl><div><dt><FileText size={13}/> Contratos</dt><dd>{b.contratos}</dd></div><div><dt><Users size={13}/> Utilizadores</dt><dd>{b.utilizadores}</dd></div><div><dt><Landmark size={13}/> Contas</dt><dd>{b.contas}</dd></div></dl>
    <footer><button type="button" onClick={()=>setEditing(b)}><Pencil size={13}/> Editar</button><button type="button" onClick={()=>setAssign(b)}><Shuffle size={13}/> Atribuir contratos</button></footer>
   </article>)}
   <article className="cis-branch-card all"><header><span className="cis-branch-code">TODAS</span></header><h4>Todas as agências</h4>
    <p className="cis-branch-where cis-msg-muted">Utilizadores sem agência vêem a empresa toda e podem filtrar por agência.</p>
    <dl><div><dt><Users size={13}/> Utilizadores</dt><dd>{globalUsers}</dd></div></dl></article>
  </div>}

  {!loading&&accounts.length>0&&<div className="cis-branch-accounts"><h4><Landmark size={15}/> Contas da Caixa por agência</h4>
   <p className="cis-msg-muted">Conta «partilhada» aparece para todas as agências (ex.: banco central da empresa).</p>
   <div className="cis-table-scroll"><table><thead><tr><th>Conta</th><th>Estado</th><th>Agência</th></tr></thead><tbody>
    {accounts.map(a=><tr key={a.id}><td><b>{a.codigo}</b> · {a.nome}</td><td>{a.estado}</td><td>
     <select value={a.branch_id??''} onChange={e=>void setAccount(a,e.target.value)} aria-label={'Agência da conta '+a.codigo}>
      <option value="">Partilhada (todas)</option>{branches.filter(b=>b.activo).map(b=><option key={b.id} value={b.id}>{b.nome}</option>)}</select></td></tr>)}
   </tbody></table></div></div>}

  {editing&&<BranchForm token={token} branch={editing==='nova'?null:editing} onClose={()=>setEditing(null)} onSaved={n=>{setEditing(null);setNotice(n);void load();}}/>}
  {assign&&<AssignCity token={token} branch={assign} cities={cities} onClose={()=>setAssign(null)} onSaved={n=>{setAssign(null);setNotice(n);void load();}}/>}
 </section>;
}

function BranchForm({token,branch,onClose,onSaved}:{token:string;branch:Branch|null;onClose:()=>void;onSaved:(n:string)=>void}){
 const [f,setF]=useState({codigo:branch?.codigo??'',nome:branch?.nome??'',provincia:branch?.provincia??'',cidade:branch?.cidade??'',morada:branch?.morada??'',telefone:branch?.telefone??'',email:branch?.email??'',activo:branch?!!branch.activo:true});
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const set=(k:keyof typeof f)=>(e:{target:{value:string}})=>setF(v=>({...v,[k]:e.target.value}));
 async function submit(e:FormEvent){e.preventDefault();setBusy(true);setError('');
  try{const body=Object.fromEntries(Object.entries(f).map(([k,v])=>[k,typeof v==='string'&&v.trim()===''?null:v]));
   const r=await fetch(API+'/cis/agencias'+(branch?'/'+branch.id:''),{method:branch?'PUT':'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(body)});
   const j=await r.json().catch(()=>({})) as {notice?:string;message?:string;errors?:Record<string,string[]>};
   if(!r.ok)throw new Error(Object.values(j.errors??{}).flat()[0]||j.message||'Não foi possível guardar.');onSaved(j.notice??'Guardado.');}
  catch(err){setError(err instanceof Error?err.message:'Falhou.');}finally{setBusy(false);}}
 return <div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label={branch?'Editar agência':'Nova agência'} onClick={onClose}>
  <form className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onSubmit={submit}>
   <div className="cis-users-form-head"><h3>{branch?'Editar agência':'Nova agência'}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <div className="cis-users-form-row"><label>Nome<input value={f.nome} onChange={set('nome')} required minLength={2} maxLength={120} autoFocus placeholder="Ex.: Matola"/></label>
    <label>Código<input value={f.codigo} onChange={e=>setF(v=>({...v,codigo:e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g,'')}))} required maxLength={12} placeholder="Ex.: MAT-01"/></label></div>
   <div className="cis-users-form-row"><label>Cidade<input value={f.cidade} onChange={set('cidade')} maxLength={100}/></label><label>Província<input value={f.provincia} onChange={set('provincia')} maxLength={60}/></label></div>
   <label>Morada<input value={f.morada} onChange={set('morada')} maxLength={255}/></label>
   <div className="cis-users-form-row"><label>Telefone<input inputMode="tel" value={f.telefone} onChange={set('telefone')} maxLength={30}/></label><label>Email<input type="email" value={f.email} onChange={set('email')} maxLength={190}/></label></div>
   {branch&&!branch.principal&&<label className="cis-branch-active"><input type="checkbox" checked={f.activo} onChange={e=>setF(v=>({...v,activo:e.target.checked}))}/> Agência activa</label>}
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}>{busy?'A guardar…':branch?'Guardar':'Criar agência'}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </form></div>;
}

function AssignCity({token,branch,cities,onClose,onSaved}:{token:string;branch:Branch;cities:City[];onClose:()=>void;onSaved:(n:string)=>void}){
 const [city,setCity]=useState(cities.find(c=>c.cidade.toLowerCase()===(branch.cidade??'').toLowerCase())?.cidade??'');
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const n=cities.find(c=>c.cidade===city)?.contratos??0;
 async function submit(e:FormEvent){e.preventDefault();if(!city)return;
  if(!window.confirm(`Atribuir os ${n} contrato(s) de ${city} à agência ${branch.nome}?`))return;
  setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/agencias/'+branch.id+'/atribuir-cidade',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({cidade:city})});
   const j=await r.json().catch(()=>({})) as {notice?:string;message?:string};if(!r.ok)throw new Error(j.message||'Falhou.');onSaved(j.notice??'Feito.');}
  catch(err){setError(err instanceof Error?err.message:'Falhou.');}finally{setBusy(false);}}
 return <div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Atribuir contratos" onClick={onClose}>
  <form className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onSubmit={submit}>
   <div className="cis-users-form-head"><h3>Atribuir contratos a {branch.nome}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <p className="cis-msg-muted">Escolha a cidade registada nos contratos. Todos os contratos dessa cidade passam para esta agência. Um contrato também pode ser mudado individualmente na sua ficha.</p>
   <label>Cidade dos contratos<select value={city} onChange={e=>setCity(e.target.value)} required><option value="">Escolher…</option>{cities.map(c=><option key={c.cidade} value={c.cidade}>{c.cidade} · {c.contratos} contrato(s)</option>)}</select></label>
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy||!city}>{busy?'A atribuir…':`Atribuir ${n||''} contrato(s)`}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </form></div>;
}
