import {useEffect,useState} from 'react';
import {BarChart3,FileSpreadsheet,Users} from 'lucide-react';
import {Badge,Person,date,money as fmtMoney,monthShort} from './rh/ui';

const API=import.meta.env.VITE_API_URL||'/api';
const currentMonth=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit'}).format(new Date()).slice(0,7);
const money=(v:string|number)=>fmtMoney(v);
type Totals={period:string;closed_at:string|null;total:number;finalized:number;pending:number;modified:number;net:string;base:string;inss:string;irps:string};
type Line={id:number;name:string;code:string;company:string;status:string;amounts:Record<string,string>|null};
type Result={period:string;date_end:string;companies:string[];current:Totals;previous:Totals;employees:number;approved_leave:number;
 indemnities:{agreed:string;paid:string;balance:string;overdue:string};rows:{data:Line[];last_page:number}};
const comparison={total:'Lançamentos',finalized:'Finalizados',pending:'Pendentes',modified:'Alterados',base:'Vencimento base',inss:'Total INSS',irps:'IRPS',net:'Salário líquido'} as const;
const columns={gross:'Vencimento',base:'Vencimento base',employee_inss:'INSS colaborador',employer_inss:'INSS empresa',irps:'IRPS',net:'Salário líquido'} as const;

export default function RhReportsDesk({token}:{token:string}){
 const [period,setPeriod]=useState(currentMonth),[company,setCompany]=useState(''),[page,setPage]=useState(1);
 const [result,setResult]=useState<Result|null>(null),[companies,setCompanies]=useState<string[]>([]),[error,setError]=useState('');
 useEffect(()=>{
  if(!period)return;
  const ctrl=new AbortController();setResult(null);setError('');
  const params=new URLSearchParams({period,page:String(page),...(company?{company}:{})});
  fetch(API+'/cis/rh/relatorios?'+params,{signal:ctrl.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const d=await r.json();if(!r.ok)throw new Error(d.message||'Falha ao carregar relatórios.');return d as Result;})
   .then(d=>{if(!ctrl.signal.aborted){setResult(d);setCompanies(d.companies);}})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e.message);});
  return ()=>ctrl.abort();
 },[token,period,company,page]);
 return <section className="cis-panel cis-proposals rh-reports">
  <div className="rh-toolbar">
   <label className="cis-profile-field"><span>Mês de referência</span><input type="month" value={period} onChange={e=>{setPeriod(e.target.value);setPage(1);}}/></label>
   <label className="cis-profile-field"><span>Empresa</span><select value={company} onChange={e=>{setCompany(e.target.value);setPage(1);}}>
    <option value="">Todas as empresas</option>{companies.map(c=><option key={c}>{c}</option>)}</select></label>
  </div>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {!period&&<p>Seleccione um mês.</p>}
  {period&&!result&&!error&&<p role="status">A carregar os relatórios…</p>}
  {result&&<>
   <div className="cis-stat-grid">
    <article className="cis-panel cis-stat"><div className="cis-stat-top">Colaboradores activos no cadastro <Users size={17}/></div><strong>{result.employees}</strong></article>
    <article className="cis-panel cis-stat"><div className="cis-stat-top">Férias aprovadas que abrangem o mês</div><strong>{result.approved_leave}</strong></article>
    <article className="cis-panel cis-stat"><div className="cis-stat-top">Salário líquido finalizado</div><strong>{money(result.current.net)}</strong></article>
    <article className="cis-panel cis-stat"><div className="cis-stat-top">Saldo de indemnizações no fim do mês</div><strong>{money(result.indemnities.balance)}</strong></article>
   </div>
   <div className="rh-reports-grid">
    <article className="rh-card"><h4><BarChart3 size={16}/> Comparativo salarial</h4><p>Os valores monetários incluem apenas lançamentos finalizados.</p>
     <div className="cis-table-scroll"><table className="rh-table"><thead><tr><th>Indicador</th><th className="num">{monthShort(result.previous.period)}</th><th className="num">{monthShort(result.current.period)}</th><th className="num">Variação</th></tr></thead>
      <tbody>{(Object.entries(comparison) as [keyof typeof comparison,string][]).map(([k,label])=>{
       const current=result.current[k],previous=result.previous[k],change=Number(current)-Number(previous);
       return <tr key={k}><td>{label}</td><td className="num">{typeof previous==='number'?previous:money(previous)}</td><td className="num">{typeof current==='number'?current:money(current)}</td>
        <td className={'num '+(change>0?'rh-up':change<0?'rh-down':'muted')}>{change>0?'+':''}{typeof current==='number'?change:money(change)}</td></tr>;
      })}</tbody></table></div>
     <p className="rh-note">{result.current.closed_at?'Mês fechado em '+date(result.current.closed_at.slice(0,10))+'.':'Mês ainda não fechado.'}</p>
    </article>
    <article className="rh-card"><h4>Indemnizações até {date(result.date_end)}</h4>
     <dl className="rh-kpis"><div><dt>Acordado</dt><dd>{money(result.indemnities.agreed)}</dd></div><div><dt>Pago</dt><dd>{money(result.indemnities.paid)}</dd></div>
      <div><dt>Saldo em dívida</dt><dd>{money(result.indemnities.balance)}</dd></div><div><dt>Vencido antes da data de corte</dt><dd>{money(result.indemnities.overdue)}</dd></div></dl>
    </article>
   </div>
   <h4><FileSpreadsheet size={16}/> Mapa salarial do mês</h4>
   {!result.rows.data.length?<p>Sem lançamentos para os filtros seleccionados.</p>:<div className="cis-table-scroll rh-scroll"><table className="rh-table rh-cards"><thead><tr><th>Colaborador</th><th>Estado</th>{Object.values(columns).map(x=><th key={x} className="num">{x}</th>)}</tr></thead>
    <tbody>{result.rows.data.map(row=><tr key={row.id}><td><Person name={row.name} code={row.code} company={row.company}/></td><td data-label="Estado"><Badge state={row.amounts?row.status:'por_conferir'}/></td>
     {Object.keys(columns).map(k=><td key={k} data-label={columns[k as keyof typeof columns]} className="num">{row.amounts?.[k]!=null?money(row.amounts[k]):'—'}</td>)}</tr>)}</tbody>
    <tfoot><tr><td>Totais{result.rows.last_page>1?' (esta página)':''}</td><td></td>{Object.keys(columns).map(k=><td key={k} data-label={columns[k as keyof typeof columns]} className="num">{money(result.rows.data.reduce((a,row)=>a+Number(row.amounts?.[k]??0),0))}</td>)}</tr></tfoot></table></div>}
   {result.rows.last_page>1&&<div className="cis-prop-actions"><button type="button" disabled={page<=1} onClick={()=>setPage(v=>v-1)}>Anterior</button>
    <span>{page} / {result.rows.last_page}</span><button type="button" disabled={page>=result.rows.last_page} onClick={()=>setPage(v=>v+1)}>Seguinte</button></div>}
  </>}
 </section>;
}
