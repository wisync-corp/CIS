// Agenda de cobrança do dia (folha de cobrança, modelo Loandisk): promessas, seguimentos e atrasos sem contacto.
import {useCallback,useEffect,useState} from 'react';
import {CalendarCheck,HandCoins,AlertTriangle,PhoneCall,Clock,Send,RefreshCw,MessageCircle,ArrowUpRight,X,CalendarDays} from 'lucide-react';
import {ContactForm,type Contact} from './ContractContacts';
import BranchFilter from './BranchFilter';

const API=import.meta.env.VITE_API_URL||'/api';
type Row={contrato:string;cliente:string;cliente_id:number|null;telefone:string|null;em_divida:string;vencido:string;dias_atraso:number;proximo_vencimento:string|null;contacto?:Contact;ultimo_contacto?:string|null};
type Agenda={instalado:boolean;hoje:string;promessas_hoje:Row[];promessas_falhadas:Row[];seguimentos:Row[];sem_contacto:Row[];vencem_hoje:Row[];dias_sem_contacto:number;lembretes_por_enviar:number|null};
const mt=(v:unknown)=>Number(v??0).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const dt=(v:string|null|undefined)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';
const wa=(t:string|null)=>{const d=(t??'').replace(/\D/g,'');return d.length>=9?'https://wa.me/'+(d.length===9?'258'+d:d):null;};

export default function CollectionAgenda({token,onOpenContract,onSendReminders}:{token:string;onOpenContract:(n:string)=>void;onSendReminders:()=>void}){
 const [a,setA]=useState<Agenda|null>(null),[error,setError]=useState(''),[agencia,setAgencia]=useState(''),[busy,setBusy]=useState(false);
 const [contact,setContact]=useState<Row|null>(null),[notice,setNotice]=useState('');
 const load=useCallback(async()=>{setBusy(true);setError('');try{const r=await fetch(API+'/cis/cobranca/agenda'+(agencia?'?agencia='+agencia:''),{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});
  const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível carregar a agenda.');setA(j as Agenda);}catch(e){setError((e as Error).message);}finally{setBusy(false);}},[token,agencia]);
 useEffect(()=>{void load();},[load]);
 const sections:{key:keyof Agenda;title:string;icon:typeof HandCoins;tone:string;hint:string}[]=[
  {key:'promessas_hoje',title:'Promessas para hoje',icon:HandCoins,tone:'info',hint:'Confirmar o pagamento prometido.'},
  {key:'promessas_falhadas',title:'Promessas falhadas',icon:AlertTriangle,tone:'bad',hint:'A data passou sem o pagamento prometido.'},
  {key:'seguimentos',title:'Contactos agendados',icon:Clock,tone:'warn',hint:'Próximo contacto marcado para hoje ou antes.'},
  {key:'sem_contacto',title:'Em atraso sem contacto',icon:PhoneCall,tone:'bad',hint:`Sem contacto há ${a?.dias_sem_contacto??7} dias ou mais.`},
  {key:'vencem_hoje',title:'Vencem hoje',icon:CalendarDays,tone:'muted',hint:'Prestações com vencimento hoje.'},
 ];
 return <section className="cis-panel cis-agenda">
  <div className="cis-users-head"><div><h3><CalendarCheck size={18}/> Agenda de cobrança · {dt(a?.hoje)}</h3>
   <p>Tudo o que precisa de acção hoje na sua carteira. Registe cada contacto: as promessas são verificadas automaticamente contra os pagamentos.</p></div>
   <div className="cis-prop-actions"><BranchFilter token={token} value={agencia} onChange={setAgencia}/>
    {a?.lembretes_por_enviar!=null&&<button type="button" className="primary" disabled={!a.lembretes_por_enviar} onClick={onSendReminders}><Send size={15}/> Lembretes de hoje ({a.lembretes_por_enviar})</button>}
    <button type="button" onClick={()=>void load()} disabled={busy}><RefreshCw size={15}/> Actualizar</button></div></div>
  {a&&!a.instalado&&<div className="cis-contract-notice"><AlertTriangle size={17}/><span>Contactos e promessas ainda não instalados. Na VPS execute: <code>bash deploy/install-collection-contacts.sh</code></span></div>}
  {error&&<div className="cis-table-error" role="alert"><AlertTriangle size={16}/>{error}</div>}
  {notice&&<div className="cis-msg-notice" role="status">{notice}</div>}
  {a&&<div className="cis-agenda-cards">{sections.map(s=><a key={s.key} href={'#ag-'+s.key} className={'cis-agenda-card '+s.tone}><s.icon size={18}/><b>{(a[s.key] as Row[]).length}</b><span>{s.title}</span></a>)}</div>}
  {a&&sections.map(s=>{const rows=a[s.key] as Row[];if(!rows.length)return null;return <div key={s.key} id={'ag-'+s.key} className="cis-agenda-section">
   <h4><s.icon size={16}/> {s.title} <small>{s.hint}</small></h4>
   <div className="cis-table-scroll"><table><thead><tr><th>Mutuário</th><th>Contrato</th><th>{s.key.startsWith('promessas')?'Promessa':'Situação'}</th><th>Em dívida</th><th>Último contacto</th><th></th></tr></thead>
    <tbody>{rows.map(r=><tr key={s.key+r.contrato}>
     <td><b>{r.cliente}</b><small>{r.telefone??'sem contacto'}</small></td><td>{r.contrato}</td>
     <td>{r.contacto?.promessa?<>{mt(r.contacto.promessa.valor)} até {dt(r.contacto.promessa.data)}{Number(r.contacto.promessa.pago??0)>0&&<small>recebido {mt(r.contacto.promessa.pago)}</small>}</>
      :r.dias_atraso>0?<span className="cis-prop-badge bad">{r.dias_atraso} dia(s) de atraso</span>:<span className="cis-prop-badge muted">vence {dt(r.proximo_vencimento)}</span>}</td>
     <td>{mt(r.em_divida)}{Number(r.vencido)>0&&<small>vencido {mt(r.vencido)}</small>}</td>
     <td>{r.contacto?<>{r.contacto.resultado_label}<small>{r.contacto.por} · {dt(r.contacto.em)}</small></>:r.ultimo_contacto?dt(r.ultimo_contacto):'Nunca'}</td>
     <td className="cis-agenda-actions">
      {wa(r.telefone)&&<a href={wa(r.telefone)!} target="_blank" rel="noreferrer" title="Abrir WhatsApp"><MessageCircle size={15}/></a>}
      <button type="button" className="primary" onClick={()=>setContact(r)}><PhoneCall size={14}/> Registar</button>
      <button type="button" onClick={()=>onOpenContract(r.contrato)} title="Abrir ficha"><ArrowUpRight size={15}/></button></td>
    </tr>)}</tbody></table></div></div>;})}
  {a&&sections.every(s=>!(a[s.key] as Row[]).length)&&<p className="cis-msg-muted">Nada pendente hoje na sua carteira.</p>}
  {contact&&<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Registar contacto" onClick={()=>setContact(null)}>
   <div className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()}>
    <div className="cis-users-form-head"><h3>Contacto · {contact.cliente} <small>{contact.contrato}</small></h3><button type="button" aria-label="Fechar" onClick={()=>setContact(null)}><X size={17}/></button></div>
    <ContactForm token={token} numero={contact.contrato} suggested={Number(contact.vencido)>0?contact.vencido:undefined} onCancel={()=>setContact(null)}
     onSaved={c=>{setContact(null);setNotice(c.promessa?'Contacto e promessa registados.':'Contacto registado.');void load();}}/>
   </div></div>}
 </section>;
}
