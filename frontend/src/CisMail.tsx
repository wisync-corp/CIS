import {useCallback,useEffect,useState,type FormEvent} from 'react';
import {Mail,RefreshCw} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
const purposes={geral:'Geral',cobrancas:'Cobranças',documentos:'Contratos e recibos',subscricoes:'Subscrições'};
type Purpose=keyof typeof purposes;
type Provider='smtp'|'microsoft'|'google';
const providers={smtp:'Outro fornecedor (SMTP)',microsoft:'Microsoft 365',google:'Google Workspace'};
type Account={id:number;sender:string;label:string;provider:Provider;authorized?:boolean;tested_at?:string|null};
type History={id:number;sender:string;recipient:string;subject:string;status:string;created_at:string};
type Data={accounts:Account[];defaults:Partial<Record<Purpose,number>>;callback?:string;history?:History[]};
const empty={label:'',sender:'',provider:'microsoft' as Provider,tenant:'',client_id:'',secret:'',host:'',port:'587',security:'starttls',username:''};
const statusNames:Record<string,string>={aceite:'Aceite pelo fornecedor',por_confirmar:'Por confirmar',em_envio:'Em envio'};

function useMailApi(token:string){
 return useCallback(async<T,>(path:string,method='GET',payload?:unknown):Promise<T>=>{
  const r=await fetch(API+'/cis/email'+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},...(payload?{body:JSON.stringify(payload)}:{})});
  const result=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(Object.values(result.errors??{}).flat()[0] as string||result.message||'O serviço de e-mail está indisponível.');
  return result as T;
 },[token]);
}

export function MailSettings({token}:{token:string}){
 const api=useMailApi(token);
 const [data,setData]=useState<Data|null>(null),[form,setForm]=useState(empty);
 const [recipient,setRecipient]=useState(''),[purpose,setPurpose]=useState<Purpose>('geral'),[account,setAccount]=useState('');
 const [connection,setConnection]=useState(''),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 const load=useCallback(async()=>{setError('');try{setData(await api<Data>('/contas'));}catch(e){setError((e as Error).message);}},[api]);
 useEffect(()=>{void load();},[load]);
 async function change(path:string,method:string,payload:unknown,message:string){
  setBusy(true);setNotice('');setError('');
  try{setData(await api<Data>(path,method,payload));setNotice(message);return true;}
  catch(e){setError((e as Error).message);return false;}
  finally{setBusy(false);}
 }
 async function authorize(id:number){
  setBusy(true);setConnection('');setError('');
  try{setConnection((await api<{url:string}>('/contas/'+id+'/autorizar','POST')).url);}
  catch(e){setError((e as Error).message);}
  finally{setBusy(false);}
 }
 async function save(e:FormEvent){
  e.preventDefault();
  const {label,sender,provider,secret,tenant,client_id,host,port,security,username}=form;
  const payload={label,sender,provider,secret,...(provider==='smtp'?{host,port:Number(port),security,username}:{client_id,...(provider==='microsoft'?{tenant}:{})})};
  if(await change('/contas','POST',payload,'Conta guardada. Termine a ligação e envie um teste.')){setForm(empty);setConnection('');}
 }
 const field=(key:Exclude<keyof typeof empty,'provider'|'security'>,label:string,type='text')=>
  <label className="cis-profile-field"><span>{label}</span><input required type={type} value={form[key]} onChange={e=>setForm({...form,[key]:e.target.value})}/></label>;
 return <section className="cis-panel cis-proposals" aria-label="Definições de e-mail">
  <header className="cis-prop-head"><div><h3>Contas de envio de e-mail</h3><p>Configure o fornecedor da instituição e valide a conta com um envio de teste.</p></div>
   <button className="cis-edit-action" type="button" onClick={()=>void load()} disabled={busy}><RefreshCw size={15}/> Actualizar</button></header>
  {error&&<p className="cis-table-error" role="alert">{error}</p>}{notice&&<p className="cis-msg-notice" role="status">{notice}</p>}
  {data&&<>
   <form className="cis-prop-decision" onSubmit={e=>void save(e)}>
    <h4>Configurar remetente</h4>
    <div className="cis-profile-fields">
     <label className="cis-profile-field"><span>Fornecedor</span><select value={form.provider} onChange={e=>setForm({...empty,label:form.label,sender:form.sender,provider:e.target.value as Provider})}>{Object.entries(providers).map(([id,name])=><option key={id} value={id}>{name}</option>)}</select></label>
     {field('label','Nome da conta')}{field('sender','Endereço de envio','email')}
     {form.provider==='smtp'?<>{field('host','Servidor SMTP')}{field('port','Porta','number')}{field('username','Utilizador')}
      <label className="cis-profile-field"><span>Segurança</span><select value={form.security} onChange={e=>setForm({...form,security:e.target.value})}><option value="starttls">STARTTLS</option><option value="tls">TLS implícito</option></select></label>
     </>:<>{field('client_id','ID da aplicação')}{form.provider==='microsoft'&&field('tenant','ID do inquilino Microsoft Entra')}</>}
     {field('secret',form.provider==='smtp'?'Palavra-passe SMTP':'Segredo da aplicação','password')}
    </div>
    {form.provider!=='smtp'&&<p>URI de redireccionamento a registar <code>{data.callback}</code></p>}
    <button type="submit" className="cis-edit-action" disabled={busy}>Guardar conta</button>
   </form>
   {connection&&<p><a href={connection} target="_blank" rel="noreferrer">Abrir autorização no fornecedor</a>. Ao terminar, volte e actualize a lista.</p>}
   {!!data.accounts.length&&<>
    <label className="cis-profile-field"><span>Destinatário do teste</span><input type="email" value={recipient} onChange={e=>setRecipient(e.target.value)}/></label>
    <div className="cis-table-scroll"><table><thead><tr><th>Conta</th><th>Fornecedor</th><th>Estado</th><th>Acções</th></tr></thead><tbody>{data.accounts.map(a=><tr key={a.id}>
     <td><strong>{a.label}</strong><br/>{a.sender}</td><td>{providers[a.provider]}</td><td>{a.tested_at?'Testada':a.authorized?'Por testar':'Por autorizar'}</td>
     <td>{a.provider!=='smtp'&&<button type="button" className="cis-edit-action" disabled={busy} onClick={()=>void authorize(a.id)}>{a.authorized?'Voltar a autorizar':'Autorizar'}</button>}
      <button type="button" className="cis-edit-action" disabled={busy||!a.authorized||!recipient} onClick={()=>void change('/contas/'+a.id+'/teste','POST',{recipient},'E-mail de teste aceite pelo fornecedor.')}>Enviar teste</button></td>
    </tr>)}</tbody></table></div>
    <form className="cis-prop-decision" onSubmit={e=>{e.preventDefault();void change('/predefinicoes','PUT',{purpose,account_id:Number(account)},'Remetente predefinido actualizado.');}}>
     <h4>Remetentes predefinidos</h4>
     <div className="cis-profile-fields">
      <label className="cis-profile-field"><span>Comunicação</span><select value={purpose} onChange={e=>{setPurpose(e.target.value as Purpose);setAccount('');}}>{Object.entries(purposes).map(([id,text])=><option key={id} value={id}>{text}</option>)}</select></label>
      <label className="cis-profile-field"><span>Conta</span><select value={account||data.defaults[purpose]||''} onChange={e=>setAccount(e.target.value)}><option value="">Seleccione uma conta testada</option>{data.accounts.filter(a=>a.tested_at).map(a=><option key={a.id} value={a.id}>{a.label} · {a.sender}</option>)}</select></label>
     </div>
     <button type="submit" className="cis-edit-action" disabled={busy||!account}>Guardar predefinição</button>
    </form>
   </>}
  </>}
 </section>;
}

