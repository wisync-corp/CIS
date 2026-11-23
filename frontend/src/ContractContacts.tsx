// Contactos de cobrança e promessas de pagamento de um contrato (modelo Loandisk).
import {useCallback,useEffect,useState,type FormEvent} from 'react';
import {PhoneCall,AlertCircle,CheckCircle2,CalendarClock,HandCoins,Ban} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
export type Contact={id:number;contrato:string;canal:string;canal_label:string;resultado:string;resultado_label:string;notas:string|null;proximo_contacto:string|null;
 promessa:{valor:string;data:string;estado:'pendente'|'cumprida'|'falhada'|'cancelada';pago:string|null;resolvida_em:string|null}|null;por:string;em:string};
export const CANAIS:Record<string,string>={chamada:'Chamada',whatsapp:'WhatsApp',sms:'SMS',visita:'Visita',presencial:'Na agência',outro:'Outro'};
export const RESULTADOS:Record<string,string>={prometeu_pagar:'Prometeu pagar',vai_pagar:'Vai pagar no vencimento',sem_resposta:'Sem resposta',numero_errado:'Número errado / indisponível',recusou:'Recusou pagar',ja_pagou:'Diz que já pagou',outro:'Outro'};
const PROM:Record<string,{label:string;tone:string}>={pendente:{label:'Promessa pendente',tone:'info'},cumprida:{label:'Promessa cumprida',tone:'ok'},falhada:{label:'Promessa falhada',tone:'bad'},cancelada:{label:'Promessa cancelada',tone:'muted'}};
const today=()=>{const p=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());const g=(t:string)=>p.find(x=>x.type===t)?.value??'';return g('year')+'-'+g('month')+'-'+g('day');};
const dt=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';
const mt=(v:unknown)=>Number(v??0).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';

export function ContactForm({token,numero,suggested,onSaved,onCancel}:{token:string;numero:string;suggested?:string;onSaved:(c:Contact)=>void;onCancel?:()=>void}){
 const [canal,setCanal]=useState('chamada'),[resultado,setResultado]=useState('sem_resposta'),[notas,setNotas]=useState('');
 const [valor,setValor]=useState(suggested??''),[data,setData]=useState(today()),[next,setNext]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const promise=resultado==='prometeu_pagar';
 async function submit(e:FormEvent){e.preventDefault();setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/contactos',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
   body:JSON.stringify({canal,resultado,notas:notas||null,proximo_contacto:next||null,...(promise?{promessa_valor:valor,promessa_data:data}:{})})});
   const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error((j.errors?Object.values(j.errors as Record<string,string[]>).flat()[0]:undefined)||j.message||'Não foi possível registar.');
   onSaved(j as Contact);setNotas('');setNext('');}catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 return <form className="cis-contact-form" onSubmit={e=>void submit(e)}>
  <div className="cis-profile-fields">
   <label className="cis-profile-field"><span>Canal</span><select value={canal} onChange={e=>setCanal(e.target.value)}>{Object.entries(CANAIS).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></label>
   <label className="cis-profile-field"><span>Resultado</span><select value={resultado} onChange={e=>setResultado(e.target.value)}>{Object.entries(RESULTADOS).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></label>
   {promise&&<><label className="cis-profile-field"><span>Valor prometido (MT)</span><input type="number" min="1" step="0.01" required value={valor} onChange={e=>setValor(e.target.value)}/></label>
    <label className="cis-profile-field"><span>Data prometida</span><input type="date" required min={today()} value={data} onChange={e=>setData(e.target.value)}/><small>No dia, o mutuário recebe o lembrete da promessa.</small></label></>}
   {!promise&&<label className="cis-profile-field"><span>Próximo contacto</span><input type="date" min={today()} value={next} onChange={e=>setNext(e.target.value)}/><small>Aparece na agenda nesse dia.</small></label>}
   <label className="cis-profile-field cis-prop-wide"><span>Notas</span><textarea rows={2} maxLength={2000} value={notas} onChange={e=>setNotas(e.target.value)} placeholder="O que foi dito, quem atendeu…"/></label>
  </div>
  {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
  <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}><PhoneCall size={15}/> {busy?'A registar…':'Registar contacto'}</button>{onCancel&&<button type="button" onClick={onCancel}>Cancelar</button>}</div>
 </form>;
}

export function ContactList({items,token,onChanged}:{items:Contact[];token:string;onChanged:()=>void}){
 async function cancel(id:number){if(!window.confirm('Cancelar esta promessa?'))return;
  await fetch(API+'/cis/contactos/'+id+'/cancelar-promessa',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json'}});onChanged();}
 if(!items.length)return <p className="cis-msg-muted">Ainda não há contactos registados.</p>;
 return <ol className="cis-contact-list">{items.map(c=><li key={c.id}>
  <div className="cis-contact-line"><b>{c.canal_label} · {c.resultado_label}</b><small>{c.por} · {c.em?.slice(0,16).replace('T',' ')}</small></div>
  {c.notas&&<p>{c.notas}</p>}
  {c.promessa&&<p className="cis-contact-promise"><HandCoins size={14}/><span className={'cis-prop-badge '+PROM[c.promessa.estado].tone}>{PROM[c.promessa.estado].label}</span>
   {mt(c.promessa.valor)} até {dt(c.promessa.data)}{Number(c.promessa.pago??0)>0?` · recebido ${mt(c.promessa.pago)}`:''}
   {c.promessa.estado==='pendente'&&<button type="button" className="cis-edit-action" onClick={()=>void cancel(c.id)}><Ban size={12}/> cancelar</button>}</p>}
  {c.proximo_contacto&&<p className="cis-contact-next"><CalendarClock size={14}/> Próximo contacto: {dt(c.proximo_contacto)}</p>}
 </li>)}</ol>;
}

export default function ContractContacts({token,numero,suggested}:{token:string;numero:string;suggested?:string}){
 const [items,setItems]=useState<Contact[]|null>(null),[installed,setInstalled]=useState(true),[canAdd,setCanAdd]=useState(false),[notice,setNotice]=useState('');
 const load=useCallback(async()=>{const r=await fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/contactos',{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});
  const j=await r.json().catch(()=>({}));if(r.ok){setInstalled(j.instalado!==false);setItems(j.data??[]);setCanAdd(!!j.pode_registar);}},[numero,token]);
 useEffect(()=>{void load();},[load]);
 if(!installed)return <div className="cis-contract-notice"><AlertCircle size={17}/><span>Contactos e promessas ainda não instalados. Na VPS execute: <code>bash deploy/install-collection-contacts.sh</code></span></div>;
 return <section className="cis-contract-operation">
  {canAdd&&<><h4><PhoneCall size={16}/> Registar contacto</h4><ContactForm token={token} numero={numero} suggested={suggested} onSaved={c=>{setNotice(c.promessa?'Contacto e promessa registados.':'Contacto registado.');void load();}}/></>}
  {notice&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
  <h4 style={{marginTop:20}}>Histórico de contactos</h4>
  {items===null?<p className="cis-msg-muted">A carregar…</p>:<ContactList items={items} token={token} onChanged={()=>void load()}/>}
 </section>;
}
