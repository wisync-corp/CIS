import {useCallback,useEffect,useMemo,useState} from 'react';
import {ArrowLeft,FileText,CalendarDays,Wallet,Layers,ShieldCheck,LockKeyhole,AlertCircle,RefreshCw,ChevronDown,ChevronUp,ArrowDownUp,ReceiptText,Eye,ArrowUpRight,CheckCircle2,Info,ChevronRight} from 'lucide-react';
import CollectionsDesk from './CollectionsDesk';
import {coluna,estadoPrestacao,situacaoContrato} from './glossario';

type Item=Record<string,string|number|null>;
type Payload={contract:Item;installments:Item[];legacy_rows?:Item[];installment_rows:number;notice:string;contract_fields_divergent?:string[];columns_missing_in_database?:string[]};
type Tab='Contrato'|'Plano de pagamentos'|'Condições financeiras'|'Pagamentos'|'Documento original';
const API=import.meta.env.VITE_API_URL||'/api';
const groups:{tab:Tab;icon:typeof FileText;fields:{name:string;label:string;kind?:'date'|'money'|'percent'}[]}[]=[
 {tab:'Contrato',icon:FileText,fields:[


  {name:'Nº de Cliente',label:'N.º do mutuário'},
  {name:'Data de Inicio',label:'Data de início',kind:'date'},
  {name:'Data Final',label:'Data final',kind:'date'},
  {name:'Cidade',label:'Cidade'},
  {name:'Estado do Contrato',label:'Estado do contrato'}]},
 {tab:'Condições financeiras',icon:Wallet,fields:[
  {name:'Quantia Creditada',label:'Capital desembolsado',kind:'money'},
  {name:'% de Juro',label:'Taxa de juro (% por período)',kind:'percent'},
  {name:'Tipo de Juro',label:'Tipo de juro'},
  {name:'Estratégia de Cálculo',label:'Sistema de amortização'},
  {name:'% de Multa',label:'Taxa de juros de mora (%)',kind:'percent'},
  {name:'M. Pagamento',label:'Periodicidade'},
  {name:'Forma de Pagamento',label:'Forma de pagamento'},
  {name:'Prazo/Duração',label:'Prazo'},
  {name:'Disponibilidade',label:'Disponibilidade'},
  {name:'Nº de Prestações',label:'Número de prestações'}]},
];
const installmentColumns=[
 {name:'Nº da Parcela',kind:'number'}, {name:'Data de Cobrança',kind:'date'},
 {name:'Capital',kind:'number'}, {name:'Amortização',kind:'number'},
 {name:'Valor de Juros',kind:'number'}, {name:'Valor a Pagar',kind:'number'},
 {name:'Valor Pago',kind:'number'}, {name:'Estado',kind:'text'}
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
const validAmount=(raw:unknown):raw is number|string=>raw!==null&&raw!==undefined&&raw!==''&&Number.isFinite(Number(raw));
const isPaid=(row:Item)=>['pago','pago em atraso','liquidado','finalizado'].includes(String(row.Estado??'').trim().toLowerCase());
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
 const [paymentOrder,setPaymentOrder]=useState<string|undefined>();
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
 // Indicadores de topo da ficha — leitura das prestações existentes.
 const financialSnapshot=useMemo(()=>{
  const rows=data?.installments??[];
  const paidRows=rows.filter(isPaid);
  const pendingRows=rows.filter(row=>!isPaid(row));
  const due=[...pendingRows].filter(row=>/^\d{4}-\d{2}-\d{2}/.test(String(row['Data de Cobrança']??'')))
   .sort((a,b)=>String(a['Data de Cobrança']).localeCompare(String(b['Data de Cobrança'])))[0]??null;
  return {installments:rows.length,pending:pendingRows.length,nextDue:due,paidRows:paidRows.length};
 },[data]);
 const currency=(value:number)=>money.format(value)+' MT';
 const firstLoanAmount=contract?.['Quantia Creditada'];
 const sortedInstallments=useMemo(()=>data?.installments.map((item,index)=>({item,index})).sort((a,b)=>{
  const cmp=compareInstallments(a.item,b.item,sortColumn);
  return cmp===0?a.index-b.index:(sortDirection==='asc'?cmp:-cmp);
 }).map(({item})=>item)??[],[data,sortColumn,sortDirection]);
 function sortBy(column:InstallmentSort){if(sortColumn===column)setSortDirection(prev=>prev==='asc'?'desc':'asc');else{setSortColumn(column);setSortDirection('asc');}}
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Ficha individual</span></div>
  {error&&<div className="cis-credit-error-state" role="alert"><AlertCircle size={22}/><div><strong>Não foi possível carregar a ficha</strong><p>{error}</p></div><button type="button" onClick={()=>void load()}><RefreshCw size={16}/> Tentar novamente</button></div>}
  {loading&&<div className="cis-credit-loading-state" role="status"><RefreshCw size={19} className="cis-credit-loading-icon"/> A consultar contrato e prestações…</div>}
  {justCreated&&<div className="cis-contract-created-banner" role="status" aria-live="polite">
    <div><ShieldCheck size={24}/><span><strong>Crédito criado e desembolso registado</strong><small>A operação foi confirmada pelo servidor. Está agora na ficha do contrato {numero}. Não volte a submeter a abertura.</small></span></div>
    <button type="button" onClick={onBack}>Ir à carteira</button>
   </div>}
  {data&&contract&&<>
   <header className="cis-profile-hero cis-credit-hero">
    <div className="cis-profile-avatar"><FileText size={30}/></div>
    <div className="cis-profile-heading">
     <span>FICHA DE CRÉDITO · {numero}</span>
     <h2>{format(contract,'Nome do Cliente')}</h2>
     <div className="cis-credit-hero-meta"><span className="cis-credit-status"><span className="cis-credit-status-dot"/>{situacaoContrato(format(contract,'Estado do Contrato'))}</span><span>Mutuário #{format(contract,'Nº de Cliente')}</span><span>Início: {format(contract,'Data de Inicio','date')}</span></div>
    </div>
    <div className="cis-profile-hero-actions"><button type="button" onClick={()=>void load()} disabled={loading}><RefreshCw size={16}/> Actualizar</button></div>
   </header>
   <div className="cis-credit-highlights" aria-label="Resumo do contrato">
    <article className="cis-credit-highlight cis-credit-highlight-main"><span>Crédito concedido</span>
     <strong>{validAmount(firstLoanAmount)?currency(Number(firstLoanAmount)):'Não informado'}</strong>
     <small>Capital inicial registado</small></article>
    <article className="cis-credit-highlight"><span>Prestações</span>
     <strong>{financialSnapshot.installments}</strong>
     <small>{financialSnapshot.pending} ainda por liquidar</small></article>
    <article className="cis-credit-highlight"><span>Próximo vencimento registado</span>
     <strong>{financialSnapshot.nextDue?format(financialSnapshot.nextDue,'Data de Cobrança','date'):'—'}</strong>
     <small>{financialSnapshot.nextDue?'Prestação '+format(financialSnapshot.nextDue,'Nº da Parcela'):'Sem prestação pendente com data válida'}</small></article>
   </div>
   {!!data.contract_fields_divergent?.length&&<details className="cis-credit-disclosure">
    <summary><AlertCircle size={16}/> Dados divergentes entre prestações <ChevronDown size={15}/></summary>
    <p>Estes campos apresentam valores diferentes: {data.contract_fields_divergent.join(', ')}. Verifique o contrato original antes de tomar decisões.</p>
   </details>}
   {!!data.columns_missing_in_database?.length&&<details className="cis-credit-disclosure">
    <summary><Info size={16}/> Campos do sistema antigo não disponíveis <ChevronDown size={15}/></summary>
    <p>{data.columns_missing_in_database.join(', ')}</p>
   </details>}
   <div className="cis-profile-layout">
    <nav className="cis-profile-tabs cis-credit-navigation" aria-label="Secções do contrato">
     {([
      {name:'Contrato' as Tab,icon:FileText,detail:'Dados principais'},
      {name:'Condições financeiras' as Tab,icon:Wallet,detail:'Termos do crédito'},
      {name:'Plano de pagamentos' as Tab,icon:CalendarDays,detail:'Vencimentos'},
      {name:'Pagamentos' as Tab,icon:ReceiptText,detail:'Recebimentos'},
      {name:'Documento original' as Tab,icon:Eye,detail:'Documentação'}
     ]).map(({name,icon:Icon,detail})=><button type="button" key={name} className={tab===name?'selected':''} aria-current={tab===name?'page':undefined} onClick={()=>setTab(name)}>
      <Icon size={19}/><span>{name}<small>{detail}</small></span><ChevronRight className="cis-credit-nav-chevron" size={15}/></button>)}
    </nav>
    <div className="cis-profile-content">
     <header className="cis-profile-section-title"><div><h3>{tab}</h3><p>{tab==='Plano de pagamentos'?'Calendário e valores das prestações.':tab==='Pagamentos'?'Cobranças, simulações e histórico deste contrato, num único local.':'Informações essenciais do contrato.'}</p></div></header>
     {tab==='Pagamentos'?<CollectionsDesk token={token} embeddedContract={numero} embeddedProfile={data} initialOrder={paymentOrder}/>:tab==='Documento original'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>A rotina <strong>cmb_vizualizar_Click</strong> abre um ficheiro PDF guardado no computador Windows do Excel original. O ficheiro não é gerado pelo Contractos.frm e não está disponível automaticamente no servidor CIS.</span></div>
      <h4>Documento associado ao contrato</h4>
      <div className="cis-profile-fields">
       <div className="cis-profile-field"><span>Número do contrato</span><div className="cis-profile-field-value">{numero}</div></div>
       <div className="cis-profile-field"><span>Documento original</span><div className="cis-profile-field-value">Ainda não associado ao armazenamento privado do CIS</div></div>
      </div>
      <p>Será necessário importar os PDFs originais e confirmar a correspondência de cada documento com o seu contrato, protegendo os ficheiros por permissões. Não será criado um documento fictício.</p>
     </section>:tab==='Plano de pagamentos'?<>
      <div className="cis-contract-count"><CalendarDays size={18}/>{data.installment_rows} linha(s) de prestação registada(s)</div>
      <div className="cis-table-scroll"><table><thead><tr>{installmentColumns.map(({name,kind})=><th key={name} aria-sort={sortColumn===name?(sortDirection==='asc'?'ascending':'descending'):undefined}>
       <button type="button" className="cis-contract-sort" onClick={()=>sortBy(name)} title={`Ordenar ${coluna(name)} por ${kind==='date'?'data':kind==='number'?'número':'texto'}`}>
        {coluna(name)}{sortColumn===name?(sortDirection==='asc'?<ChevronUp size={15}/>:<ChevronDown size={15}/>):<ArrowDownUp size={14}/>}
       </button></th>)}<th>Operação da prestação</th></tr></thead>
       <tbody>{sortedInstallments.map((item,index)=><tr key={String(item.Ordem??index)}>
        {installmentColumns.map(({name,kind})=><td key={name}>{name==='Estado'?estadoPrestacao(item.Estado):format(item,name,kind==='date'?'date':kind==='number'&&name!=='Nº da Parcela'?'money':undefined)}</td>)}
        <td><button type="button" className="cis-contract-draft-button" onClick={()=>{const eligible=['Pendente','Pagamento Atrasado'].includes(String(item.Estado??''))&&!/finalizado|liquidado|cancelado|anulado/i.test(String(contract?.['Estado do Contrato']??''));setPaymentOrder(eligible?String(item.Ordem??''):undefined);setTab('Pagamentos');}}><ReceiptText size={15}/> {['Pendente','Pagamento Atrasado'].includes(String(item.Estado??''))&&!/finalizado|liquidado|cancelado|anulado/i.test(String(contract?.['Estado do Contrato']??''))?'Efectuar pagamento':'Ver histórico'}</button></td>
       </tr>)}</tbody></table></div>
     </>:<div className="cis-credit-section">
      {tab==='Contrato'&&<div className="cis-credit-section-lead"><CheckCircle2 size={19}/><div><strong>Informação do contrato</strong><p>Os elementos essenciais, sem campos técnicos do antigo Excel.</p></div></div>}
      <div className="cis-profile-fields">{groups.find(g=>g.tab===tab)?.fields.map(field=><div className="cis-profile-field" key={field.name}>
      <span>{field.label}</span><div className="cis-profile-field-value">{format(contract,field.name,field.kind)}</div>
     </div>)}</div>
      {tab==='Contrato'&&<button type="button" className="cis-credit-next-action" onClick={()=>setTab('Condições financeiras')}>Consultar condições do crédito <ArrowUpRight size={16}/></button>}
     </div>}
    </div>
   </div>
  </>}
 </section>;
}