export function MailComposer({token}:{token:string}){
 const api=useMailApi(token);
 const [data,setData]=useState<Data|null>(null),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
 const [account,setAccount]=useState(''),[recipient,setRecipient]=useState(''),[subject,setSubject]=useState(''),[body,setBody]=useState('');
 const [purpose,setPurpose]=useState<Purpose>('geral');
 const load=useCallback(async()=>{try{setData(await api<Data>('/opcoes'));}catch(e){setError((e as Error).message);}},[api]);
 useEffect(()=>{void load();},[load]);
 const usable=data?.accounts??[];
 const candidate=data?.defaults[purpose]??data?.defaults.geral;
 const selected=account||(usable.some(a=>a.id===candidate)?String(candidate):'');
 async function send(e:FormEvent){
  e.preventDefault();if(busy||!selected)return;
  setBusy(true);setError('');setNotice('');
  try{await api('/enviar','POST',{account_id:Number(selected),recipient,subject,body,purpose});setNotice('Mensagem aceite pelo fornecedor de e-mail.');setRecipient('');setSubject('');setBody('');}
  catch(e){setError((e as Error).message);}
  finally{await load();setBusy(false);}
 }
 return <div className="cis-prop-decision">
  {error&&<p role="alert" className="cis-table-error">{error}</p>}{notice&&<p role="status" className="cis-msg-notice">{notice}</p>}
  {data&&<form onSubmit={e=>void send(e)}>
   {usable.length===0&&<p>Configure e teste uma conta em Definições → E-mail.</p>}
   <div className="cis-profile-fields">
    <label className="cis-profile-field"><span>Comunicação</span><select value={purpose} onChange={e=>{setPurpose(e.target.value as Purpose);setAccount('');}}>{Object.entries(purposes).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label>
    <label className="cis-profile-field"><span>Remetente</span><select value={selected} required onChange={e=>setAccount(e.target.value)}><option value="">Seleccione a conta</option>{usable.map(a=><option key={a.id} value={a.id}>{a.label} · {a.sender}</option>)}</select></label>
    <label className="cis-profile-field"><span>Destinatário</span><input type="email" value={recipient} required onChange={e=>setRecipient(e.target.value)}/></label>
    <label className="cis-profile-field"><span>Assunto</span><input value={subject} maxLength={200} required onChange={e=>setSubject(e.target.value)}/></label>
   </div>
   <label className="cis-profile-field"><span>Mensagem</span><textarea rows={6} value={body} maxLength={15000} required onChange={e=>setBody(e.target.value)}/></label>
   <button className="cis-edit-action" type="submit" disabled={busy||!selected}><Mail size={15}/> {busy?'A enviar…':'Enviar e-mail'}</button>
  </form>}
  {!!data?.history?.length&&<div className="cis-table-scroll"><table><thead><tr><th>Data</th><th>Remetente</th><th>Destinatário</th><th>Assunto</th><th>Estado</th></tr></thead>
   <tbody>{data.history.map(row=><tr key={row.id}><td>{row.created_at}</td><td>{row.sender}</td><td>{row.recipient}</td><td>{row.subject}</td><td>{statusNames[row.status]??row.status}</td></tr>)}</tbody></table></div>}
 </div>;
}
