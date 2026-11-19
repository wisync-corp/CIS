import {useEffect,useState} from 'react';
import {createPortal} from 'react-dom';
import {BarChart3,FileText,Download,Printer,X,AlertCircle,ShieldCheck,Scale,Landmark,UserCheck,Loader2} from 'lucide-react';
import logoPrincipal from './assets/brand/cis-principal-claro.svg';

const API=import.meta.env.VITE_API_URL||'/api';
type Def={id:string;titulo:string;periodo:'intervalo'|'data'|'ano'|'nenhum';descricao:string;base_legal:string};
type Section={titulo:string;colunas:string[];linhas:string[][]};
type Report=Def&{nota:string;resumo:[string,string][];seccoes:Section[];emitido_em:string;emitido_por:string|null;codigo_verificacao:string;
 intervalo?:{inicio:string;fim:string};data_referencia?:string;ano?:number};

const ICON:Record<string,typeof BarChart3>={actividade:BarChart3,crc:Landmark,branqueamento:Scale,identificacao:UserCheck};
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo'}).format(new Date());
const dt=(v?:string|null)=>{if(!v)return '—';const [y,m,d]=v.slice(0,10).split('-');return d?`${d}/${m}/${y}`:v;};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const isMoneyCol=(c:string)=>/\(MT\)/.test(c);
const fmtCell=(c:string,v:string)=>isMoneyCol(c)&&v!==''&&!isNaN(Number(v))?money.format(Number(v)):(/^\d{4}-\d{2}-\d{2}$/.test(v)?dt(v):v);
const fmtSummary=(v:string)=>{const m=v.match(/^(-?\d+(?:\.\d+)?) MT$/);return m?money.format(Number(m[1]))+' MT':v;};
const fmtRow=(cols:string[],row:string[],j:number)=>{const v=row[j];
 if(cols[j]==='Valor'&&/\(MT\)/.test(row[0]??'')&&v!==''&&!isNaN(Number(v)))return money.format(Number(v));
 return fmtCell(cols[j],v);};
