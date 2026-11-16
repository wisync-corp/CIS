import {useCallback,useEffect,useMemo,useState} from 'react';
import {ArrowLeft,FileText,CalendarDays,Wallet,Layers,ShieldCheck,LockKeyhole,AlertCircle,RefreshCw,ChevronDown,ChevronUp,ArrowDownUp,ReceiptText,ArrowRightLeft,Eye} from 'lucide-react';

type Item=Record<string,string|number|null>;
type CollectionQuote={parcela:number;dias_atraso:number;juros:number;multa:number;total:number;estado:string;data_pagamento:string;aviso:string};
type Payload={contract:Item;installments:Item[];legacy_rows?:Item[];installment_rows:number;notice:string;contract_fields_divergent?:string[];columns_missing_in_database?:string[]};
type Tab='Contrato'|'Plano de prestações'|'Condições financeiras'|'Administração'|'Dados legados'|'Todos os campos'|'Cobrança'|'Aumento de crédito'|'Documento original';
const API=import.meta.env.VITE_API_URL||'/api';
function paymentTodayMozambique():string{
 const parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Maputo',
  year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
 const val=(kind:string)=>parts.find(part=>part.type===kind)?.value??'';
 return val('year')+'-'+val('month')+'-'+val('day');
}
const groups:{tab:Tab;icon:typeof FileText;fields:{name:string;label:string;kind?:'date'|'money'|'percent'}[]}[]=[
 {tab:'Contrato',icon:FileText,fields:[
  {name:'Nr do Contracto',label:'Número do contrato'},
  {name:'Nome do Cliente',label:'Mutuário'},
  {name:'Nº de Cliente',label:'N.º do cliente'},
  {name:'Codigo Cliente',label:'Código do cliente'},
  {name:'Data de Inicio',label:'Data de início',kind:'date'},
  {name:'Data Final',label:'Data final',kind:'date'},
  {name:'Cidade',label:'Cidade'},
  {name:'Estado do Contrato',label:'Estado do contrato'}]},
 {tab:'Condições financeiras',icon:Wallet,fields:[
  {name:'Quantia Creditada',label:'Quantia creditada',kind:'money'},
  {name:'% de Juro',label:'Taxa de juro (%)',kind:'percent'},
  {name:'Tipo de Juro',label:'Tipo de juro'},
  {name:'Estratégia de Cálculo',label:'Estratégia de cálculo das prestações'},
  {name:'% de Multa',label:'Taxa de multa (%)',kind:'percent'},
  {name:'M. Pagamento',label:'Modalidade de pagamento'},
  {name:'Forma de Pagamento',label:'Forma de pagamento'},
  {name:'Prazo/Duração',label:'Prazo / duração'},
  {name:'Disponibilidade',label:'Disponibilidade'},
  {name:'Nº de Prestações',label:'Número de prestações'}]},
 {tab:'Administração',icon:ShieldCheck,fields:[
  {name:'Nr do Contracto',label:'Identificador do contrato'},
  {name:'usuario',label:'Utilizador de origem'},
  {name:'Estado do Contrato',label:'Estado original do contrato'},
  {name:'Nº',label:'Número de ordem original'},
  {name:'Column1',label:'Coluna auxiliar legada'}]},
 {tab:'Dados legados',icon:FileText,fields:[
  {name:'Nr do Contracto',label:'N.º do contrato'},
  {name:'Nº',label:'Número original'},
  {name:'Column1',label:'Campo auxiliar legado'},
  {name:'Codigo Cliente',label:'Código do cliente'},
  {name:'Nº de Cliente',label:'N.º do cliente'},
  {name:'Nome do Cliente',label:'Nome do cliente'},
  {name:'usuario',label:'Utilizador original'}]},
];
// Os 33 campos originais são copiados do INSERT INTO Contractos do ficheiro Contractos.frm.
const allLegacyColumns=[
 'Nr do Contracto','Nº','Nome do Cliente','Nº de Cliente',
 'Quantia Creditada','M. Pagamento','Prazo/Duração','% de Juro','Tipo de Juro','Estratégia de Cálculo','% de Multa',
 'Forma de Pagamento','Disponibilidade','Data de Inicio','Cidade','Data Final',
 'Data de Cobrança','Capital','Amortização','Valor a Pagar','Data de Pagamento',
 'Valor de Juros','Valor de Multa','Valor Pago','Estado','Codigo Cliente',
 'Estado do Contrato','Nº da Parcela','Nº de Prestações','Column1',
 'Dias','Intervalo','Situação de Pagamento','usuario'
] as const;
const dateLegacyColumns=new Set(['Data de Inicio','Data Final','Data de Cobrança','Data de Pagamento']);
const moneyLegacyColumns=new Set(['Quantia Creditada','Capital','Amortização','Valor a Pagar','Valor de Juros','Valor de Multa','Valor Pago']);
const installmentColumns=[
 {name:'Nº da Parcela',kind:'number'}, {name:'Data de Cobrança',kind:'date'},
 {name:'Data de Pagamento',kind:'date'}, {name:'Capital',kind:'number'},
 {name:'Amortização',kind:'number'}, {name:'Valor de Juros',kind:'number'},
 {name:'Valor de Multa',kind:'number'}, {name:'Valor a Pagar',kind:'number'},
 {name:'Valor Pago',kind:'number'}, {name:'Estado',kind:'text'},
 {name:'Situação de Pagamento',kind:'text'}, {name:'Dias',kind:'number'},
 {name:'Intervalo',kind:'number'}, {name:'Nº',kind:'number'}, {name:'Column1',kind:'text'}
] as const;
type InstallmentSort=typeof installmentColumns[number]['name'];
function compareInstallments(a:Item,b:Item,column:InstallmentSort):number{
 const kind=installmentColumns.find(item=>item.name===column)!.kind;
 const left=a[column],right=b[column];
 const empty=(v:string|number|null|undefined)=>v===null||v===undefined||String(v).trim()===''||String(v)==='0000-00-00';
 if(empty(left)&&empty(right))return 0;
 if(empty(left))return 1;
 if(empty(right))return -1;
 if(kind==='number'){
  const n1=Number(left),n2=Number(right);
  if(Number.isFinite(n1)&&Number.isFinite(n2))return n1-n2;
 }
 if(kind==='date'){
  const l=String(left).slice(0,10),r=String(right).slice(0,10);
  if(/^\d{4}-\d{2}-\d{2}$/.test(l)&&/^\d{4}-\d{2}-\d{2}$/.test(r))return l.localeCompare(r);
 }
 return String(left).localeCompare(String(right),'pt',{numeric:kind==='number'});
}
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const integerMoney=new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:0});
function format(item:Item,name:string,kind?:'date'|'money'|'percent'){
 const raw=item[name];
 if(raw===null||raw===undefined||raw==='')return 'Não informado';
 if(kind==='date'){
  const v=String(raw);
  if(/^\d{4}-\d{2}-\d{2}/.test(v)){
   const date=v.slice(0,10);
   if(date==='0000-00-00')return 'Data não informada (legado)';
   return date.slice(8,10)+'/'+date.slice(5,7)+'/'+date.slice(0,4);
  }
  return v; // preservar representações legadas sem inventar uma data
 }
 if(kind==='money'&&Number.isFinite(Number(raw)))return (Number.isInteger(Number(raw))?integerMoney:money).format(Number(raw))+' MT';
 if(kind==='percent')return String(raw)+'%';
 return String(raw);
}
export default function ContractProfile({numero,token,onBack,justCreated=false}:{numero:string;token:string;onBack:()=>void;justCreated?:boolean}){
 const [tab,setTab]=useState<Tab>('Contrato');
 const [data,setData]=useState<Payload|null>(null);
 const [loading,setLoading]=useState(true);
 const [error,setError]=useState('');
 const [sortColumn,setSortColumn]=useState<InstallmentSort>('Nº da Parcela');
 const [targetInstallment,setTargetInstallment]=useState<number|null>(null);
 const [increaseAmount,setIncreaseAmount]=useState('');
 const [collectionOrd,setCollectionOrd]=useState('');
 const [collectPenalty,setCollectPenalty]=useState(false);
 const [collectionQuote,setCollectionQuote]=useState<CollectionQuote|null>(null);
 const [collectionBusy,setCollectionBusy]=useState(false);
 const [collectionError,setCollectionError]=useState('');
 const [collectionStatus,setCollectionStatus]=useState('');
 const [receivedConfirmed,setReceivedConfirmed]=useState(false);
 const [sortDirection,setSortDirection]=useState<'asc'|'desc'>('asc');
 const load=useCallback(async(signal?:AbortSignal)=>{
  setLoading(true);setError('');
  try{
   const res=await fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/ficha',{
    headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal
   });
   if(!res.ok)throw new Error(res.status===503?'Consulta privada de contratos ainda não autorizada.':res.status===409?'Foram detectadas informações inconsistentes no contrato.':res.status===404?'Contrato não encontrado.':'Não foi possível consultar a ficha do contrato.');
   const body=await res.json() as Payload;
   if(!signal?.aborted)setData(body);
  }catch(e){
   if((e as Error).name!=='AbortError')setError(e instanceof Error?e.message:'Erro na consulta.');
  }finally{if(!signal?.aborted)setLoading(false);}
 },[numero,token]);
 useEffect(()=>{const controller=new AbortController();void load(controller.signal);return()=>controller.abort();},[load]);
 const contract=data?.contract;
 // Indicadores calculados apenas a partir das prestações efectivamente lidas.
 const financialSnapshot=useMemo(()=>{
  const rows=data?.installments??[];
  const sum=(key:string)=>rows.reduce((total,row)=>total+(Number.isFinite(Number(row[key]))?Number(row[key]):0),0);
  const paid=sum('Valor Pago');
  const scheduled=sum('Valor a Pagar');
  const outstanding=Math.max(0,scheduled-paid);
  const pending=rows.filter(row=>!['Pago','Pago em Atraso'].includes(String(row.Estado??''))).length;
  return {paid,scheduled,outstanding,pending,installments:rows.length};
 },[data]);
 const firstLoanAmount=contract?.['Quantia Creditada'];
 const currency=(value:number)=>money.format(value)+' MT';
 const selectedInstallment=targetInstallment===null?null:data?.installments[targetInstallment]??null;
 const sortedInstallments=useMemo(()=>data?.installments.map((item,index)=>({item,index})).sort((a,b)=>{
  const cmp=compareInstallments(a.item,b.item,sortColumn);
  return cmp===0?a.index-b.index:(sortDirection==='asc'?cmp:-cmp);
 }).map(({item})=>item)??[],[data,sortColumn,sortDirection]);
 const quoteCollection=async()=>{
  if(!collectionOrd)return;
  setCollectionBusy(true);setCollectionError('');setCollectionQuote(null);setReceivedConfirmed(false);setCollectionStatus('');
  try{
   const response=await fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/prestacoes/'+encodeURIComponent(collectionOrd)+'/simular-cobranca',{
    method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json',Accept:'application/json'},
    body:JSON.stringify({data_pagamento:paymentTodayMozambique(),cobrar_multa:collectPenalty})
   });
   const payload=await response.json().catch(()=>null) as CollectionQuote&{message?:string};
   if(!response.ok)throw new Error(payload?.message??'Não foi possível preparar a cobrança. Apenas contratos CIS novos são elegíveis.');
   setCollectionQuote(payload);
  }catch(e){setCollectionError(e instanceof Error?e.message:'Falha na consulta de cobrança.');}
  finally{setCollectionBusy(false);}
 };
 const settleCollection=async()=>{
  if(!collectionOrd||!collectionQuote||!receivedConfirmed||collectionBusy)return;
  if(!window.confirm('Confirma que recebeu efectivamente '+collectionQuote.total+' MT do mutuário? Esta operação lançará a entrada no Extracto e liquidará a prestação.'))return;
  setCollectionBusy(true);setCollectionError('');
  try{
   const response=await fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/prestacoes/'+encodeURIComponent(collectionOrd)+'/cobrar',{
    method:'POST',headers:{Authorization:'Bearer '+token,'Content-Type':'application/json',Accept:'application/json'},
    body:JSON.stringify({data_pagamento:collectionQuote.data_pagamento,cobrar_multa:collectPenalty,valor_recebido:collectionQuote.total,confirmacao_recebimento:true})
   });
   const payload=await response.json().catch(()=>null) as {message?:string;total?:number};
   if(!response.ok)throw new Error(payload?.message??'Cobrança não efectuada. Verifique o histórico antes de repetir.');
   setCollectionStatus('Pagamento registado: '+payload.total+' MT. O Extracto foi actualizado.');
   setCollectionQuote(null);setCollectionOrd('');setReceivedConfirmed(false);
   await load();
  }catch(e){setCollectionError(e instanceof Error?e.message:'Falha de cobrança. Confirme o histórico antes de repetir.');}
  finally{setCollectionBusy(false);}
 };
 function sortBy(column:InstallmentSort){if(sortColumn===column)setSortDirection(prev=>prev==='asc'?'desc':'asc');else{setSortColumn(column);setSortDirection('asc');}}
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Ficha individual</span></div>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
  {loading&&<div className="cis-panel cis-loading">A consultar contrato e prestações…</div>}
  {data&&contract&&<>
   {justCreated&&<div className="cis-contract-created-banner" role="status" aria-live="polite">
    <div><ShieldCheck size={24}/><span><strong>Crédito criado e desembolso registado</strong><small>A operação foi confirmada pelo servidor. Está agora na ficha do contrato {numero}. Não volte a submeter a abertura.</small></span></div>
    <button type="button" onClick={onBack}>Ir à carteira</button>
   </div>}
   <header className="cis-profile-hero">
    <div className="cis-profile-avatar"><FileText size={31}/></div>
    <div className="cis-profile-heading"><span>FICHA DE CONTRATO</span><h2>{format(contract,'Nr do Contracto')}</h2><p>{format(contract,'Nome do Cliente')} · {format(contract,'Estado do Contrato')}</p></div>
    <div className="cis-profile-hero-actions"><button type="button" onClick={()=>void load()}><RefreshCw size={16}/> Actualizar</button></div>
   </header>
   <div className="cis-contract-overview-grid" aria-label="Indicadores deste contrato">
    <article><span>Crédito concedido</span><strong>{firstLoanAmount==null?'Não informado':currency(Number(firstLoanAmount))}</strong><small>Valor registado no contrato</small></article>
    <article><span>Total previsto</span><strong>{currency(financialSnapshot.scheduled)}</strong><small>Soma das prestações registadas</small></article>
    <article><span>Total pago</span><strong>{currency(financialSnapshot.paid)}</strong><small>Valor Pago nas prestações</small></article>
    <article><span>Por liquidar · previsto</span><strong>{currency(financialSnapshot.outstanding)}</strong><small>{financialSnapshot.pending} de {financialSnapshot.installments} prestações não liquidadas</small></article>
   </div>
   <div className="cis-contract-overview-note"><AlertCircle size={15}/> Valores calculados das prestações existentes; juros variáveis e multas futuras podem alterar o total efectivo a receber.</div>
   <div className="cis-contract-notice"><LockKeyhole size={17}/><span>Os dados históricos são preservados. A cobrança integral exige contrato CIS novo, validação no servidor, confirmação de recebimento e activação financeira. Pagamentos parciais e renovações não estão disponíveis.</span></div>
   {!!data.contract_fields_divergent?.length&&<div className="cis-contract-warning" role="status">Os seguintes campos não são uniformes entre as prestações deste contrato: {data.contract_fields_divergent.join(', ')}. Confirme as linhas originais antes de interpretar os valores gerais.</div>}
   {!!data.columns_missing_in_database?.length&&<div className="cis-contract-warning" role="status">Campos previstos no formulário VBA mas ausentes desta versão da base de dados: {data.columns_missing_in_database.join(', ')}.</div>}
   <div className="cis-profile-layout">
    <nav className="cis-profile-tabs" aria-label="Secções do contrato">
     {([
      {name:'Contrato' as Tab,icon:FileText},
      {name:'Plano de prestações' as Tab,icon:Layers},
      {name:'Condições financeiras' as Tab,icon:Wallet},
      {name:'Administração' as Tab,icon:ShieldCheck},
      {name:'Dados legados' as Tab,icon:FileText},
      {name:'Todos os campos' as Tab,icon:Layers},
      {name:'Cobrança' as Tab,icon:ReceiptText},
      {name:'Documento original' as Tab,icon:Eye}
     ]).map(({name,icon:Icon})=><button type="button" key={name} className={tab===name?'selected':''} aria-current={tab===name?'page':undefined} onClick={()=>setTab(name)}><Icon size={19}/>{name}</button>)}
    </nav>
    <div className="cis-profile-content">
     <header className="cis-profile-section-title"><div><h3>{tab==='Aumento de crédito'?'Aumento de crédito · prestação '+(selectedInstallment?format(selectedInstallment,'Nº da Parcela'):'não seleccionada'):tab}</h3><p>{tab==='Plano de prestações'?'Prestações registadas na tabela Contractos.':tab==='Aumento de crédito'?'Operação ligada a uma prestação já existente; apenas revisão, sem gravação.':'Informações originais do sistema de microcrédito.'}</p></div></header>
     {tab==='Cobrança'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><ReceiptText size={18}/><span>O formulário Cobrança.frm foi analisado. Esta funcionalidade prepara a liquidação TOTAL das prestações criadas pelo CIS; pagamentos parciais e renovações permanecem bloqueados. Juros variáveis dependem dos dias efectivos, e multas sobre juros só são aplicadas quando escolhidas.</span></div>
      <h4>Prestações do contrato {numero}</h4>
      <div className="cis-table-scroll"><table><thead><tr><th>Parcela</th><th>Vencimento</th><th>Estado</th><th>A pagar (MT)</th><th>Pago (MT)</th></tr></thead><tbody>
      {sortedInstallments.map((row,index)=><tr key={String(row.Ordem??index)}><td>{format(row,'Nº da Parcela')}</td><td>{format(row,'Data de Cobrança','date')}</td><td>{format(row,'Estado')}</td><td>{format(row,'Valor a Pagar','money')}</td><td>{format(row,'Valor Pago','money')}</td></tr>)}
      </tbody></table></div>
      <div className="cis-profile-fields">
       <label className="cis-profile-field"><span>Prestação a receber</span><select value={collectionOrd} onChange={e=>{setCollectionOrd(e.target.value);setCollectionQuote(null);setReceivedConfirmed(false);setCollectionError('');}}>
        <option value="">Seleccionar prestação pendente</option>
        {data.installments.filter(item=>['Pendente','Pagamento Atrasado'].includes(String(item.Estado??''))).map(item=><option key={String(item.Ordem)} value={String(item.Ordem)}>
         Parcela {format(item,'Nº da Parcela')} · {format(item,'Data de Cobrança','date')}</option>)}
       </select></label>
       <label className="cis-profile-field cis-contract-confirm"><input type="checkbox" checked={collectPenalty} onChange={e=>{setCollectPenalty(e.target.checked);setCollectionQuote(null);setReceivedConfirmed(false);}}/> Cobrar multa por atraso (quando aplicável)</label>
      </div>
      <button type="button" className="cis-contract-draft-button" disabled={!collectionOrd||collectionBusy} onClick={()=>void quoteCollection()}>{collectionBusy?'A consultar…':'Calcular valor para cobrança'}</button>
      {collectionQuote&&<div className="cis-contract-review"><strong>Prévia da cobrança</strong>
       <p>Data: {collectionQuote.data_pagamento} · Dias de atraso: {collectionQuote.dias_atraso}</p>
       <p>Juros: {collectionQuote.juros} MT · Multa: {collectionQuote.multa} MT</p>
       <p><strong>Total a receber: {collectionQuote.total} MT</strong></p>
       <label className="cis-contract-confirm"><input type="checkbox" checked={receivedConfirmed} onChange={e=>setReceivedConfirmed(e.target.checked)}/> Confirmo que recebi efectivamente o valor integral indicado.</label>
       <button type="button" className="cis-contract-draft-button" disabled={!receivedConfirmed||collectionBusy} onClick={()=>void settleCollection()}>{collectionBusy?'A registar…':'Confirmar pagamento e entrada no Extracto'}</button>
      </div>}
      {collectionError&&<div className="cis-table-error" role="alert">{collectionError}</div>}
      {collectionStatus&&<div className="cis-contract-review" role="status">{collectionStatus}</div>}
      <p>A cobrança real requer activação pelo administrador e permissões limitadas no MySQL. Contratos históricos e pagamentos parciais continuam protegidos.</p>
     </section>:tab==='Aumento de crédito'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>A rotina <strong>YourButton_Click</strong> do Contractos.frm permite aumento directo apenas para juro fixo. Contém uma atribuição a «Valor Pago» que exige reconciliação antes da migração. Esta página é de preparação; não altera o contrato.</span></div>
      <button className="cis-contract-draft-button" type="button" onClick={()=>{setTab('Plano de prestações');setTargetInstallment(null);setIncreaseAmount('');}}>← Voltar às prestações</button>
      {selectedInstallment?<><h4>Prestação {format(selectedInstallment,'Nº da Parcela')} · contrato {numero}</h4>
       <div className="cis-profile-fields">
        <div className="cis-profile-field"><span>Estado da prestação</span><div className="cis-profile-field-value">{format(selectedInstallment,'Estado')}</div></div>
        <div className="cis-profile-field"><span>Valor a pagar registado</span><div className="cis-profile-field-value">{format(selectedInstallment,'Valor a Pagar','money')}</div></div>
        <div className="cis-profile-field"><span>Valor pago registado</span><div className="cis-profile-field-value">{format(selectedInstallment,'Valor Pago','money')}</div></div>
        <div className="cis-profile-field"><span>Tipo de juro</span><div className="cis-profile-field-value">{format(contract,'Tipo de Juro')}</div></div>
        <label className="cis-profile-field"><span>Montante de aumento pretendido (MT) — não gravado</span>
         <input type="number" min="0.01" step="0.01" disabled={String(contract['Tipo de Juro']??'').trim().toLowerCase()!=='fixo'} value={increaseAmount} onChange={e=>setIncreaseAmount(e.target.value)} placeholder="Indicar montante adicional"/>
        </label>
       </div>
       <p>{String(contract['Tipo de Juro']??'').trim().toLowerCase()!=='fixo'?'O VBA só permite aumento directo para contratos de juro fixo.':Number(increaseAmount)>0?'Valor de aumento introduzido, ainda sujeito à validação dos movimentos e do histórico de pagamento.':'Introduza o montante para preparar a revisão da prestação.'} Não existe cálculo, aprovação ou gravação de aumento nesta etapa.</p>
      </>:<p>Abra o separador Plano de prestações e escolha «Aumentar crédito» na prestação que pretende consultar.</p>}
     </section>:tab==='Documento original'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>A rotina <strong>cmb_vizualizar_Click</strong> abre um ficheiro PDF guardado no computador Windows do Excel original. O ficheiro não é gerado pelo Contractos.frm e não está disponível automaticamente no servidor CIS.</span></div>
      <h4>Documento associado ao contrato</h4>
      <div className="cis-profile-fields">
       <div className="cis-profile-field"><span>Número do contrato</span><div className="cis-profile-field-value">{numero}</div></div>
       <div className="cis-profile-field"><span>Documento original</span><div className="cis-profile-field-value">Ainda não associado ao armazenamento privado do CIS</div></div>
      </div>
      <p>Será necessário importar os PDFs originais e confirmar a correspondência de cada documento com o seu contrato, protegendo os ficheiros por permissões. Não será criado um documento fictício.</p>
     </section>:tab==='Todos os campos'?<div className="cis-contract-all-fields">
      <p className="cis-contract-count">Consulta das 33 colunas originais do Contractos.frm e da estratégia de cálculo acrescentada pelo CIS, para cada prestação. Os valores permanecem os da base MySQL.</p>
      <div className="cis-table-scroll"><table><thead><tr>{allLegacyColumns.map(name=><th key={name}>{name}</th>)}</tr></thead>
      <tbody>{(data.legacy_rows??[]).map((row,index)=><tr key={String(row.Ordem??index)}>
       {allLegacyColumns.map(name=><td key={name}>{Object.prototype.hasOwnProperty.call(row,name)
       ?format(row,name,dateLegacyColumns.has(name)?'date':moneyLegacyColumns.has(name)?'money':undefined)
       :'Campo ausente na tabela'}</td>)}
      </tr>)}</tbody></table></div>
     </div>:tab==='Plano de prestações'?<>
      <div className="cis-contract-count"><CalendarDays size={18}/>{data.installment_rows} linha(s) de prestação registada(s)</div>
      <div className="cis-table-scroll"><table><thead><tr>{installmentColumns.map(({name,kind})=><th key={name} aria-sort={sortColumn===name?(sortDirection==='asc'?'ascending':'descending'):undefined}>
       <button type="button" className="cis-contract-sort" onClick={()=>sortBy(name)} title={`Ordenar ${name} por ${kind==='date'?'data':kind==='number'?'número':'texto'}`}>
        {name}{sortColumn===name?(sortDirection==='asc'?<ChevronUp size={15}/>:<ChevronDown size={15}/>):<ArrowDownUp size={14}/>}
       </button></th>)}<th>Operação da prestação</th></tr></thead>
       <tbody>{sortedInstallments.map((item,index)=><tr key={String(item.Ordem??index)}>
        {installmentColumns.map(({name,kind})=><td key={name}>{format(item,name,kind==='date'?'date':kind==='number'&&name!=='Nº da Parcela'?'money':undefined)}</td>)}
        <td><button type="button" className="cis-contract-draft-button" onClick={()=>{
         const sourceIndex=data.installments.indexOf(item);
         setTargetInstallment(sourceIndex);
         setIncreaseAmount('');
         setTab('Aumento de crédito');
        }} disabled={String(contract['Tipo de Juro']??'').trim().toLowerCase()!=='fixo'} title={String(contract['Tipo de Juro']??'').trim().toLowerCase()!=='fixo'?'O aumento directo no VBA aplica-se apenas a juro fixo':'Rever aumento associado a esta prestação'}><ArrowRightLeft size={15}/> Aumentar crédito</button></td>
       </tr>)}</tbody></table></div>
     </>:<div className="cis-profile-fields">{groups.find(g=>g.tab===tab)?.fields.map(field=><div className="cis-profile-field" key={field.name}>
      <span>{field.label}</span><div className="cis-profile-field-value">{format(contract,field.name,field.kind)}</div>
     </div>)}</div>}
    </div>
   </div>
  </>}
 </section>;
}
