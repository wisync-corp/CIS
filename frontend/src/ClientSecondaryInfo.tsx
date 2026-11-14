import {useEffect,useState,type FormEvent} from 'react';
import {ShieldCheck, X} from 'lucide-react';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const fields=[
 ['ponto_referencia','Ponto de referência da morada'],
 ['local_trabalho_actividade','Local de trabalho / actividade'],
 ['fonte_rendimento_principal','Principal fonte de rendimento'],
 ['contacto_alternativo','Contacto alternativo'],
 ['pessoa_referencia_nome','Nome da pessoa de referência'],
 ['pessoa_referencia_contacto','Contacto da pessoa de referência'],
 ['melhor_horario_contacto','Melhor horário para contacto'],
] as const;
type Field=(typeof fields)[number][0];
type FormValues=Record<Field,string>;
const empty=Object.fromEntries(fields.map(([field])=>[field,''])) as FormValues;

export default function ClientSecondaryInfo({id,token,onClose}:{id:number;token:string;onClose:()=>void}){
 const [form,setForm]=useState<FormValues>({...empty});
 const [informed,setInformed]=useState(false);
 const [busy,setBusy]=useState(true);
 const [error,setError]=useState('');
 const [success,setSuccess]=useState('');
 useEffect(()=>{
  const controller=new AbortController();setBusy(true);setError('');
  fetch(API+'/cis/clientes/'+id+'/complementares',{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},
   signal:controller.signal
  }).then(async response=>{
   if(!response.ok)throw new Error(response.status===503?'Cadastro complementar ainda não está activado no servidor.':'Não foi possível consultar o cadastro complementar.');
   return response.json() as Promise<Partial<Record<Field,string|null>>>;
  }).then(data=>{
   setForm(Object.fromEntries(fields.map(([k])=>[k,k==='melhor_horario_contacto'?(data[k]??'').slice(0,5):(data[k]??'')])) as FormValues);
  }).catch(e=>{if(e.name!=='AbortError')setError(e.message);})
   .finally(()=>{if(!controller.signal.aborted)setBusy(false);});
  return ()=>controller.abort();
 },[id,token]);
 async function save(e:FormEvent){
  e.preventDefault();setBusy(true);setError('');setSuccess('');
  try{
   const res=await fetch(API+'/cis/clientes/'+id+'/complementares',{
    method:'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({...form,referencia_contacto_informado:informed})
   });
   if(!res.ok){
    const details=await res.json().catch(()=>null) as {message?:string}|null;
    throw new Error(details?.message || 'Não foi possível guardar as informações complementares.');
   }
   setSuccess('Informações complementares guardadas na base do CIS.');
  }catch(e){setError(e instanceof Error?e.message:'Erro ao guardar.');}
  finally{setBusy(false);}
 }
 const hasThirdParty=Boolean(form.pessoa_referencia_nome.trim()||form.pessoa_referencia_contacto.trim());
 return <div className="cis-edit-backdrop" role="presentation">
  <section className="cis-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="cis-secondary-title">
   <div className="cis-secondary-title"><h3 id="cis-secondary-title"><ShieldCheck size={20}/> Informações complementares · Cliente #{id}</h3><button type="button" onClick={onClose} aria-label="Fechar"><X size={19}/></button></div>
   <p>Dados adicionais de contacto e verificação. A morada, contacto principal, BI e outros dados existentes não são repetidos.</p>
   <form onSubmit={save}>
    {fields.map(([key,label])=><label key={key}>{label}<input
      type={key==='melhor_horario_contacto'?'time':'text'} step={key==='melhor_horario_contacto'?60:undefined} autoComplete="off" maxLength={key==='ponto_referencia'?255:key==='contacto_alternativo'||key==='pessoa_referencia_contacto'?40:key==='melhor_horario_contacto'?100:180}
      value={form[key]} onChange={e=>{setSuccess('');setForm(prev=>({...prev,[key]:e.target.value}));}}/>
    </label>)}
    {hasThirdParty&&<label className="cis-secondary-confirm"><input type="checkbox" checked={informed} onChange={e=>setInformed(e.target.checked)}/>
      Confirmo que o cliente foi informado sobre a finalidade da recolha do contacto da pessoa de referência.</label>}
    {error&&<div className="cis-table-error" role="alert">{error}</div>}
    {success&&<p role="status" className="cis-secondary-success">{success}</p>}
    <div className="cis-edit-actions">
     <button type="button" onClick={onClose} disabled={busy}>Fechar</button>
     <button type="submit" disabled={busy}>{busy?'A processar…':'Guardar informações'}</button>
    </div>
   </form>
  </section>
 </div>;
}
