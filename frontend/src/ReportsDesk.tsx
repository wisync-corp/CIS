import CompanyBrand,{PoweredBy} from './CompanyBrand';
import {useCompanyProfile,type CompanyProfile} from './company';
import {useEffect,useMemo,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {BarChart3,FileText,Download,Printer,X,AlertCircle,ShieldCheck,Scale,Landmark,UserCheck,Loader2,CalendarClock,Hourglass,Users,TrendingUp,Wallet,Repeat,RefreshCw,Search,ChevronDown,ChevronUp,Info} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Def={id:string;grupo?:string;titulo:string;periodo:'intervalo'|'futuro'|'data'|'ano'|'nenhum';descricao:string;base_legal:string};
type Section={titulo:string;colunas:string[];linhas:string[][];grafico?:string[];tom?:'risco';total_final?:boolean};
type Report=Def&{nota:string;resumo:[string,string][];seccoes:Section[];emitido_em:string;emitido_por:string|null;codigo_verificacao:string;
 intervalo?:{inicio:string;fim:string};data_referencia?:string;ano?:number};

const ICON:Record<string,typeof BarChart3>={actividade:BarChart3,crc:Landmark,branqueamento:Scale,identificacao:UserCheck,agenda:CalendarClock,atrasos:Hourglass,operadores:Users,evolucao:TrendingUp,caixa:Wallet,novocredito:Repeat};
const today=()=>new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo'}).format(new Date());
const plusDays=(d:string,n:number)=>{const x=new Date(d+'T00:00:00Z');x.setUTCDate(x.getUTCDate()+n);return x.toISOString().slice(0,10);};
const dt=(v?:string|null)=>{if(!v)return '—';const [y,m,d]=v.slice(0,10).split('-');return d?`${d}/${m}/${y}`:v;};
const moneyNF=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2,useGrouping:'always' as any});
const money={format:(n:number)=>moneyNF.format(n).replace(/^(-?\d{1,3})(\d{3}),/,'$1\u00a0$2,')};
const compact=new Intl.NumberFormat('pt-MZ',{notation:'compact',maximumFractionDigits:1});
const isMoneyCol=(c:string)=>/\(MT\)/.test(c);
const isPctCol=(c:string)=>/^%/.test(c)||/\(%\)/.test(c);
const isNumCol=(c:string)=>isMoneyCol(c)||isPctCol(c)||/^(Contratos|Prestações|Dias|Movimentos|Clientes|Pagas|Score|Renovações|Créditos activos)/.test(c);
const STATUS_COLS=['Situação','Classificação','Faixa','Fora do regime simplificado','Identificação','Contrato activo','Aprovável'];
const num=(v:string)=>{if(v==='')return null;const n=Number(v);return isNaN(n)?null:n;};
const fmtCell=(c:string,v:string,row:string[])=>{
 if(isMoneyCol(c)&&num(v)!==null)return money.format(Number(v));
 if(c==='Valor'&&/\(MT\)/.test(row[0]??'')&&num(v)!==null)return money.format(Number(v));
 if(/^\d{4}-\d{2}-\d{2}$/.test(v))return dt(v);return v;};
const shortLabel=(l:string)=>{const t=l.replace(/^Semana de\s*/i,'').replace(/^(\d{4})-(\d{2})$/,'$2/$1');return t.length>12?t.slice(0,11)+'…':t;};
const fmtSummary=(v:string)=>{const m=v.match(/^(-?\d+(?:\.\d+)?) MT$/);return m?money.format(Number(m[1]))+' MT':v;};
const tone=(label:string)=>/risco|atraso|vencid|em falta|sem bi|sem nuit|sem contacto|incumprimento|atenção|fora do regime/i.test(label)?'bad'
 :/recebid|juros|multas|excelente|entradas|elegíveis/i.test(label)?'good'
 :/desembols|carteira|valor a cobrar|saldo|movimento/i.test(label)?'main':'neutral';
