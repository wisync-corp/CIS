import {useEffect,useState,type FormEvent} from 'react';
import {KeyRound,Plus,X} from 'lucide-react';
import {Badge,Empty,Pager,date} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
type Account={id:number;name:string;company:string;expires_at:string;revoked_at:string|null};
type Event={id:number;name:string;company:string;operation:string;row_count:number|null;created_at:string;user:string|null};
type Data={accounts:{data:Account[];last_page:number};events:Event[];companies:string[]};
const events:Record<string,string>={criar:'Conta criada',revogar:'Conta revogada',ler_colaboradores:'Consulta ao cadastro'};

export default function RhIntegrationsDesk({token}:{token:string}){
 const [data,setData]=useState<Data|null>(null),[page,setPage]=useState(1),[rev,setRev]=useState(0);
 const [name,setName]=useState(''),[company,setCompany]=useState(''),[days,setDays]=useState('30');
 const [secret,setSecret]=useState(''),[revoke,setRevoke]=useState<Account|null>(null);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[adding,setAdding]=useState(false),[copied,setCopied]=useState(false);
 const url=API+'/cis/rh/integracoes';
 useEffect(()=>{
  const c=new AbortController();
  fetch(url+'?page='+page,{signal:c.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message);return d as Data;})
   .then(d=>{if(!c.signal.aborted)setData(d);}).catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[url,token,page,rev]);
 async function request(path:string,body:unknown){
  const r=await fetch(url+path,{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(body)});
  const d=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message||'Falha na operação.');
  return d;
 }
 async function issue(e:FormEvent){
  e.preventDefault();if(busy)return;
  setBusy(true);setError('');setNotice('');setSecret('');
  try{const d=await request('',{name,company,valid_days:Number(days)});setSecret(d.token);setName('');setPage(1);setRev(x=>x+1);}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 async function confirm(){
  if(!revoke||busy)return;
  setBusy(true);setError('');
  try{await request('/'+revoke.id+'/revogar',{});setRevoke(null);setSecret('');setRev(x=>x+1);setNotice('Conta revogada.');}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 const now=new Date().toISOString().slice(0,10);
 const dt=(v:string)=>{const t=Date.parse(v);if(v.length<=10||!Number.isFinite(t))return date(v.slice(0,10));
  return new Intl.DateTimeFormat('pt-PT',{timeZone:'Africa/Maputo',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(t)).replace(',','');};
 const copy=()=>{void navigator.clipboard?.writeText(secret).then(()=>setCopied(true)).catch(()=>setCopied(false));};
 return <section className="cis-panel cis-proposals">
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {!data&&!error&&<p role="status">A carregar integrações…</p>}
  {data&&<>
   <div className="rh-toolbar"><p className="rh-note" style={{margin:0,flex:1}}>Cada conta técnica só lê o cadastro de colaboradores de uma empresa e expira na data indicada.</p>
    <div className="rh-toolbar-end">{!adding?<button type="button" className="rh-primary" disabled={!!secret} onClick={()=>setAdding(true)}><Plus size={16}/> Nova conta técnica</button>
     :<button type="button" className="cis-edit-action" onClick={()=>setAdding(false)}><X size={15}/> Fechar formulário</button>}</div></div>
   {adding&&<form className="rh-form" onSubmit={e=>void issue(e)}>
    <div className="rh-form-head"><h4>Nova conta técnica</h4></div>
    <div className="rh-fields">
     <label className="cis-profile-field"><span>Sistema de origem</span><input required maxLength={120} placeholder="Ex.: Sistema de assiduidade" value={name} onChange={e=>setName(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Empresa autorizada</span><select required value={company} onChange={e=>setCompany(e.target.value)}>
      <option value="">Seleccione</option>{data.companies.map(c=><option key={c}>{c}</option>)}</select></label>
     <label className="cis-profile-field"><span>Validade (dias)</span><input type="number" min="1" max="365" required value={days} onChange={e=>setDays(e.target.value)}/><small className="rh-note">Entre 1 e 365.</small></label>
    </div>
    {!data.companies.length&&<p className="rh-note">Registe primeiro os colaboradores da empresa em RH.</p>}
    <div className="cis-prop-actions" style={{marginTop:12}}><button type="submit" className="rh-primary" disabled={busy||!company||!!secret}><KeyRound size={15}/> Gerar credencial</button></div>
   </form>}
   {secret&&<div className="rh-form" role="alert"><div className="rh-form-head"><h4>Credencial gerada</h4></div>
    <p>Copie-a agora para a configuração segura do outro sistema: <strong>não volta a ser mostrada</strong>.</p>
    <div className="rh-search"><input aria-label="Credencial de RH" readOnly value={secret} onFocus={e=>e.target.select()}/><button type="button" onClick={copy}>{copied?'Copiada':'Copiar'}</button></div>
    <div className="cis-prop-actions" style={{marginTop:10}}><button type="button" className="cis-edit-action" onClick={()=>{setSecret('');setCopied(false);setAdding(false);}}>Já copiei · ocultar</button></div>
   </div>}
   <details className="rh-card rh-details"><summary><strong>Como o outro sistema consulta o cadastro</strong></summary>
    <p><code>GET /api/cis/rh/api/v1/colaboradores</code> com o cabeçalho <code>Authorization: Bearer &lt;credencial&gt;</code>. Cada consulta fica registada na auditoria.</p></details>
   <h4 style={{margin:'16px 0 8px'}}>Contas técnicas</h4>
   {data.accounts.data.length===0?<Empty title="Sem contas técnicas."/>:<div className="cis-table-scroll"><table className="rh-table rh-cards"><thead><tr><th>Sistema</th><th>Empresa</th><th>Validade</th><th>Estado</th><th></th></tr></thead>
    <tbody>{data.accounts.data.map(a=><tr key={a.id}><td><strong>{a.name}</strong></td><td data-label="Empresa">{a.company}</td><td data-label="Validade" className="nowrap">{date(a.expires_at.slice(0,10))}</td>
     <td data-label="Estado">{a.revoked_at?<Badge tone="neutral">Revogada</Badge>:a.expires_at.slice(0,10)<now?<Badge tone="warn">Expirada</Badge>:<Badge tone="ok">Activa</Badge>}</td>
     <td>{!a.revoked_at&&<div className="rh-row-actions"><button type="button" className="cis-edit-action" disabled={busy} onClick={()=>setRevoke(a)}>Revogar</button></div>}</td></tr>)}</tbody></table></div>}
   <Pager page={page} last={data.accounts.last_page} onPage={setPage}/>
   {revoke&&<div className="cis-prop-decision"><p>Revogar a credencial de <strong>{revoke.name}</strong> ({revoke.company})? O outro sistema deixa de conseguir consultar o cadastro.</p><div className="cis-prop-actions">
    <button type="button" className="danger" disabled={busy} onClick={()=>void confirm()}>Confirmar revogação</button><button type="button" disabled={busy} onClick={()=>setRevoke(null)}>Cancelar</button></div></div>}
   <h4 style={{margin:'18px 0 8px'}}>Auditoria recente</h4>
   {!data.events.length?<Empty title="Sem operações registadas."/>:<div className="cis-table-scroll"><table className="rh-table rh-cards"><thead><tr><th>Data</th><th>Sistema</th><th>Empresa</th><th>Operação</th><th>Utilizador</th><th className="num">Registos</th></tr></thead>
    <tbody>{data.events.map(e=><tr key={e.id}><td className="nowrap">{dt(e.created_at)}</td><td data-label="Sistema">{e.name}</td><td data-label="Empresa">{e.company}</td><td data-label="Operação">{events[e.operation]??e.operation}</td>
     <td data-label="Utilizador">{e.user??'Conta técnica'}</td><td data-label="Registos" className="num">{e.row_count??'—'}</td></tr>)}</tbody></table></div>}
  </>}
 </section>;
}
