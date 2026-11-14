import {useEffect,useState,type FormEvent} from 'react';
import {ArrowLeft,FilePlus2,LockKeyhole,CalendarDays,Wallet,UserRound,Info,RotateCcw,ArrowRightLeft,BadgePlus,Eye,ReceiptText} from 'lucide-react';
import {previewContractVba,type ContractPreview} from './contractVbaPreview';

type Field={key:string;label:string;type?:'date'|'number'|'select';choices?:string[];required?:boolean;hint?:string};
type Borrower={ID:number;'Numeração':string|number|null;'Nome Completo':string;Cidade:string|null};
type InterestQuote={found:boolean;taxa?:string|number;periodo_dias?:number;limite_inferior?:number;limite_superior?:number;message?:string};
type OpeningReview={cadastro_confirmado:boolean;cliente:{id:number;numero:string|number|null;nome:string};taxa_tabela:string|null;problemas:string[];dados_verificados:boolean;autorizado_para_gravar:false;pendencias:string[]};
const API=import.meta.env.VITE_API_URL||'/api';
function monthAfter(date:string,months:number):string {
 if(!/^\d{4}-\d{2}-\d{2}$/.test(date))return '';
 const [year,month,day]=date.split('-').map(Number);
 const target=new Date(Date.UTC(year,month-1+months,1));
 const max=new Date(Date.UTC(target.getUTCFullYear(),target.getUTCMonth()+1,0)).getUTCDate();
 target.setUTCDate(Math.min(day,max));
 return target.toISOString().slice(0,10);
}
const fields:Field[]=[
 {key:'cliente',label:'Mutuário / cliente',required:true,hint:'Seleccionado exclusivamente a partir dos clientes reais existentes no MySQL.'},
 {key:'cliente_id',label:'ID do cliente',required:true,hint:'ID real obtido automaticamente após a selecção do mutuário.'},
 {key:'numero',label:'Número do contrato',hint:'O número C-0000 será atribuído pelo servidor quando o fluxo financeiro for homologado.'},
 {key:'cidade',label:'Cidade',required:true},
 {key:'data_inicio',label:'Data de início',type:'date',required:true},
 {key:'data_final',label:'Data final prevista',type:'date',hint:'Calculada a partir da data de início e do prazo após a homologação.'},
 {key:'quantia',label:'Quantia creditada (MT)',type:'number',required:true},
 {key:'saldo_disponivel',label:'Saldo disponível para desembolso (MT)',type:'number',hint:'Depende da reconciliação das entradas e saídas do Extracto.'},
 {key:'tipo_juro',label:'Tipo de juro',type:'select',choices:['Fixo','Variável','Composto'],required:true},
 {key:'periodo_tabela',label:'Período automático da tabela (dias)',type:'select',choices:['30'],hint:'Contractos.frm usa k = 30 quando o prazo mensal está seleccionado. A tabela de 15 dias fica em Definições, mas não é aplicada automaticamente por este formulário.'},
 {key:'juros',label:'Taxa de juro (%)',type:'number',required:true,hint:'Para juro Fixo, consultada automaticamente na tabela de preços pelas condições indicadas.'},
 {key:'multa',label:'Taxa de multa (%)',type:'number',required:true},
 {key:'prazo',label:'Prazo',type:'select',choices:['1 Mês','2 Meses','3 Meses'],required:true},
 {key:'modalidade',label:'Modalidade de pagamento',type:'select',choices:['Mensal','Uma Parcela'],required:true},
 {key:'forma',label:'Forma de pagamento',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Corresponde às três opções originais do VBA, com ortografia corrigida na interface.'},
 {key:'disponibilidade',label:'Meio de disponibilização do crédito',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Forma de desembolso; o saldo e o Extracto exigem reconciliação antes de qualquer gravação.'},
];
const groupings=[
 {name:'Mutuário e contrato',icon:UserRound,keys:['cliente','cliente_id','numero','cidade','data_inicio','data_final']},
 {name:'Condições do crédito',icon:Wallet,keys:['quantia','saldo_disponivel','tipo_juro','periodo_tabela','juros','multa','prazo','modalidade','forma','disponibilidade']},
];
export default function ContractDraft({onBack,token}:{onBack:()=>void;token:string}){
 const [values,setValues]=useState<Record<string,string>>({});
 const [clientSearch,setClientSearch]=useState('');
 const [clients,setClients]=useState<Borrower[]>([]);
 const [selectedBorrower,setSelectedBorrower]=useState<Borrower|null>(null);
 const [clientError,setClientError]=useState('');
 const [clientLoading,setClientLoading]=useState(false);
 const [quote,setQuote]=useState<InterestQuote|null>(null);
 const [quoteKey,setQuoteKey]=useState('');
 const [quoteLoading,setQuoteLoading]=useState(false);
 const [quoteError,setQuoteError]=useState('');
 useEffect(()=>{
  const controller=new AbortController();
  const timer=window.setTimeout(()=>{
   setClientLoading(true);setClientError('');
   const params=new URLSearchParams({q:clientSearch.trim()});
   fetch(API+'/cis/contratos/clientes?'+params.toString(),{
    headers:{Authorization:'Bearer '+token,Accept:'application/json'},
    signal:controller.signal
   }).then(async result=>{
    if(!result.ok)throw new Error('Não foi possível consultar os clientes autorizados.');
    return result.json() as Promise<Borrower[]>;
   }).then(list=>{if(!controller.signal.aborted)setClients(list);})
   .catch(e=>{if(e.name!=='AbortError')setClientError(e.message);})
   .finally(()=>{if(!controller.signal.aborted)setClientLoading(false);});
  },300);
  return ()=>{controller.abort();window.clearTimeout(timer);};
 },[clientSearch,token]);
 const fixedRate=values.tipo_juro==='Fixo';
 const currentQuoteKey=values.quantia+'|30';
 const validAmount=values.quantia!==''&&Number.isFinite(Number(values.quantia))&&Number(values.quantia)>=0&&/^\d+(\.\d{1,2})?$/.test(values.quantia??'');
 const priced= fixedRate&&quoteKey===currentQuoteKey&&quote?.found===true;
 useEffect(()=>{
  setQuote(null);setQuoteKey('');setQuoteError('');
  if(!fixedRate || !validAmount || !(values.prazo&&['1 Mês','2 Meses','3 Meses'].includes(values.prazo))){
   setQuoteLoading(false);return;
  }
  const key=currentQuoteKey;
  const controller=new AbortController();
  const timer=window.setTimeout(()=>{
   setQuoteLoading(true);
   fetch(API+'/admin/interest-quote?'+new URLSearchParams({
    montante:values.quantia,periodo_dias:'30'
   }),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
    .then(async res=>{
     if(!res.ok)throw new Error(res.status===503?'A tabela de preços ainda não está activada no MySQL.':'Não foi possível consultar a tabela de juros.');
     return res.json() as Promise<InterestQuote>;
    })
    .then(value=>{if(!controller.signal.aborted){setQuote(value);setQuoteKey(key);}})
    .catch(e=>{if(e.name!=='AbortError')setQuoteError(e.message);})
    .finally(()=>{if(!controller.signal.aborted)setQuoteLoading(false);});
  },300);
  return ()=>{window.clearTimeout(timer);controller.abort();};
 },[fixedRate,validAmount,values.quantia,values.prazo,token,currentQuoteKey]);
 const [checked,setChecked]=useState(false);
 const [message,setMessage]=useState('');
 const [review,setReview]=useState<OpeningReview|null>(null);
 const [reviewBusy,setReviewBusy]=useState(false);
 const [reviewError,setReviewError]=useState('');
 const [preview,setPreview]=useState<ContractPreview|null>(null);
 const [previewError,setPreviewError]=useState('');
 const [workflowHint,setWorkflowHint]=useState('');
 useEffect(()=>{setReview(null);setChecked(false);setMessage('');setPreview(null);setPreviewError('');},[values,selectedBorrower,quoteKey]);
 const tipo=values.tipo_juro??'';
 const modalidade=tipo==='Variável'?'Uma Parcela':tipo==='Composto'?'Mensal':(values.modalidade??'');
 const prazoMeses=Number((values.prazo??'').slice(0,1));
 const numeroPrestacoes=(!prazoMeses||!tipo)?null:tipo==='Variável'||modalidade==='Uma Parcela'?1:prazoMeses;
 const dataFinal=values.data_inicio&&prazoMeses?monthAfter(values.data_inicio,prazoMeses):'';
 const dates=numeroPrestacoes&&values.data_inicio?Array.from({length:numeroPrestacoes},(_,index)=>
  index+1===numeroPrestacoes?dataFinal:monthAfter(values.data_inicio,index+1)):[];
 const handle=async(event:FormEvent)=>{
  event.preventDefault();
  setReview(null);setReviewError('');setMessage('');setChecked(false);
  if(fixedRate&&!priced){setQuoteError('Não existe taxa parametrizada válida para este montante e período.');return;}
  if(!selectedBorrower){setClientError('Seleccione um cliente existente no cadastro.');return;}
  setReviewBusy(true);
  try{
   const result=await fetch(API+'/cis/contractos/validar-abertura',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({
     cliente_id:selectedBorrower.ID,montante:values.quantia,tipo_juro:tipo,
     periodo_dias:fixedRate?30:null,
     taxa_informada:fixedRate?Number(quote?.taxa):Number(values.juros),
     taxa_multa:Number(values.multa),prazo_meses:prazoMeses,
     modalidade,forma_pagamento:values.forma,disponibilidade:values.disponibilidade,
     data_inicio:values.data_inicio
    })
   });
   if(!result.ok){
    if(result.status===422)throw new Error('Existem dados inválidos. Verifique os campos preenchidos.');
    throw new Error(result.status===503?'A validação privada ainda não foi activada.':'Não foi possível validar os dados no servidor.');
   }
   const body=await result.json() as OpeningReview;
   setReview(body);setChecked(true);
   if(body.cadastro_confirmado){
    try{setPreview(previewContractVba({montante:Number(values.quantia),taxa:Number(fixedRate?quote?.taxa:values.juros),tipo:tipo as 'Fixo'|'Variável'|'Composto',modalidade:modalidade as 'Mensal'|'Uma Parcela',prazoMeses,dataInicio:values.data_inicio}));}
    catch(e){setPreviewError(e instanceof Error?e.message:'Não foi possível preparar o plano.');}
   }
   setMessage('Verificação concluída no Laravel, sem criar contrato ou movimento financeiro.');
  }catch(error){setReviewError(error instanceof Error?error.message:'Erro na validação.');}
  finally{setReviewBusy(false);}
 };
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Preparação de novo contrato</span></div>
  <header className="cis-profile-hero"><div className="cis-profile-avatar"><FilePlus2 size={29}/></div><div className="cis-profile-heading"><span>FORMULÁRIO CONTRATOS.FRM</span><h2>Preparar novo contrato</h2><p>Mesmos elementos de entrada do formulário VBA · sem gravar dados</p></div></header>
  <div className="cis-contract-notice"><LockKeyhole size={18}/><span><strong>Modo de preparação:</strong> este formulário não cria contratos, não calcula prestações definitivas e não altera o Extracto. O registo financeiro real só ficará disponível após validação explícita das regras VBA e dos controlos de acesso.</span></div>
  <form className="cis-contract-draft" onSubmit={handle} onReset={()=>{setValues({});setSelectedBorrower(null);setClientSearch('');setQuote(null);setQuoteKey('');setQuoteError('');setChecked(false);setReview(null);setReviewError('');setPreview(null);setPreviewError('');setMessage('');}}>
  {groupings.map(({name,icon:Icon,keys})=><section className="cis-panel cis-contract-draft-section" key={name}>
   <h3><Icon size={20}/>{name}</h3><div className="cis-profile-fields">{keys.map(key=>{
    const field=fields.find(f=>f.key===key)!;
    const disabled=['numero','data_final','saldo_disponivel','cliente','cliente_id'].includes(key)||(key==='modalidade'&&['Variável','Composto'].includes(tipo))||(key==='juros'&&fixedRate)||(key==='periodo_tabela');
    const val=key==='modalidade'?modalidade:key==='periodo_tabela'&&fixedRate?'30':key==='data_final'?dataFinal:key==='juros'&&fixedRate?(priced?String(quote?.taxa??''):''):values[key]??'';
    if(key==='cliente')return <div key={key} className="cis-profile-field cis-contract-client-picker">
     <label htmlFor="cis-client-search">Pesquisar mutuário existente *</label>
     <input id="cis-client-search" type="search" placeholder="Escreva nome ou ID do cliente" value={clientSearch} autoComplete="off" onChange={e=>setClientSearch(e.target.value)}/>
     <select value={selectedBorrower?.ID??''} required aria-label="Seleccionar cliente" onChange={e=>{
      const borrower=clients.find(c=>c.ID===Number(e.target.value))??null;
      setSelectedBorrower(borrower);
      setValues(v=>({...v,cliente:borrower?.['Nome Completo']??'',cliente_id:borrower?String(borrower.ID):'',cidade:borrower?.Cidade??''}));
     }}><option value="">Seleccionar cliente do cadastro</option>{clients.map(c=><option key={c.ID} value={c.ID}>{c['Nome Completo']} · ID {c.ID}</option>)}</select>
     {selectedBorrower&&<small>Seleccionado: {selectedBorrower['Nome Completo']} · ID {selectedBorrower.ID}</small>}
     {clientLoading&&<small>À procura de clientes…</small>}{clientError&&<small role="alert">{clientError}</small>}
    </div>;
    return <label key={key} className="cis-profile-field"><span>{field.label}{field.required?' *':''}</span>
     {field.choices?<select value={val} disabled={disabled} required={!disabled&&field.required} onChange={e=>setValues(prev=>({...prev,[key]:e.target.value}))}>
       <option value="">Seleccionar</option>{field.choices.map(choice=><option key={choice} value={choice}>{choice}</option>)}
      </select>:<input type={field.type??'text'} disabled={disabled} value={val} required={!disabled&&field.required} min={field.type==='number'?0:undefined} step={field.type==='number'?'0.01':undefined} placeholder={key==='numero'?'Atribuído automaticamente':undefined} onChange={e=>setValues(prev=>({...prev,[key]:e.target.value}))}/>}
     {field.hint&&<small><Info size={12}/>{field.hint}</small>}
     {key==='juros'&&fixedRate&&<small role="status" className={priced?'cis-pricing-success':'cis-pricing-pending'}>{quoteLoading?'A consultar a tabela…':priced?`Taxa encontrada: ${quote?.taxa}% · faixa ${quote?.limite_inferior}–${quote?.limite_superior} MT · ${quote?.periodo_dias} dias`:(quoteError||quote?.message||'Preencha o montante e o prazo. O VBA consulta automaticamente 30 dias.')}</small>}
    </label>;
   })}</div>
  </section>)}
  <section className="cis-panel cis-contract-draft-section"><h3><CalendarDays size={20}/> Plano de prestações · preparação</h3>
   <div className="cis-contract-draft-count"><span>N.º previsto de prestações (regra do VBA)</span><strong>{numeroPrestacoes??'—'}</strong></div>
   <p>Datas previstas segundo a regra DateAdd do VBA (adicionar meses ao início). O quadro financeiro indicativo aparece depois de validar os dados no servidor. Nenhuma operação é gravada.</p>
   {dates.length>0&&<div className="cis-contract-date-preview">{dates.map((date,i)=><div key={i}><strong>Parcela {i+1}</strong><time dateTime={date}>{date.split('-').reverse().join('/')}</time></div>)}</div>}
  </section>
  <section className="cis-panel cis-contract-draft-section"><h3><ArrowRightLeft size={20}/> Operações previstas no formulário original</h3>
   <p>As operações seguintes constam de Contractos.frm. Permanecem bloqueadas porque modificam valores financeiros ou dependem da Cobrança e do Extracto.</p>
   <div className="cis-contract-workflow-actions">
    <button type="button" onClick={()=>{setWorkflowHint("A abertura real permanece bloqueada. Preencha os dados e use Validar abertura no servidor; a validação não desembolsa fundos.");document.getElementById("cis-contract-final-review")?.scrollIntoView({behavior:"smooth"});}}><BadgePlus size={18}/> Criar e desembolsar crédito</button>
    <button type="button" onClick={()=>setWorkflowHint("Para rever um aumento, regresse à carteira, abra a ficha de um contrato e escolha Aumento de crédito. Não são gravados aumentos.")}><ArrowRightLeft size={18}/> Aumentar crédito (juro fixo)</button>
    <button type="button" onClick={()=>setWorkflowHint("Para consultar prestações na Cobrança, regresse à carteira, abra um contrato e escolha o separador Cobrança. Receber pagamentos continua bloqueado.")}><ReceiptText size={18}/> Abrir Cobrança</button>
    <button type="button" onClick={()=>setWorkflowHint("Os PDFs originais não estão no VPS. Na ficha de um contrato, consulte Documento original para verificar a situação de associação.")}><Eye size={18}/> Visualizar documento original</button>
   </div>
   {workflowHint&&<div className="cis-contract-notice" role="status"><LockKeyhole size={17}/><span>{workflowHint} <button type="button" className="cis-contract-draft-button" onClick={onBack}>Voltar à carteira</button></span></div>}
  </section>
  <section className="cis-panel cis-contract-draft-section" id="cis-contract-final-review"><h3><CalendarDays size={20}/> Regras de prazo e prestações do VBA</h3>
   <p>Prazo permitido no formulário: 1, 2 ou 3 meses. Juro variável: uma parcela, com juros apurados na cobrança após a data efectiva de pagamento. Juro composto: prestações mensais. Juro fixo: uma parcela ou mensal. Nenhum cálculo é aplicado a valores financeiros reais nesta fase.</p>
   {checked&&<div role="status" className="cis-contract-notice">{message}</div>}
   {reviewError&&<div className="cis-table-error" role="alert">{reviewError}</div>}
   {previewError&&<div className="cis-table-error" role="alert">{previewError}</div>}
   {preview&&<div className="cis-contract-plan-preview"><h4>Pré-visualização das prestações (Contractos.frm)</h4>
    <p>Prévia sem gravação, gerada segundo os ramos FIXO / VARIÁVEL / COMPOSTO de cmb_adicionar_Click. A paridade monetária com o VBA ainda exige testes antes da produção.</p>
    <div className="cis-table-scroll"><table><thead><tr>{['Parcela','Vencimento','Capital (MT)','Amortização (MT)','Juros (MT)','A pagar (MT)','Estado'].map(label=><th key={label}>{label}</th>)}</tr></thead><tbody>{preview.linhas.map(row=><tr key={row.parcela}>
     <td>{row.parcela}</td><td>{row.vencimento.split('-').reverse().join('/')}</td>{[row.capital,row.amortizacao,row.juros,row.valorPagar].map((amount,index)=><td key={index}>{new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}).format(amount)}</td>)}<td>{row.estado}</td>
    </tr>)}</tbody></table></div><p>Total previsto: {new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}).format(preview.totalPagar)} MT · Juros: {new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}).format(preview.totalJuros)} MT.
    {tipo==='Variável'?' Os juros variáveis são calculados na Cobrança quando for conhecida a data efectiva de pagamento.':''}</p>
   </div>}
   {review&&<div className="cis-contract-review" role="status"><strong>{review.dados_verificados?'Cadastro e tabela de preços verificados':'Verificação encontrou pendências'}</strong><p>Mutuário: {review.cliente.nome} · ID {review.cliente.id}. {review.taxa_tabela!==null?`Taxa original confirmada: ${review.taxa_tabela}%.`:''}</p>
    {review.problemas.length>0&&<div><strong>Incompatibilidades</strong><ul>{review.problemas.map(problem=><li key={problem}>{problem}</li>)}</ul></div>}
    <div><strong>Requisitos para activar a abertura real</strong><ul>{review.pendencias.map(item=><li key={item}>{item}</li>)}</ul></div>
    <p><strong>Abertura e desembolso: não autorizados.</strong> Esta verificação não grava contratos, prestações nem extractos.</p></div>}
   <div className="cis-edit-actions"><button type="reset"><RotateCcw size={16}/> Limpar formulário</button><button type="submit" disabled={reviewBusy}>{reviewBusy?"A validar no servidor…":"Validar abertura no servidor"}</button></div>
  </section></form>
 </section>;
}
