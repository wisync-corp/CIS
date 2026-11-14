import {useCallback,useEffect,useMemo,useState} from 'react';
import {ArrowLeft,FileText,CalendarDays,Wallet,Layers,ShieldCheck,LockKeyhole,AlertCircle,RefreshCw,ChevronDown,ChevronUp,ArrowDownUp,ReceiptText,ArrowRightLeft,Eye} from 'lucide-react';

type Item=Record<string,string|number|null>;
type Payload={contract:Item;installments:Item[];legacy_rows?:Item[];installment_rows:number;notice:string;contract_fields_divergent?:string[];columns_missing_in_database?:string[]};
type Tab='Contrato'|'Plano de prestações'|'Condições financeiras'|'Administração'|'Dados legados'|'Todos os campos'|'Cobrança'|'Aumento de crédito'|'Documento original';
const API=import.meta.env.VITE_API_URL||'/api';
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
 'Quantia Creditada','M. Pagamento','Prazo/Duração','% de Juro','Tipo de Juro','% de Multa',
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
 if(kind==='money'&&Number.isFinite(Number(raw)))return money.format(Number(raw))+' MT';
 if(kind==='percent')return String(raw)+'%';
 return String(raw);
}
export default function ContractProfile({numero,token,onBack}:{numero:string;token:string;onBack:()=>void}){
 const [tab,setTab]=useState<Tab>('Contrato');
 const [data,setData]=useState<Payload|null>(null);
 const [loading,setLoading]=useState(true);
 const [error,setError]=useState('');
 const [sortColumn,setSortColumn]=useState<InstallmentSort>('Nº da Parcela');
 const [targetInstallment,setTargetInstallment]=useState('');
 const [increaseAmount,setIncreaseAmount]=useState('');
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
 const sortedInstallments=useMemo(()=>data?.installments.map((item,index)=>({item,index})).sort((a,b)=>{
  const cmp=compareInstallments(a.item,b.item,sortColumn);
  return cmp===0?a.index-b.index:(sortDirection==='asc'?cmp:-cmp);
 }).map(({item})=>item)??[],[data,sortColumn,sortDirection]);
 function sortBy(column:InstallmentSort){if(sortColumn===column)setSortDirection(prev=>prev==='asc'?'desc':'asc');else{setSortColumn(column);setSortDirection('asc');}}
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Ficha individual</span></div>
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={18}/>{error}</div>}
  {loading&&<div className="cis-panel cis-loading">A consultar contrato e prestações…</div>}
  {data&&contract&&<>
   <header className="cis-profile-hero">
    <div className="cis-profile-avatar"><FileText size={31}/></div>
    <div className="cis-profile-heading"><span>FICHA DE CONTRATO</span><h2>{format(contract,'Nr do Contracto')}</h2><p>{format(contract,'Nome do Cliente')} · {format(contract,'Estado do Contrato')}</p></div>
    <div className="cis-profile-hero-actions"><button type="button" onClick={()=>void load()}><RefreshCw size={16}/> Actualizar</button></div>
   </header>
   <div className="cis-contract-notice"><LockKeyhole size={17}/><span>Consulta apenas de leitura. Contratos Finalizados e Em Progresso são determinados pelo campo original «Estado do Contrato», não pelo estado de uma prestação. Os valores financeiros são os originais, ainda não reconciliados.</span></div>
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
      {name:'Aumento de crédito' as Tab,icon:ArrowRightLeft},
      {name:'Documento original' as Tab,icon:Eye}
     ]).map(({name,icon:Icon})=><button type="button" key={name} className={tab===name?'selected':''} aria-current={tab===name?'page':undefined} onClick={()=>setTab(name)}><Icon size={19}/>{name}</button>)}
    </nav>
    <div className="cis-profile-content">
     <header className="cis-profile-section-title"><div><h3>{tab}</h3><p>{tab==='Plano de prestações'?'Prestações registadas na tabela Contractos.':'Informações originais do sistema de microcrédito.'}</p></div></header>
     {tab==='Cobrança'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>O botão <strong>cmb_cobranca_Click</strong> do Contractos.frm transfere o número do contrato para outro formulário denominado Cobrança. A consulta das prestações é possível; receber pagamentos permanece bloqueado até à análise de Cobrança.frm.</span></div>
      <h4>Prestações vinculadas ao contrato {numero}</h4>
      <div className="cis-table-scroll"><table><thead><tr><th>Parcela</th><th>Vencimento</th><th>Estado</th><th>Valor a pagar (MT)</th><th>Valor pago (MT)</th></tr></thead>
       <tbody>{sortedInstallments.map((row,index)=><tr key={String(row.Ordem??index)}>
        <td>{format(row,'Nº da Parcela')}</td>
        <td>{format(row,'Data de Cobrança','date')}</td>
        <td>{format(row,'Estado')}</td>
        <td>{format(row,'Valor a Pagar','money')}</td>
        <td>{format(row,'Valor Pago','money')}</td>
       </tr>)}</tbody></table></div>
      <p>Estes valores são históricos, não constituem um saldo actualizado ou uma ordem de cobrança. O lançamento de pagamentos, juros variáveis e multas não está disponível nesta etapa.</p>
     </section>:tab==='Aumento de crédito'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>A rotina <strong>YourButton_Click</strong> do Contractos.frm permite aumento directo apenas para juro fixo. Contém uma atribuição a «Valor Pago» que exige reconciliação antes da migração. Esta página é de preparação; não altera o contrato.</span></div>
      <h4>Revisão de aumento de crédito</h4>
      <div className="cis-profile-fields">
       <label className="cis-profile-field"><span>Prestação pretendida</span>
        <select value={targetInstallment} onChange={event=>setTargetInstallment(event.target.value)}>
         <option value="">Seleccionar uma prestação</option>
         {data.installments.map((row,index)=><option value={String(row.Ordem??index)} key={index}>Parcela {format(row,'Nº da Parcela')} · {format(row,'Estado')}</option>)}
        </select>
       </label>
       <label className="cis-profile-field"><span>Valor de aumento pretendido (MT) — apenas consulta local</span>
        <input type="number" min="0.01" step="0.01" value={increaseAmount} onChange={e=>setIncreaseAmount(e.target.value)} placeholder="Montante adicional"/>
       </label>
       <div className="cis-profile-field"><span>Tipo de juro original</span><div className="cis-profile-field-value">{format(contract,'Tipo de Juro')}</div></div>
       <div className="cis-profile-field"><span>Elegibilidade técnica</span><div className="cis-profile-field-value">{String(contract['Tipo de Juro']??'').toLowerCase()==='fixo'?'Juro fixo — requer reconciliação dos movimentos':'Não elegível para aumento directo no VBA'}</div></div>
      </div>
      <p>{targetInstallment&&Number(increaseAmount)>0?'Pedido preliminar preenchido.':'Seleccione a prestação e indique o montante que pretende avaliar.'} Não existe cálculo nem gravação de aumento nesta etapa.</p>
     </section>:tab==='Documento original'?<section className="cis-contract-operation">
      <div className="cis-contract-notice"><LockKeyhole size={18}/><span>A rotina <strong>cmb_vizualizar_Click</strong> abre um ficheiro PDF guardado no computador Windows do Excel original. O ficheiro não é gerado pelo Contractos.frm e não está disponível automaticamente no servidor CIS.</span></div>
      <h4>Documento associado ao contrato</h4>
      <div className="cis-profile-fields">
       <div className="cis-profile-field"><span>Número do contrato</span><div className="cis-profile-field-value">{numero}</div></div>
       <div className="cis-profile-field"><span>Documento original</span><div className="cis-profile-field-value">Ainda não associado ao armazenamento privado do CIS</div></div>
      </div>
      <p>Será necessário importar os PDFs originais e confirmar a correspondência de cada documento com o seu contrato, protegendo os ficheiros por permissões. Não será criado um documento fictício.</p>
     </section>:tab==='Todos os campos'?<div className="cis-contract-all-fields">
      <p className="cis-contract-count">Consulta integral das 33 colunas do INSERT original do Contractos.frm, para cada prestação. Os valores permanecem os da base MySQL.</p>
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
       </button></th>)}</tr></thead>
       <tbody>{sortedInstallments.map((item,index)=><tr key={String(item.Ordem??index)}>
        {installmentColumns.map(({name,kind})=><td key={name}>{format(item,name,kind==='date'?'date':kind==='number'&&name!=='Nº da Parcela'?'money':undefined)}</td>)}
       </tr>)}</tbody></table></div>
     </>:<div className="cis-profile-fields">{groups.find(g=>g.tab===tab)?.fields.map(field=><div className="cis-profile-field" key={field.name}>
      <span>{field.label}</span><div className="cis-profile-field-value">{format(contract,field.name,field.kind)}</div>
     </div>)}</div>}
    </div>
   </div>
  </>}
 </section>;
}
