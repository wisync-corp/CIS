// Definições › Carteiras: gestor de carteira por contrato (atribuição em massa) e agentes.
import {useCallback,useEffect,useState,type FormEvent} from 'react';
import {Briefcase,UserRound,AlertCircle,CheckCircle2,X,Shuffle,FileText,UsersRound} from 'lucide-react';
import {useBranches} from './branches';

const API=import.meta.env.VITE_API_URL||'/api';
type Person={id:number;nome:string};
type Summary={cobradores:(Person&{contratos:number;activos:number})[];agentes:(Person&{mutuarios:number})[];activos_sem_responsavel:number;contratos_activos:number;cidades:{cidade:string;contratos:number}[];mutuarios_sem_agente?:number;cidades_mutuarios?:{cidade:string;mutuarios:number}[]};

export default function PortfoliosAdmin({token}:{token:string}){
 const [s,setS]=useState<Summary|null>(null),[error,setError]=useState(''),[notice,setNotice]=useState(''),[assign,setAssign]=useState<Person|null>(null),[assignC,setAssignC]=useState<Person|null>(null);
 const load=useCallback(async()=>{setError('');
  try{const r=await fetch(API+'/cis/carteiras',{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});
   const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível carregar as carteiras.');setS(j as Summary);}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível carregar as carteiras.');}},[token]);
 useEffect(()=>{void load();},[load]);
 return <section className="cis-panel cis-users cis-branches">
  <div className="cis-users-head"><div><h3><Briefcase size={18}/> Carteiras</h3><p>O gestor de carteira (função Cobrador) só vê os contratos de que é responsável; o agente só vê os mutuários que cadastrou. Atribua aqui em massa, ou um a um na ficha do contrato ou do mutuário.</p></div></div>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {notice&&!error&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
  {!s&&!error&&<p className="cis-msg-muted">A carregar…</p>}
  {s&&<>
   {s.activos_sem_responsavel>0&&<div className="cis-contract-notice" role="status"><AlertCircle size={17}/><span><strong>{s.activos_sem_responsavel} de {s.contratos_activos} contrato(s) em curso</strong> ainda sem gestor de carteira. Só o administrador e os gestores de crédito os acompanham.</span></div>}
   <h4 className="cis-portfolio-title"><Briefcase size={15}/> Gestores de carteira</h4>
   {s.cobradores.length===0?<p className="cis-msg-muted">Não há utilizadores com a função Cobrador / gestor de carteira. Crie-os em Utilizadores.</p>:
   <div className="cis-branch-grid">{s.cobradores.map(c=><article key={c.id} className="cis-branch-card">
    <header><span className="cis-branch-code">CARTEIRA</span></header><h4>{c.nome}</h4>
    <dl><div><dt><FileText size={13}/> Contratos</dt><dd>{c.contratos}</dd></div><div><dt><FileText size={13}/> Em curso</dt><dd>{c.activos}</dd></div></dl>
    <footer><button type="button" onClick={()=>setAssign(c)}><Shuffle size={13}/> Atribuir contratos</button></footer>
   </article>)}</div>}
   <h4 className="cis-portfolio-title"><UsersRound size={15}/> Agentes</h4>
   {(s.mutuarios_sem_agente??0)>0&&s.agentes.length>0&&<div className="cis-contract-notice" role="status"><AlertCircle size={17}/><span><strong>{s.mutuarios_sem_agente} mutuário(s)</strong> ainda sem agente (cadastros antigos). Use «Atribuir mutuários» para os distribuir por cidade ou agência.</span></div>}
   {s.agentes.length===0?<p className="cis-msg-muted">Não há utilizadores com a função Agente.</p>:
   <div className="cis-branch-grid">{s.agentes.map(a=><article key={a.id} className="cis-branch-card">
    <header><span className="cis-branch-code">AGENTE</span></header><h4>{a.nome}</h4>
    <dl><div><dt><UserRound size={13}/> Mutuários</dt><dd>{a.mutuarios}</dd></div></dl>
    <p className="cis-branch-where cis-msg-muted">Os mutuários que cadastra ficam automaticamente na sua carteira.</p>
    <footer><button type="button" onClick={()=>setAssignC(a)}><Shuffle size={13}/> Atribuir mutuários</button></footer>
   </article>)}</div>}
  </>}
  {assignC&&s&&<AssignClients token={token} person={assignC} cities={s.cidades_mutuarios??[]} onClose={()=>setAssignC(null)} onSaved={n=>{setAssignC(null);setNotice(n);void load();}}/>}
  {assign&&s&&<AssignForm token={token} person={assign} cities={s.cidades} onClose={()=>setAssign(null)} onSaved={n=>{setAssign(null);setNotice(n);void load();}}/>}
 </section>;
}

