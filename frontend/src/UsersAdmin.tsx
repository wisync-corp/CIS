// Definições › Utilizadores: equipa do CIS, funções e permissões (só administrador).
import {useCallback,useEffect,useMemo,useState,type FormEvent} from 'react';
import {UserPlus,Search,ShieldCheck,KeyRound,LogOut,Copy,CheckCircle2,AlertCircle,X,Pencil,History,Users as UsersIcon} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type U={id:number;name:string;email:string;telefone:string|null;role:string;role_label:string;active:boolean;must_change_password:boolean;last_login_at:string|null;created_at:string|null;sessoes:number};
type Role={id:string;label:string;descricao:string;permissoes:string[]};
type Perm={id:string;label:string};
type Audit={accao:string;detalhe:string|null;created_at:string;actor:string|null;alvo:string|null};

const when=(v:string|null)=>{if(!v)return 'Nunca';const d=new Date(v.replace(' ','T'));return isNaN(+d)?v:d.toLocaleString('pt-PT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});};
const ACCAO:Record<string,string>={criado:'Conta criada',alterado:'Conta alterada',palavra_passe_reposta:'Palavra-passe reposta',palavra_passe_alterada:'Palavra-passe alterada pelo próprio',sessoes_terminadas:'Sessões terminadas'};

export default function UsersAdmin({token}:{token:string}){
 const [users,setUsers]=useState<U[]>([]),[audit,setAudit]=useState<Audit[]>([]),[roles,setRoles]=useState<Role[]>([]),[perms,setPerms]=useState<Perm[]>([]);
 const [loading,setLoading]=useState(true),[error,setError]=useState(''),[notice,setNotice]=useState('');
 const [q,setQ]=useState(''),[filter,setFilter]=useState<'todos'|'activos'|'inactivos'>('activos');
 const [editing,setEditing]=useState<U|'novo'|null>(null);
 const [secret,setSecret]=useState<{nome:string;email:string;senha:string}|null>(null);
 const [showMatrix,setShowMatrix]=useState(false),[showAudit,setShowAudit]=useState(false);
 const call=useCallback(async<T,>(path:string,init?:RequestInit)=>{
  const r=await fetch(API+path,{...init,headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json',...(init?.headers??{})}});
  const j=await r.json().catch(()=>({})) as T&{message?:string;errors?:Record<string,string[]>};
  if(!r.ok)throw new Error(Object.values(j.errors??{}).flat()[0]||j.message||'Pedido recusado.');
  return j;},[token]);
 const load=useCallback(async()=>{setError('');
  try{const [a,b]=await Promise.all([call<{utilizadores:U[];auditoria:Audit[]}>('/cis/utilizadores'),call<{funcoes:Role[];permissoes:Perm[]}>('/cis/utilizadores/funcoes')]);
   setUsers(a.utilizadores);setAudit(a.auditoria);setRoles(b.funcoes);setPerms(b.permissoes);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível carregar os utilizadores.');}finally{setLoading(false);}},[call]);
 useEffect(()=>{void load();},[load]);
 const shown=useMemo(()=>{const t=q.trim().toLowerCase();return users.filter(u=>(filter==='todos'||(filter==='activos')===u.active)&&(!t||(u.name+' '+u.email+' '+u.role_label).toLowerCase().includes(t)));},[users,q,filter]);
 const counts={activos:users.filter(u=>u.active).length,inactivos:users.filter(u=>!u.active).length};

 async function act(path:string,msg?:string){setError('');setNotice('');
  try{const j=await call<{notice?:string;palavra_passe_temporaria?:string}>(path,{method:'POST'});
   setNotice(msg??j.notice??'Feito.');await load();return j;}
  catch(e){setError(e instanceof Error?e.message:'Falhou.');}}
 async function reset(u:U){if(!window.confirm(`Repor a palavra-passe de ${u.name}? As sessões abertas são terminadas.`))return;
  const j=await act('/cis/utilizadores/'+u.id+'/repor-palavra-passe');if(j?.palavra_passe_temporaria)setSecret({nome:u.name,email:u.email,senha:j.palavra_passe_temporaria});}
 async function toggle(u:U){if(!window.confirm(u.active?`Desactivar ${u.name}? Deixa de conseguir entrar e as sessões são terminadas.`:`Reactivar ${u.name}?`))return;
  setError('');setNotice('');try{const j=await call<{notice:string}>('/cis/utilizadores/'+u.id,{method:'PATCH',body:JSON.stringify({active:!u.active})});setNotice(j.notice);await load();}
  catch(e){setError(e instanceof Error?e.message:'Falhou.');}}

 return <section className="cis-panel cis-users">
  <div className="cis-users-head"><div><h3><UsersIcon size={18}/> Utilizadores</h3><p>Quem entra no CIS e o que cada um pode fazer. As contas novas recebem uma palavra-passe temporária, trocada no primeiro acesso.</p></div>
   <button type="button" className="primary" onClick={()=>{setEditing('novo');setSecret(null);}}><UserPlus size={15}/> Novo utilizador</button></div>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {notice&&!error&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
  {secret&&<div className="cis-users-secret" role="status">
   <div><b>Palavra-passe temporária de {secret.nome}</b><small>Entregue-a à pessoa (só aparece agora). No primeiro acesso, com o email <b>{secret.email}</b>, terá de a trocar.</small></div>
   <code>{secret.senha}</code>
   <button type="button" onClick={()=>{void navigator.clipboard.writeText(`CIS — ${secret.email}\nPalavra-passe temporária: ${secret.senha}\n(vai ser pedido que a troque no primeiro acesso)`);setNotice('Dados de acesso copiados.');}}><Copy size={14}/> Copiar</button>
   <button type="button" aria-label="Fechar" onClick={()=>setSecret(null)}><X size={15}/></button></div>}

  <div className="cis-users-bar">
   <label className="cis-msg-search"><Search size={14}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="Procurar por nome, email ou função" aria-label="Procurar utilizador"/></label>
   <div className="cis-msg-queue-filters">{(['activos','inactivos','todos'] as const).map(f=><button key={f} type="button" className={filter===f?'on':''} onClick={()=>setFilter(f)}>{f==='activos'?'Activos':f==='inactivos'?'Inactivos':'Todos'} <b>{f==='todos'?users.length:counts[f]}</b></button>)}</div>
   <button type="button" className="link" onClick={()=>setShowMatrix(v=>!v)}><ShieldCheck size={14}/> {showMatrix?'Ocultar':'Ver'} funções e permissões</button>
  </div>

  {showMatrix&&roles.length>0&&<div className="cis-table-scroll cis-users-matrix"><table><thead><tr><th>Permissão</th>{roles.map(r=><th key={r.id} title={r.descricao}>{r.label}</th>)}</tr></thead>
   <tbody>{perms.map(p=><tr key={p.id}><td>{p.label}</td>{roles.map(r=><td key={r.id} className="c">{r.permissoes.includes(p.id)?<CheckCircle2 size={15} className="yes"/>:<span className="no">—</span>}</td>)}</tr>)}</tbody></table></div>}

  {loading?<p className="cis-msg-muted">A carregar…</p>:<div className="cis-table-scroll"><table className="cis-users-table"><thead><tr><th>Utilizador</th><th>Função</th><th>Estado</th><th>Último acesso</th><th></th></tr></thead>
   <tbody>{shown.map(u=><tr key={u.id} className={u.active?'':'off'}>
    <td><div className="cis-users-who"><span className="cis-initial">{u.name.charAt(0).toUpperCase()}</span><div><b>{u.name}</b><small>{u.email}{u.telefone?' · '+u.telefone:''}</small></div></div></td>
    <td><span className={'cis-users-role r-'+u.role}>{u.role_label}</span></td>
    <td>{!u.active?<span className="cis-users-state off">Inactivo</span>:u.must_change_password?<span className="cis-users-state wait">Aguarda 1.º acesso</span>:<span className="cis-users-state on">Activo</span>}</td>
    <td>{when(u.last_login_at)}</td>
    <td className="cis-users-actions">
     <button type="button" onClick={()=>{setEditing(u);setSecret(null);}} title="Editar"><Pencil size={14}/> Editar</button>
     <button type="button" onClick={()=>void reset(u)} title="Repor palavra-passe"><KeyRound size={14}/></button>
     {u.sessoes>0&&<button type="button" onClick={()=>void act('/cis/utilizadores/'+u.id+'/terminar-sessoes')} title="Terminar sessões abertas"><LogOut size={14}/></button>}
     <button type="button" className={u.active?'danger':''} onClick={()=>void toggle(u)}>{u.active?'Desactivar':'Reactivar'}</button>
    </td></tr>)}
    {!shown.length&&<tr><td colSpan={5} className="cis-msg-muted">Nenhum utilizador neste filtro.</td></tr>}</tbody></table></div>}

  <button type="button" className="link cis-users-audit-toggle" onClick={()=>setShowAudit(v=>!v)}><History size={14}/> {showAudit?'Ocultar':'Ver'} registo de alterações</button>
  {showAudit&&<ul className="cis-users-audit">{audit.map((a,i)=><li key={i}><span>{when(a.created_at)}</span><b>{ACCAO[a.accao]??a.accao}</b> — {a.alvo??'—'}{a.detalhe?<> ({a.detalhe})</>:null}<small>por {a.actor??'sistema'}</small></li>)}
   {!audit.length&&<li className="cis-msg-muted">Sem registos.</li>}</ul>}

  {editing&&<UserForm token={token} roles={roles} user={editing==='novo'?null:editing} onClose={()=>setEditing(null)}
   onSaved={(n,s)=>{setEditing(null);setNotice(n);if(s)setSecret(s);void load();}}/>}
 </section>;
}

function UserForm({token,roles,user,onClose,onSaved}:{token:string;roles:Role[];user:U|null;onClose:()=>void;onSaved:(notice:string,secret?:{nome:string;email:string;senha:string})=>void}){
 const [name,setName]=useState(user?.name??''),[email,setEmail]=useState(user?.email??''),[tel,setTel]=useState(user?.telefone??''),[role,setRole]=useState(user?.role??'cobrador');
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const sel=roles.find(r=>r.id===role);
 async function submit(e:FormEvent){e.preventDefault();setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/utilizadores'+(user?'/'+user.id:''),{method:user?'PATCH':'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({name,email,telefone:tel||null,role})});
   const j=await r.json().catch(()=>({})) as {notice?:string;palavra_passe_temporaria?:string;message?:string;errors?:Record<string,string[]>};
   if(!r.ok)throw new Error(Object.values(j.errors??{}).flat()[0]||j.message||'Não foi possível guardar.');
   onSaved(j.notice??'Guardado.',j.palavra_passe_temporaria?{nome:name,email:email.trim().toLowerCase(),senha:j.palavra_passe_temporaria}:undefined);
  }catch(err){setError(err instanceof Error?err.message:'Falhou.');}finally{setBusy(false);}}
 return <div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label={user?'Editar utilizador':'Novo utilizador'} onClick={onClose}>
  <form className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onSubmit={submit}>
   <div className="cis-users-form-head"><h3>{user?'Editar utilizador':'Novo utilizador'}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <label>Nome completo<input value={name} onChange={e=>setName(e.target.value)} required minLength={3} maxLength={120} autoFocus/></label>
   <div className="cis-users-form-row">
    <label>Email (para entrar)<input type="email" value={email} onChange={e=>setEmail(e.target.value)} required maxLength={190}/></label>
    <label>Telefone<input inputMode="tel" value={tel} onChange={e=>setTel(e.target.value)} maxLength={30} placeholder="Opcional"/></label>
   </div>
   <fieldset className="cis-users-roles"><legend>Função</legend>
    {roles.map(r=><label key={r.id} className={role===r.id?'on':''}><input type="radio" name="cis-user-role" checked={role===r.id} onChange={()=>setRole(r.id)}/><span><b>{r.label}</b><small>{r.descricao}</small></span></label>)}
   </fieldset>
   {sel&&<p className="cis-msg-muted">Pode: {sel.permissoes.length} de 13 permissões. Veja a tabela «funções e permissões» para o detalhe.</p>}
   {!user&&<p className="cis-users-hint"><KeyRound size={14}/> Ao criar, o CIS gera uma palavra-passe temporária (mostrada uma vez) que a pessoa troca no primeiro acesso.</p>}
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}>{busy?'A guardar…':user?'Guardar alterações':'Criar utilizador'}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </form></div>;
}
