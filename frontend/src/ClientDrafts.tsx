import {useEffect,useState,type FormEvent} from 'react';
import {AlertCircle,CheckCircle2,RefreshCw} from 'lucide-react';
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const fields=[['nome_completo','Nome completo'],['numero_bi','Número de BI'],['nacionalidade','Nacionalidade'],['contacto','Contacto'],['email','Email'],['bairro','Bairro'],['cidade','Cidade'],['provincia','Província'],['rua','Rua'],['numero_casa','N.º da casa'],['numero_quarteirao','N.º do quarteirão'],['estado_civil','Estado civil'],['naturalidade','Naturalidade'],['data_emissao_bi','Data de emissão do BI'],['conduta','Conduta']] as const;
type Key=typeof fields[number][0];
type Form=Record<Key,string>;
type Client=Form & {id:number};
type Page={data:Client[];total:number};
const empty=Object.fromEntries(fields.map(([k])=>[k,''])) as Form;
export default function ClientDrafts({token}:{token:string}){
 const [form,setForm]=useState<Form>({...empty}),[items,setItems]=useState<Client[]>([]);
 const [editing,setEditing]=useState<number|null>(null),[busy,setBusy]=useState(false);
 const [message,setMessage]=useState(''),[error,setError]=useState(''),[search,setSearch]=useState('');
 const [reload,setReload]=useState(0);
 useEffect(()=>{const ctrl=new AbortController();setError('');
 const params=new URLSearchParams();if(search.trim())params.set('q',search.trim());
 fetch(API+'/cis/clients?'+params,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
 .then(async res=>{if(!res.ok)throw new Error('Não foi possível consultar os clientes locais.');return res.json();})
 .then((data:Page)=>setItems(data.data)).catch(e=>{if(e.name!=='AbortError')setError(e.message);});
 return ()=>ctrl.abort();},[token,search,reload]);
 async function save(ev:FormEvent){ev.preventDefault();setBusy(true);setError('');setMessage('');
 try{const path=editing===null?'/cis/clients':'/cis/clients/'+editing;
 const res=await fetch(API+path,{method:editing===null?'POST':'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(form)});
 const body=await res.json();
 if(!res.ok){const first=body.errors?Object.values(body.errors)[0]:null;throw new Error(Array.isArray(first)?String(first[0]):body.message||'Não foi possível guardar.');}
 setMessage(editing===null?'Cliente registado com sucesso na base local do CIS.':'Dados do cliente actualizados.');
 setForm({...empty});setEditing(null);setReload(n=>n+1);
 }catch(e){setError(e instanceof Error?e.message:'Falha ao guardar');}finally{setBusy(false);}}
 function edit(row:Client){setEditing(row.id);setForm(Object.fromEntries(fields.map(([k])=>[k,String(row[k]??'')])) as Form);setMessage('');window.scrollTo({top:0,behavior:'smooth'});}
 return <section className="cis-panel cis-list-panel">
 <div className="cis-panel-head"><div><h2>{editing===null?'Registar cliente':'Editar cliente #'+editing}</h2><p>Cadastro do CIS · dados guardados na base local SQLite, sem alterações ao MySQL Cloud.</p></div></div>
 <div className="cis-banner"><AlertCircle size={20}/><div><b>Ambiente de desenvolvimento — utilize clientes fictícios.</b><p>Este cadastro é funcional no CIS local. A sincronização com a cloud permanece desactivada até validação das permissões e TLS.</p></div></div>
 <form onSubmit={save} className="cis-draft-form">{fields.map(([key,label])=><label key={key}>{label}<input required={key==='nome_completo'} minLength={key==='nome_completo'?3:undefined} type={key==='email'?'email':key==='data_emissao_bi'?'date':'text'} maxLength={key==='contacto'?30:155} value={form[key]} onChange={e=>setForm(v=>({...v,[key]:e.target.value}))}/></label>)}
 <button type="submit" disabled={busy}>{busy?'A guardar…':editing===null?'Registar cliente':'Guardar alterações'}</button>
 {editing!==null&&<button type="button" onClick={()=>{setEditing(null);setForm({...empty});}} className="cis-draft-cancel">Cancelar edição</button>}</form>
 {message&&<div className="cis-draft-ok" role="status"><CheckCircle2 size={18}/>{message}</div>}
 {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
 <div className="cis-panel-head cis-draft-list-head"><h2>Clientes registados</h2><button type="button" onClick={()=>setReload(n=>n+1)} aria-label="Actualizar clientes"><RefreshCw size={18}/></button></div>
 <div className="cis-table-controls"><input aria-label="Pesquisar clientes locais" placeholder="Pesquisar nome do cliente" value={search} onChange={e=>setSearch(e.target.value)}/></div>
 <div className="cis-table-scroll"><table><thead><tr><th>ID</th><th>Nome</th><th>Contacto</th><th>Cidade</th><th>Província</th><th>Acção</th></tr></thead><tbody>
 {items.map(item=><tr key={item.id}><td>{item.id}</td><td>{item.nome_completo}</td><td>{item.contacto||'—'}</td><td>{item.cidade||'—'}</td><td>{item.provincia||'—'}</td><td><button type="button" onClick={()=>edit(item)} className="cis-client-edit">Editar</button></td></tr>)}
 </tbody></table>{items.length===0&&<div className="cis-table-none">Ainda não existem clientes registados.</div>}</div>
 </section>;
}