function AssignForm({token,person,cities,onClose,onSaved}:{token:string;person:Person;cities:{cidade:string;contratos:number}[];onClose:()=>void;onSaved:(n:string)=>void}){
 const branches=useBranches(token);
 const [city,setCity]=useState(''),[branch,setBranch]=useState(''),[activeOnly,setActiveOnly]=useState(true),[freeOnly,setFreeOnly]=useState(true);
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 async function submit(e:FormEvent){e.preventDefault();
  if(!city&&!branch){setError('Escolha a cidade ou a agência.');return;}
  if(!window.confirm(`Atribuir a ${person.nome} os contratos ${[city&&'de '+city,branch&&'da agência '+(branches.agencias.find(b=>String(b.id)===branch)?.nome??'')].filter(Boolean).join(' e ')}${freeOnly?' que ainda não têm gestor de carteira':''}?`))return;
  setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/carteiras/atribuir',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({user_id:person.id,cidade:city||null,agencia:branch?Number(branch):null,so_activos:activeOnly,so_sem_responsavel:freeOnly})});
   const j=await r.json().catch(()=>({})) as {notice?:string;message?:string};if(!r.ok)throw new Error(j.message||'Falhou.');onSaved(j.notice??'Feito.');}
  catch(err){setError(err instanceof Error?err.message:'Falhou.');}finally{setBusy(false);}}
 return <div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Atribuir contratos" onClick={onClose}>
  <form className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onSubmit={submit}>
   <div className="cis-users-form-head"><h3>Atribuir contratos a {person.nome}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <p className="cis-msg-muted">Escolha a cidade registada nos contratos e/ou a agência. Um contrato também pode ser mudado individualmente na sua ficha.</p>
   <label>Cidade dos contratos<select value={city} onChange={e=>setCity(e.target.value)}><option value="">Qualquer cidade</option>{cities.map(c=><option key={c.cidade} value={c.cidade}>{c.cidade} · {c.contratos} em curso</option>)}</select></label>
   {branches.agencias.length>1&&<label>Agência<select value={branch} onChange={e=>setBranch(e.target.value)}><option value="">Qualquer agência</option>{branches.agencias.map(b=><option key={b.id} value={b.id}>{b.nome}</option>)}</select></label>}
   <label className="cis-check"><input type="checkbox" checked={activeOnly} onChange={e=>setActiveOnly(e.target.checked)}/> Só contratos em curso (com prestações por pagar)</label>
   <label className="cis-check"><input type="checkbox" checked={freeOnly} onChange={e=>setFreeOnly(e.target.checked)}/> Só contratos ainda sem gestor de carteira</label>
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy||(!city&&!branch)}>{busy?'A atribuir…':'Atribuir'}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </form></div>;
}

function AssignClients({token,person,cities,onClose,onSaved}:{token:string;person:Person;cities:{cidade:string;mutuarios:number}[];onClose:()=>void;onSaved:(n:string)=>void}){
 const branches=useBranches(token);
 const [city,setCity]=useState(''),[branch,setBranch]=useState(''),[freeOnly,setFreeOnly]=useState(true);
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 async function submit(e:FormEvent){e.preventDefault();
  if(!city&&!branch){setError('Escolha a cidade ou a agência.');return;}
  if(!window.confirm(`Atribuir a ${person.nome} os mutuários ${[city&&'de '+city,branch&&'da agência '+(branches.agencias.find(b=>String(b.id)===branch)?.nome??'')].filter(Boolean).join(' e ')}${freeOnly?' que ainda não têm agente':''}?`))return;
  setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/carteiras/atribuir-mutuarios',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({user_id:person.id,cidade:city||null,agencia:branch?Number(branch):null,so_sem_agente:freeOnly})});
   const j=await r.json().catch(()=>({})) as {notice?:string;message?:string};if(!r.ok)throw new Error(j.message||'Falhou.');onSaved(j.notice??'Feito.');}
  catch(err){setError(err instanceof Error?err.message:'Falhou.');}finally{setBusy(false);}}
 return <div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Atribuir mutuários" onClick={onClose}>
  <form className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onSubmit={submit}>
   <div className="cis-users-form-head"><h3>Atribuir mutuários a {person.nome}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <p className="cis-msg-muted">Escolha a cidade do cadastro do mutuário e/ou a agência. Um mutuário também pode ser mudado individualmente na sua ficha.</p>
   <label>Cidade do mutuário<select value={city} onChange={e=>setCity(e.target.value)}><option value="">Qualquer cidade</option>{cities.map(c=><option key={c.cidade} value={c.cidade}>{c.cidade} · {c.mutuarios} mutuário(s)</option>)}</select></label>
   {branches.agencias.length>1&&<label>Agência<select value={branch} onChange={e=>setBranch(e.target.value)}><option value="">Qualquer agência</option>{branches.agencias.map(b=><option key={b.id} value={b.id}>{b.nome}</option>)}</select></label>}
   <label className="cis-check"><input type="checkbox" checked={freeOnly} onChange={e=>setFreeOnly(e.target.checked)}/> Só mutuários ainda sem agente</label>
   {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy||(!city&&!branch)}>{busy?'A atribuir…':'Atribuir'}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </form></div>;
}
