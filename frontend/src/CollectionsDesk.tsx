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
type Quote={
 parcela:number;dias_atraso:number;juros:number;multa:number;total:number;
 estado:string;data_pagamento:string;aviso:string
};
type Mode='total'|'parcial'|'renovar_juros'|'capitalizar'|'remanescente';
const statuses:Record<Mode,{name:string;details:string;group:string}>={
 total:{name:'Pagamento integral',details:'Liquidação completa da prestação, com eventual multa por atraso.',group:'Com pagamento'},
 parcial:{name:'Pagamento parcial',details:'Registar montante inferior ao devido; juros antes da amortização.',group:'Com pagamento'},
 renovar_juros:{name:'Renovar juros',details:'Novo período de juros sobre o capital ainda devido, sem recebimento.',group:'Sem pagamento'},
 capitalizar:{name:'Capitalizar juros',details:'Incorporar juros no capital e apurar nova taxa pela tabela de faixas.',group:'Sem pagamento'},
 remanescente:{name:'Liquidar remanescente',details:'Liquidação final após parcial, sujeita a controlo dos atrasos.',group:'Regularização'}
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
 case'remanescente':return <Coins size={18}/>;}
}
export default function CollectionsDesk({token}:{token:string}){
 const [query,setQuery]=useState('');
 const [applied,setApplied]=useState('');
 const [page,setPage]=useState(1);
 const [list,setList]=useState<ListResponse|null>(null);
 const [listing,setListing]=useState(true);
 const [selected,setSelected]=useState<string|null>(null);
 const [profile,setProfile]=useState<ProfileResponse|null>(null);
 const [loading,setLoading]=useState(false);
 const [message,setMessage]=useState('');
 const [mode,setMode]=useState<Mode>('total');
 const [ord,setOrd]=useState('');
 const [penalty,setPenalty]=useState(false);
 const [paymentAmount,setPaymentAmount]=useState('');
 const [quote,setQuote]=useState<Quote|null>(null);
 const [quoting,setQuoting]=useState(false);
 const [accountId,setAccountId]=useState('');
 const [accounts,setAccounts]=useState<{id:number;code:string;name:string;status:string}[]>([]);
 const [view,setView]=useState<'operacao'|'historico'>('operacao');
 const [refresh,setRefresh]=useState(0);

 useEffect(()=>{
  const ctrl=new AbortController();
  const timer=window.setTimeout(()=>{setPage(1);setApplied(query.trim());},300);
  return()=>{window.clearTimeout(timer);ctrl.abort();};
 },[query]);
 useEffect(()=>{
  if(selected)return;
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
 },[selected,page,applied,refresh,token]);
 useEffect(()=>{
  if(!selected)return;
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
 },[selected,refresh,token]);
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
   next:[...pending].sort((a,b)=>text(a['Data de Cobrança']).localeCompare(text(b['Data de Cobrança'])))[0]
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

 function selectContract(numero:string){setSelected(numero);setProfile(null);setMode('total');setView('operacao');setAccountId('');setPenalty(false);setQuote(null);window.scrollTo({top:0,behavior:'smooth'});}
 function back(){setSelected(null);setProfile(null);setMessage('');setRefresh(v=>v+1);}
 function chooseMode(value:Mode){setMode(value);setQuote(null);setPaymentAmount('');setMessage('');}
 function chooseOrd(value:string){setOrd(value);setQuote(null);setPaymentAmount('');setMessage('');}
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

 return <section className="cis-collections">
  <div className="cis-collections-hero"><div><span className="cis-collections-kicker"><ReceiptText size={14}/> CAPTA · OPERAÇÕES DE CRÉDITO</span>
   <h2>Cobranças</h2><p>Gestão de prestações, recebimentos e tratamento de situações de pagamento.</p>
   <div className="cis-collections-hero-badges"><span><ShieldCheck size={14}/> Dados do Contractos</span><span><LockKeyhole size={14}/> Operações financeiras protegidas</span></div></div>
   <button type="button" onClick={()=>{setRefresh(v=>v+1);setQuote(null);}} disabled={listing||loading}><RefreshCw size={16}/> Actualizar</button>
  </div>
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
    <div className="cis-collections-backline"><button type="button" onClick={back}><ArrowLeft size={16}/> Voltar aos contratos</button><span><FileText size={15}/> {selected}</span></div>
    {loading?<div className="cis-cash-loading">A carregar a ficha…</div>:profile&&<>
     <div className="cis-collections-client"><div><small>CONTRATO EM ANÁLISE</small><h3>{text(profile.contract['Nome do Cliente'])}</h3><span>Contrato {selected} · Cliente n.º {text(profile.contract['Nº de Cliente'])}</span></div>
      <span className="cis-collections-client-badge">{text(profile.contract['Estado do Contrato'])}</span></div>
     <div className="cis-collections-stats">
      <article><span>Prestações</span><strong>{rows.length}</strong><small>Total registado</small></article>
      <article><span>Valor pendente</span><strong>{fmt(totals.pending)}</strong><small>Prestações pendentes e atrasadas</small></article>
      <article><span>Valor liquidado</span><strong>{fmt(totals.paid)}</strong><small>Soma de Valor Pago</small></article>
      <article><span>Juros e multas liquidados</span><strong>{fmt(totals.added)}</strong><small>Componentes das prestações pagas</small></article>
      <article><span>Valor bruto</span><strong>{fmt(totals.gross)}</strong><small>Pago + pendente, sem reconciliação</small></article>
      <article><span>Próxima cobrança</span><strong>{date(totals.next?.['Data de Cobrança'])}</strong><small>{totals.next?fmt(totals.next['Valor a Pagar']):'Sem próximas prestações'}</small></article>
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
        <dl><div><dt>Capital</dt><dd>{fmt(selectedInstallment.Capital)}</dd></div><div><dt>Amortização</dt><dd>{fmt(selectedInstallment['Amortização'])}</dd></div><div><dt>Juros</dt><dd>{fmt(selectedInstallment['Valor de Juros'])}</dd></div><div><dt>Multa registada</dt><dd>{fmt(selectedInstallment['Valor de Multa'])}</dd></div><div><dt>Valor a pagar</dt><dd>{fmt(selectedInstallment['Valor a Pagar'])}</dd></div><div><dt>Valor já pago</dt><dd>{fmt(selectedInstallment['Valor Pago'])}</dd></div><div><dt>Vencimento</dt><dd>{date(selectedInstallment['Data de Cobrança'])}</dd></div><div><dt>Estado de pagamento</dt><dd>{text(selectedInstallment['Situação de Pagamento'])}</dd></div></dl>
       </div>}
      </div>
      <div className="cis-collections-panel">
       <div className="cis-collections-sectionhead"><div><h3>2. Tratamento do pagamento</h3><p>Escolha o cenário equivalente ao formulário Cobrança.</p></div></div>
       <div className="cis-collections-mode-grid">
        {(['total','parcial','renovar_juros','capitalizar','remanescente'] as Mode[]).map(value=><button type="button" key={value} disabled={!ord} className={mode===value?'selected':''} onClick={()=>chooseMode(value)}>
         {modeIcon(value)}<span><strong>{statuses[value].name}</strong><small>{statuses[value].group}</small></span>{mode===value&&<CheckCircle2 size={16}/>}</button>)}
       </div>
       <p className="cis-collections-mode-hint">{statuses[mode].details}</p>
       <div className="cis-collections-fields">
        <label>Data real de pagamento (Maputo)<input type="date" value={datePayment} readOnly/><small>Para simulação de liquidação integral, apenas a data de hoje é aceite.</small></label>
        <label>Conta receptora na Caixa <span className="cis-collections-required">Obrigatória para cobrança futura</span><select value={accountId} onChange={e=>setAccountId(e.target.value)}><option value="">Seleccionar conta activa</option>{accounts.filter(a=>a.status==='activa').map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select><small>{activeAccount?'Conta seleccionada (nenhuma gravação será feita).':'Contas sem saldo validado não podem receber cobranças.'}</small></label>
        {mode==='total'&&<label className="cis-collections-check"><input type="checkbox" checked={penalty} onChange={e=>{setPenalty(e.target.checked);setQuote(null);}}/> Cobrar multa por atraso quando aplicável</label>}
        {mode==='parcial'&&<label>Valor efectivamente recebido (MT)<input inputMode="decimal" type="text" value={paymentAmount} onChange={e=>setPaymentAmount(e.target.value)} placeholder="0.00"/><small>O VBA exige valor inferior à prestação e, como regra inicial, não inferior aos juros.</small></label>}
       </div>
       {mode==='total'&&<form onSubmit={e=>void simulate(e)}>
        <button type="submit" className="cis-collections-primary" disabled={!canQuote||quoting}>{quoting?'A calcular…':'Simular pagamento integral'} <Calculator size={16}/></button>
       </form>}
       {modeLocked&&<div className="cis-collections-planned"><LockKeyhole size={17}/><span><strong>Operação não activada.</strong> O cenário foi identificado no VBA, mas a sua execução depende da homologação de regras financeiras, conta receptora e lançamento atómico.</span></div>}
       {mode==='parcial'&&paymentAmount&&<div className="cis-collections-breakdown">
        <h4>Verificação indicativa do pagamento parcial</h4>
        {!partialValid?<div className="cis-collections-risk"><AlertCircle size={16}/> Verifique o valor: deve ser positivo, inferior ao valor a pagar e não inferior aos juros, conforme o VBA. Não foi efectuado qualquer lançamento.</div>:
         <dl><div><dt>Valor introduzido</dt><dd>{fmt(partialNum)}</dd></div><div><dt>Juros cobertos primeiro</dt><dd>{fmt(partialInterest)}</dd></div><div><dt>Amortização indicativa</dt><dd>{fmt(partialCapital)}</dd></div><div><dt>Diferença aritmética</dt><dd>{fmt(Math.max(0,expected-partialNum))}</dd></div></dl>}
        <small>Não substitui a criação/recalendarização de uma nova prestação nem representa valor remanescente homologado.</small>
       </div>}
       {mode==='renovar_juros'&&<div className="cis-collections-planned"><Info size={17}/><span>Na rotina ActualizarFinanceiroCobranca, renovar juros preserva o capital e acrescenta mais um período de juros. Não é um pagamento nem deve criar entrada no Extracto.</span></div>}
       {mode==='capitalizar'&&<div className="cis-collections-planned"><Info size={17}/><span>Capitalização exige recalcular a taxa e o intervalo aplicável a partir das faixas da Table4/Definições. Nunca aplicar taxa presumida nem alterar capital sem validação.</span></div>}
       {mode==='remanescente'&&<div className="cis-collections-planned"><Info size={17}/><span>Liquidar o remanescente exige verificar prestações em atraso, montante realmente devido e evidência de recebimento. O botão VBA não é suficiente para autorizar a operação.</span></div>}
       {quote&&mode==='total'&&<div className="cis-collections-quote"><div className="cis-collections-quote-title"><CheckCircle2 size={18}/><strong>Prévia calculada no servidor</strong></div>
        <dl><div><dt>Data</dt><dd>{date(quote.data_pagamento)}</dd></div><div><dt>Dias de atraso</dt><dd>{quote.dias_atraso}</dd></div><div><dt>Juros</dt><dd>{fmt(quote.juros)}</dd></div><div><dt>Multa</dt><dd>{fmt(quote.multa)}</dd></div><div className="total"><dt>Total proposto</dt><dd>{fmt(quote.total)}</dd></div><div><dt>Estado previsto</dt><dd>{quote.estado}</dd></div></dl>
        <div className="cis-collections-planned"><LockKeyhole size={17}/><span>Esta prévia não significa cobrança concluída. O backend recusa pagamentos sem associação obrigatória a Extracto.account_id.</span></div></div>}
       <div className="cis-collections-disabled"><button type="button" disabled={cannotExecute}><LockKeyhole size={16}/> Confirmar e registar pagamento</button><small>Desactivado até integrar conta, permissões, idempotência e confirmação de entrada real.</small></div>
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
