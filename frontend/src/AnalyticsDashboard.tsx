import {useEffect,useState} from 'react';
import {BarChart3,CalendarDays,RefreshCw} from 'lucide-react';
import BranchFilter from './BranchFilter';
import './analytics-dashboard.css';

const API=import.meta.env.VITE_API_URL||'/api';
const number=new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:2});
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const views=['Carteira de microcrédito','Análise de mutuários','Desempenho financeiro'] as const;
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
type Section={id?:string;titulo:string;colunas:string[];linhas:(string|number)[][];total_final?:boolean};
type Report={resumo:[string,string][];seccoes:Section[];nota:string;estado_civil_dashboard?:Section['linhas'];mutuarios_dashboard?:{com_desembolso:number;retencao:string|null;aquisicao:string|null}};
type Stat=[string,string];
const format=(v:string|number)=>{const m=String(v).match(/^(-?\d+(?:\.\d+)?)( MT|%)?$/);return m?(m[2]===' MT'?money:number).format(Number(m[1]))+(m[2]??''):v;};
const rate=(v?:string|null)=>v==null?'—':v+'%';
const title=(s:Section)=>s.titulo.replace(/^\d+\. /,'');
function Table({section}:{section:Section}){
 return <article className="cis-panel cis-analytics-panel"><h3>{title(section)}</h3>
 {section.linhas.length?<div className="cis-table-scroll"><table aria-label={title(section)}><thead><tr>{section.colunas.map(c=><th key={c} scope="col">{c}</th>)}</tr></thead>
 <tbody>{section.linhas.map((row,i)=><tr key={i}>{row.map((v,k)=><td key={k}>{k===0?v:format(v)}</td>)}</tr>)}</tbody></table></div>:<p>Sem registos no período.</p>}</article>;
}
function Chart({section,column,label}:{section:Section;column:string;label:string}){
 const index=section.colunas.indexOf(column);
 if(index<1)return null;
 const rows=section.total_final?section.linhas.slice(0,-1):section.linhas;
 const max=Math.max(0,...rows.map(r=>Math.abs(Number(r[index]))));
 return <article className="cis-panel cis-analytics-panel"><h3>{label}</h3>
 {max>0?<ul className="cis-analytics-bars">{rows.map((row,i)=><li key={i}><span>{row[0]}</span><span className="cis-analytics-track" aria-hidden="true"><span style={{width:Math.abs(Number(row[index]))/max*100+'%'}}/></span><strong>{money.format(Number(row[index]))} MT</strong></li>)}</ul>:<p>Sem valores no período.</p>}</article>;
}
export default function AnalyticsDashboard({token}:{token:string}){
 const date=today();
 const [year,setYear]=useState(date.slice(0,4)),[month,setMonth]=useState(date.slice(5,7)),[branch,setBranch]=useState('');
 const [view,setView]=useState<number>(0),[reload,setReload]=useState(0);
 const [report,setReport]=useState<Report|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 const years=Array.from({length:Number(date.slice(0,4))-1999},(_,i)=>Number(date.slice(0,4))-i);
 const financial=view===2;
 useEffect(()=>{
  const ctrl=new AbortController();
  const first=month==='todos'?year+'-01-01':year+'-'+month+'-01';
  const last=month==='todos'?year+'-12-31':year+'-'+month+'-'+new Date(Date.UTC(Number(year),Number(month),0)).getUTCDate();
  const params=new URLSearchParams(financial?{ano:year}:{inicio:first,fim:last});
  if(branch)params.set('agencia',branch);
  setLoading(true);setError('');setReport(null);
  fetch(API+'/cis/relatorios/'+(financial?'evolucao':'actividade')+'?'+params,{signal:ctrl.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async response=>{const body=await response.json();if(!response.ok)throw new Error(body.message||'Não foi possível carregar o Dashboard.');return body as Report;})
   .then(body=>{if(!ctrl.signal.aborted)setReport(body);})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e instanceof Error?e.message:'Não foi possível carregar o Dashboard.');})
   .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  return ()=>ctrl.abort();
 },[token,year,month,branch,financial,reload]);
 const sections=report?.seccoes??[];
 const civil:Section={titulo:'Estado civil dos mutuários com desembolso',colunas:['Estado civil','Mutuários'],linhas:report?.estado_civil_dashboard??[]};
 const clients=report?.mutuarios_dashboard;
 const stats:Stat[]=view===1?[
  ...(report?.resumo.filter(([key])=>key.startsWith('Mutuários'))??[]),
  ['Mutuários com desembolso',String(clients?.com_desembolso??'—')],
  ['Taxa de retenção',rate(clients?.retencao)],['Taxa de aquisição',rate(clients?.aquisicao)],
 ]:report?.resumo??[];
 const displayed=view===0?['desembolsos','carteira','risco','faixas']:view===1?['genero','exposicao']:['mensal'];
 const chart=sections.find(s=>s.id===(financial?'mensal':'faixas'));
 const monthly=financial?chart:undefined;
 const disbursed=monthly?.colunas.indexOf('Desembolsado (MT)')??-1;
 const growth=monthly&&disbursed>0?{
  ...monthly,colunas:[...monthly.colunas,'Variação mensal dos desembolsos (%)'],
  linhas:monthly.linhas.map((row,i)=>{
   const previous=Number(monthly.linhas[i-1]?.[disbursed]??0);
   return [...row,i>0&&previous>0&&(!monthly.total_final||i<monthly.linhas.length-1)?number.format((Number(row[disbursed])-previous)/previous*100)+'%':'—'];
  }),
 }:undefined;
 return <section className="cis-analytics" aria-label="Dashboard" aria-busy={loading}>
  <div className="cis-panel cis-analytics-toolbar">
   <div className="cis-analytics-views" aria-label="Área de análise">{views.map((name,i)=><button key={name} type="button" className="cis-edit-action" aria-pressed={view===i} onClick={()=>setView(i)}>{name}</button>)}</div>
   <div className="cis-analytics-filters">
    <label><CalendarDays size={15} aria-hidden="true"/> Ano <select value={year} onChange={e=>{setYear(e.target.value);setMonth('todos');}}>{years.map(y=><option key={y}>{y}</option>)}</select></label>
    {!financial&&<label>Mês <select value={month} onChange={e=>setMonth(e.target.value)}><option value="todos">Todos</option>{Array.from({length:year===date.slice(0,4)?Number(date.slice(5,7)):12},(_,i)=>{const m=String(i+1).padStart(2,'0');return <option key={m} value={m}>{new Intl.DateTimeFormat('pt-MZ',{month:'long',timeZone:'UTC'}).format(new Date(Date.UTC(2020,i,1)))}</option>;})}</select></label>}
    <BranchFilter token={token} value={branch} onChange={setBranch}/>
    <button type="button" className="cis-edit-action" disabled={loading} onClick={()=>setReload(v=>v+1)}><RefreshCw size={15}/> Actualizar</button>
   </div>
  </div>
  {loading&&<p className="cis-panel cis-analytics-panel" role="status">A carregar os indicadores…</p>}
  {error&&<div className="cis-table-error" role="alert">{error}</div>}
  {report&&!loading&&<>
   <div className="cis-stat-grid">{stats.map(([label,value])=><article key={label} className="cis-panel cis-stat"><div className="cis-stat-top">{label}<BarChart3 size={17} aria-hidden="true"/></div><strong>{format(value)}</strong></article>)}</div>
   {view===1&&<p className="cis-analytics-note">Retenção compara os mutuários com desembolso no período anterior de igual duração. Aquisição mede a percentagem de mutuários com desembolso que receberam o primeiro crédito no período.</p>}
   {chart&&view===0&&<Chart section={chart} column="Valor (MT)" label="Desembolsos por faixa de crédito"/>}
   {chart&&financial&&<div className="cis-analytics-charts"><Chart section={chart} column="Desembolsado (MT)" label="Desembolsos por mês"/><Chart section={chart} column="Recebido (MT)" label="Recebimentos por mês"/><Chart section={chart} column="Juros (MT)" label="Juros recebidos por mês"/></div>}
   {view===1&&<Table section={civil}/>}
   {displayed.map(id=>{const section=(id==='mensal'?growth:undefined)??sections.find(s=>s.id===id);return section&&<Table key={id} section={section}/>;})}
   <p className="cis-analytics-note">{report.nota}</p>
  </>}
 </section>;
}
