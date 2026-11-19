import {useEffect,useState} from 'react';
import {Landmark,ArrowUpRight,ArrowDownRight,CalendarDays,Users,FileText,Wallet,RefreshCw,AlertCircle,Clock} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Data={
 actualizado_em:string;mes:string;nota:string;periodo?:{mes:string;ano:string;anos:number[]};
 carteira:{desembolsado_periodo:string;contratos_desembolsados_periodo:number;prestacoes_desembolsadas_periodo:string;capital_em_carteira:string;valor_a_receber:string;prestacoes_por_receber:number;contratos_activos:number;prestacoes_vencidas:number;valor_vencido:string;contratos_em_atraso:number;vencidas_periodo:number;valor_vencido_periodo:string;contratos_vencidos_periodo:number;pendentes_periodo:number;valor_pendente_periodo:string;contratos_pendentes_periodo:number};
 recebimentos:{juros:string;juros_mes:string;multas:string;multas_mes:string;capital_recuperado:string;capital_recuperado_mes:string;total_recebido:string;recebido_mes:string};
 caixa:{disponivel:boolean;saldo_total:string|null;contas:{code:string;name:string;saldo:string|null}[];entradas_mes:string|null;saidas_mes:string|null;desembolsos_mes:string|null};
};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const mt=(v:string|null|undefined)=>v===null||v===undefined?'—':money.format(Number(v))+' MT';
const MONTHS=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
const nowMaputo=()=>{const p=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Maputo',year:'numeric',month:'numeric'}).formatToParts(new Date());return {y:p.find(x=>x.type==='year')!.value,m:p.find(x=>x.type==='month')!.value};};
const periodLabel=(m:string,y:string)=>m==='todos'&&y==='todos'?'todo o período':m==='todos'?'o ano '+y:y==='todos'?MONTHS[Number(m)-1].toLowerCase()+' (todos os anos)':MONTHS[Number(m)-1].toLowerCase()+' de '+y;

export type ExtractoPreset={mes:string;ano:string;base:'cobranca'|'pagamento'|'desembolso';estado:'todos'|'aberta'|'vencida'|'paga';rotulo:string};
export default function Overview({token,onNavigate,onOpenExtracto}:{token:string;onNavigate:(page:string)=>void;onOpenExtracto?:(p:ExtractoPreset)=>void}){
 const [data,setData]=useState<Data|null>(null);
 const [error,setError]=useState('');
 const [loading,setLoading]=useState(true);
 const [refresh,setRefresh]=useState(0);
 const [mes,setMes]=useState(()=>nowMaputo().m);
 const [ano,setAno]=useState(()=>nowMaputo().y);
 useEffect(()=>{
  const ctrl=new AbortController();setLoading(true);setError('');
  fetch(API+'/cis/visao-geral?'+new URLSearchParams({mes,ano}),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível calcular os indicadores.');return j as Data;})
   .then(d=>{if(!ctrl.signal.aborted)setData(d);})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e instanceof Error?e.message:'Erro ao carregar indicadores.');})
   .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  return()=>ctrl.abort();
 },[token,refresh,mes,ano]);

 const c=data?.carteira,r=data?.recebimentos,cx=data?.caixa;
 const per=periodLabel(mes,ano);
 const years=Array.from(new Set([...(data?.periodo?.anos??[]),Number(nowMaputo().y)])).sort((a,b)=>b-a);
 const stats=[
  {name:'Carteira desembolsada',icon:Landmark,value:mt(c?.desembolsado_periodo),hint:c?`${c.contratos_desembolsados_periodo} contrato(s) em ${per} · em curso hoje ${mt(c.capital_em_carteira)}`:'Capital desembolsado no período',go:{base:'desembolso',estado:'todos'} as const},
  {name:'Juros efectivamente recebidos',icon:ArrowUpRight,value:mt(r?.juros_mes),hint:r?`Em ${per} · total geral ${mt(r.juros)}`:'Separados da amortização',go:{base:'pagamento',estado:'paga'} as const},
  {name:'Multas recebidas',icon:ArrowDownRight,value:mt(r?.multas_mes),hint:r?`Em ${per} · total geral ${mt(r.multas)}`:'Por atraso no pagamento',go:{base:'pagamento',estado:'paga'} as const},
  {name:'Prestações vencidas',icon:CalendarDays,value:c?String(c.vencidas_periodo):'—',hint:c?`${mt(c.valor_vencido_periodo)} em atraso · ${c.contratos_vencidos_periodo} contrato(s) · vencimento em ${per}`:'Vencimento anterior a hoje',alert:!!c&&c.vencidas_periodo>0,go:{base:'cobranca',estado:'vencida'} as const},
  {name:'Prestações pendentes',icon:Clock,value:c?String(c.pendentes_periodo):'—',hint:c?`${mt(c.valor_pendente_periodo)} a receber · ${c.contratos_pendentes_periodo} contrato(s) · vencimento em ${per}`:'Ainda dentro do prazo',go:{base:'cobranca',estado:'aberta'} as const}
 ];
 const ledger:[string,string,string|undefined][]=[
  ['Capital','Desembolsado no período',c?.desembolsado_periodo],
  ['Amortização','Capital recuperado no período',r?.capital_recuperado_mes],
  ['Juros','Receita financeira recebida no período',r?.juros_mes],
  ['Multa','Encargos por atraso recebidos no período',r?.multas_mes],
  ['Prestação','Prestações dos contratos desembolsados no período',c?.prestacoes_desembolsadas_periodo]
 ];
 return <>
  <div className="cis-overview-period" role="group" aria-label="Período dos indicadores">
   <CalendarDays size={16}/><span>Período</span>
   <label>Mês<select value={mes} onChange={e=>setMes(e.target.value)}><option value="todos">Todos</option>{MONTHS.map((n,i)=><option key={n} value={String(i+1)}>{n}</option>)}</select></label>
   <label>Ano<select value={ano} onChange={e=>setAno(e.target.value)}><option value="todos">Todos</option>{years.map(y=><option key={y} value={String(y)}>{y}</option>)}</select></label>
   {(mes!==nowMaputo().m||ano!==nowMaputo().y)&&<button type="button" onClick={()=>{const n=nowMaputo();setMes(n.m);setAno(n.y);}}>Mês actual</button>}
   <small>Vencidas e pendentes contam pela data de vencimento. Os saldos das contas são sempre os de hoje.</small>
  </div>
  <section className="cis-banner cis-overview-banner"><Wallet size={21}/><div>
   {error?<><b>Indicadores indisponíveis</b><p><AlertCircle size={13}/> {error}</p></>:
    <><b>{cx?.disponivel?<>Saldo da Caixa: {mt(cx.saldo_total)}</>:'Caixa'}</b>
    <p>{loading&&!data?'A calcular indicadores…':cx?.disponivel?<>{cx.contas.map(a=>a.code+' '+mt(a.saldo)).join(' · ')} — em {per}: entradas {mt(cx.entradas_mes)}, saídas {mt(cx.saidas_mes)}{Number(cx.desembolsos_mes)>0?<>, desembolsos {mt(cx.desembolsos_mes)}</>:null}.</>:'Saldos das contas indisponíveis: verifique a página Caixa.'}</p></>}
  </div><button type="button" className="cis-overview-refresh" onClick={()=>setRefresh(v=>v+1)} disabled={loading} aria-label="Actualizar indicadores"><RefreshCw size={15}/>{data?' '+data.actualizado_em.slice(11):''}</button></section>
  <div className="cis-stat-grid">{stats.map(({name,icon:Icon,value,hint,alert,go})=>{const body=<><div className="cis-stat-top"><span>{name}</span><Icon size={18}/></div><strong>{loading&&!data?'…':value}</strong><small>{hint}</small>{onOpenExtracto&&<em className="cis-stat-link">Ver no extracto <ArrowUpRight size={13}/></em>}</>;
   return onOpenExtracto?<button type="button" className={'cis-stat cis-stat-button'+(alert?' cis-stat-alert':'')} key={name} title={`Abrir o extracto: ${name.toLowerCase()} em ${per}`} onClick={()=>onOpenExtracto({mes,ano,...go,rotulo:`${name} · ${per}`})}>{body}</button>
    :<article className={'cis-stat'+(alert?' cis-stat-alert':'')} key={name}>{body}</article>;})}</div>
  <div className="cis-home-grid"><section className="cis-panel"><div className="cis-panel-head"><div><h2>Registos do sistema</h2><p>Recebido em {per}: <b>{mt(r?.recebido_mes)}</b> · total geral: <b>{mt(r?.total_recebido)}</b>.</p></div></div><div className="cis-action-row"><button type="button" onClick={()=>onNavigate('Clientes')}><Users size={20}/><span><b>Clientes</b><small>Consultar o cadastro</small></span><ArrowUpRight size={18}/></button><button type="button" onClick={()=>onNavigate('Contratos e prestações')}><FileText size={20}/><span><b>Contratos</b><small>{c?`${c.prestacoes_por_receber} prestações por receber`:'Consultar prestações e saldos'}</small></span><ArrowUpRight size={18}/></button></div></section>
  <section className="cis-panel"><h2>Distribuição dos pagamentos</h2><p>Componentes financeiros separados e rastreáveis.</p><div className="cis-ledger">{ledger.map(([title,sub,v])=><div key={title}><span className="cis-ledger-dot"/><span><b>{title}</b><small>{sub}</small></span><strong>{loading&&!data?'…':mt(v)}</strong></div>)}</div>
   {data&&<p className="cis-overview-note">{data.nota}</p>}</section></div>
 </>;
}
