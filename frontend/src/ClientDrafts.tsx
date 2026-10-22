import {useEffect,useState,type FormEvent} from 'react';
import {AlertCircle,CheckCircle2,RefreshCw} from 'lucide-react';
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Draft={id:number;nome_completo:string;contacto:string|null;email:string|null;cidade:string|null;provincia:string|null;estado:string};
type Page={data:Draft[];total:number};
const blank={nome_completo:'',contacto:'',email:'',bairro:'',cidade:'',provincia:''};
export default function ClientDrafts({token}:{token:string}){
 const [form,setForm]=useState({...blank}),[items,setItems]=useState<Draft[]>([]);
 const [busy,setBusy]=useState(false),[message,setMessage]=useState(''),[error,setError]=useState('');
 async function load(){try{const res=await fetch(API+'/cis/client-drafts',{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});
 if(!res.ok)throw new Error('Não foi possível consultar os rascunhos.');const data:Page=await res.json();setItems(data.data);}
 catch(e){setError(e instanceof Error?e.message:'Falha na consulta');}}
 useEffect(()=>{void load();},[token]);
 async function save(ev:FormEvent){ev.preventDefault();setBusy(true);setError('');setMessage('');
 try{const res=await fetch(API+'/cis/client-drafts',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(form)});
 const body=await res.json();
 if(!res.ok){const first=body.errors?Object.values(body.errors)[0]:null;throw new Error(Array.isArray(first)?String(first[0]):body.message||'Não foi possível guardar.');}
 setMessage('Rascunho registado no SQLite local. Nada foi enviado ao MySQL Cloud.');setForm({...blank});await load();
 }catch(e){setError(e instanceof Error?e.message:'Falha no registo');}finally{setBusy(false);}}
 return <section className="cis-panel cis-list-panel">
 <div className="cis-panel-head"><div><h2>Cadastro de clientes — rascunhos</h2><p>Ambiente local de desenvolvimento. Não cria clientes na base MySQL Cloud.</p></div></div>
 <div className="cis-banner"><AlertCircle size={20}/><div><b>Utilize apenas dados fictícios neste formulário.</b><p>O armazenamento local de rascunhos ainda não constitui um cadastro de produção.</p></div></div>
 <form onSubmit={save} className="cis-draft-form">
 {([['nome_completo','Nome completo'],['contacto','Contacto'],['email','Email'],['bairro','Bairro'],['cidade','Cidade'],['provincia','Província']] as const).map(([key,label])=><label key={key}>{label}<input required={key==='nome_completo'} minLength={key==='nome_completo'?3:undefined} type={key==='email'?'email':'text'} maxLength={key==='contacto'?30:155} value={form[key]} onChange={e=>setForm(v=>({...v,[key]:e.target.value}))}/></label>)}
 <button type="submit" disabled={busy}>{busy?'A guardar…':'Guardar rascunho local'}</button></form>
 {message&&<div className="cis-draft-ok" role="status"><CheckCircle2 size={18}/>{message}</div>}
 {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
 <div className="cis-panel-head cis-draft-list-head"><h2>Rascunhos guardados</h2><button type="button" onClick={()=>void load()} aria-label="Actualizar rascunhos"><RefreshCw size={18}/></button></div>
 <div className="cis-table-scroll"><table><thead><tr><th>ID</th><th>Nome</th><th>Cidade</th><th>Província</th><th>Estado</th></tr></thead><tbody>
 {items.map(item=><tr key={item.id}><td>{item.id}</td><td>{item.nome_completo}</td><td>{item.cidade||'—'}</td><td>{item.provincia||'—'}</td><td>{item.estado}</td></tr>)}
 </tbody></table>{items.length===0&&<div className="cis-table-none">Ainda não existem rascunhos.</div>}</div>
 </section>;
}
