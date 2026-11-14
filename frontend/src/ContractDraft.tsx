import {useState,type FormEvent} from 'react';
import {ArrowLeft,FilePlus2,LockKeyhole,CalendarDays,Wallet,UserRound,Info,RotateCcw,ArrowRightLeft,BadgePlus,Eye,ReceiptText} from 'lucide-react';

type Field={key:string;label:string;type?:'date'|'number'|'select';choices?:string[];required?:boolean;hint?:string};
const fields:Field[]=[
 {key:'cliente',label:'Mutuário / cliente',required:true,hint:'No sistema operacional, deverá ser seleccionado a partir do cadastro de Clientes.'},
 {key:'cliente_id',label:'Número do cliente',required:true,hint:'Identificador da tabela Clientes; não criar números de cliente manualmente.'},
 {key:'numero',label:'Número do contrato',hint:'O número C-0000 será atribuído pelo servidor quando o fluxo financeiro for homologado.'},
 {key:'cidade',label:'Cidade',required:true},
 {key:'data_inicio',label:'Data de início',type:'date',required:true},
 {key:'data_final',label:'Data final prevista',type:'date',hint:'Calculada a partir da data de início e do prazo após a homologação.'},
 {key:'quantia',label:'Quantia creditada (MT)',type:'number',required:true},
 {key:'saldo_disponivel',label:'Saldo disponível para desembolso (MT)',type:'number',hint:'Depende da reconciliação das entradas e saídas do Extracto.'},
 {key:'tipo_juro',label:'Tipo de juro',type:'select',choices:['Fixo','Variável','Composto'],required:true},
 {key:'juros',label:'Taxa de juro (%)',type:'number',required:true},
 {key:'multa',label:'Taxa de multa (%)',type:'number',required:true},
 {key:'prazo',label:'Prazo',type:'select',choices:['1 Mês','2 Meses','3 Meses'],required:true},
 {key:'modalidade',label:'Modalidade de pagamento',type:'select',choices:['Mensal','Uma Parcela'],required:true},
 {key:'forma',label:'Forma de pagamento',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Corresponde às três opções originais do VBA, com ortografia corrigida na interface.'},
 {key:'disponibilidade',label:'Meio de disponibilização do crédito',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Forma de desembolso; o saldo e o Extracto exigem reconciliação antes de qualquer gravação.'},
];
const groupings=[
 {name:'Mutuário e contrato',icon:UserRound,keys:['cliente','cliente_id','numero','cidade','data_inicio','data_final']},
 {name:'Condições do crédito',icon:Wallet,keys:['quantia','saldo_disponivel','tipo_juro','juros','multa','prazo','modalidade','forma','disponibilidade']},
];
export default function ContractDraft({onBack}:{onBack:()=>void}){
 const [values,setValues]=useState<Record<string,string>>({});
 const [checked,setChecked]=useState(false);
 const [message,setMessage]=useState('');
 const tipo=values.tipo_juro??'';
 const modalidade=tipo==='Variável'?'Uma Parcela':tipo==='Composto'?'Mensal':(values.modalidade??'');
 const prazoMeses=Number((values.prazo??'').slice(0,1));
 const numeroPrestacoes=(!prazoMeses||!tipo)?null:tipo==='Variável'||modalidade==='Uma Parcela'?1:prazoMeses;
 const handle=(event:FormEvent)=>{event.preventDefault();setChecked(true);setMessage('Campos revistos no navegador. A criação real do contrato, o plano de prestações e o movimento de desembolso continuam desactivados até à homologação dos cálculos e das permissões.');};
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Preparação de novo contrato</span></div>
  <header className="cis-profile-hero"><div className="cis-profile-avatar"><FilePlus2 size={29}/></div><div className="cis-profile-heading"><span>FORMULÁRIO CONTRATOS.FRM</span><h2>Preparar novo contrato</h2><p>Mesmos elementos de entrada do formulário VBA · sem gravar dados</p></div></header>
  <div className="cis-contract-notice"><LockKeyhole size={18}/><span><strong>Modo de preparação:</strong> este formulário não cria contratos, não calcula prestações definitivas e não altera o Extracto. O registo financeiro real só ficará disponível após validação explícita das regras VBA e dos controlos de acesso.</span></div>
  <form className="cis-contract-draft" onSubmit={handle} onReset={()=>{setValues({});setChecked(false);setMessage('');}}>
  {groupings.map(({name,icon:Icon,keys})=><section className="cis-panel cis-contract-draft-section" key={name}>
   <h3><Icon size={20}/>{name}</h3><div className="cis-profile-fields">{keys.map(key=>{
    const field=fields.find(f=>f.key===key)!;
    const disabled=['numero','data_final','saldo_disponivel'].includes(key)||(key==='modalidade'&&['Variável','Composto'].includes(tipo));
    const val=key==='modalidade'?modalidade:values[key]??'';
    return <label key={key} className="cis-profile-field"><span>{field.label}{field.required?' *':''}</span>
     {field.choices?<select value={val} disabled={disabled} required={field.required} onChange={e=>setValues(prev=>({...prev,[key]:e.target.value}))}>
       <option value="">Seleccionar</option>{field.choices.map(choice=><option key={choice} value={choice}>{choice}</option>)}
      </select>:<input type={field.type??'text'} disabled={disabled} value={val} required={field.required} min={field.type==='number'?0:undefined} step={field.type==='number'?'0.01':undefined} placeholder={key==='numero'?'Atribuído automaticamente':undefined} onChange={e=>setValues(prev=>({...prev,[key]:e.target.value}))}/>}
     {field.hint&&<small><Info size={12}/>{field.hint}</small>}
    </label>;
   })}</div>
  </section>)}
  <section className="cis-panel cis-contract-draft-section"><h3><CalendarDays size={20}/> Plano de prestações · preparação</h3>
   <div className="cis-contract-draft-count"><span>N.º previsto de prestações (regra do VBA)</span><strong>{numeroPrestacoes??'—'}</strong></div>
   <p>Este número representa apenas a quantidade de prestações conforme o tipo de juro, modalidade e prazo seleccionados. Montantes, juros, vencimentos e multas não são calculados nem gravados.</p>
  </section>
  <section className="cis-panel cis-contract-draft-section"><h3><ArrowRightLeft size={20}/> Operações previstas no formulário original</h3>
   <p>As operações seguintes constam de Contractos.frm. Permanecem bloqueadas porque modificam valores financeiros ou dependem da Cobrança e do Extracto.</p>
   <div className="cis-contract-workflow-actions">
    <button type="button" disabled title="Requer validação financeira"><BadgePlus size={18}/> Criar e desembolsar crédito</button>
    <button type="button" disabled title="Requer validação financeira"><ArrowRightLeft size={18}/> Aumentar crédito (juro fixo)</button>
    <button type="button" disabled title="Módulo Cobranças ainda não homologado"><ReceiptText size={18}/> Abrir Cobrança</button>
    <button type="button" disabled title="Operação original a validar"><Eye size={18}/> Visualizar documento original</button>
   </div>
  </section>
  <section className="cis-panel cis-contract-draft-section"><h3><CalendarDays size={20}/> Regras de prazo e prestações do VBA</h3>
   <p>Prazo permitido no formulário: 1, 2 ou 3 meses. Juro variável: uma parcela, com juros apurados na cobrança após a data efectiva de pagamento. Juro composto: prestações mensais. Juro fixo: uma parcela ou mensal. Nenhum cálculo é aplicado a valores financeiros reais nesta fase.</p>
   {checked&&<div role="status" className="cis-contract-notice">{message}</div>}
   <div className="cis-edit-actions"><button type="reset"><RotateCcw size={16}/> Limpar formulário</button><button type="submit">Verificar preenchimento</button></div>
  </section></form>
 </section>;
}
