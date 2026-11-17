import {useEffect,useMemo,useState,type FormEvent} from 'react';
import {
  ReceiptText,Search,RefreshCw,FileText,CalendarDays,CheckCircle2,AlertCircle,
  ArrowRight,ArrowLeft,Wallet,Clock3,ShieldCheck,LockKeyhole,Landmark,
  Calculator,Layers,ArrowDownLeft,RotateCcw,TrendingUp,Info,CreditCard,
  Filter,BookOpen,ChevronRight,Coins
} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Row=Record<string,string|number|null>;
type ContractRow={numero:string;cliente:string;cliente_id:string|number|null;linhas_prestacoes:number;estado:string};
type ListResponse={data:ContractRow[];current_page:number;last_page:number;total:number};
type ProfileResponse={
 contract:Row;installments:Row[];installment_rows:number;notice:string;
 contract_fields_divergent?:string[];columns_missing_in_database?:string[];
};
type ScenarioQuote={resultado:string;aviso:string;consulta_apenas:boolean;[key:string]:unknown};
type ParcelasQuote={
 consulta_apenas:boolean;modo:'multa'|'reparcelamento';prazo:string;periodo_referencia:number;
 dias_propostos:number;base:string;valor_recebido:string|null;valor_a_pagar_original:string;
 juros_originais:string;taxa_faixa:string;taxa_aplicada:string;taxa_manual:boolean;limite_inferior:string;limite_superior:string;
 juros_ou_multa_calculados:string;total_calculado:string;calculo:string;arredondamento:string;
 taxa_multa_contrato?:string|null;multa_pela_taxa_contratual?:string|null;metodos_divergentes?:boolean;
 aviso:string;
};
type Quote={
 parcela:number;dias_atraso:number;juros:number;multa:number;total:number;
 estado:string;data_pagamento:string;aviso:string
};
type Mode='total'|'parcial'|'renovar_juros'|'capitalizar'|'remanescente'|'renovar_credito'|'actualizar_data'|'finalizar_contrato';
const statuses:Record<Mode,{name:string;details:string;group:string}>={
 total:{name:'Pagamento integral',details:'Liquidação completa da prestação, com eventual multa por atraso.',group:'Com pagamento'},
 parcial:{name:'Pagamento parcial',details:'Registar montante inferior ao devido; juros antes da amortização.',group:'Com pagamento'},
 renovar_juros:{name:'Renovar juros',details:'Novo período de juros sobre o capital ainda devido, sem recebimento.',group:'Sem pagamento'},
 capitalizar:{name:'Capitalizar juros',details:'Incorporar juros no capital e apurar nova taxa pela tabela de faixas.',group:'Sem pagamento'},
 remanescente:{name:'Liquidar remanescente',details:'Liquidação final após parcial, sujeita a controlo dos atrasos.',group:'Regularização'},
 renovar_credito:{name:'Renovar crédito',details:'Nova operação de crédito após o termo da anterior; exige novo contrato e desembolso autorizado.',group:'Novo crédito'},
 actualizar_data:{name:'Rever data de pagamento',details:'Verificar data efectiva, dias de atraso, estado e eventual recálculo de juros variáveis.',group:'Regularização'},
 finalizar_contrato:{name:'Finalizar contrato',details:'Verificar se todas as prestações se encontram efectivamente liquidadas antes de encerrar.',group:'Encerramento'}
};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmt=(value:unknown)=>value===null||value===undefined||String(value)===''||!Number.isFinite(Number(value))
 ?'—':money.format(Number(value))+' MT';