const statusTone=(v:string,c='')=>c==='Aprovável'?(v==='Sim'?'good':'bad'):/alto risco|incumprimento|atenção|em atraso|^sim$|em falta|mais de 90|61 – 90|31 – 60/i.test(v)?'bad':/^bom$|risco médio|8 – 30|1 – 7/i.test(v)?'warn':/regular|baixo risco|liquidado|excelente|completa|^não$/i.test(v)?'good':'neutral';
const csvCell=(v:unknown)=>{const s=v===null||v===undefined?'':String(v);return /[";\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;};
function startOf(kind:string){const t=today();const [y,m]=t.split('-').map(Number);
 if(kind==='mes_anterior'){const py=m===1?y-1:y,pm=m===1?12:m-1;const last=new Date(Date.UTC(py,pm,0)).getUTCDate();return [`${py}-${String(pm).padStart(2,'0')}-01`,`${py}-${String(pm).padStart(2,'0')}-${last}`];}
 if(kind==='trimestre'){const q=Math.floor((m-1)/3)*3+1;return [`${y}-${String(q).padStart(2,'0')}-01`,t];}
 if(kind==='ano')return [`${y}-01-01`,t];
 return [`${y}-${String(m).padStart(2,'0')}-01`,t];}
function periodText(r:Report){
 if(r.intervalo)return `${dt(r.intervalo.inicio)} a ${dt(r.intervalo.fim)}`;
 if(r.data_referencia)return `Situação a ${dt(r.data_referencia)}`;if(r.ano)return `Ano ${r.ano}`;return `Situação a ${dt(r.emitido_em)}`;}

/** Gráfico de colunas (1–2 séries) para secções com indicação «grafico». */
function Bars({s}:{s:Section}){
 const rows=s.total_final?s.linhas.slice(0,-1):s.linhas;
 const cols=(s.grafico??[]).map(c=>s.colunas.indexOf(c)).filter(i=>i>=0);
 if(!rows.length||!cols.length)return null;
 const max=Math.max(1,...rows.flatMap(r=>cols.map(i=>Number(r[i])||0)));
 const slot=Math.max(46,cols.length*26+22),W=Math.max(360,rows.length*slot),H=196,base=160;
 const bw=cols.length>1?20:Math.min(46,slot*0.6);
 const colors=s.tom==='risco'?['#f04438','#f79009']:['var(--cis-primary)','#12b76a'];
 return <div className="cis-rep-chart">{cols.length>1&&<div className="cis-rep-legend">{cols.map((i,k)=><span key={i}><i style={{background:colors[k]}}/>{s.colunas[i].replace(' (MT)','')}</span>)}</div>}
  <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Gráfico: ${s.titulo}`}>
   <line x1="0" x2={W} y1={base} y2={base} stroke="#e4e7ec"/>
   {rows.map((r,ri)=>{const sl=W/rows.length;const x0=ri*sl+sl/2-(cols.length*bw+(cols.length-1)*4)/2;
    return <g key={ri}>{cols.map((ci,k)=>{const v=Number(r[ci])||0;const h=Math.round(v/max*(base-28));const x=x0+k*(bw+4);
     return <g key={ci}><rect x={x} y={base-h} width={bw} height={Math.max(h,v>0?2:0)} rx="4" fill={colors[k]}><title>{`${r[0]} · ${s.colunas[ci]}: ${money.format(v)}`}</title></rect>
      {cols.length===1&&v>0&&<text x={x+bw/2} y={base-h-6} textAnchor="middle" fontSize="11" fill="#475467">{compact.format(v)}</text>}</g>;})}
     <text x={ri*sl+sl/2} y={base+17} textAnchor="middle" fontSize="11" fill="#475467">{shortLabel(r[0])}</text></g>;})}
  </svg></div>;
}

function SectionView({s,open}:{s:Section;open:boolean}){
 const [q,setQ]=useState('');const [sort,setSort]=useState<{i:number;dir:1|-1}|null>(null);const [isOpen,setOpen]=useState(open);
 const totalRow=s.total_final?s.linhas[s.linhas.length-1]:null;
 const count=s.linhas.length-(s.total_final?1:0);
 const body=useMemo(()=>{let rows=s.total_final?s.linhas.slice(0,-1):s.linhas;
  if(q.trim()){const t=q.trim().toLowerCase();rows=rows.filter(r=>r.some(v=>v.toLowerCase().includes(t)));}
  if(sort){const {i,dir}=sort;rows=[...rows].sort((a,b)=>{const x=num(a[i]),y=num(b[i]);return (x!==null&&y!==null?x-y:a[i].localeCompare(b[i],'pt'))*dir;});}
  return rows;},[s,q,sort]);
 return <section className={'cis-rep-section'+(s.tom==='risco'?' risk':'')}>
  <header><button type="button" className="cis-rep-toggle" onClick={()=>setOpen(o=>!o)} aria-expanded={isOpen}>{isOpen?<ChevronUp size={16}/>:<ChevronDown size={16}/>}<h4>{s.titulo.replace(/^\d+\.\s*/,'')}</h4><span className="cis-rep-count">{count}</span></button>
   {isOpen&&count>8&&<label className="cis-rep-search"><Search size={14}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="Pesquisar nesta tabela…" aria-label={`Pesquisar em ${s.titulo}`}/></label>}</header>
  {isOpen&&<>
   {s.grafico&&count>0&&<Bars s={s}/>}
   {count===0?<p className="cis-rep-empty-row">Sem registos.</p>:
   <div className="cis-rep-table"><table><thead><tr>{s.colunas.map((c,i)=><th key={c} className={isNumCol(c)?'num':''} aria-sort={sort?.i===i?(sort.dir===1?'ascending':'descending'):undefined}>
     <button type="button" onClick={()=>setSort(p=>p?.i===i?{i,dir:p.dir===1?-1:1}:{i,dir:isNumCol(c)?-1:1})} title="Ordenar">{c}{sort?.i===i?(sort.dir===1?<ChevronUp size={12}/>:<ChevronDown size={12}/>):null}</button></th>)}</tr></thead>
    <tbody>{body.map((l,ri)=><tr key={ri}>{l.map((v,j)=>{const c=s.colunas[j];
     if(isPctCol(c)&&num(v)!==null)return <td key={j} className="num"><span className="cis-rep-pct"><i style={{width:Math.min(100,Number(v))+'%'}} className={s.tom==='risco'||/> 30|risco/.test(c)?'bad':''}/><b>{v}%</b></span></td>;
     if(STATUS_COLS.includes(c)&&v)return <td key={j}><span className={'cis-rep-pill '+statusTone(v,c)}>{v}</span></td>;
     if(c==='Dados em falta'||c==='Em falta')return <td key={j}>{v?<span className="cis-rep-pill bad">{v}</span>:<span className="cis-rep-pill good">Completo</span>}</td>;
     return <td key={j} className={isNumCol(c)?'num':''}>{fmtCell(c,v,l)||'—'}</td>;})}</tr>)}
     {!body.length&&<tr><td colSpan={s.colunas.length} className="cis-rep-empty-row">Nenhuma linha corresponde à pesquisa.</td></tr>}</tbody>
    {totalRow&&<tfoot><tr>{totalRow.map((v,j)=><td key={j} className={isNumCol(s.colunas[j])?'num':''}>{fmtCell(s.colunas[j],v,totalRow)}</td>)}</tr></tfoot>}
   </table></div>}
   {q&&<small className="cis-rep-filtered">{body.length} de {count} linhas</small>}
  </>}
 </section>;
}

const Doc=({r,company}:{r:Report;company:CompanyProfile|null})=><article className="cis-evidence cis-report-doc">
 <header><CompanyBrand company={company}/><div><h1>{r.titulo}</h1><p>{periodText(r)}</p><p>Emitido em {dt(r.emitido_em)} às {r.emitido_em.slice(11)}{r.emitido_por?` por ${r.emitido_por}`:''}</p></div></header>
 <section className="cis-evidence-summary cis-report-summary">{r.resumo.map(([k,v])=><div key={k}><span>{k}</span><strong>{fmtSummary(v)}</strong></div>)}</section>
 {r.seccoes.map(s=><section key={s.titulo}><h2>{s.titulo}</h2>{s.linhas.length?<table><thead><tr>{s.colunas.map(c=><th key={c} className={isNumCol(c)?'num':''}>{c}</th>)}</tr></thead>
  <tbody>{s.linhas.map((l,i)=><tr key={i} className={s.total_final&&i===s.linhas.length-1?'total':''}>{l.map((v,j)=><td key={j} className={isNumCol(s.colunas[j])?'num':''}>{fmtCell(s.colunas[j],v,l)}</td>)}</tr>)}</tbody></table>:<p>Sem registos.</p>}</section>)}
 <section className="cis-report-legal"><p><b>Base legal:</b> {r.base_legal}</p><p><b>Nota:</b> {r.nota}</p></section>
 <footer><ShieldCheck size={14}/><span>Código de verificação <b>{r.codigo_verificacao}</b> · gerado pelo CIS a partir dos registos do sistema. Valores em Meticais (MT).</span><PoweredBy/></footer>
</article>;

function Seg({items,cur,onPick,label}:{items:[string,string][];cur:string;onPick:(k:string)=>void;label:string}){
 return <div className="cis-rep-seg" role="group" aria-label={label}>{items.map(([k,l])=><button type="button" key={k} className={cur===k?'on':''} aria-pressed={cur===k} onClick={()=>onPick(k)}>{l}</button>)}</div>;
}

export default function ReportsDesk({token}:{token:string}){
 const company=useCompanyProfile(token);
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 const [defs,setDefs]=useState<Def[]>([]);const [sel,setSel]=useState('agenda');
 const [inicio,setInicio]=useState(startOf('mes')[0]);const [fim,setFim]=useState(today());
 const [aIni,setAIni]=useState(today());const [aFim,setAFim]=useState(plusDays(today(),30));
 const [data,setData]=useState(today());const [ano,setAno]=useState(String(new Date().getFullYear()));
 const [preset,setPreset]=useState('mes');const [aPreset,setAPreset]=useState('30');
 const [rep,setRep]=useState<Report|null>(null);const [busy,setBusy]=useState(false);const [error,setError]=useState('');
 const [view,setView]=useState<'painel'|'documento'>('painel');const [printing,setPrinting]=useState(false);const [tick,setTick]=useState(0);
 const ctrl=useRef<AbortController|null>(null);
 const def=defs.find(d=>d.id===sel);

 useEffect(()=>{fetch(API+'/cis/relatorios',{headers}).then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível carregar os relatórios.');return j;})
  .then(j=>setDefs(j.relatorios)).catch(e=>setError(e.message));
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token]);

 // Gera automaticamente ao escolher o relatório ou mudar o período.
 useEffect(()=>{
  if(!def)return;
  const p=new URLSearchParams();
  if(def.periodo==='intervalo'){if(!inicio||!fim||fim<inicio)return;p.set('inicio',inicio);p.set('fim',fim);}
  if(def.periodo==='futuro'){if(!aIni||!aFim||aFim<aIni)return;p.set('inicio',aIni);p.set('fim',aFim);}
  if(def.periodo==='data'){if(!data)return;p.set('data',data);}
  if(def.periodo==='ano')p.set('ano',ano);
  const t=window.setTimeout(()=>{ctrl.current?.abort();const c=new AbortController();ctrl.current=c;setBusy(true);setError('');
   fetch(API+'/cis/relatorios/'+def.id+'?'+p,{headers,signal:c.signal}).then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error((j as {message?:string}).message||'Não foi possível gerar o relatório.');return j as Report;})
    .then(j=>{if(!c.signal.aborted)setRep(j);}).catch(e=>{if(e.name!=='AbortError'){setError(e.message);setRep(null);}}).finally(()=>{if(!c.signal.aborted)setBusy(false);});},300);
  return()=>window.clearTimeout(t);
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[def?.id,inicio,fim,aIni,aFim,data,ano,tick]);
 useEffect(()=>{setRep(null);},[sel]);

 function exportCsv(){
  if(!rep)return;const out:string[][]=[[rep.titulo],[periodText(rep)],[`Emitido em ${rep.emitido_em}`,rep.emitido_por??''],[`Código de verificação ${rep.codigo_verificacao}`],[]];
  out.push(['Resumo']);for(const [k,v] of rep.resumo)out.push([k,v]);out.push([]);
  for(const s of rep.seccoes){out.push([s.titulo]);out.push(s.colunas);for(const l of s.linhas)out.push(l.map((v,i)=>isMoneyCol(s.colunas[i])?v.replace('.',','):v));out.push([]);}
  out.push(['Base legal',rep.base_legal]);out.push(['Nota',rep.nota]);out.push([]);out.push(['Powered by WiSync']);
  if(company?.nome_empresa)out.unshift([company.nome_empresa+(company.nuit?` · NUIT ${company.nuit}`:'')]);
  const csv='﻿'+out.map(l=>l.map(csvCell).join(';')).join('\r\n');
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
  a.download=`relatorio-${rep.id}-${(rep.data_referencia||rep.ano||rep.intervalo?.fim||today())}.csv`;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),2000);
 }
 function print(){
  setPrinting(true);
  setTimeout(()=>{const prev=document.title;document.title=`${rep?.titulo} ${rep?.emitido_em.slice(0,10)}`;document.body.classList.add('cis-printing-evidence');
   const done=()=>{document.body.classList.remove('cis-printing-evidence');document.title=prev;setPrinting(false);window.removeEventListener('afterprint',done);};
   window.addEventListener('afterprint',done);window.print();setTimeout(done,1500);},150);
 }
 const Ic=def?(ICON[def.id]??FileText):FileText;const g=def?.grupo==='Gestão';

 return <div className="cis-reports">
  <aside className="cis-report-list" aria-label="Relatórios">
   {['Gestão','Regulatórios'].map(grp=>{const list=defs.filter(d=>(d.grupo??'Regulatórios')===grp);return list.length?<div key={grp} className="cis-report-group">
    <h3>{grp==='Gestão'?'Gestão':'Regulatórios · Banco de Moçambique'}</h3>
    <ul>{list.map(d=>{const I=ICON[d.id]??FileText;return <li key={d.id}><button type="button" className={d.id===sel?'selected':''} onClick={()=>setSel(d.id)} title={d.descricao}><span className={'cis-rep-ico '+(grp==='Gestão'?'g':'r')}><I size={16}/></span><b>{d.titulo}</b></button></li>;})}</ul>
   </div>:null;})}
   <p className="cis-report-hint">Os relatórios regulatórios seguem a regulamentação pesquisada em Novembro de 2026 e serão ajustados aos modelos oficiais do Banco de Moçambique.</p>
  </aside>

  <section className="cis-rep-main">
   {def&&<header className="cis-rep-head">
    <span className={'cis-rep-ico big '+(g?'g':'r')}><Ic size={22}/></span>
    <div><span className={'cis-rep-badge '+(g?'g':'r')}>{g?'Gestão':'Regulatório'}</span><h2>{def.titulo}</h2><p>{def.descricao}</p>
     <details className="cis-rep-legal"><summary><Info size={13}/> Base legal e notas</summary><p>{def.base_legal}</p>{rep?.nota&&<p>{rep.nota}</p>}</details></div>
   </header>}

   {def&&<div className="cis-rep-toolbar">
    <div className="cis-rep-period">
     {def.periodo==='intervalo'&&<><Seg label="Período" items={[['mes','Este mês'],['mes_anterior','Mês anterior'],['trimestre','Trimestre'],['ano','Ano']]} cur={preset} onPick={k=>{setPreset(k);const [a,b]=startOf(k);setInicio(a);setFim(b);}}/>
      <label>De<input type="date" value={inicio} max={fim} onChange={e=>{setInicio(e.target.value);setPreset('');}}/></label>
      <label>Até<input type="date" value={fim} min={inicio} max={today()} onChange={e=>{setFim(e.target.value);setPreset('');}}/></label></>}
     {def.periodo==='futuro'&&<><Seg label="Período" items={[['0','Hoje'],['1','Amanhã'],['7','7 dias'],['30','30 dias']]} cur={aPreset} onPick={k=>{setAPreset(k);if(k==='1'){setAIni(plusDays(today(),1));setAFim(plusDays(today(),1));}else{setAIni(today());setAFim(plusDays(today(),Number(k)));}}}/>
      <label>De<input type="date" value={aIni} max={aFim} onChange={e=>{setAIni(e.target.value);setAPreset('');}}/></label>
      <label>Até<input type="date" value={aFim} min={aIni} onChange={e=>{setAFim(e.target.value);setAPreset('');}}/></label></>}
     {def.periodo==='data'&&<label>Situação a<input type="date" value={data} max={today()} onChange={e=>setData(e.target.value)}/></label>}
     {def.periodo==='ano'&&<Seg label="Ano" items={Array.from({length:4},(_,i)=>{const y=String(new Date().getFullYear()-i);return [y,y] as [string,string];})} cur={ano} onPick={setAno}/>}
     {def.periodo==='nenhum'&&<span className="cis-rep-now">Situação actual</span>}
    </div>
    <div className="cis-rep-actions">
     <Seg label="Vista" items={[['painel','Painel'],['documento','Documento']]} cur={view} onPick={k=>setView(k as 'painel'|'documento')}/>
     <button type="button" onClick={()=>setTick(t=>t+1)} disabled={busy} aria-label="Actualizar" title="Actualizar">{busy?<Loader2 size={15} className="spin"/>:<RefreshCw size={15}/>}</button>
     <button type="button" onClick={exportCsv} disabled={!rep}><Download size={15}/> CSV</button>
     <button type="button" className="primary" onClick={print} disabled={!rep}><Printer size={15}/> PDF</button>
    </div>
   </div>}

   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
   {busy&&!rep&&<div className="cis-rep-skeleton" aria-busy="true" aria-label="A gerar o relatório"><div/><div/><div/><div/><span/><span/></div>}

   {rep&&view==='painel'&&<div className={'cis-rep-board'+(busy?' loading':'')}>
    <div className="cis-rep-meta"><span>{periodText(rep)}</span><span>Gerado às {rep.emitido_em.slice(11)}{rep.emitido_por?` por ${rep.emitido_por}`:''} · código {rep.codigo_verificacao}</span></div>
    <div className="cis-rep-kpis">{rep.resumo.map(([k,v])=><div key={k} className={'cis-rep-kpi '+tone(k)}><span>{k}</span><strong>{fmtSummary(v)}</strong></div>)}</div>
    {rep.seccoes.map((s,i)=><SectionView key={rep.id+rep.emitido_em+s.titulo} s={s} open={i<4||s.linhas.length<=12}/>)}
   </div>}
   {rep&&view==='documento'&&<div className="cis-report-preview"><Doc r={rep} company={company}/></div>}
  </section>
  {printing&&rep&&createPortal(<div className="cis-evidence-overlay"><div className="cis-evidence-bar"><b>A preparar a impressão…</b><button type="button" onClick={()=>setPrinting(false)} aria-label="Fechar"><X size={16}/></button></div><Doc r={rep} company={company}/></div>,document.body)}
 </div>;
}
