// Visão geral de RH: estado da folha do mês, o que requer atenção e atalhos com contagens.
import {useEffect,useState} from 'react';
import {AlertTriangle,CalendarDays,CheckCircle2,FileSignature,FilePen,HandCoins,Receipt,Users,Wallet} from 'lucide-react';
import {Badge,money,monthName} from './ui';
import type {RhSection} from '../RhRecordsDesk';

const API=import.meta.env.VITE_API_URL||'/api';
const currentMonth=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit'}).format(new Date()).slice(0,7);
type Totals={total:number;finalized:number;pending:number;modified:number;net:string;base:string;inss:string;irps:string;closed_at:string|null};
type Report={current:Totals;employees:number;approved_leave:number;indemnities:{agreed:string;paid:string;balance:string;overdue:string}};
type Alert={tone:'bad'|'warn'|'ok'|'info';title:string;text:string;go?:{label:string;to:RhSection|'folha'|'relatorios'}};

const TILES:{group:string;items:{key:RhSection;icon:typeof Users;label:string;hint:string}[]}[]=[
 {group:'Pessoas',items:[
  {key:'colaboradores',icon:Users,label:'Colaboradores',hint:'Cadastro e contas do CIS'},
  {key:'contratos',icon:FileSignature,label:'Contratos de trabalho',hint:'Vigência, regime e valor'},
  {key:'adendas',icon:FilePen,label:'Adendas',hint:'Alterações aos contratos'},
  {key:'ferias',icon:CalendarDays,label:'Férias',hint:'Pedidos e aprovações'}]},
 {group:'Remuneração',items:[
  {key:'indemnizacoes',icon:HandCoins,label:'Indemnizações',hint:'Acordos e planos'},
  {key:'prestacoes',icon:Receipt,label:'Prestações de indemnização',hint:'Pagamentos previstos'}]},
];

