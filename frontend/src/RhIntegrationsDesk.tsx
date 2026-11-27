import {useEffect,useState,type FormEvent} from 'react';
import {KeyRound} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Account={id:number;name:string;company:string;expires_at:string;revoked_at:string|null};
type Event={id:number;name:string;company:string;operation:string;row_count:number|null;created_at:string;user:string|null};
type Data={accounts:{data:Account[];last_page:number};events:Event[];companies:string[]};
const events:Record<string,string>={criar:'Conta criada',revogar:'Conta revogada',ler_colaboradores:'Consulta ao cadastro'};

export default function RhIntegrationsDesk({token}:{token:string}){
 const [data,setData]=useState<Data|null>(null),[page,setPage]=useState(1),[rev,setRev]=useState(0);
 const [name,setName]=useState(''),[company,setCompany]=useState(''),[days,setDays]=useState('30');
 const [secret,setSecret]=useState(''),[revoke,setRevoke]=useState<Account|null>(null);
 const [error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
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
 return <section className="cis-panel cis-proposals">
  <div className="cis-prop-head"><div><h3><KeyRound size={17}/> Integrações de RH</h3><p>Credenciais técnicas para consultar o cadastro de colaboradores de uma empresa.</p></div></div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {!data&&!error&&<p role="status">A carregar integrações…</p>}
  {data&&<>
   <form className="cis-prop-decision" onSubmit={e=>void issue(e)}>
    <h4>Criar conta técnica</h4>
    <div className="cis-profile-fields">
     <label className="cis-profile-field"><span>Sistema de origem</span><input required maxLength={120} value={name} onChange={e=>setName(e.target.value)}/></label>
     <label className="cis-profile-field"><span>Empresa autorizada</span><select required value={company} onChange={e=>setCompany(e.target.value)}>
      <option value="">Seleccione</option>{data.companies.map(c=><option key={c}>{c}</option>)}</select></label>
     <label className="cis-profile-field"><span>Validade em dias</span><input type="number" min="1" max="365" required value={days} onChange={e=>setDays(e.target.value)}/></label>
    </div>
    {!data.companies.length&&<p>Registe primeiro os colaboradores da empresa em RH.</p>}
    <button type="submit" className="cis-edit-action" disabled={busy||!company||!!secret}>Gerar credencial</button>
   </form>
   {secret&&<div className="cis-prop-decision"><h4>Credencial da integração</h4><p>Copie para a configuração segura do sistema. Será mostrada apenas nesta operação.</p>
    <input aria-label="Credencial de RH" readOnly value={secret} onFocus={e=>e.target.select()}/>
    <button type="button" className="cis-edit-action" onClick={()=>setSecret('')}>Ocultar credencial</button>
   </div>}
   <p>Consulta por <code>GET /api/cis/rh/api/v1/colaboradores</code>. Envie a credencial no cabeçalho <code>Authorization: Bearer …</code>.</p>
   {data.accounts.data.length===0?<p>Sem contas técnicas.</p>:<div className="cis-table-scroll"><table><thead><tr><th>Sistema</th><th>Empresa</th><th>Validade</th><th>Estado</th><th>Acções</th></tr></thead>
    <tbody>{data.accounts.data.map(a=><tr key={a.id}><td>{a.name}</td><td>{a.company}</td><td>{a.expires_at}</td><td>{a.revoked_at?'Revogada':'Emitida'}</td>
     <td>{!a.revoked_at&&<button type="button" className="cis-edit-action" disabled={busy} onClick={()=>setRevoke(a)}>Revogar</button>}</td></tr>)}</tbody></table></div>}
   {data.accounts.last_page>1&&<div className="cis-prop-actions"><button type="button" disabled={page<=1} onClick={()=>setPage(x=>x-1)}>Anterior</button>
    <span>{page} / {data.accounts.last_page}</span><button type="button" disabled={page>=data.accounts.last_page} onClick={()=>setPage(x=>x+1)}>Seguinte</button></div>}
   {revoke&&<div className="cis-prop-decision"><p>Revogar a credencial de {revoke.name}?</p><div className="cis-prop-actions">
    <button type="button" className="danger" disabled={busy} onClick={()=>void confirm()}>Confirmar revogação</button><button type="button" disabled={busy} onClick={()=>setRevoke(null)}>Cancelar</button></div></div>}
   <h4>Auditoria recente</h4>
   {!data.events.length?<p>Sem operações registadas.</p>:<div className="cis-table-scroll"><table><thead><tr><th>Data</th><th>Sistema</th><th>Empresa</th><th>Operação</th><th>Utilizador</th><th>Registos</th></tr></thead>
    <tbody>{data.events.map(e=><tr key={e.id}><td>{e.created_at}</td><td>{e.name}</td><td>{e.company}</td><td>{events[e.operation]??e.operation}</td>
     <td>{e.user??'Conta técnica'}</td><td>{e.row_count??'—'}</td></tr>)}</tbody></table></div>}
  </>}
 </section>;
}
