import {useEffect,useState} from 'react';

const API=import.meta.env.VITE_API_URL||'/api';
type Person={id:number;nome:string};
type Data={instalado:boolean;responsavel?:Person|null;agente?:Person|null;pode_atribuir?:boolean;opcoes:Person[]};

/** Gestor de carteira de um contrato ou agente de um mutuário; o administrador pode alterar. */
export default function PortfolioOwner({kind,id,token}:{kind:'contrato'|'mutuario';id:string|number;token:string}){
 const url=API+'/cis/carteiras/'+(kind==='contrato'?'contratos/':'mutuarios/')+encodeURIComponent(String(id));
 const [data,setData]=useState<Data|null>(null),[msg,setMsg]=useState(''),[busy,setBusy]=useState(false);
 useEffect(()=>{const c=new AbortController();
  fetch(url,{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j)setData(j);}).catch(()=>{});
  return()=>c.abort();},[url,token]);
 if(!data?.instalado)return null;
 const cur=(kind==='contrato'?data.responsavel:data.agente)??null;
 const label=kind==='contrato'?'Gestor de carteira':'Agente';
 async function change(v:string){
  setBusy(true);setMsg('');
  try{
   const r=await fetch(url,{method:'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({user_id:v?Number(v):null})});
   const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível alterar.');
   setData(d=>d?{...d,[kind==='contrato'?'responsavel':'agente']:(kind==='contrato'?j.responsavel:j.agente)}:d);setMsg(j.notice??'Alterado.');
  }catch(e){setMsg(e instanceof Error?e.message:'Não foi possível alterar.');}
  finally{setBusy(false);}
 }
 const options=data.opcoes.some(o=>o.id===cur?.id)||!cur?data.opcoes:[cur,...data.opcoes];
 return <span className="cis-contract-branch cis-portfolio-owner" title={msg||undefined}>{label}: {data.pode_atribuir
  ?<select value={cur?.id??''} disabled={busy} onChange={e=>void change(e.target.value)} aria-label={label}>
    <option value="">— sem {kind==='contrato'?'responsável':'agente'} —</option>
    {options.map(o=><option key={o.id} value={o.id}>{o.nome}</option>)}
   </select>
  :<b>{cur?.nome??'—'}</b>}</span>;
}