const num=(value:unknown)=>value===null||value===undefined||value===''?0:Number(value);
const text=(value:unknown)=>value===null||value===undefined||String(value).trim()===''?'—':String(value);
const date=(value:unknown)=>{
 const s=text(value);return /^\d{4}-\d{2}-\d{2}/.test(s)?s.slice(8,10)+'/'+s.slice(5,7)+'/'+s.slice(0,4):s;
};
const isPending=(row:Row)=>['Pendente','Pagamento Atrasado'].includes(text(row.Estado));
const paid=(row:Row)=>['Pago','Pago em Atraso'].includes(text(row.Estado));
const today=()=>{
 const tokens=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Maputo',day:'2-digit',month:'2-digit',year:'numeric'}).formatToParts(new Date());
 const part=(type:string)=>tokens.find(p=>p.type===type)?.value??'';
 return part('year')+'-'+part('month')+'-'+part('day');
};
function modeIcon(mode:Mode){
 switch(mode){case'total':return <CheckCircle2 size={18}/>;case'parcial':return <Wallet size={18}/>;
 case'renovar_juros':return <RotateCcw size={18}/>;case'capitalizar':return <TrendingUp size={18}/>;
 case'remanescente':return <Coins size={18}/>;
 case'renovar_credito':return <FileText size={18}/>;case'actualizar_data':return <CalendarDays size={18}/>;
 case'finalizar_contrato':return <ShieldCheck size={18}/>;}
}
type EmbeddedProfile={contract:Row;installments:Row[];installment_rows:number;notice:string;contract_fields_divergent?:string[];columns_missing_in_database?:string[]};
export default function CollectionsDesk({token,embeddedContract,embeddedProfile,initialOrder}:{token:string;embeddedContract?:string;embeddedProfile?:EmbeddedProfile;initialOrder?:string}){
 const [query,setQuery]=useState('');
 const [applied,setApplied]=useState('');
 const [page,setPage]=useState(1);
 const [list,setList]=useState<ListResponse|null>(null);
 const [listing,setListing]=useState(true);
 const [selected,setSelected]=useState<string|null>(embeddedContract??null);
 const [profile,setProfile]=useState<ProfileResponse|null>(embeddedProfile??null);
 const [loading,setLoading]=useState(false);
 const [message,setMessage]=useState('');
 const [mode,setMode]=useState<Mode>('total');
 const [ord,setOrd]=useState(initialOrder??'');
 const [penalty,setPenalty]=useState(false);
 const [paymentAmount,setPaymentAmount]=useState('');
 const [reviewDate,setReviewDate]=useState(today());
 const [quote,setQuote]=useState<Quote|null>(null);
 const [quoting,setQuoting]=useState(false);
 const [scenarioBusy,setScenarioBusy]=useState(false);
 const [scenarioQuote,setScenarioQuote]=useState<ScenarioQuote|null>(null);
 const [capitalPeriod,setCapitalPeriod]=useState('');
 const [parcelaPrazo,setParcelaPrazo]=useState('');
 const [taxaManual,setTaxaManual]=useState('');
 const [parcelaBusy,setParcelaBusy]=useState(false);
 const [parcelaQuote,setParcelaQuote]=useState<ParcelasQuote|null>(null);
 const [accountId,setAccountId]=useState('');
 const [channel,setChannel]=useState('');
 const [evidenceRef,setEvidenceRef]=useState('');
 const [accounts,setAccounts]=useState<{id:number;code:string;name:string;status:string}[]>([]);
 const [view,setView]=useState<'operacao'|'historico'>('operacao');
 const [refresh,setRefresh]=useState(0);

 useEffect(()=>{
  const ctrl=new AbortController();
  const timer=window.setTimeout(()=>{setPage(1);setApplied(query.trim());},300);
  return()=>{window.clearTimeout(timer);ctrl.abort();};
 },[query]);
 useEffect(()=>{
  if(selected||embeddedContract)return;
  const ctrl=new AbortController();setListing(true);setMessage('');
  const params=new URLSearchParams({page:String(page)});if(applied)params.set('q',applied);
  fetch(API+'/cis/contractos?'+params.toString(),{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal
  }).then(async response=>{
   const json=await response.json().catch(()=>({})) as ListResponse&{message?:string};
   if(!response.ok)throw new Error(json.message||'Não foi possível consultar contratos.');
   return json;
  }).then(data=>{if(!ctrl.signal.aborted)setList(data);})
   .catch(e=>{if(!ctrl.signal.aborted){setList(null);setMessage(e instanceof Error?e.message:'Erro na consulta.');}})
   .finally(()=>{if(!ctrl.signal.aborted)setListing(false);});
  return()=>ctrl.abort();
 },[selected,page,applied,refresh,token,embeddedContract]);
 useEffect(()=>{
  if(!selected||embeddedContract)return;
  const ctrl=new AbortController();setLoading(true);setMessage('');
  fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/ficha',{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal
  }).then(async r=>{
   const data=await r.json().catch(()=>({})) as ProfileResponse&{message?:string};
   if(!r.ok)throw new Error(data.message||'Não foi possível obter as prestações.');
   return data;
  }).then(data=>{if(!ctrl.signal.aborted){setProfile(data);setOrd('');setQuote(null);setParcelaQuote(null);setParcelaPrazo('');setPaymentAmount('');}})
   .catch(e=>{if(!ctrl.signal.aborted){setProfile(null);setMessage(e instanceof Error?e.message:'Erro na ficha.');}})
   .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  return()=>ctrl.abort();
 },[selected,refresh,token,embeddedContract]);
 useEffect(()=>{
  const ctrl=new AbortController();
  fetch(API+'/cis/caixa/contas',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(r=>r.ok?r.json():null).then(data=>{if(!ctrl.signal.aborted)setAccounts(data?.accounts??[]);})
   .catch(()=>{});
  return()=>ctrl.abort();
 },[token]);

 const rows=profile?.installments??[];
 const selectedInstallment=rows.find(r=>String(r.Ordem)===ord);
 const pending=rows.filter(isPending);
 const paidRows=rows.filter(r=>paid(r)||num(r['Valor Pago'])>0);
 const totals=useMemo(()=>{
  const sum=(key:string,filter:(r:Row)=>boolean=()=>true)=>rows.filter(filter).reduce((s,r)=>s+num(r[key]),0);
  return {
   pending:sum('Valor a Pagar',isPending),
   paid:sum('Valor Pago'),
   added:paidRows.reduce((s,r)=>s+num(r['Valor de Juros'])+num(r['Valor de Multa']),0),
   gross:sum('Valor a Pagar',isPending)+sum('Valor Pago'),
   late:rows.filter(r=>text(r.Estado)==='Pagamento Atrasado').length,
   next:[...pending].filter(r=>text(r['Data de Cobrança']).slice(0,10)>=today()).sort((a,b)=>text(a['Data de Cobrança']).localeCompare(text(b['Data de Cobrança'])))[0]
  };
 },[rows,paidRows]);
 const datePayment=today();
 const expected=num(selectedInstallment?.['Valor a Pagar']);
 const interests=num(selectedInstallment?.['Valor de Juros']);
 const partialNum=Number(paymentAmount);
 const partialValid=Number.isFinite(partialNum)&&partialNum>0&&partialNum<expected&&partialNum>=interests;
 const activeAccount=accounts.find(a=>String(a.id)===accountId&&a.status==='activa');
 const partialInterest=partialValid?Math.min(partialNum,interests):null;
 const partialCapital=partialValid?Math.max(0,partialNum-interests):null;
 const modeLocked=mode!=='total';
 const canQuote=mode==='total'&&!!selectedInstallment&&isPending(selectedInstallment);
 const cannotExecute=true; // Cobranças reais sem Extracto.account_id são recusadas pelo backend.

 function selectContract(numero:string){setSelected(numero);setProfile(null);setMode('total');setView('operacao');setAccountId('');setChannel('');setEvidenceRef('');setPenalty(false);setQuote(null);setScenarioQuote(null);setParcelaQuote(null);setParcelaPrazo('');setTaxaManual('');window.scrollTo({top:0,behavior:'smooth'});}
 function back(){setSelected(null);setProfile(null);setMessage('');setRefresh(v=>v+1);}
 function chooseMode(value:Mode){setMode(value);setQuote(null);setScenarioQuote(null);setPaymentAmount('');setCapitalPeriod('');setParcelaQuote(null);setParcelaPrazo('');setTaxaManual('');setReviewDate(today());setMessage('');}
 function chooseOrd(value:string){setOrd(value);setQuote(null);setScenarioQuote(null);setParcelaQuote(null);setPaymentAmount('');setMessage('');}
 async function simulateParcelas(e:FormEvent<HTMLFormElement>){
  e.preventDefault();
  if(!selected||!ord||!parcelaPrazo||parcelaBusy)return;
  if(mode!=='parcial'&&!(mode==='total'&&penalty))return;
  setParcelaQuote(null);setParcelaBusy(true);setMessage('');
  try{
   const payload={modo:mode==='parcial'?'reparcelamento':'multa',prazo:parcelaPrazo,
    ...(mode==='parcial'?{valor_recebido:paymentAmount.trim()}: {}),
    ...(taxaManual.trim()?{taxa_manual:taxaManual.trim()}: {})};
   const response=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+encodeURIComponent(ord)+'/simular-parcelas',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify(payload)
   });
   const data=await response.json().catch(()=>({})) as ParcelasQuote&{message?:string;errors?:Record<string,string[]>};
   if(!response.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'Não foi possível calcular com as regras de Parcelas.');
   setParcelaQuote(data);
  }catch(err){setMessage(err instanceof Error?err.message:'Falha na simulação de parcelas.');}
  finally{setParcelaBusy(false);}
 }
 async function simulateScenario(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(!selected||scenarioBusy||mode==='total')return;
  const key=ord||String(rows[0]?.Ordem??'');
  if(!key){setMessage('O contrato não possui uma prestação que permita simulação.');return;}
  setScenarioBusy(true);setScenarioQuote(null);setMessage('');
  try{
   const response=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+encodeURIComponent(key)+'/simular-cenario',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({operacao:mode,
     ...(mode==='parcial'?{valor_pago:paymentAmount.trim()}:{}),
     ...(mode==='capitalizar'?{periodo_referencia:Number(capitalPeriod)}:{}),
     ...(mode==='actualizar_data'?{data_pagamento:reviewDate}:{})})
   });
   const data=await response.json().catch(()=>({})) as ScenarioQuote&{message?:string;errors?:Record<string,string[]>};
   if(!response.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'Não foi possível simular a operação.');
   setScenarioQuote(data);
  }catch(err){setMessage(err instanceof Error?err.message:'Falha na simulação.');}
  finally{setScenarioBusy(false);}
 }

 async function simulate(e:FormEvent){
  e.preventDefault();if(!selected||!ord||!canQuote)return;
  setQuote(null);setQuoting(true);setMessage('');
  try{
   const r=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+ord+'/simular-cobranca',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({data_pagamento:datePayment,cobrar_multa:penalty})
   });
   const data=await r.json().catch(()=>({})) as Quote&{message?:string};
   if(!r.ok)throw new Error(data.message||'Esta prestação ainda não pode ser simulada.');
   setQuote(data);
  }catch(e){setMessage(e instanceof Error?e.message:'Falha ao calcular.');}
  finally{setQuoting(false);}
 }

 return <section className={'cis-collections'+(embeddedContract?' cis-collections-embedded':'')}>
  {!embeddedContract&&<div className="cis-collections-hero"><div><span className="cis-collections-kicker"><ReceiptText size={14}/> CAPTA · OPERAÇÕES DE CRÉDITO</span>
   <h2>Cobranças</h2><p>Gestão de prestações, recebimentos e tratamento de situações de pagamento.</p>
   <div className="cis-collections-hero-badges"><span><ShieldCheck size={14}/> Dados do Contractos</span><span><LockKeyhole size={14}/> Operações financeiras protegidas</span></div></div>
   <button type="button" onClick={()=>{setRefresh(v=>v+1);setQuote(null);}} disabled={listing||loading}><RefreshCw size={16}/> Actualizar</button>
  </div>}
  <div className="cis-collections-protection"><Info size={18}/><div><strong>Preparação e simulação de cobranças</strong><p>O registo efectivo de pagamentos permanece bloqueado até integrar obrigatoriamente a conta receptora e a entrada no Extracto. As opções do formulário VBA são apresentadas, mas não executam alterações financeiras sem homologação.</p></div></div>
  {message&&<div className="cis-cash-message error" role="alert"><AlertCircle size={17}/>{message}</div>}
  {!selected?<div className="cis-collections-panel">
    <div className="cis-collections-sectionhead"><div><h3>Seleccionar contrato</h3><p>Pesquise por número do contrato ou nome do mutuário.</p></div><span><BookOpen size={15}/> {list?.total??'—'} contrato(s)</span></div>
    <label className="cis-collections-search"><Search size={18}/><span className="cis-sr-only">Pesquisar contrato</span><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Pesquisar contrato ou mutuário…"/></label>
    {listing?<div className="cis-cash-loading" role="status">A carregar contratos…</div>:!list?.data.length?
     <div className="cis-cash-empty"><Search size={24}/><strong>Nenhum contrato encontrado</strong><p>Confirme os critérios da pesquisa.</p></div>:
     <div className="cis-collections-list">{list.data.map(row=><button type="button" key={row.numero} onClick={()=>selectContract(row.numero)}>
      <span className="cis-collections-list-icon"><FileText size={19}/></span><span className="cis-collections-list-info"><strong>{row.numero}</strong><small>{row.cliente} · {row.linhas_prestacoes} prestação(ões)</small></span>
      <span className="cis-collections-list-state">{row.estado}</span><ChevronRight size={18}/></button>)}</div>}
    {list&&list.last_page>1&&<div className="cis-collections-pagination"><button type="button" disabled={page<=1} onClick={()=>setPage(p=>p-1)}>Anterior</button><span>Página {page} de {list.last_page}</span><button type="button" disabled={page>=list.last_page} onClick={()=>setPage(p=>p+1)}>Seguinte</button></div>}
   </div>:
   <div className="cis-collections-workspace">
    {!embeddedContract&&<div className="cis-collections-backline"><button type="button" onClick={back}><ArrowLeft size={16}/> Voltar aos contratos</button><span><FileText size={15}/> {selected}</span></div>}
    {loading?<div className="cis-cash-loading">A carregar a ficha…</div>:profile&&<>
     {!embeddedContract&&<div className="cis-collections-client"><div><small>CONTRATO EM ANÁLISE</small><h3>{text(profile.contract['Nome do Cliente'])}</h3><span>Contrato {selected} · Cliente n.º {text(profile.contract['Nº de Cliente'])}</span></div>
      <span className="cis-collections-client-badge">{text(profile.contract['Estado do Contrato'])}</span></div>}
     <div className="cis-collections-stats">
      <article><span>Prestações</span><strong>{rows.length}</strong><small>Total registado</small></article>
      <article><span>Valor pendente</span><strong>{fmt(totals.pending)}</strong><small>Prestações pendentes e atrasadas</small></article>
      <article><span>Valor liquidado</span><strong>{fmt(totals.paid)}</strong><small>Soma de Valor Pago</small></article>
      <article><span>Juros e multas liquidados</span><strong>{fmt(totals.added)}</strong><small>Componentes das prestações pagas</small></article>
      <article><span>Valor bruto</span><strong>{fmt(totals.gross)}</strong><small>Pago + pendente, sem reconciliação</small></article>
      <article><span>Próxima cobrança</span><strong>{date(totals.next?.['Data de Cobrança'])}</strong><small>{totals.next?fmt(totals.next['Valor a Pagar']):'Sem próximas prestações'}</small></article>
      <article><span>Prestações em atraso</span><strong>{totals.late}</strong><small>Estado registado: Pagamento Atrasado</small></article>
     </div>
     {!!profile.contract_fields_divergent?.length&&<div className="cis-collections-risk"><AlertCircle size={17}/> Campos divergentes no contrato: {profile.contract_fields_divergent.join(', ')}. Não assumir valores únicos sem reconciliação.</div>}
     <div className="cis-collections-switch"><button type="button" className={view==='operacao'?'selected':''} onClick={()=>setView('operacao')}><Calculator size={16}/> Operação de cobrança</button><button type="button" className={view==='historico'?'selected':''} onClick={()=>setView('historico')}><BookOpen size={16}/> Prestações e histórico</button></div>
     {view==='operacao'?<div className="cis-collections-payment-grid">
      <div className="cis-collections-panel">
       <div className="cis-collections-sectionhead"><div><h3>1. Seleccionar prestação</h3><p>Escolha uma prestação pendente ou em atraso.</p></div><span>{pending.length} pendentes</span></div>
       <div className="cis-collections-installments">{pending.map(row=><button type="button" className={ord===String(row.Ordem)?'selected':''} key={String(row.Ordem)} onClick={()=>chooseOrd(String(row.Ordem))}>
        <span className="cis-collections-installment-no">#{text(row['Nº da Parcela'])}</span><span><strong>{date(row['Data de Cobrança'])}</strong><small className={text(row.Estado)==='Pagamento Atrasado'?'late':''}>{text(row.Estado)}</small></span><b>{fmt(row['Valor a Pagar'])}</b></button>)}
        {!pending.length&&<p className="cis-collections-empty-small">Não existem prestações pendentes nesta ficha.</p>}</div>
       {selectedInstallment&&<div className="cis-collections-breakdown">
        <h4>Composição da prestação</h4>
        <dl><div><dt>Capital</dt><dd>{fmt(selectedInstallment.Capital)}</dd></div><div><dt>Taxa de juro</dt><dd>{text(profile.contract['% de Juro'])}%</dd></div><div><dt>Tipo de juro</dt><dd>{text(profile.contract['Tipo de Juro'])}</dd></div><div><dt>Taxa de multa</dt><dd>{text(profile.contract['% de Multa'])}%</dd></div><div><dt>Modalidade contratual</dt><dd>{text(profile.contract['M. Pagamento'])}</dd></div><div><dt>Amortização</dt><dd>{fmt(selectedInstallment['Amortização'])}</dd></div><div><dt>Juros</dt><dd>{fmt(selectedInstallment['Valor de Juros'])}</dd></div><div><dt>Multa registada</dt><dd>{fmt(selectedInstallment['Valor de Multa'])}</dd></div><div><dt>Valor a pagar</dt><dd>{fmt(selectedInstallment['Valor a Pagar'])}</dd></div><div><dt>Valor já pago</dt><dd>{fmt(selectedInstallment['Valor Pago'])}</dd></div><div><dt>Vencimento</dt><dd>{date(selectedInstallment['Data de Cobrança'])}</dd></div><div><dt>Estado de pagamento</dt><dd>{text(selectedInstallment['Situação de Pagamento'])}</dd></div></dl>
       </div>}
      </div>
      <div className="cis-collections-panel">
       <div className="cis-collections-sectionhead"><div><h3>2. Tratamento do pagamento</h3><p>Escolha o cenário equivalente ao formulário Cobrança.</p></div></div>
       <div className="cis-collections-mode-grid">
        {(['total','parcial','renovar_juros','capitalizar','remanescente','renovar_credito','actualizar_data','finalizar_contrato'] as Mode[]).map(value=><button type="button" key={value} disabled={!ord&&!['renovar_credito','finalizar_contrato'].includes(value)} className={mode===value?'selected':''} onClick={()=>chooseMode(value)}>
         {modeIcon(value)}<span><strong>{statuses[value].name}</strong><small>{statuses[value].group}</small></span>{mode===value&&<CheckCircle2 size={16}/>}</button>)}
       </div>
       <p className="cis-collections-mode-hint">{statuses[mode].details}</p>
       <div className="cis-collections-fields">
        <label>Data real de pagamento (Maputo)<input type="date" value={datePayment} readOnly/><small>Para simulação de liquidação integral, apenas a data de hoje é aceite.</small></label>
        <label>Forma de pagamento confirmada <small>Forma prevista no contrato: {text(profile.contract['Forma de Pagamento'])}</small><select value={channel} onChange={e=>setChannel(e.target.value)}><option value="">Seleccionar forma de recebimento</option><option value="numerario">Numerário</option><option value="banco">Transferência / depósito bancário</option><option value="movel">Transferência móvel</option></select><small>A modalidade contratual e o canal efectivamente utilizado podem ser diferentes; registar a divergência no processo.</small></label>
        <label>Conta receptora na Caixa <span className="cis-collections-required">Obrigatória para cobrança futura</span><select value={accountId} onChange={e=>setAccountId(e.target.value)}><option value="">Seleccionar conta activa</option>{accounts.filter(a=>a.status==='activa').map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select><small>{activeAccount?'Conta seleccionada (nenhuma gravação será feita).':'Contas sem saldo validado não podem receber cobranças.'}</small></label>
        <label>Referência do comprovativo / operação <small>Preparação, não será guardada</small><input type="text" maxLength={120} value={evidenceRef} onChange={e=>setEvidenceRef(e.target.value)} placeholder="Número de operação ou recibo"/><small>O comprovativo deverá ser validado antes do registo definitivo.</small></label>
        {mode==='total'&&<label className="cis-collections-check"><input type="checkbox" checked={penalty} onChange={e=>{setPenalty(e.target.checked);setQuote(null);setParcelaQuote(null);setParcelaPrazo('');}}/> Cobrar multa por atraso quando aplicável</label>}
        {mode==='actualizar_data'&&<label>Data a rever (não gravada)<input type="date" value={reviewDate} max={today()} onChange={e=>setReviewDate(e.target.value)}/><small>A rotina VBA altera estado e juros variáveis; o CAPTA não grava esta revisão sem auditoria e validação.</small></label>}
        {mode==='capitalizar'&&<label>Período de referência para nova taxa <span className="cis-collections-required">Escolha explícita obrigatória</span><select value={capitalPeriod} onChange={e=>{setCapitalPeriod(e.target.value);setScenarioQuote(null);}}><option value="">Seleccionar período</option><option value="15">15 dias</option><option value="30">30 dias</option></select><small>O VBA privilegia 30 dias, mas a simulação CAPTA exige uma escolha explícita para evitar taxa presumida.</small></label>}
        {mode==='parcial'&&<label>Valor efectivamente recebido (MT)<input inputMode="decimal" type="text" value={paymentAmount} onChange={e=>{setPaymentAmount(e.target.value);setScenarioQuote(null);setParcelaQuote(null);}} placeholder="0.00"/><small>O VBA exige valor inferior à prestação e, como regra inicial, não inferior aos juros.</small></label>}
       </div>
       {selectedInstallment&&(mode==='parcial'||mode==='total'&&penalty)&&
        <div className="cis-collections-parcelas">
         <div className="cis-collections-parcelas-heading"><Calculator size={18}/><div><strong>Calculadora de Parcelas</strong><small>Regras verificadas no ficheiro Parcelas.frm</small></div></div>
         <p>{mode==='parcial'?'Recalcular uma prestação indicativa sobre o valor restante após pagamento parcial.':'Comparar o cálculo de multa por faixa do formulário original com a taxa de multa prevista no contrato.'}</p>
         <form onSubmit={e=>void simulateParcelas(e)}>
          <label>Prazo da calculadora <span className="cis-collections-required">Escolha explícita</span>
           <select value={parcelaPrazo} onChange={e=>{setParcelaPrazo(e.target.value);setParcelaQuote(null);}}>
            <option value="">Seleccionar prazo</option>
            <option value="1 Semana">1 Semana</option>
            <option value="2 Semanas">2 Semanas</option>
            <option value="3 Semanas">3 Semanas</option>
            <option value="Mês">Mês</option>
           </select>
          </label>
          <label>Taxa manual (%) <span className="cis-collections-required">Opcional · só para simulação</span>
           <input inputMode="decimal" value={taxaManual} maxLength={6} onChange={e=>{setTaxaManual(e.target.value);setParcelaQuote(null);}} placeholder="Usar taxa automática da faixa"/>
          </label>
          <small>No VBA, o prazo inicial é «2 Semanas» e fica oculto no modo multa. Aqui é explicitado para evitar aplicação silenciosa de taxas.</small>
          <button type="submit" disabled={!parcelaPrazo||parcelaBusy||(mode==='parcial'&&!partialValid)}>
           {parcelaBusy?'A calcular…':mode==='parcial'?'Simular nova parcela':'Comparar cálculo de multa'} <ArrowRight size={16}/>
          </button>
         </form>
         {parcelaQuote&&<div className="cis-collections-parcelas-result" role="status">
          <strong>Resultado indicativo · Sem gravação</strong>
          <dl>
           <div><dt>Base calculada</dt><dd>{fmt(parcelaQuote.base)}</dd></div>
           <div><dt>Taxa da faixa</dt><dd>{parcelaQuote.taxa_faixa}%</dd></div>
           <div><dt>Taxa aplicada</dt><dd>{parcelaQuote.taxa_aplicada}%{parcelaQuote.taxa_manual?' (manual)':''}</dd></div>
           <div><dt>Faixa de valor</dt><dd>{parcelaQuote.limite_inferior} – {parcelaQuote.limite_superior} MT</dd></div>
           <div><dt>Período de referência</dt><dd>{parcelaQuote.periodo_referencia} dias</dd></div>
           <div><dt>Prazo seleccionado</dt><dd>{parcelaQuote.dias_propostos} dias</dd></div>
           <div><dt>{mode==='parcial'?'Juros adicionais':'Multa pela faixa'}</dt><dd>{fmt(parcelaQuote.juros_ou_multa_calculados)}</dd></div>
           <div className="total"><dt>{mode==='parcial'?'Nova prestação indicativa':'Multa calculada pelo Parcelas.frm'}</dt><dd>{fmt(parcelaQuote.total_calculado)}</dd></div>
           {mode==='total'&&<><div><dt>Taxa de multa contratual</dt><dd>{parcelaQuote.taxa_multa_contrato===null?'Não informada':parcelaQuote.taxa_multa_contrato+'%'}</dd></div><div><dt>Multa pela taxa contratual</dt><dd>{fmt(parcelaQuote.multa_pela_taxa_contratual)}</dd></div></>}
          </dl>
          {parcelaQuote.metodos_divergentes&&<div className="cis-collections-risk"><AlertCircle size={17}/> As taxas do formulário Parcelas e do contrato divergem. Não deve ser cobrada qualquer multa sem escolher e homologar a política.</div>}
          <p>{parcelaQuote.aviso}</p>
          <small>{parcelaQuote.arredondamento}</small>
         </div>}
        </div>}
       {mode==='total'&&<form onSubmit={e=>void simulate(e)}>
        <button type="submit" className="cis-collections-primary" disabled={!canQuote||quoting}>{quoting?'A calcular…':'Simular pagamento integral'} <Calculator size={16}/></button>
       </form>}
       {modeLocked&&<div className="cis-collections-planned"><LockKeyhole size={17}/><span><strong>Operação não activada.</strong> O cenário foi identificado no VBA. O CAPTA pode apresentar uma prévia informativa, mas nenhuma alteração financeira será gravada.</span></div>}
       {modeLocked&&<form onSubmit={e=>void simulateScenario(e)}><button className="cis-collections-primary" type="submit" disabled={scenarioBusy||!(ord||rows.length)||mode==='parcial'&&!partialValid||mode==='capitalizar'&&!capitalPeriod}>{scenarioBusy?'A consultar regras…':'Simular cenário sem gravar'} <Calculator size={16}/></button></form>}
       {scenarioQuote&&modeLocked&&<div className="cis-collections-quote"><div className="cis-collections-quote-title"><ShieldCheck size={18}/><strong>Prévia informativa do servidor</strong></div><dl>{(['capital_historico','juros_historicos','valor_pago','juros_cobertos','amortizacao','diferenca_aritmetica','capital','juros_actual','juros_novo','prestacao_nova','capital_anterior','juros_anteriores','capital_novo','taxa','periodo_referencia','faixa','juros_novos','data_revisao','dias_atraso','estado_proposto','prestacoes_em_atraso','prestacoes_nao_liquidadas','elegivel_para_analise'] as const).filter(key=>scenarioQuote[key]!==undefined).map(key=><div key={key}><dt>{({'capital_historico':'Capital histórico','juros_historicos':'Juros históricos','valor_pago':'Valor parcial','juros_cobertos':'Juros cobertos','amortizacao':'Amortização indicativa','diferenca_aritmetica':'Diferença aritmética','capital':'Capital','juros_actual':'Juros actuais','juros_novo':'Juros revistos','prestacao_nova':'Prestação indicativa','capital_anterior':'Capital anterior','juros_anteriores':'Juros anteriores','capital_novo':'Novo capital indicativo','taxa':'Taxa (%)','periodo_referencia':'Período (dias)','faixa':'Faixa de capital','juros_novos':'Juros novos','data_revisao':'Data revista','dias_atraso':'Dias em atraso','estado_proposto':'Estado proposto','prestacoes_em_atraso':'Prestações em atraso','prestacoes_nao_liquidadas':'Prestações não liquidadas','elegivel_para_analise':'Elegível para análise'} as Record<string,string>)[key]}</dt><dd>{Array.isArray(scenarioQuote[key])?(scenarioQuote[key] as unknown[]).join(' – '):typeof scenarioQuote[key]==='boolean'?(scenarioQuote[key]?'Sim':'Não'):String(scenarioQuote[key])}</dd></div>)}</dl><div className="cis-collections-planned"><Info size={16}/><span>{scenarioQuote.aviso}</span></div></div>}
       {mode==='parcial'&&paymentAmount&&<div className="cis-collections-breakdown">
        <h4>Verificação indicativa do pagamento parcial</h4>
        {!partialValid?<div className="cis-collections-risk"><AlertCircle size={16}/> Verifique o valor: deve ser positivo, inferior ao valor a pagar e não inferior aos juros, conforme o VBA. Não foi efectuado qualquer lançamento.</div>:
         <dl><div><dt>Valor introduzido</dt><dd>{fmt(partialNum)}</dd></div><div><dt>Juros cobertos primeiro</dt><dd>{fmt(partialInterest)}</dd></div><div><dt>Amortização indicativa</dt><dd>{fmt(partialCapital)}</dd></div><div><dt>Diferença aritmética</dt><dd>{fmt(Math.max(0,expected-partialNum))}</dd></div></dl>}
        <small>Não substitui a criação/recalendarização de uma nova prestação nem representa valor remanescente homologado.</small>
       </div>}
       {mode==='renovar_juros'&&<div className="cis-collections-planned"><Info size={17}/><span>Na rotina ActualizarFinanceiroCobranca, renovar juros preserva o capital e acrescenta mais um período de juros. Não é um pagamento nem deve criar entrada no Extracto.</span></div>}
       {mode==='capitalizar'&&<div className="cis-collections-planned"><Info size={17}/><span>Capitalização exige recalcular a taxa e o intervalo aplicável a partir das faixas da Table4/Definições. Nunca aplicar taxa presumida nem alterar capital sem validação.</span></div>}
       {mode==='remanescente'&&<div className="cis-collections-planned"><Info size={17}/><span>Liquidar o remanescente exige verificar prestações em atraso, montante realmente devido e evidência de recebimento. O botão VBA não é suficiente para autorizar a operação.</span></div>}
       {mode==='renovar_credito'&&<div className="cis-collections-planned"><Info size={17}/><span>A renovação do empréstimo no VBA cria um NOVO contrato. Não é igual a renovar juros: exige nova avaliação, consentimento, conta de desembolso, identificação e aprovação independente.</span></div>}
       {mode==='actualizar_data'&&<div className="cis-collections-planned"><CalendarDays size={17}/><span>Data seleccionada: {date(reviewDate)}. Vencimento da prestação: {date(selectedInstallment?.['Data de Cobrança'])}. O VBA permite rever datas passadas e recalcular juros variáveis; alterações retroactivas exigem trilho de auditoria e não são executadas aqui.</span></div>}
       {mode==='finalizar_contrato'&&<div className="cis-collections-planned"><ShieldCheck size={17}/><span>{pending.length?'Existem '+pending.length+' prestação(ões) pendentes. Não é possível presumir liquidação integral ou encerrar o contrato.':'A lista não apresenta prestações pendentes. É necessário confirmar todos os pagamentos e o Extracto antes da finalização.'}</span></div>}
       {quote&&mode==='total'&&<div className="cis-collections-quote"><div className="cis-collections-quote-title"><CheckCircle2 size={18}/><strong>Prévia calculada no servidor</strong></div>
        <dl><div><dt>Data</dt><dd>{date(quote.data_pagamento)}</dd></div><div><dt>Dias de atraso</dt><dd>{quote.dias_atraso}</dd></div><div><dt>Juros</dt><dd>{fmt(quote.juros)}</dd></div><div><dt>Multa</dt><dd>{fmt(quote.multa)}</dd></div><div className="total"><dt>Total proposto</dt><dd>{fmt(quote.total)}</dd></div><div><dt>Estado previsto</dt><dd>{quote.estado}</dd></div></dl>
        <div className="cis-collections-planned"><LockKeyhole size={17}/><span>Esta prévia não significa cobrança concluída. O backend recusa pagamentos sem associação obrigatória a Extracto.account_id.</span></div></div>}
       <div className="cis-collections-disabled"><button type="button" disabled={cannotExecute}><LockKeyhole size={16}/> Confirmar e registar pagamento</button><small>Desactivado até integrar conta, permissões, idempotência, referência documental e confirmação de entrada real. Nenhum dado introduzido nesta preparação é gravado.</small></div>
      </div>
     </div>:
     <div className="cis-collections-panel">
      <div className="cis-collections-sectionhead"><div><h3>Prestações e histórico financeiro</h3><p>Campos originais da tabela Contractos. Inclui movimentos completos, parciais e atrasados quando registados.</p></div><span>{rows.length} linha(s)</span></div>
      <div className="cis-table-scroll"><table className="cis-collections-table"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Data de pagamento</th><th>Capital</th><th>Amortização</th><th>Juros</th><th>Multa</th><th>Previsto</th><th>Recebido</th><th>Estado</th><th>Situação</th></tr></thead><tbody>{rows.map((row,i)=><tr key={String(row.Ordem??i)}>
       <td>{text(row['Nº da Parcela'])}</td><td>{date(row['Data de Cobrança'])}</td><td>{date(row['Data de Pagamento'])}</td>
       <td>{fmt(row.Capital)}</td><td>{fmt(row['Amortização'])}</td><td>{fmt(row['Valor de Juros'])}</td><td>{fmt(row['Valor de Multa'])}</td><td>{fmt(row['Valor a Pagar'])}</td><td>{fmt(row['Valor Pago'])}</td>
       <td><span className={'cis-collections-pill '+(paid(row)?'paid':isPending(row)?'pending':'')}>{text(row.Estado)}</span></td><td>{text(row['Situação de Pagamento'])}</td>
      </tr>)}</tbody></table></div>
      <div className="cis-collections-scope"><ShieldCheck size={16}/> Leitura apenas: não altera Contractos, Extracto nem o estado de pagamento.</div>
     </div>}
    </>}
   </div>}
  <div className="cis-collections-footer"><ShieldCheck size={16}/> Fonte única dos lançamentos: Extracto. A Tesouraria opcional terá reconciliação bancária, sem duplicar os movimentos da Caixa.</div>
 </section>;
}
