import {useEffect,useState} from 'react';
import {Landmark,ArrowUpRight,ArrowDownRight,CalendarDays,Users,FileText,Wallet,RefreshCw,AlertCircle} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Data={
 actualizado_em:string;mes:string;nota:string;
 carteira:{capital_em_carteira:string;valor_a_receber:string;prestacoes_por_receber:number;contratos_activos:number;prestacoes_vencidas:number;valor_vencido:string;contratos_em_atraso:number};
 recebimentos:{juros:string;juros_mes:string;multas:string;multas_mes:string;capital_recuperado:string;total_recebido:string;recebido_mes:string};
 caixa:{disponivel:boolean;saldo_total:string|null;contas:{code:string;name:string;saldo:string|null}[];entradas_mes:string|null;saidas_mes:string|null;desembolsos_mes:string|null};
};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const mt=(v:string|null|undefined)=>v===null||v===undefined?'—':money.format(Number(v))+' MT';
const monthName=(ym:string)=>{const [y,m]=ym.split('-');return new Date(Number(y),Number(m)-1,1).toLocaleDateString('pt-PT',{month:'long'});};

export default function Overview({token,onNavigate}:{token:string;onNavigate:(page:string)=>void}){
 const [data,setData]=useState<Data|null>(null);
 const [error,setError]=useState('');
 const [loading,setLoading]=useState(true);
 const [refresh,setRefresh]=useState(0);
 useEffect(()=>{
  const ctrl=new AbortController();setLoading(true);setError('');
  fetch(API+'/cis/visao-geral',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível calcular os indicadores.');return j as Data;})
   .then(d=>{if(!ctrl.signal.aborted)setData(d);})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e instanceof Error?e.message:'Erro ao carregar indicadores.');})
   .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  return()=>ctrl.abort();
 },[token,refresh]);

 const c=data?.carteira,r=data?.recebimentos,cx=data?.caixa;
 const mes=data?monthName(data.mes):'mês';
 const stats=[
  {name:'Capital em carteira',icon:Landmark,value:mt(c?.capital_em_carteira),hint:c?`${c.contratos_activos} contratos activos · ${mt(c.valor_a_receber)} a receber`:'Prestações por receber'},
  {name:'Juros efectivamente recebidos',icon:ArrowUpRight,value:mt(r?.juros),hint:r?`Em ${mes}: ${mt(r.juros_mes)}`:'Separados da amortização'},
  {name:'Multas recebidas',icon:ArrowDownRight,value:mt(r?.multas),hint:r?`Em ${mes}: ${mt(r.multas_mes)}`:'Por atraso no pagamento'},
  {name:'Prestações vencidas',icon:CalendarDays,value:c?String(c.prestacoes_vencidas):'—',hint:c?`${mt(c.valor_vencido)} em atraso · ${c.contratos_em_atraso} contrato(s)`:'Vencimento anterior a hoje',alert:!!c&&c.prestacoes_vencidas>0}
 ];
 const ledger:[string,string,string|undefined][]=[
  ['Capital','Em carteira (por receber)',c?.capital_em_carteira],
  ['Amortização','Capital recuperado',r?.capital_recuperado],
  ['Juros','Receita financeira recebida',r?.juros],
  ['Multa','Encargos por atraso recebidos',r?.multas],
  ['Prestação','Total por receber',c?.valor_a_receber]
 ];
 return <>
  <section className="cis-banner cis-overview-banner"><Wallet size={21}/><div>
   {error?<><b>Indicadores indisponíveis</b><p><AlertCircle size={13}/> {error}</p></>:
    <><b>{cx?.disponivel?<>Saldo da Caixa: {mt(cx.saldo_total)}</>:'Caixa'}</b>
    <p>{loading&&!data?'A calcular indicadores…':cx?.disponivel?<>{cx.contas.map(a=>a.code+' '+mt(a.saldo)).join(' · ')} — em {mes}: entradas {mt(cx.entradas_mes)}, saídas {mt(cx.saidas_mes)}{Number(cx.desembolsos_mes)>0?<>, desembolsos {mt(cx.desembolsos_mes)}</>:null}.</>:'Saldos das contas indisponíveis: verifique a página Caixa.'}</p></>}
  </div><button type="button" className="cis-overview-refresh" onClick={()=>setRefresh(v=>v+1)} disabled={loading} aria-label="Actualizar indicadores"><RefreshCw size={15}/>{data?' '+data.actualizado_em.slice(11):''}</button></section>
  <div className="cis-stat-grid">{stats.map(({name,icon:Icon,value,hint,alert})=><article className={'cis-stat'+(alert?' cis-stat-alert':'')} key={name}><div className="cis-stat-top"><span>{name}</span><Icon size={18}/></div><strong>{loading&&!data?'…':value}</strong><small>{hint}</small></article>)}</div>
  <div className="cis-home-grid"><section className="cis-panel"><div className="cis-panel-head"><div><h2>Registos do sistema</h2><p>Recebido no total: <b>{mt(r?.total_recebido)}</b> · em {mes}: <b>{mt(r?.recebido_mes)}</b>.</p></div></div><div className="cis-action-row"><button type="button" onClick={()=>onNavigate('Clientes')}><Users size={20}/><span><b>Clientes</b><small>Consultar o cadastro</small></span><ArrowUpRight size={18}/></button><button type="button" onClick={()=>onNavigate('Contratos e prestações')}><FileText size={20}/><span><b>Contratos</b><small>{c?`${c.prestacoes_por_receber} prestações por receber`:'Consultar prestações e saldos'}</small></span><ArrowUpRight size={18}/></button></div></section>
  <section className="cis-panel"><h2>Distribuição dos pagamentos</h2><p>Componentes financeiros separados e rastreáveis.</p><div className="cis-ledger">{ledger.map(([title,sub,v])=><div key={title}><span className="cis-ledger-dot"/><span><b>{title}</b><small>{sub}</small></span><strong>{loading&&!data?'…':mt(v)}</strong></div>)}</div>
   {data&&<p className="cis-overview-note">{data.nota}</p>}</section></div>
 </>;
}
