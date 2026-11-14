import {useCallback,useEffect,useState} from 'react';
import {ArrowLeft,FileText,CalendarDays,Wallet,Layers,ShieldCheck,LockKeyhole,AlertCircle,RefreshCw} from 'lucide-react';

type Item=Record<string,string|number|null>;
type Payload={contract:Item;installments:Item[];installment_rows:number;notice:string};
type Tab='Contrato'|'Plano de prestações'|'Condições financeiras'|'Administração';
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
  {name:'Estado do Contrato',label:'Estado original do contrato'}]},
];
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
   <div className="cis-contract-notice"><LockKeyhole size={17}/><span>Consulta apenas de leitura. Não existem operações de alteração de parcelas, juros, multas ou movimentos. Os valores são os originais, ainda não reconciliados.</span></div>
   <div className="cis-profile-layout">
    <nav className="cis-profile-tabs" aria-label="Secções do contrato">
     {([
      {name:'Contrato' as Tab,icon:FileText},
      {name:'Plano de prestações' as Tab,icon:Layers},
      {name:'Condições financeiras' as Tab,icon:Wallet},
      {name:'Administração' as Tab,icon:ShieldCheck}
     ]).map(({name,icon:Icon})=><button type="button" key={name} className={tab===name?'selected':''} aria-current={tab===name?'page':undefined} onClick={()=>setTab(name)}><Icon size={19}/>{name}</button>)}
    </nav>
    <div className="cis-profile-content">
     <header className="cis-profile-section-title"><div><h3>{tab}</h3><p>{tab==='Plano de prestações'?'Prestações registadas na tabela Contractos.':'Informações originais do sistema de microcrédito.'}</p></div></header>
     {tab==='Plano de prestações'?<>
      <div className="cis-contract-count"><CalendarDays size={18}/>{data.installment_rows} linha(s) de prestação registada(s)</div>
      <div className="cis-table-scroll"><table><thead><tr>{[
        'Nº da Parcela','Data de Cobrança','Data de Pagamento','Capital','Amortização',
        'Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Estado'
       ].map(n=><th key={n}>{n}</th>)}</tr></thead>
       <tbody>{data.installments.map((item,index)=><tr key={String(item.Ordem??index)}>
        {['Nº da Parcela','Data de Cobrança','Data de Pagamento','Capital','Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Estado'].map(name=>
         <td key={name}>{format(item,name,name.startsWith('Data')?'date':(['Capital','Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago'].includes(name)?'money':undefined))}</td>)}
       </tr>)}</tbody></table></div>
     </>:<div className="cis-profile-fields">{groups.find(g=>g.tab===tab)?.fields.map(field=><div className="cis-profile-field" key={field.name}>
      <span>{field.label}</span><div className="cis-profile-field-value">{format(contract,field.name,field.kind)}</div>
     </div>)}</div>}
    </div>
   </div>
  </>}
 </section>;
}
