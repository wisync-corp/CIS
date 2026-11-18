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
type Quote={
 parcela:number;dias_atraso:number;juros:number;multa:number;total:number;total_texto:string;
 estado?:string;data_pagamento:string;aviso:string;origem?:'cis'|'historico';registo_activo?:boolean;
 nova_prestacao?:{parcela:number;data_inicio:string;data_vencimento:string;prazo:string;capital:string;juros:string;valor_a_pagar:string;juros_estimado:boolean}
};
type PaymentResult={registado:boolean;repetido:boolean;extracto_nr:number;conta?:string;saldo_conta_actual?:string;contrato_finalizado?:boolean;notice:string;total_texto?:string;valor?:string};
type HistoryEvent={id:number;operacao:string;parcela:number|null;valor:string|null;extracto_nr:number|null;referencia:string|null;motivo:string|null;antes:Row|null;depois:Row|null;em:string};
const historyLabels:Record<string,string>={cobranca_total:'Pagamento integral',desembolso:'Desembolso / abertura',renovar_juros:'Renovação de juros',capitalizar:'Capitalização de juros',actualizar_data:'Revisão de data',prorrogar_15_30:'Prorrogação 15→30 dias',pagamento_parcial:'Pagamento parcial',finalizar_contrato:'Finalização',renovacao_nova_prestacao:'Nova prestação (renovação)'};
const historyFields=['Capital','Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','% de Juro','Estado','Data de Pagamento','Situação de Pagamento'];
const newKey=()=>typeof crypto!=='undefined'&&'randomUUID' in crypto?crypto.randomUUID():'10000000-1000-4000-8000-100000000000'.replace(/[018]/g,c=>(Number(c)^Math.random()*16>>Number(c)/4).toString(16));
type Mode='total'|'renovar_emprestimo'|'parcial'|'renovar_juros'|'capitalizar'|'remanescente'|'renovar_credito'|'actualizar_data'|'finalizar_contrato'|'prorrogar_15_30';
const statuses:Record<Mode,{name:string;details:string;group:string}>={
 total:{name:'Pagamento integral',details:'Liquidação completa da prestação, com eventual multa por atraso.',group:'Com pagamento'},
 renovar_emprestimo:{name:'Renovar empréstimo',details:'O cliente paga só os juros; o mesmo contrato recebe nova prestação com o mesmo capital e prazo.',group:'Com pagamento'},
 parcial:{name:'Pagamento parcial',details:'Registar montante inferior ao devido; juros antes da amortização.',group:'Com pagamento'},
 renovar_juros:{name:'Renovar juros',details:'Novo período de juros sobre o capital ainda devido, sem recebimento.',group:'Sem pagamento'},
 capitalizar:{name:'Capitalizar juros',details:'Incorporar juros no capital e apurar nova taxa pela tabela de faixas.',group:'Sem pagamento'},
 remanescente:{name:'Liquidar remanescente',details:'Liquidação final após parcial, sujeita a controlo dos atrasos.',group:'Regularização'},
 renovar_credito:{name:'Renovar crédito',details:'Nova operação de crédito após o termo da anterior; exige novo contrato e desembolso autorizado.',group:'Novo crédito'},
 actualizar_data:{name:'Rever data de pagamento',details:'Verificar data efectiva, dias de atraso, estado e eventual recálculo de juros variáveis.',group:'Regularização'},
 finalizar_contrato:{name:'Finalizar contrato',details:'Verificar se todas as prestações se encontram efectivamente liquidadas antes de encerrar.',group:'Encerramento'},
 prorrogar_15_30:{name:'Passar de 15 para 30 dias',details:'Avaliar a alteração do prazo de 15 para 30 dias antes de qualquer recálculo ou aprovação.',group:'Alteração de prazo'}
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
const paid=(row:Row)=>['Pago','Pago em Atraso','Renovado'].includes(text(row.Estado));
const today=()=>{
 const tokens=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Maputo',day:'2-digit',month:'2-digit',year:'numeric'}).formatToParts(new Date());
 const part=(type:string)=>tokens.find(p=>p.type===type)?.value??'';
 return part('year')+'-'+part('month')+'-'+part('day');
};
function modeIcon(mode:Mode){
 switch(mode){case'total':return <CheckCircle2 size={18}/>;case'parcial':return <Wallet size={18}/>;
 case'renovar_juros':case'renovar_emprestimo':return <RotateCcw size={18}/>;case'capitalizar':return <TrendingUp size={18}/>;
 case'remanescente':return <Coins size={18}/>;
 case'renovar_credito':return <FileText size={18}/>;case'actualizar_data':return <CalendarDays size={18}/>;
 case'finalizar_contrato':return <ShieldCheck size={18}/>;case'prorrogar_15_30':return <CalendarDays size={18}/>;}
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
 const [paymentNature,setPaymentNature]=useState<'recebido'|'nao_recebido'|null>(null);
 const [ord,setOrd]=useState(initialOrder??'');
 const [penalty,setPenalty]=useState(false);
 const [paymentAmount,setPaymentAmount]=useState('');
 const [reviewDate,setReviewDate]=useState(today());
 const [renewStart,setRenewStart]=useState('');
 const [quote,setQuote]=useState<Quote|null>(null);
 const [quoting,setQuoting]=useState(false);
 const [scenarioBusy,setScenarioBusy]=useState(false);
 const [scenarioQuote,setScenarioQuote]=useState<ScenarioQuote|null>(null);
 const [capitalPeriod,setCapitalPeriod]=useState('');
 const [accountId,setAccountId]=useState('');
 const [channel,setChannel]=useState('');
 const [evidenceRef,setEvidenceRef]=useState('');
 const [accounts,setAccounts]=useState<{id:number;code:string;name:string;status:string;saldo_disponivel?:string|null}[]>([]);
 const [confirmReceipt,setConfirmReceipt]=useState(false);
 const [paying,setPaying]=useState(false);
 const [payKey,setPayKey]=useState('');
 const [paymentDone,setPaymentDone]=useState<PaymentResult|null>(null);
 const [payError,setPayError]=useState('');
 const [payWaitUntil,setPayWaitUntil]=useState(0);
 const [clock,setClock]=useState(Date.now());
 useEffect(()=>{if(payWaitUntil<=Date.now())return;const id=window.setInterval(()=>setClock(Date.now()),1000);return()=>window.clearInterval(id);},[payWaitUntil]);
 const payWait=Math.max(0,Math.ceil((payWaitUntil-clock)/1000));
 const [events,setEvents]=useState<HistoryEvent[]|null>(null);
 const [view,setView]=useState<'resumo'|'tratamento'|'historico'>(initialOrder?'tratamento':'resumo');
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
  }).then(data=>{if(!ctrl.signal.aborted){setProfile(data);setOrd('');setQuote(null);setPaymentAmount('');}})
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

 useEffect(()=>{
  if(!selected||view!=='historico')return;
  const ctrl=new AbortController();setEvents(null);
  fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/historico-alteracoes',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(r=>r.ok?r.json():{data:[]}).then(d=>{if(!ctrl.signal.aborted)setEvents(d.data??[]);}).catch(()=>{if(!ctrl.signal.aborted)setEvents([]);});
  return()=>ctrl.abort();
 },[selected,view,refresh,token]);
 const rows=profile?.installments??[];
 const selectedInstallment=rows.find(r=>String(r.Ordem)===ord);
 const pending=rows.filter(isPending);
 const currentTerm=text(profile?.contract['Prazo/Duração']);
 const isFifteenDayTerm=/^(?:15\s*dias?|2\s*semanas)$/i.test(currentTerm.trim());
 const contractStatus=text(profile?.contract['Estado do Contrato']);
 const contractClosed=/finalizado|liquidado|cancelado|anulado/i.test(contractStatus);
 const installmentEligible=(row:Row|undefined)=>Boolean(row&&isPending(row)&&!contractClosed&&
  !/^(?:completo|liquidado|finalizado)$/i.test(String(row?.['Situação de Pagamento']??'').trim()));
 const eligibleModes:Mode[]=selectedInstallment&&installmentEligible(selectedInstallment)?(
   paymentNature==='recebido'?['total',...(pending.length===1?['renovar_emprestimo' as Mode]:[]),'parcial',...((String(selectedInstallment['Situação de Pagamento']??'').toLowerCase().includes('parcial')||num(selectedInstallment['Valor Pago'])>0)?['remanescente' as Mode]:[])]:
   paymentNature==='nao_recebido'?['renovar_juros','capitalizar',...(isFifteenDayTerm?['prorrogar_15_30' as Mode]:[])]:[]
 ):[];
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
 const renewing=mode==='renovar_emprestimo';
 const live=mode==='total'||renewing;
 const modeLocked=!live;
 const flowReceived=paymentNature==='recebido';
 const flowNoReceipt=paymentNature==='nao_recebido';
 const hasChoice=flowReceived||flowNoReceipt;
 const modeAllowed=eligibleModes.includes(mode);
 const activeOrder=ord&&rows.some(r=>String(r.Ordem)===ord&&installmentEligible(r));
 const canQuote=live&&flowReceived&&(!renewing||/^\d{4}-\d{2}-\d{2}$/.test(renewStart))&&!!selectedInstallment&&installmentEligible(selectedInstallment);
 const refOk=evidenceRef.trim().length>=4;
 const canExecute=live&&flowReceived&&!!quote&&!!quote.registo_activo&&!!activeAccount&&refOk&&confirmReceipt&&!paying&&payWait===0;

 function selectContract(numero:string){setSelected(numero);setProfile(null);setPaymentNature(null);setMode('total');setView('resumo');setAccountId('');setChannel('');setEvidenceRef('');setPenalty(false);setQuote(null);setScenarioQuote(null);window.scrollTo({top:0,behavior:'smooth'});}
 function back(){setSelected(null);setProfile(null);setMessage('');setRefresh(v=>v+1);}
 function chooseMode(value:Mode){if(!eligibleModes.includes(value))return;setMode(value);setQuote(null);setConfirmReceipt(false);setPayKey('');setPaymentDone(null);if(value==='renovar_emprestimo')setRenewStart(text(selectedInstallment?.['Data de Cobrança']).slice(0,10));setScenarioQuote(null);setPaymentAmount('');setCapitalPeriod('');setReviewDate(today());setMessage('');}
 function chooseOrd(value:string){setConfirmReceipt(false);setPayKey('');setPaymentDone(null);setOrd(value);setPaymentNature(null);setMode('total');setQuote(null);setScenarioQuote(null);setPaymentAmount('');setMessage('');}
 async function simulateScenario(e:FormEvent<HTMLFormElement>){
  e.preventDefault();if(!selected||scenarioBusy||live||!modeAllowed||!activeOrder)return;
  const key=ord;
  if(!key){setMessage('O contrato não possui uma prestação que permita simulação.');return;}
  setScenarioBusy(true);setScenarioQuote(null);setMessage('');
  try{
   const response=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+encodeURIComponent(key)+'/simular-cenario',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({operacao:mode,
     ...(mode==='parcial'?{valor_pago:paymentAmount.trim()}:{}),
     ...(mode==='capitalizar'?{periodo_referencia:Number(capitalPeriod)}:{}),
     ...(mode==='prorrogar_15_30'?{prazo_origem_dias:15,prazo_destino_dias:30}:{}),
     ...(mode==='actualizar_data'?{data_pagamento:reviewDate}:{})})
   });
   const data=await response.json().catch(()=>({})) as ScenarioQuote&{message?:string;errors?:Record<string,string[]>};
   if(!response.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'Não foi possível simular a operação.');
   setScenarioQuote(data);
  }catch(err){setMessage(err instanceof Error?err.message:'Falha na simulação.');}
  finally{setScenarioBusy(false);}
 }

 async function pay(){
  if(!selected||!ord||!quote||!canExecute)return;
  const key=payKey||newKey();setPayKey(key);setPaying(true);setMessage('');setPayError('');
  try{
   const r=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+ord+(renewing?'/renovar':'/cobrar'),{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({data_pagamento:datePayment,cobrar_multa:penalty,...(renewing?{data_inicio:renewStart}:{}),valor_recebido:quote.total_texto,
     conta_id:Number(accountId),referencia:evidenceRef.trim(),idempotency_key:key,confirmacao_recebimento:true})
   });
   if(r.status===429){
    const wait=Math.min(120,Math.max(5,Number(r.headers.get('Retry-After'))||60));
    setPayWaitUntil(Date.now()+wait*1000);
    throw new Error('Limite de segurança: no máximo 3 tentativas por minuto. Aguarde '+wait+' s e clique uma única vez. Nenhum pagamento foi duplicado.');
   }
   const data=await r.json().catch(()=>({})) as PaymentResult&{message?:string;errors?:Record<string,string[]>};
   if(!r.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'O pagamento não foi registado.');
   setPaymentDone(data);setQuote(null);setConfirmReceipt(false);setPayKey('');setRefresh(v=>v+1);
  }catch(err){setPayError(err instanceof Error?err.message:'Falha ao registar o pagamento.');}
  finally{setPaying(false);}
 }

 async function simulate(e:FormEvent){
  e.preventDefault();if(!selected||!ord||!canQuote||!modeAllowed)return;
  setQuote(null);setConfirmReceipt(false);setPaymentDone(null);setQuoting(true);setMessage('');
  try{
   const r=await fetch(API+'/cis/contractos/'+encodeURIComponent(selected)+'/prestacoes/'+ord+(renewing?'/simular-renovacao':'/simular-cobranca'),{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({data_pagamento:datePayment,cobrar_multa:penalty,...(renewing?{data_inicio:renewStart}:{})})
   });
   const data=await r.json().catch(()=>({})) as Quote&{message?:string;errors?:Record<string,string[]>};
   if(!r.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'Esta prestação ainda não pode ser simulada.');
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
  <div className="cis-collections-protection"><Info size={18}/><div><strong>Cobrança ligada à Caixa</strong><p>O pagamento integral entra numa conta da Caixa e guarda o estado anterior da prestação no histórico. A renovação do empréstimo recebe os juros e cria nova prestação no mesmo contrato. Pagamento parcial e capitalização continuam apenas em simulação.</p></div></div>
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
     <div className="cis-collections-compact-meta" aria-label="Situação das cobranças">
       <span><Wallet size={15}/> <strong>{fmt(totals.pending)}</strong> por receber</span>
       <span><CheckCircle2 size={15}/> <strong>{fmt(totals.paid)}</strong> registados como pagos</span>
       <span><Clock3 size={15}/> <strong>{totals.late}</strong> prestações marcadas em atraso</span>
     </div>
     {!!profile.contract_fields_divergent?.length&&<div className="cis-collections-risk"><AlertCircle size={17}/> Campos divergentes no contrato: {profile.contract_fields_divergent.join(', ')}. Não assumir valores únicos sem reconciliação.</div>}
     <div className="cis-collections-switch" role="tablist" aria-label="Pagamentos deste contrato">
      <button type="button" role="tab" aria-selected={view==='resumo'} className={view==='resumo'?'selected':''} onClick={()=>setView('resumo')}><Wallet size={16}/> Resumo</button>
      {activeOrder&&<button type="button" role="tab" aria-selected={view==='tratamento'} className={view==='tratamento'?'selected':''} onClick={()=>setView('tratamento')}><ReceiptText size={16}/> Tratamento do pagamento</button>}
      <button type="button" role="tab" aria-selected={view==='historico'} className={view==='historico'?'selected':''} onClick={()=>setView('historico')}><BookOpen size={16}/> Histórico</button>
     </div>
     {view==='resumo'?<div className="cis-collections-panel cis-collections-overview">
      <div className="cis-collections-sectionhead"><div><h3>Prestações por receber</h3><p>Escolha a prestação que pretende tratar. O sistema verifica o estado do contrato e da prestação antes de abrir o formulário.</p></div><span>{pending.length} pendentes</span></div>
      {contractClosed&&<div className="cis-collections-risk"><LockKeyhole size={16}/> Contrato com estado «{contractStatus}». Não é permitido iniciar nova cobrança nesta ficha.</div>}
      <div className="cis-collections-overview-list">{rows.map(row=><div className="cis-collections-overview-row" key={String(row.Ordem)}>
       <div className="cis-collections-overview-id"><span>Parcela {text(row['Nº da Parcela'])}</span><strong>{fmt(row['Valor a Pagar'])}</strong></div>
       <div className="cis-collections-overview-meta"><span>Vencimento: {date(row['Data de Cobrança'])}</span><span className={isPending(row)?'pending':'settled'}>{text(row.Estado)} · {text(row['Situação de Pagamento'])}</span></div>
       <button type="button" disabled={!installmentEligible(row)} onClick={()=>{chooseOrd(String(row.Ordem));setView('tratamento');}}>
        {installmentEligible(row)?<>Efectuar pagamento <ArrowRight size={15}/></>:<><LockKeyhole size={14}/> Indisponível</>}
       </button>
      </div>)}</div>
      {!rows.length&&<p>Este contrato ainda não tem prestações registadas.</p>}
      <div className="cis-collections-scope"><ShieldCheck size={16}/> Esta acção abre o tratamento, sem efectuar qualquer lançamento financeiro.</div>
     </div>:view==='tratamento'?<div className="cis-collections-treatment">
      <div className="cis-collections-treatment-header">
       <button type="button" onClick={()=>setView('resumo')}><ArrowLeft size={16}/> Voltar às prestações</button>
       <div><span>TRATAMENTO DE PAGAMENTO</span><h3>Parcela {text(selectedInstallment?.['Nº da Parcela'])} · {text(selectedInstallment?.Estado)}</h3>
       <small>Prazo do contrato: {currentTerm}. {isFifteenDayTerm?'Elegível para análise de mudança de 15 para 30 dias.':'Prazo mantido conforme o contrato.'}</small></div>
      </div>
      {!activeOrder?<div className="cis-collections-risk"><AlertCircle size={16}/> Esta prestação não está pendente ou o contrato não permite cobrança. Volte à lista e escolha uma prestação elegível.</div>:
      <div className="cis-collections-payment-grid">
      <div className="cis-collections-panel">
       <div className="cis-collections-sectionhead"><div><h3>1. Prestação em tratamento</h3><p>Os dados abaixo são exclusivos da prestação seleccionada.</p></div><span>{text(selectedInstallment?.Estado)}</span></div>
       {selectedInstallment&&<>
        <div className="cis-collections-focus-amount"><small>Valor previsto para esta prestação</small><strong>{fmt(selectedInstallment['Valor a Pagar'])}</strong><span>Vencimento: {date(selectedInstallment['Data de Cobrança'])}</span></div>
        <dl className="cis-collections-focus-lines">
         <div><dt>Estado</dt><dd>{text(selectedInstallment.Estado)}</dd></div>
         <div><dt>Situação</dt><dd>{text(selectedInstallment['Situação de Pagamento'])}</dd></div>
         <div><dt>Valor já pago (registo)</dt><dd>{fmt(selectedInstallment['Valor Pago'])}</dd></div>
         <div><dt>Juros previstos</dt><dd>{fmt(selectedInstallment['Valor de Juros'])}</dd></div>
        </dl>
        <details className="cis-collections-technical"><summary>Ver composição financeira e taxas</summary>
         <dl><div><dt>Capital</dt><dd>{fmt(selectedInstallment.Capital)}</dd></div>
          <div><dt>Amortização</dt><dd>{fmt(selectedInstallment['Amortização'])}</dd></div>
          <div><dt>Juros</dt><dd>{fmt(selectedInstallment['Valor de Juros'])}</dd></div>
          <div><dt>Multa registada</dt><dd>{fmt(selectedInstallment['Valor de Multa'])}</dd></div>
          <div><dt>Taxa de juro</dt><dd>{text(profile.contract['% de Juro'])}</dd></div>
          <div><dt>Taxa de multa</dt><dd>{text(profile.contract['% de Multa'])}</dd></div>
          <div><dt>Tipo de juro</dt><dd>{text(profile.contract['Tipo de Juro'])}</dd></div></dl>
        </details>
       </>}
      </div>
      <div className="cis-collections-panel">
       <div className="cis-collections-sectionhead"><div><h3>2. Qual é a natureza desta operação?</h3><p>O tratamento depende primeiro de saber se houve recebimento efectivo.</p></div></div>
       <div className="cis-collections-nature" role="group" aria-label="Natureza do tratamento">
        <button type="button" aria-pressed={flowReceived} className={flowReceived?'selected':''} onClick={()=>{setPaymentNature('recebido');setMode('total');setQuote(null);setScenarioQuote(null);setPaymentAmount('');}}>
         <ArrowDownLeft size={19}/><span><strong>Houve pagamento</strong><small>Valor recebido integralmente, parcialmente ou para liquidar saldo</small></span><ChevronRight size={16}/></button>
        <button type="button" aria-pressed={flowNoReceipt} className={flowNoReceipt?'selected':''} onClick={()=>{setPaymentNature('nao_recebido');setMode('renovar_juros');setQuote(null);setScenarioQuote(null);setPaymentAmount('');setChannel('');setAccountId('');setEvidenceRef('');}}>
         <Clock3 size={19}/><span><strong>Não houve pagamento</strong><small>Avaliar renovação de juros, capitalização ou alteração de prazo</small></span><ChevronRight size={16}/></button>
       </div>
       {hasChoice&&<>
        <div className="cis-collections-flow-caption"><span>ETAPA 2</span><strong>{flowReceived?'Qual foi o tipo de recebimento?':'Qual é a medida proposta?'}</strong></div>
        <div className="cis-collections-mode-grid cis-collections-mode-grid-refined" role="group" aria-label="Tratamentos aplicáveis">
         {eligibleModes.map(value=><button type="button" key={value} aria-pressed={mode===value} className={mode===value?'selected':''} onClick={()=>chooseMode(value)}>
          {modeIcon(value)}<span><strong>{statuses[value].name}</strong><small>{statuses[value].details}</small></span>{mode===value&&<CheckCircle2 size={16}/>}</button>)}
        </div>
        <p className="cis-collections-mode-hint">{flowReceived?'Prepare a validação do dinheiro recebido. A escolha não confirma qualquer entrada.':'Sem recebimento não há lançamento de caixa. Estes tratamentos são alterações de condições, sujeitas a aprovação.'}</p>
       </>
       }
       {hasChoice&&<>
       <div className="cis-collections-fields">
        {flowReceived&&<label>Data real de pagamento (Maputo)<input type="date" value={datePayment} readOnly/><small>Para simulação de liquidação integral, apenas a data de hoje é aceite.</small></label>}
        {flowReceived&&<label>Forma de pagamento confirmada <small>Forma prevista no contrato: {text(profile.contract['Forma de Pagamento'])}</small><select value={channel} onChange={e=>setChannel(e.target.value)}><option value="">Seleccionar forma de recebimento</option><option value="numerario">Numerário</option><option value="banco">Transferência / depósito bancário</option><option value="movel">Transferência móvel</option></select><small>A modalidade contratual e o canal efectivamente utilizado podem ser diferentes; registar a divergência no processo.</small></label>}
        {flowReceived&&<label>Conta receptora na Caixa <span className="cis-collections-required">Obrigatória</span><select value={accountId} onChange={e=>{setAccountId(e.target.value);setConfirmReceipt(false);}}><option value="">Seleccionar conta activa</option>{accounts.filter(a=>a.status==='activa').map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}{a.saldo_disponivel?' · saldo '+fmt(a.saldo_disponivel):''}</option>)}</select><small>{activeAccount?'O valor recebido entra no saldo desta conta.':'Escolha a conta onde o dinheiro foi efectivamente recebido.'}</small></label>}
        {flowReceived&&<label>Referência do comprovativo / operação <span className="cis-collections-required">Obrigatória</span><input type="text" maxLength={60} value={evidenceRef} onChange={e=>setEvidenceRef(e.target.value)} placeholder="Número de operação ou recibo"/><small>Fica gravada no Extracto e no histórico da prestação.</small></label>}
        {renewing&&<label>Data de início do novo período <span className="cis-collections-required">Obrigatória</span><input type="date" value={renewStart} onChange={e=>{setRenewStart(e.target.value);setQuote(null);setConfirmReceipt(false);}}/><small>O vencimento é calculado automaticamente: início + {currentTerm||'o mesmo prazo'}. Normalmente começa no vencimento anterior ({date(selectedInstallment?.['Data de Cobrança'])}).</small></label>}
        {flowReceived&&live&&<label className="cis-collections-check"><input type="checkbox" checked={penalty} onChange={e=>{setPenalty(e.target.checked);setQuote(null);}}/> Cobrar multa por atraso quando aplicável</label>}
        {mode==='actualizar_data'&&<label>Data a rever (não gravada)<input type="date" value={reviewDate} max={today()} onChange={e=>setReviewDate(e.target.value)}/><small>A rotina VBA altera estado e juros variáveis; o CAPTA não grava esta revisão sem auditoria e validação.</small></label>}
        {mode==='capitalizar'&&<label>Período de referência para nova taxa <span className="cis-collections-required">Escolha explícita obrigatória</span><select value={capitalPeriod} onChange={e=>{setCapitalPeriod(e.target.value);setScenarioQuote(null);}}><option value="">Seleccionar período</option><option value="15">15 dias</option><option value="30">30 dias</option></select><small>O VBA privilegia 30 dias, mas a simulação CAPTA exige uma escolha explícita para evitar taxa presumida.</small></label>}
        {flowReceived&&mode==='parcial'&&<label>Valor efectivamente recebido (MT)<input inputMode="decimal" type="text" value={paymentAmount} onChange={e=>{setPaymentAmount(e.target.value);setScenarioQuote(null);}} placeholder="0.00"/><small>O VBA exige valor inferior à prestação e, como regra inicial, não inferior aos juros.</small></label>}
       </div>
       {live&&<form onSubmit={e=>void simulate(e)}>
        <button type="submit" className="cis-collections-primary" disabled={!canQuote||quoting}>{quoting?'A calcular…':renewing?'Simular renovação':'Simular pagamento integral'} <Calculator size={16}/></button>
       </form>}
       {modeLocked&&<div className="cis-collections-planned"><LockKeyhole size={17}/><span><strong>Operação não activada.</strong> O cenário foi identificado no VBA. O CAPTA pode apresentar uma prévia informativa, mas nenhuma alteração financeira será gravada.</span></div>}
       {modeLocked&&<form onSubmit={e=>void simulateScenario(e)}><button className="cis-collections-primary" type="submit" disabled={scenarioBusy||!activeOrder||mode==='parcial'&&!partialValid||mode==='capitalizar'&&!capitalPeriod}>{scenarioBusy?'A consultar regras…':'Simular cenário sem gravar'} <Calculator size={16}/></button></form>}
       {scenarioQuote&&modeLocked&&<div className="cis-collections-quote"><div className="cis-collections-quote-title"><ShieldCheck size={18}/><strong>Prévia informativa do servidor</strong></div><dl>{(['capital_historico','juros_historicos','valor_pago','juros_cobertos','amortizacao','diferenca_aritmetica','capital','juros_actual','juros_novo','prestacao_nova','capital_anterior','juros_anteriores','capital_novo','taxa','periodo_referencia','faixa','juros_novos','data_revisao','dias_atraso','estado_proposto','prestacoes_em_atraso','prestacoes_nao_liquidadas','elegivel_para_analise','prazo_origem_dias','prazo_destino_dias','taxa_destino'] as const).filter(key=>scenarioQuote[key]!==undefined).map(key=><div key={key}><dt>{({'capital_historico':'Capital histórico','juros_historicos':'Juros históricos','valor_pago':'Valor parcial','juros_cobertos':'Juros cobertos','amortizacao':'Amortização indicativa','diferenca_aritmetica':'Diferença aritmética','capital':'Capital','juros_actual':'Juros actuais','juros_novo':'Juros revistos','prestacao_nova':'Prestação indicativa','capital_anterior':'Capital anterior','juros_anteriores':'Juros anteriores','capital_novo':'Novo capital indicativo','taxa':'Taxa (%)','periodo_referencia':'Período (dias)','faixa':'Faixa de capital','juros_novos':'Juros novos','data_revisao':'Data revista','dias_atraso':'Dias em atraso','estado_proposto':'Estado proposto','prestacoes_em_atraso':'Prestações em atraso','prestacoes_nao_liquidadas':'Prestações não liquidadas','elegivel_para_analise':'Elegível para análise','prazo_origem_dias':'Prazo anterior (dias)','prazo_destino_dias':'Novo prazo (dias)','taxa_destino':'Taxa prevista na faixa 30 dias (%)'} as Record<string,string>)[key]}</dt><dd>{Array.isArray(scenarioQuote[key])?(scenarioQuote[key] as unknown[]).join(' – '):typeof scenarioQuote[key]==='boolean'?(scenarioQuote[key]?'Sim':'Não'):String(scenarioQuote[key])}</dd></div>)}</dl><div className="cis-collections-planned"><Info size={16}/><span>{scenarioQuote.aviso}</span></div></div>}
       {mode==='parcial'&&paymentAmount&&<div className="cis-collections-breakdown">
        <h4>Verificação indicativa do pagamento parcial</h4>
        {!partialValid?<div className="cis-collections-risk"><AlertCircle size={16}/> Verifique o valor: deve ser positivo, inferior ao valor a pagar e não inferior aos juros, conforme o VBA. Não foi efectuado qualquer lançamento.</div>:
         <dl><div><dt>Valor introduzido</dt><dd>{fmt(partialNum)}</dd></div><div><dt>Juros cobertos primeiro</dt><dd>{fmt(partialInterest)}</dd></div><div><dt>Amortização indicativa</dt><dd>{fmt(partialCapital)}</dd></div><div><dt>Diferença aritmética</dt><dd>{fmt(Math.max(0,expected-partialNum))}</dd></div></dl>}
        <small>Não substitui a criação/recalendarização de uma nova prestação nem representa valor remanescente homologado.</small>
       </div>}
       {mode==='renovar_juros'&&<div className="cis-collections-planned"><Info size={17}/><span>Na rotina ActualizarFinanceiroCobranca, renovar juros preserva o capital e acrescenta mais um período de juros. Não é um pagamento nem deve criar entrada no Extracto.</span></div>}
       {mode==='capitalizar'&&<div className="cis-collections-planned"><Info size={17}/><span>Capitalização exige recalcular a taxa e o intervalo aplicável a partir das faixas da Table4/Definições. Nunca aplicar taxa presumida nem alterar capital sem validação.</span></div>}
       {mode==='remanescente'&&<div className="cis-collections-planned"><Info size={17}/><span>Liquidar o remanescente exige verificar prestações em atraso, montante realmente devido e evidência de recebimento. O botão VBA não é suficiente para autorizar a operação.</span></div>}
       {mode==='prorrogar_15_30'&&<div className="cis-collections-planned"><CalendarDays size={17}/><span>Prazo actual: 15 dias. Prazo pretendido: 30 dias. A mudança exige nova data de vencimento e taxa de referência de 30 dias, sujeitas a revisão. Esta prévia não altera a prestação nem cria recebimento.</span></div>}
       {mode==='renovar_credito'&&<div className="cis-collections-planned"><Info size={17}/><span>Novo crédito com condições diferentes exige novo contrato. Para manter as mesmas condições, use «Renovar empréstimo» em «Houve pagamento».</span></div>}
       {renewing&&<div className="cis-collections-planned"><Info size={17}/><span>Os juros{penalty?' e a multa':''} entram na conta escolhida. A prestação actual fica «Renovado» e o histórico guarda a fotografia antes/depois. Sem limite de renovações.</span></div>}
       {mode==='actualizar_data'&&<div className="cis-collections-planned"><CalendarDays size={17}/><span>Data seleccionada: {date(reviewDate)}. Vencimento da prestação: {date(selectedInstallment?.['Data de Cobrança'])}. O VBA permite rever datas passadas e recalcular juros variáveis; alterações retroactivas exigem trilho de auditoria e não são executadas aqui.</span></div>}
       {mode==='finalizar_contrato'&&<div className="cis-collections-planned"><ShieldCheck size={17}/><span>{pending.length?'Existem '+pending.length+' prestação(ões) pendentes. Não é possível presumir liquidação integral ou encerrar o contrato.':'A lista não apresenta prestações pendentes. É necessário confirmar todos os pagamentos e o Extracto antes da finalização.'}</span></div>}
       {quote&&live&&<div className="cis-collections-quote"><div className="cis-collections-quote-title"><CheckCircle2 size={18}/><strong>Prévia calculada no servidor</strong></div>
        <dl><div><dt>Data</dt><dd>{date(quote.data_pagamento)}</dd></div><div><dt>Dias de atraso</dt><dd>{quote.dias_atraso}</dd></div><div><dt>Juros</dt><dd>{fmt(quote.juros)}</dd></div><div><dt>Multa</dt><dd>{fmt(quote.multa)}</dd></div><div className="total"><dt>{renewing?'Juros a receber agora':'Total proposto'}</dt><dd>{fmt(quote.total)}</dd></div><div><dt>Estado previsto</dt><dd>{renewing?'Renovado':quote.estado}</dd></div></dl>
        {quote.nova_prestacao&&<><h4>Nova prestação no mesmo contrato</h4><dl><div><dt>Parcela</dt><dd>{quote.nova_prestacao.parcela}</dd></div><div><dt>Início</dt><dd>{date(quote.nova_prestacao.data_inicio)}</dd></div><div><dt>Vencimento (automático)</dt><dd>{date(quote.nova_prestacao.data_vencimento)}</dd></div><div><dt>Prazo</dt><dd>{quote.nova_prestacao.prazo}</dd></div><div><dt>Capital</dt><dd>{fmt(quote.nova_prestacao.capital)}</dd></div><div><dt>Juros{quote.nova_prestacao.juros_estimado?' (estimados)':''}</dt><dd>{fmt(quote.nova_prestacao.juros)}</dd></div><div className="total"><dt>Prestação a pagar</dt><dd>{fmt(quote.nova_prestacao.valor_a_pagar)}</dd></div></dl></>}
        {quote.aviso&&<p className="cis-collections-mode-hint">{quote.origem==='historico'?'Contrato histórico (VBA). ':''}{quote.aviso}</p>}
        {!quote.registo_activo&&<div className="cis-collections-planned"><LockKeyhole size={17}/><span>Registo real ainda não activado neste servidor. A prévia está correcta, mas o pagamento não pode ser gravado.</span></div>}</div>}
       </>}
       {flowReceived&&live&&quote&&<label className="cis-collections-check"><input type="checkbox" checked={confirmReceipt} onChange={e=>setConfirmReceipt(e.target.checked)}/> Confirmo que recebi {fmt(quote.total)} na conta {activeAccount?activeAccount.code:'seleccionada'}</label>}
       {flowReceived&&live&&<div className="cis-collections-action"><button type="button" className="cis-collections-primary" disabled={!canExecute} onClick={()=>void pay()}>{paying?'A registar…':payWait>0?'Aguarde '+payWait+' s (limite de segurança)':<><CheckCircle2 size={16}/> {renewing?'Confirmar juros e renovar':'Confirmar e registar pagamento'}</>}</button><small>{!quote?(renewing?'Simule primeiro a renovação.':'Simule primeiro o pagamento integral.'):!quote.registo_activo?'Registo real desligado no servidor: execute deploy/activate-contract-cash-flows.sh na VPS.':!activeAccount?'Seleccione a conta receptora.':!refOk?'Indique a referência do comprovativo.':!confirmReceipt?'Confirme o recebimento efectivo.':'O pagamento é gravado uma única vez: prestação, entrada no Extracto e histórico antes/depois.'}</small></div>}
       {payError&&<div className="cis-cash-message error" role="alert"><AlertCircle size={17}/>{payError}</div>}
       {paymentDone&&<div className="cis-cash-message success" role="status"><CheckCircle2 size={17}/>{paymentDone.notice}{paymentDone.saldo_conta_actual?' Saldo actual: '+fmt(paymentDone.saldo_conta_actual)+'.':''}{paymentDone.contrato_finalizado?' Contrato finalizado.':''}</div>}
      </div>
     </div>}</div>:
     <div className="cis-collections-panel">
      <div className="cis-collections-sectionhead"><div><h3>Prestações e histórico financeiro</h3><p>Campos originais da tabela Contractos. Inclui movimentos completos, parciais e atrasados quando registados.</p></div><span>{rows.length} linha(s)</span></div>
      <div className="cis-table-scroll"><table className="cis-collections-table"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Data de pagamento</th><th>Capital</th><th>Amortização</th><th>Juros</th><th>Multa</th><th>Previsto</th><th>Recebido</th><th>Estado</th><th>Situação</th></tr></thead><tbody>{rows.map((row,i)=><tr key={String(row.Ordem??i)}>
       <td>{text(row['Nº da Parcela'])}</td><td>{date(row['Data de Cobrança'])}</td><td>{date(row['Data de Pagamento'])}</td>
       <td>{fmt(row.Capital)}</td><td>{fmt(row['Amortização'])}</td><td>{fmt(row['Valor de Juros'])}</td><td>{fmt(row['Valor de Multa'])}</td><td>{fmt(row['Valor a Pagar'])}</td><td>{fmt(row['Valor Pago'])}</td>
       <td><span className={'cis-collections-pill '+(paid(row)?'paid':isPending(row)?'pending':'')}>{text(row.Estado)}</span></td><td>{text(row['Situação de Pagamento'])}</td>
      </tr>)}</tbody></table></div>
      <div className="cis-collections-sectionhead cis-collections-history-head"><div><h3>Alterações registadas</h3><p>Fotografia permanente de cada prestação antes e depois de cada operação. Não pode ser alterada nem apagada.</p></div><span>{events?events.length:'—'} registo(s)</span></div>
      {events===null?<div className="cis-cash-loading" role="status">A carregar o histórico…</div>:!events.length?<p className="cis-collections-mode-hint">Ainda não há alterações registadas pelo CIS neste contrato.</p>:
       <div className="cis-collections-history">{events.map(ev=><details key={ev.id}><summary><strong>{historyLabels[ev.operacao]??ev.operacao}</strong><span>Parcela {ev.parcela??'—'}</span><span>{fmt(ev.valor)}</span><span>{text(ev.em).replace('T',' ').slice(0,16)}</span></summary>
        <table><thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead><tbody>{historyFields.filter(f=>ev.antes?.[f]!==undefined||ev.depois?.[f]!==undefined).map(f=><tr key={f} className={String(ev.antes?.[f]??'')!==String(ev.depois?.[f]??'')?'changed':''}><td>{f}</td><td>{text(ev.antes?.[f])}</td><td>{text(ev.depois?.[f])}</td></tr>)}</tbody></table>
        <small>{ev.extracto_nr?'Extracto Nr '+ev.extracto_nr+' · ':''}{ev.referencia?'REF '+ev.referencia+' · ':''}{text(ev.motivo)}</small></details>)}</div>}
      <div className="cis-collections-scope"><ShieldCheck size={16}/> Leitura apenas: não altera Contractos, Extracto nem o estado de pagamento.</div>
     </div>}
    </>}
   </div>}
  <div className="cis-collections-footer"><ShieldCheck size={16}/> Fonte única dos lançamentos: Extracto. A Tesouraria opcional terá reconciliação bancária, sem duplicar os movimentos da Caixa.</div>
 </section>;
}