export default function RhOverview({token,totals,onNavigate,onOpen}:{token:string;totals:Partial<Record<RhSection,number>>;onNavigate:(s:RhSection)=>void;onOpen:(page:'Folha salarial'|'Relatórios de RH')=>void}){
 const period=currentMonth();
 const [report,setReport]=useState<Report|null>(null),[status,setStatus]=useState<'por_abrir'|'aberto'|'fechado'|null>(null),[error,setError]=useState('');
 useEffect(()=>{
  const c=new AbortController(),h={Authorization:'Bearer '+token,Accept:'application/json'};
  const get=(path:string)=>fetch(API+path,{headers:h,signal:c.signal}).then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message||'Não foi possível carregar o resumo.');return d;});
  Promise.all([get('/cis/rh/relatorios?'+new URLSearchParams({period})),get('/cis/rh/folha?'+new URLSearchParams({period,page:'1'}))])
   .then(([r,f])=>{if(!c.signal.aborted){setReport(r);setStatus(f.period_status??null);}})
   .catch(e=>{if(!c.signal.aborted)setError(e.message);});
  return ()=>c.abort();
 },[token,period]);
 const t=report?.current,total=t?.total??0,pct=(n:number)=>total?(100*n/total)+'%':'0%';
 const alerts:Alert[]=[];
 if(report&&t){
  if(status==='por_abrir')alerts.push({tone:'warn',title:'A folha de '+monthName(period)+' ainda não foi aberta',text:'Abra o mês e gere os lançamentos a partir dos contratos aprovados.',go:{label:'Abrir folha',to:'folha'}});
  if(status==='aberto'&&t.pending)alerts.push({tone:'warn',title:t.pending+' lançamento(s) pendente(s)',text:'Confirme presenças, faltas e subsídios e calcule cada lançamento.',go:{label:'Conferir',to:'folha'}});
  if(status==='aberto'&&t.modified)alerts.push({tone:'info',title:t.modified+' lançamento(s) alterado(s) por finalizar',text:'Reveja a prévia do cálculo e finalize.',go:{label:'Finalizar',to:'folha'}});
  if(status==='aberto'&&total>0&&t.finalized===total)alerts.push({tone:'ok',title:'Todos os lançamentos estão finalizados',text:'O mês pode ser fechado.',go:{label:'Fechar mês',to:'folha'}});
  if(Number(report.indemnities.overdue)>0)alerts.push({tone:'bad',title:'Prestações de indemnização vencidas: '+money(report.indemnities.overdue),text:'Registe os pagamentos efectuados ou reveja o plano.',go:{label:'Ver prestações',to:'prestacoes'}});
  if(!alerts.length)alerts.push({tone:'ok',title:'Sem pendências',text:status==='fechado'?'A folha deste mês está fechada.':'Nada requer atenção neste momento.'});
 }
 const go=(a:Alert)=>{if(!a.go)return;if(a.go.to==='folha')onOpen('Folha salarial');else if(a.go.to==='relatorios')onOpen('Relatórios de RH');else onNavigate(a.go.to);};
 return <div className="rh-ov">
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  <div className="rh-ov-top">
   <section className="rh-card" aria-labelledby="rh-ov-folha">
    <div className="rh-head"><div><h4 id="rh-ov-folha">Folha de {monthName(period)}</h4><p>Período de 21 do mês anterior a 20 do mês.</p></div>{status&&<Badge state={status}/>}</div>
    {!report&&!error&&<p role="status">A carregar o estado da folha…</p>}
    {t&&<>
     <div className="rh-progress" role="img" aria-label={`${t.finalized} finalizados, ${t.modified} alterados e ${t.pending} pendentes de ${total}`}>
      <i className="p-ok" style={{width:pct(t.finalized)}}/><i className="p-info" style={{width:pct(t.modified)}}/><i className="p-warn" style={{width:pct(t.pending)}}/></div>
     <div className="rh-legend"><span><b>{total}</b> lançamentos</span><span><b>{t.finalized}</b> finalizados</span><span><b>{t.modified}</b> alterados</span><span><b>{t.pending}</b> pendentes</span></div>
     <dl className="rh-kpis">
      <div><dt>Salário líquido finalizado</dt><dd>{money(t.net)}</dd></div><div><dt>Vencimento base</dt><dd>{money(t.base)}</dd></div>
      <div><dt>Total INSS</dt><dd>{money(t.inss)}</dd></div><div><dt>IRPS</dt><dd>{money(t.irps)}</dd></div>
     </dl>
     <div className="rh-toolbar" style={{marginTop:16}}><button type="button" className="rh-primary" onClick={()=>onOpen('Folha salarial')}><Wallet size={16}/> Abrir folha salarial</button>
      <button type="button" className="cis-edit-action" onClick={()=>onOpen('Relatórios de RH')}>Ver relatórios</button></div>
    </>}
   </section>
   <section className="rh-card" aria-labelledby="rh-ov-atencao">
    <h4 id="rh-ov-atencao"><AlertTriangle size={16}/> Requer atenção</h4><p>O que falta fazer este mês.</p>
    {report?<ul className="rh-alerts">{alerts.map(a=><li key={a.title} className={a.tone}>{a.tone==='ok'?<CheckCircle2 size={18}/>:<AlertTriangle size={18}/>}
     <div><strong>{a.title}</strong><small>{a.text}</small></div>{a.go&&<button type="button" className="cis-edit-action" onClick={()=>go(a)}>{a.go.label}</button>}</li>)}</ul>
     :!error&&<p role="status">A verificar…</p>}
    {report&&<dl className="rh-kpis" style={{marginTop:16}}>
     <div><dt>Colaboradores activos</dt><dd>{report.employees}</dd></div><div><dt>Férias aprovadas no mês</dt><dd>{report.approved_leave}</dd></div>
     <div><dt>Indemnizações por pagar</dt><dd>{money(report.indemnities.balance)}</dd></div><div><dt>Já pago</dt><dd>{money(report.indemnities.paid)}</dd></div>
    </dl>}
   </section>
  </div>
  {TILES.map(g=><section key={g.group} aria-label={g.group}><div className="rh-group-title">{g.group}</div>
   <div className="rh-tiles">{g.items.map(({key,icon:Icon,label,hint})=><button key={key} type="button" className="rh-tile" onClick={()=>onNavigate(key)}>
    <Icon size={20}/><div><b>{totals[key]??0}</b><span>{label}</span><small>{hint}</small></div></button>)}</div></section>)}
 </div>;
}