const csvCell=(v:unknown)=>{const s=v===null||v===undefined?'':String(v);return /[";\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;};
function startOf(kind:'mes'|'trimestre'|'ano'|'mes_anterior'){const t=today();const [y,m]=t.split('-').map(Number);
 if(kind==='mes')return [`${y}-${String(m).padStart(2,'0')}-01`,t];
 if(kind==='mes_anterior'){const py=m===1?y-1:y,pm=m===1?12:m-1;const last=new Date(Date.UTC(py,pm,0)).getUTCDate();return [`${py}-${String(pm).padStart(2,'0')}-01`,`${py}-${String(pm).padStart(2,'0')}-${last}`];}
 if(kind==='trimestre'){const q=Math.floor((m-1)/3)*3+1;return [`${y}-${String(q).padStart(2,'0')}-01`,t];}
 return [`${y}-01-01`,t];}

export default function ReportsDesk({token}:{token:string}){
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 const [defs,setDefs]=useState<Def[]>([]);const [sel,setSel]=useState('actividade');
 const [inicio,setInicio]=useState(startOf('mes')[0]);const [fim,setFim]=useState(today());
 const [data,setData]=useState(today());const [ano,setAno]=useState(String(new Date().getFullYear()));
 const [rep,setRep]=useState<Report|null>(null);const [busy,setBusy]=useState(false);const [error,setError]=useState('');
 const [printing,setPrinting]=useState(false);
 const def=defs.find(d=>d.id===sel);

 useEffect(()=>{fetch(API+'/cis/relatorios',{headers}).then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível carregar os relatórios.');return j;})
  .then(j=>setDefs(j.relatorios)).catch(e=>setError(e.message));
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token]);

 async function generate(){
  if(!def)return;setBusy(true);setError('');setRep(null);
  const p=new URLSearchParams();if(def.periodo==='intervalo'){p.set('inicio',inicio);p.set('fim',fim);}if(def.periodo==='data')p.set('data',data);if(def.periodo==='ano')p.set('ano',ano);
  try{const r=await fetch(API+'/cis/relatorios/'+def.id+'?'+p,{headers});const j=await r.json().catch(()=>({}));
   if(!r.ok)throw new Error((j as {message?:string}).message||'Não foi possível gerar o relatório.');setRep(j as Report);}
  catch(e){setError(e instanceof Error?e.message:'Erro.');}finally{setBusy(false);}
 }
 useEffect(()=>{setRep(null);setError('');},[sel]);

 function periodText(r:Report){
  if(r.intervalo)return `${dt(r.intervalo.inicio)} a ${dt(r.intervalo.fim)}`;
  if(r.data_referencia)return `Situação a ${dt(r.data_referencia)}`;if(r.ano)return `Ano ${r.ano}`;return `Situação a ${dt(r.emitido_em)}`;}

 function exportCsv(){
  if(!rep)return;const out:string[][]=[[rep.titulo],[periodText(rep)],[`Emitido em ${rep.emitido_em}`,rep.emitido_por??''],[`Código de verificação ${rep.codigo_verificacao}`],[]];
  out.push(['Resumo']);for(const [k,v] of rep.resumo)out.push([k,v]);out.push([]);
  for(const s of rep.seccoes){out.push([s.titulo]);out.push(s.colunas);for(const l of s.linhas)out.push(l.map((v,i)=>isMoneyCol(s.colunas[i])?v.replace('.',','):v));out.push([]);}
  out.push(['Base legal',rep.base_legal]);out.push(['Nota',rep.nota]);
  const csv='﻿'+out.map(l=>l.map(csvCell).join(';')).join('\r\n');
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
  a.download=`relatorio-${rep.id}-${(rep.data_referencia||rep.ano||(rep.intervalo?.fim??today()))}.csv`;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),2000);
 }
 function print(){
  setPrinting(true);
  setTimeout(()=>{const prev=document.title;document.title=`${rep?.titulo} ${rep?.emitido_em.slice(0,10)}`;document.body.classList.add('cis-printing-evidence');
   const done=()=>{document.body.classList.remove('cis-printing-evidence');document.title=prev;setPrinting(false);window.removeEventListener('afterprint',done);};
   window.addEventListener('afterprint',done);window.print();setTimeout(done,1500);},150);
 }

 const Doc=({r}:{r:Report})=><article className="cis-evidence cis-report-doc">
  <header><img src={logoPrincipal} alt="CIS" width={170} height={50}/><div><h1>{r.titulo}</h1><p>{periodText(r)}</p><p>Emitido em {dt(r.emitido_em)} às {r.emitido_em.slice(11)}{r.emitido_por?` por ${r.emitido_por}`:''}</p></div></header>
  <section className="cis-evidence-summary cis-report-summary">{r.resumo.map(([k,v])=><div key={k}><span>{k}</span><strong>{fmtSummary(v)}</strong></div>)}</section>
  {r.seccoes.map(s=><section key={s.titulo}><h2>{s.titulo}</h2>{s.linhas.length?<table><thead><tr>{s.colunas.map(c=><th key={c} className={isMoneyCol(c)||/^(%|Dias|Contratos|Prestações|N\.º)/.test(c)?'num':''}>{c}</th>)}</tr></thead>
   <tbody>{s.linhas.map((l,i)=><tr key={i}>{l.map((_v,j)=><td key={j} className={isMoneyCol(s.colunas[j])||/^(%|Dias|Contratos|Prestações)/.test(s.colunas[j])?'num':''}>{fmtRow(s.colunas,l,j)}</td>)}</tr>)}</tbody></table>:<p>Sem registos.</p>}</section>)}
  <section className="cis-report-legal"><p><b>Base legal:</b> {r.base_legal}</p><p><b>Nota:</b> {r.nota}</p></section>
  <footer><ShieldCheck size={14}/><span>Código de verificação <b>{r.codigo_verificacao}</b> · gerado pelo CIS a partir dos registos do sistema. Valores em Meticais (MT).</span></footer>
 </article>;

 return <div className="cis-reports">
  <aside className="cis-report-list" aria-label="Relatórios">
   <h3>Relatórios</h3>
   <ul>{defs.map(d=>{const Ic=ICON[d.id]??FileText;return <li key={d.id}><button type="button" className={d.id===sel?'selected':''} onClick={()=>setSel(d.id)}><Ic size={18}/><span><b>{d.titulo}</b><small>{d.descricao}</small></span></button></li>;})}</ul>
   <p className="cis-report-hint">Os modelos seguem a regulamentação do Banco de Moçambique pesquisada em Novembro de 2026 e podem ser ajustados quando forem confirmados os modelos oficiais.</p>
  </aside>
  <section className="cis-panel cis-report-main">
   {def&&<><div className="cis-panel-head"><div><h2>{def.titulo}</h2><p>{def.descricao}</p></div></div>
    <p className="cis-report-base"><Scale size={14}/> {def.base_legal}</p>
    <div className="cis-report-filters">
     {def.periodo==='intervalo'&&<>
      <div className="cis-report-presets">{([['mes','Este mês'],['mes_anterior','Mês anterior'],['trimestre','Este trimestre'],['ano','Este ano']] as const).map(([k,l])=><button type="button" key={k} onClick={()=>{const [a,b]=startOf(k);setInicio(a);setFim(b);}}>{l}</button>)}</div>
      <label>De<input type="date" value={inicio} max={fim} onChange={e=>setInicio(e.target.value)}/></label>
      <label>Até<input type="date" value={fim} min={inicio} max={today()} onChange={e=>setFim(e.target.value)}/></label></>}
     {def.periodo==='data'&&<label>Situação à data de<input type="date" value={data} max={today()} onChange={e=>setData(e.target.value)}/></label>}
     {def.periodo==='ano'&&<label>Ano<select value={ano} onChange={e=>setAno(e.target.value)}>{Array.from({length:6},(_,i)=>String(new Date().getFullYear()-i)).map(y=><option key={y}>{y}</option>)}</select></label>}
     <button type="button" className="primary" onClick={()=>void generate()} disabled={busy}>{busy?<><Loader2 size={15} className="spin"/> A gerar…</>:<><FileText size={15}/> Gerar relatório</>}</button>
     {rep&&<><button type="button" onClick={exportCsv}><Download size={15}/> Exportar CSV (Excel)</button><button type="button" onClick={print}><Printer size={15}/> Imprimir / PDF</button></>}
    </div></>}
   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
   {!rep&&!busy&&!error&&def&&<div className="cis-report-empty"><BarChart3 size={28}/><p>Escolha o período e carregue em <b>Gerar relatório</b>.</p></div>}
   {rep&&<div className="cis-report-preview"><Doc r={rep}/></div>}
  </section>
  {printing&&rep&&createPortal(<div className="cis-evidence-overlay"><div className="cis-evidence-bar"><b>A preparar a impressão…</b><button type="button" onClick={()=>setPrinting(false)} aria-label="Fechar"><X size={16}/></button></div><Doc r={rep}/></div>,document.body)}
 </div>;
}
