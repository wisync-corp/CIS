import {useCan} from './perms';
import {useEffect,useState,type FormEvent} from 'react';
import {ArrowLeft,FilePlus2,LockKeyhole,CalendarDays,Wallet,UserRound,Info,RotateCcw,BadgePlus,ShieldCheck} from 'lucide-react';
import {previewContractVba,type ContractPreview} from './contractVbaPreview';

type Field={key:string;label:string;type?:'date'|'number'|'select';choices?:string[];required?:boolean;hint?:string};
type Borrower={ID:number;'Numeração':string|number|null;'Nome Completo':string;Cidade:string|null};
type InterestQuote={found:boolean;taxa?:string|number;periodo_dias?:number;limite_inferior?:number;limite_superior?:number;message?:string};
type OperationalStatus={abertura_desembolso_prontos:boolean;cobranca_pronta:boolean;semanal_pronto:boolean;formulas_adicionais_prontas:boolean;nota:string};
type OpeningReview={taxa_operacao?:string;cadastro_confirmado:boolean;cliente:{id:number;numero:string|number|null;nome:string};taxa_tabela:string|null;problemas:string[];dados_verificados:boolean;autorizado_para_gravar:boolean;pendencias:string[]};
const API=import.meta.env.VITE_API_URL||'/api';
function todayMaputo():string{
 const parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Africa/Maputo',
  year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
 const part=(name:string)=>parts.find(p=>p.type===name)?.value??'';
 return part('year')+'-'+part('month')+'-'+part('day');
}
function allowedPaymentMethods(term:string):string[]{
 if(term==='1 Semana')return ['Uma Parcela'];
 if(term==='2 Semanas'||term==='3 Semanas')return ['Semanal','Uma Parcela'];
 if(term==='1 Mês')return ['Semanal','Uma Parcela'];
 if(term==='2 Meses'||term==='3 Meses')return ['Semanal','Mensal','Uma Parcela'];
 return [];
}
const calculationStrategies=[
 'Amortização fixa com juro decrescente',
 'Amortização fixa com juro fixo',
 'Prestação fixa pelo total',
 'Prestação fixa simples',
 'Tabela Price',
 'Juro variável na cobrança',
] as const;
const strategyOptionsByInterest:Record<string,string[]>={
 Fixo:['Amortização fixa com juro fixo','Prestação fixa pelo total','Prestação fixa simples'],
 Composto:['Amortização fixa com juro decrescente','Tabela Price'],
 Variável:['Juro variável na cobrança'],
};
const defaultStrategyByInterest:Record<string,string>={
 Fixo:'Amortização fixa com juro fixo',
 Composto:'Tabela Price',
 Variável:'Juro variável na cobrança',
};
function monthAfter(date:string,months:number):string {
 if(!/^\d{4}-\d{2}-\d{2}$/.test(date))return '';
 const [year,month,day]=date.split('-').map(Number);
 const target=new Date(Date.UTC(year,month-1+months,1));
 const max=new Date(Date.UTC(target.getUTCFullYear(),target.getUTCMonth()+1,0)).getUTCDate();
 target.setUTCDate(Math.min(day,max));
 return target.toISOString().slice(0,10);
}
const fields:Field[]=[
 {key:'cliente',label:'Mutuário',required:true,hint:'Seleccionado exclusivamente a partir dos mutuários reais existentes no MySQL.'},
 {key:'cliente_id',label:'ID do mutuário',required:true,hint:'ID real obtido automaticamente após a selecção do mutuário.'},
 {key:'numero',label:'Número do contrato',hint:'Número provisório calculado pelo maior Nº da tabela Contractos. Será confirmado na gravação e pode mudar se outro contrato for criado primeiro.'},
 {key:'cidade',label:'Cidade',required:true},
 {key:'data_inicio',label:'Data de início / desembolso',type:'date',required:true,hint:'Data em que o dinheiro saiu da conta. O saldo considerado é o da conta nesse dia.'},
 {key:'data_final',label:'Data final prevista',type:'date',hint:'Calculada a partir da data de início e do prazo após a homologação.'},
 {key:'quantia',label:'Capital a desembolsar (MT)',type:'number',required:true,hint:'Introduza meticais inteiros. Juros e prestações serão arredondados sempre para cima.'},
 {key:'conta_saida',label:'Conta / Caixa de saída do desembolso',required:true,hint:'O capital sai desta conta da Caixa; o contrato e o lançamento de Saída ficam associados a ela.'},
 {key:'saldo_disponivel',label:'Saldo disponível para desembolso (MT)',type:'number',hint:'Saldo da conta seleccionada. É revalidado ao gravar.'},
 {key:'tipo_juro',label:'Tipo de juro',type:'select',choices:['Fixo','Variável','Composto'],required:true},
 {key:'estrategia_calculo',label:'Sistema de amortização',type:'select',required:true,hint:'A estratégia depende do tipo de juro. Três fórmulas adicionais são regras CIS. A sua gravação exige testes e activação específica no servidor.'},
 {key:'periodo_tabela',label:'Período da tabela de juros (dias)',type:'select',choices:['15','30'],required:true,hint:'15 dias: 1 ou 2 semanas. 30 dias: 1, 2 ou 3 semanas e 1, 2 ou 3 meses.'},
 {key:'juros',label:'Taxa de juro (%)',type:'number',required:true,hint:'Para juro Fixo, consultada automaticamente na tabela de preços pelas condições indicadas.'},
 {key:'multa',label:'Taxa de juros de mora (%)',type:'number',required:true},
 {key:'prazo',label:'Prazo',type:'select',required:true,hint:'As opções mudam automaticamente conforme o período seleccionado.'},
 {key:'modalidade',label:'Periodicidade',type:'select',required:true,hint:'Conforme o prazo: semanal, mensal (apenas 2 ou 3 meses) ou prestação única.'},
 {key:'forma',label:'Forma de pagamento',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Corresponde às três opções originais do VBA, com ortografia corrigida na interface.'},
 {key:'disponibilidade',label:'Meio de disponibilização do crédito',type:'select',choices:['Transferência/Depósito Bancário','Transferência Móvel','Numerário'],required:true,hint:'Forma de desembolso; o saldo e o Extracto exigem reconciliação antes de qualquer gravação.'},
];
const groupings=[
 {name:'Mutuário',icon:UserRound,keys:['cliente','cliente_id','cidade']},
 {name:'Contrato e condições de crédito',icon:Wallet,keys:['numero','quantia','conta_saida','saldo_disponivel','tipo_juro','periodo_tabela','juros','multa','prazo','modalidade','data_inicio','data_final','forma','disponibilidade']},
 {name:'Sistema de amortização',icon:Wallet,keys:['estrategia_calculo']},
];
type ApprovedProposal={id:number;numero:string;mutuario:{id:number;nome:string};montante:number;montante_aprovado:number|null;tipo_juro:string;estrategia:string;periodo_dias:number;prazo:string;modalidade:string;taxa_aprovada:number|null;valida_ate:string|null;decidido_por:string|null};
export default function ContractDraft({onBack,onContractCreated,token,proposalId}:{onBack:()=>void;onContractCreated:(numero:string)=>void;token:string;proposalId?:number|null}){
 const can=useCan();
 const [values,setValues]=useState<Record<string,string>>(()=>({data_inicio:todayMaputo()}));
 const [clientSearch,setClientSearch]=useState('');
 const [clients,setClients]=useState<Borrower[]>([]);
 const [selectedBorrower,setSelectedBorrower]=useState<Borrower|null>(null);
 // Propostas aprovadas: só se abre contrato a partir de uma delas (quando instaladas).
 const [proposals,setProposals]=useState<ApprovedProposal[]|null>(null);
 const [proposalsInstalled,setProposalsInstalled]=useState(false);
 const [proposal,setProposal]=useState<ApprovedProposal|null>(null);
 const [clientError,setClientError]=useState('');
 const [clientLoading,setClientLoading]=useState(false);
 const [suggestionsOpen,setSuggestionsOpen]=useState(false);
 const [activeSuggestion,setActiveSuggestion]=useState(0);
 const [cashBalance,setCashBalance]=useState<string>('');
 const [cashError,setCashError]=useState('');
 const [operational,setOperational]=useState<OperationalStatus|null>(null);
 const [suggestedNumber,setSuggestedNumber]=useState('');
 const [numberError,setNumberError]=useState('');
 const [numberLoading,setNumberLoading]=useState(true);
 const [quote,setQuote]=useState<InterestQuote|null>(null);
 const [quoteKey,setQuoteKey]=useState('');
 const [quoteLoading,setQuoteLoading]=useState(false);
 const [quoteError,setQuoteError]=useState('');
 useEffect(()=>{
  if(selectedBorrower && clientSearch===selectedBorrower['Nome Completo'])return;
  if(!clientSearch.trim()){setClients([]);setClientLoading(false);return;}
  const controller=new AbortController();
  const timer=window.setTimeout(()=>{
   setClientLoading(true);setClientError('');
   const params=new URLSearchParams({q:clientSearch.trim()});
   fetch(API+'/cis/contratos/clientes?'+params.toString(),{
    headers:{Authorization:'Bearer '+token,Accept:'application/json'},
    signal:controller.signal
   }).then(async result=>{
    if(!result.ok)throw new Error('Não foi possível consultar os mutuários autorizados.');
    return result.json() as Promise<Borrower[]>;
   }).then(list=>{if(!controller.signal.aborted){setClients(list);setActiveSuggestion(0);}})
   .catch(e=>{if(e.name!=='AbortError')setClientError(e.message);})
   .finally(()=>{if(!controller.signal.aborted)setClientLoading(false);});
  },300);
  return ()=>{controller.abort();window.clearTimeout(timer);};
 },[clientSearch,token,selectedBorrower]);
 useEffect(()=>{
  const controller=new AbortController();
  fetch(API+'/cis/contractos/saldo',{headers:{
   Authorization:'Bearer '+token,Accept:'application/json'
  },signal:controller.signal}).then(async res=>{
   if(!res.ok)throw new Error('Saldo ainda não disponível para consulta.');
   return res.json() as Promise<{saldo_disponivel:number}>;
  }).then(data=>{if(!controller.signal.aborted)setCashBalance(String(data.saldo_disponivel));})
   .catch(e=>{if(e.name!=='AbortError')setCashError(e.message);});
  return ()=>controller.abort();
 },[token]);
 useEffect(()=>{
  const controller=new AbortController();
  setNumberLoading(true);setNumberError('');
  fetch(API+'/cis/contractos/proximo-numero',{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},
   signal:controller.signal
  }).then(async res=>{
   if(!res.ok)throw new Error('Não foi possível consultar a numeração actual.');
   return res.json() as Promise<{numero_sugerido:string;provisorio:boolean}>;
  }).then(data=>{
   if(!controller.signal.aborted){
    if(!/^(C-\d{4,}|[A-Z0-9]{1,10}-\d{4}-\d{3,})$/.test(data.numero_sugerido))throw new Error('Número recebido inválido.');
    setSuggestedNumber(data.numero_sugerido);
   }
  }).catch(error=>{
   if(error.name!=='AbortError'&&!controller.signal.aborted)
    setNumberError(error instanceof Error?error.message:'Falha na consulta da numeração.');
  }).finally(()=>{if(!controller.signal.aborted)setNumberLoading(false);});
  return ()=>controller.abort();
 },[token]);
 useEffect(()=>{
  const controller=new AbortController();
  fetch(API+'/cis/contractos/estado-operacional',{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},
   signal:controller.signal,
  }).then(async res=>{
   if(!res.ok)throw new Error('Estado operacional ainda não disponível.');
   return res.json() as Promise<OperationalStatus>;
  }).then(data=>{if(!controller.signal.aborted)setOperational(data);})
   .catch(()=>{if(!controller.signal.aborted)setOperational(null);});
  return ()=>controller.abort();
 },[token]);
 useEffect(()=>{const c=new AbortController();
  fetch(API+'/cis/propostas/aprovadas',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(!j||c.signal.aborted)return;setProposalsInstalled(!!j.instalado);setProposals(j.data??[]);
    if(proposalId){const p=(j.data??[]).find((x:ApprovedProposal)=>x.id===proposalId);if(p)applyProposal(p);}}).catch(()=>{});
  return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token,proposalId]);
 function applyProposal(p:ApprovedProposal|null){
  setProposal(p);
  if(!p)return;
  setSelectedBorrower({ID:p.mutuario.id,'Numeração':null,'Nome Completo':p.mutuario.nome,Cidade:null});
  setClientSearch(p.mutuario.nome);setSuggestionsOpen(false);setClientError('');
  setValues(v=>({...v,cliente:p.mutuario.nome,cliente_id:String(p.mutuario.id),quantia:String(p.montante_aprovado??p.montante),tipo_juro:p.tipo_juro,
   estrategia_calculo:p.estrategia,periodo_tabela:String(p.periodo_dias),prazo:p.prazo,modalidade:p.modalidade,...(p.taxa_aprovada!=null?{juros:String(p.taxa_aprovada)}:{})}));
  fetch(API+'/cis/clientes/'+p.mutuario.id,{headers:{Authorization:'Bearer '+token,Accept:'application/json'}}).then(r=>r.ok?r.json():null)
   .then(c=>{const cid=c?.Cidade??c?.data?.Cidade;if(cid)setValues(v=>({...v,cidade:String(cid)}));}).catch(()=>{});
 }
 const lockedByProposal=(key:string)=>!!proposal&&['cliente','tipo_juro','estrategia_calculo','periodo_tabela','prazo','modalidade'].includes(key)||(!!proposal&&key==='juros'&&proposal.taxa_aprovada!=null);
 const chooseBorrower=(borrower:Borrower)=>{
  setSelectedBorrower(borrower);
  setClientSearch(borrower['Nome Completo']);
  setSuggestionsOpen(false);
  setClientError('');
  setValues(v=>({...v,cliente:borrower['Nome Completo'],cliente_id:String(borrower.ID),cidade:borrower.Cidade??''}));
 };
 const matchingClients=clients.slice(0,12);
 const fixedRate=values.tipo_juro==='Fixo';
 const strategiesForType=strategyOptionsByInterest[values.tipo_juro??'']??[];
 const strategySelected=values.estrategia_calculo??'';
 const strategyCompatible=strategiesForType.includes(strategySelected);
 const periodo=values.periodo_tabela??'';
 const availableTerms=periodo==='15'?['1 Semana','2 Semanas']:periodo==='30'?['1 Semana','2 Semanas','3 Semanas','1 Mês','2 Meses','3 Meses']:[];
 const selectedTerm=availableTerms.includes(values.prazo??'')?values.prazo??'':'';
 const weeklyTerm=selectedTerm.includes('Semana');
 const availableModalities=allowedPaymentMethods(selectedTerm);
 const selectedModality=availableModalities.includes(values.modalidade??'')?values.modalidade??'':'';
 const unsupportedSchedule=weeklyTerm||selectedModality==='Semanal';
 const currentQuoteKey=values.quantia+'|'+periodo;
 const validAmount=values.quantia!==''&&Number.isSafeInteger(Number(values.quantia))&&Number(values.quantia)>0&&/^\d+$/.test(values.quantia??'');
 const priced= fixedRate&&quoteKey===currentQuoteKey&&quote?.found===true;
 useEffect(()=>{
  setQuote(null);setQuoteKey('');setQuoteError('');
  if(!fixedRate || !validAmount || !['15','30'].includes(periodo) || !selectedTerm){
   setQuoteLoading(false);return;
  }
  const key=currentQuoteKey;
  const controller=new AbortController();
  const timer=window.setTimeout(()=>{
   setQuoteLoading(true);
   fetch(API+'/admin/interest-quote?'+new URLSearchParams({
    montante:values.quantia,periodo_dias:periodo
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
 },[fixedRate,validAmount,values.quantia,periodo,selectedTerm,token,currentQuoteKey]);
 const [checked,setChecked]=useState(false);
 const [message,setMessage]=useState('');
 const [review,setReview]=useState<OpeningReview|null>(null);
 const [reviewBusy,setReviewBusy]=useState(false);
 const [reviewError,setReviewError]=useState('');
 const [preview,setPreview]=useState<ContractPreview|null>(null);
 const [previewError,setPreviewError]=useState('');
 const [confirmedDisbursement,setConfirmedDisbursement]=useState(false);
 const [openingBusy,setOpeningBusy]=useState(false);
 const [openedContract,setOpenedContract]=useState<{numero:string;prestacoes:number;desembolso:string|number}|null>(null);
 const [openingError,setOpeningError]=useState('');
 const [cashAccounts,setCashAccounts]=useState<{id:number;code:string;name:string;status:string;saldo_disponivel?:string|null}[]>([]);
 const [sourceAccount,setSourceAccount]=useState('');
 const [accountsLoaded,setAccountsLoaded]=useState(false);
 const [dayBalance,setDayBalance]=useState<{data:string;hoje:boolean;pode_data_passada:boolean;saldo_no_dia:string|null;disponivel:string|null;problema:string|null}|null>(null);
 const [disbursementRef,setDisbursementRef]=useState('');
 const [openingKey,setOpeningKey]=useState('');
 useEffect(()=>{
  const ctrl=new AbortController();
  fetch(API+'/cis/caixa/contas',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(r=>r.ok?r.json():null).then(d=>{if(ctrl.signal.aborted)return;const act=(d?.accounts??[]).filter((a:{status:string})=>a.status==='activa');setCashAccounts(act);setAccountsLoaded(true);if(act.length===1)setSourceAccount(String(act[0].id));}).catch(()=>{});
  return()=>ctrl.abort();
 },[token]);
 const chosenAccount=cashAccounts.find(a=>String(a.id)===sourceAccount);
 // Saldo da conta na data do desembolso (data passada: menor saldo desse dia em diante).
 useEffect(()=>{
  setDayBalance(null);
  if(!sourceAccount||!/^\d{4}-\d{2}-\d{2}$/.test(values.data_inicio??''))return;
  const ctrl=new AbortController();
  const t=window.setTimeout(()=>{fetch(API+'/cis/contractos/saldo-conta?conta_id='+encodeURIComponent(sourceAccount)+'&data='+encodeURIComponent(values.data_inicio),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(r=>r.ok?r.json():null).then(d=>{if(d&&!ctrl.signal.aborted)setDayBalance(d);}).catch(()=>{});},250);
  return()=>{ctrl.abort();window.clearTimeout(t);};
 },[sourceAccount,values.data_inicio,token]);
 const availableCash=dayBalance&&dayBalance.disponivel!==null?Number(dayBalance.disponivel):chosenAccount?Number(chosenAccount.saldo_disponivel??0):0;
 const accountCovers=!!chosenAccount&&!dayBalance?.problema&&availableCash>=Number(values.quantia||0);
 const [openingRetryAt,setOpeningRetryAt]=useState(0);
 const [openingClock,setOpeningClock]=useState(Date.now());
 useEffect(()=>{
  if(openingRetryAt<=Date.now())return;
  const timer=window.setInterval(()=>setOpeningClock(Date.now()),1000);
  return ()=>window.clearInterval(timer);
 },[openingRetryAt]);
 const openingCooldownSeconds=Math.max(0,Math.ceil((openingRetryAt-openingClock)/1000));

 useEffect(()=>{setReview(null);setChecked(false);setMessage('');setPreview(null);setPreviewError('');setConfirmedDisbursement(false);setOpeningError('');},[values,selectedBorrower,quoteKey,sourceAccount]);
 const tipo=values.tipo_juro??'';
 const modalidade=selectedModality;
 const prazoMeses=selectedTerm.includes('Mês')||selectedTerm.includes('Meses')?Number(selectedTerm.slice(0,1)):0;
 const prazoSemanas=weeklyTerm?Number(selectedTerm.slice(0,1)):0;
 const addWeeks=(date:string,weeks:number)=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(date))return '';const [y,m,d]=date.split('-').map(Number);const t=new Date(Date.UTC(y,m-1,d+7*weeks));return t.toISOString().slice(0,10);};
 const dataFinal=values.data_inicio&&(prazoMeses||prazoSemanas)?(weeklyTerm?addWeeks(values.data_inicio,prazoSemanas):monthAfter(values.data_inicio,prazoMeses)):'';
 const daysDuration=values.data_inicio&&dataFinal?Math.round((Date.parse(dataFinal+'T00:00:00Z')-Date.parse(values.data_inicio+'T00:00:00Z'))/86400000):0;
 const numeroPrestacoes=!selectedTerm||!tipo||!modalidade?null:tipo==='Variável'||modalidade==='Uma Parcela'?1:modalidade==='Semanal'?Math.ceil(daysDuration/7):prazoMeses;
 const dates=numeroPrestacoes&&values.data_inicio?Array.from({length:numeroPrestacoes},(_,index)=>
  index+1===numeroPrestacoes?dataFinal:modalidade==='Semanal'?addWeeks(values.data_inicio,index+1):monthAfter(values.data_inicio,index+1)):[];
 const handle=async(event:FormEvent)=>{
  event.preventDefault();
  setReview(null);setReviewError('');setMessage('');setChecked(false);
  if(fixedRate&&!priced){setQuoteError('Não existe taxa parametrizada válida para este montante e período.');return;}
  if(!validAmount){setReviewError('O montante do crédito deve ser um número inteiro de meticais.');return;}
  if(!selectedBorrower){setClientError('Seleccione um mutuário existente no cadastro.');return;}
  if(proposalsInstalled&&!proposal){setReviewError('Seleccione a proposta de crédito aprovada: só se abrem contratos a partir de propostas aprovadas.');return;}
  if(proposal&&Number(values.quantia)>(proposal.montante_aprovado??proposal.montante)){setReviewError('O capital não pode exceder o aprovado na proposta.');return;}
  if(!chosenAccount){setReviewError(cashAccounts.length?'Seleccione a conta / caixa de onde sai o desembolso.':'Não há contas activas na Caixa. Crie e valide uma conta em Caixa antes de abrir contratos.');document.getElementById('cis-conta-saida')?.focus();return;}
  setReviewBusy(true);
  try{
   const result=await fetch(API+'/cis/contractos/validar-abertura',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({
     cliente_id:selectedBorrower.ID,montante:values.quantia,tipo_juro:tipo,
     estrategia_calculo:strategySelected,
     taxa_informada:fixedRate?Number(quote?.taxa):Number(values.juros),
     taxa_multa:Number(values.multa),prazo_meses:prazoMeses,
     periodo_dias:Number(periodo),prazo:selectedTerm,
     modalidade,forma_pagamento:values.forma,disponibilidade:values.disponibilidade,
     data_inicio:values.data_inicio,conta_id:sourceAccount?Number(sourceAccount):null,proposta_id:proposal?.id??null
    })
   });
   if(!result.ok){
    if(result.status===422)throw new Error('Existem dados inválidos. Verifique os campos preenchidos.');
    throw new Error(result.status===503?'A validação privada ainda não foi activada.':'Não foi possível validar os dados no servidor.');
   }
   const body=await result.json() as OpeningReview;
   setReview(body);setChecked(true);
   if(body.dados_verificados)window.setTimeout(()=>document.getElementById('cis-contract-disburse')?.scrollIntoView({behavior:'smooth',block:'center'}),150);
   if(body.cadastro_confirmado&&strategyCompatible&&!(tipo==='Composto'&&modalidade==='Semanal')){
    try{setPreview(previewContractVba({montante:Number(values.quantia),taxa:Number(fixedRate?quote?.taxa:values.juros),tipo:tipo as 'Fixo'|'Variável'|'Composto',modalidade:modalidade as 'Mensal'|'Semanal'|'Uma Parcela',prazoMeses,dataInicio:values.data_inicio,estrategia:strategySelected as import('./contractVbaPreview').Strategy,prazo:selectedTerm,periodoDias:Number(periodo)}));}
    catch(e){setPreviewError(e instanceof Error?e.message:'Não foi possível preparar o plano.');}
   }
   setMessage('Verificação concluída no Laravel, sem criar contrato ou movimento financeiro.');
  }catch(error){setReviewError(error instanceof Error?error.message:'Erro na validação.');}
  finally{setReviewBusy(false);}
 };
 const openContract=async()=>{
  if(!review?.dados_verificados||!review.autorizado_para_gravar||!strategyCompatible||!confirmedDisbursement||!selectedBorrower||openingBusy||openedContract)return;
  if(Date.now()<openingRetryAt){setOpeningError('Limite temporário de tentativas. Consulte primeiro a carteira para confirmar se o contrato já existe.');return;}
  if(!strategySelected.trim()){setOpeningError('Seleccione uma fórmula de cálculo antes de criar e desembolsar o crédito.');return;}
  if(!chosenAccount||disbursementRef.trim().length<4){setOpeningError('Seleccione a conta de saída e indique a referência do comprovativo.');return;}
  if(!window.confirm('Confirma que '+values.quantia+' MT saíram da conta '+chosenAccount.code+' em '+values.data_inicio.split('-').reverse().join('/')+' para o mutuário? O CIS vai criar o contrato, gravar as prestações e lançar a Saída nessa conta.'))return;
  const key=openingKey||(typeof crypto!=='undefined'&&'randomUUID' in crypto?crypto.randomUUID():String(Date.now()));
  setOpeningKey(key);
  setOpeningBusy(true);setOpeningError('');
  try{
   const res=await fetch(API+'/cis/contractos/abrir',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({
     cliente_id:selectedBorrower.ID,montante:values.quantia,tipo_juro:tipo,
     estrategia_calculo:strategySelected,
     taxa_informada:fixedRate?Number(quote?.taxa):Number(values.juros),
     taxa_multa:Number(values.multa),prazo_meses:prazoMeses,
     periodo_dias:Number(periodo),prazo:selectedTerm,
     modalidade,forma_pagamento:values.forma,disponibilidade:values.disponibilidade,
     data_inicio:values.data_inicio,confirmacao_desembolso:true,proposta_id:proposal?.id??null,
     conta_id:Number(sourceAccount),referencia:disbursementRef.trim(),idempotency_key:key
    })
   });
   if(res.status===429){
    const retryHeader=res.headers.get('Retry-After');
    const retryNumber=retryHeader?Number(retryHeader):NaN;
    const retryDate=retryHeader?Date.parse(retryHeader):NaN;
    const delay=Number.isFinite(retryNumber)?retryNumber:Number.isFinite(retryDate)?(retryDate-Date.now())/1000:60;
    const seconds=Math.min(300,Math.max(1,Math.ceil(delay)));
    setOpeningRetryAt(Date.now()+seconds*1000);
    setOpeningClock(Date.now());
    throw new Error('Limite de segurança atingido (429). Não volte a submeter por '+seconds+' segundos. Antes de tentar novamente, confirme na carteira e no Extracto se a operação já foi registada.');
   }
   const payload=await res.json().catch(()=>null) as {numero?:string;prestacoes?:number;desembolso?:string|number;message?:string;errors?:Record<string,string[]>}|null;
   if(!res.ok){
    const details=payload?.errors?Object.values(payload.errors).flat().join(' '):payload?.message;
    throw new Error(res.status===503?'A abertura real ainda não está activada no servidor.':res.status===409?(details||'Conflito: confirme se o contrato já foi criado antes de tentar novamente.'):(details||'O servidor rejeitou a abertura; nada deve ser presumido como gravado.'));
   }
   if(!payload?.numero||payload.prestacoes===undefined||payload.desembolso===undefined)throw new Error('Resposta inesperada: confirme a carteira antes de repetir.');
   setOpenedContract({numero:payload.numero,prestacoes:payload.prestacoes,desembolso:payload.desembolso});
   // A API confirmou a transacção: abrir imediatamente a ficha do contrato criado.
   onContractCreated(payload.numero);
  }catch(e){setOpeningError(e instanceof Error?e.message:'Erro na abertura. Verifique a carteira antes de repetir.');}
  finally{setOpeningBusy(false);}
 };
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos contratos</button><span>Contratos / Preparação de novo contrato</span></div>
  <header className="cis-profile-hero"><div className="cis-profile-avatar"><FilePlus2 size={29}/></div><div className="cis-profile-heading"><span>NOVO CONTRATO</span><h2>Preparar novo contrato</h2><p>Preencha os dados, valide e confirme o desembolso</p></div></header>
  {operational&&(()=>{const off=[!operational.abertura_desembolso_prontos&&'abertura e desembolso',!operational.semanal_pronto&&'prestações semanais',!operational.cobranca_pronta&&'cobrança',!operational.formulas_adicionais_prontas&&'fórmulas adicionais'].filter(Boolean) as string[];
   return off.length?<div className="cis-contract-notice" role="status"><LockKeyhole size={18}/><span><strong>Ainda não activado no servidor:</strong> {off.join(', ')}. Peça ao administrador para concluir a activação.</span></div>:null;})()}
  {proposalsInstalled&&<section className="cis-panel cis-contract-draft-section cis-prop-pick">
   <h3><ShieldCheck size={20}/>Proposta de crédito aprovada</h3>
   {proposals&&proposals.length===0?<p className="cis-msg-muted">Não há propostas aprovadas por desembolsar. Crie a proposta em «Propostas» e aguarde a aprovação.</p>:
   <label className="cis-profile-field"><span>Proposta *</span><select value={proposal?.id??''} onChange={e=>applyProposal(proposals?.find(p=>String(p.id)===e.target.value)??null)}>
    <option value="">Seleccionar proposta aprovada</option>
    {(proposals??[]).map(p=><option key={p.id} value={p.id}>{p.numero} · {p.mutuario.nome} · {(p.montante_aprovado??p.montante).toLocaleString('pt-MZ')} MT · {p.prazo}{p.valida_ate?' · válida até '+p.valida_ate.split('-').reverse().join('/'):''}</option>)}
   </select><small><Info size={12}/>{proposal?`Aprovada por ${proposal.decidido_por??'—'}: mutuário e condições ficam fixos; o capital pode ser igual ou inferior a ${(proposal.montante_aprovado??proposal.montante).toLocaleString('pt-MZ')} MT.`:'O mutuário, o montante e as condições vêm da proposta aprovada.'}</small></label>}
  </section>}
  <form className="cis-contract-draft" onSubmit={handle} onReset={()=>{setValues({data_inicio:todayMaputo()});setSelectedBorrower(null);setClientSearch('');setClients([]);setSuggestionsOpen(false);setActiveSuggestion(0);setQuote(null);setQuoteKey('');setQuoteError('');setChecked(false);setReview(null);setReviewError('');setPreview(null);setPreviewError('');setMessage('');}}>
  {groupings.map(({name,icon:Icon,keys})=><section className="cis-panel cis-contract-draft-section" key={name}>
   <h3><Icon size={20}/>{name}</h3><div className="cis-profile-fields">{keys.map(key=>{
    const field=fields.find(f=>f.key===key)!;
    const disabled=['numero','data_final','saldo_disponivel','cliente','cliente_id'].includes(key)||lockedByProposal(key)||(key==='data_inicio'&&!can('contratos.data'))||(key==='juros'&&fixedRate)||((key==='prazo'&&!periodo)||(key==='modalidade'&&!selectedTerm)||(key==='estrategia_calculo'&&!tipo));
    const val=key==='numero'?(openedContract?.numero??suggestedNumber):key==='modalidade'?selectedModality:key==='prazo'?selectedTerm:key==='saldo_disponivel'?(chosenAccount?String(availableCash):cashAccounts.length?'':cashBalance):key==='data_final'?dataFinal:key==='juros'&&fixedRate?(priced?String(Number(quote?.taxa)):''):values[key]??'';
    if(key==='cliente')return <div key={key} className="cis-profile-field cis-contract-client-picker">
     <label htmlFor="cis-client-search">Pesquisar mutuário existente *</label>
     <div className="cis-client-combobox">
      <input id="cis-client-search" type="search" role="combobox" aria-autocomplete="list"
       aria-expanded={suggestionsOpen&&clientSearch.trim().length>0}
       aria-controls="cis-client-suggestions"
       aria-activedescendant={suggestionsOpen&&matchingClients[activeSuggestion]?'cis-client-option-'+matchingClients[activeSuggestion].ID:undefined}
       placeholder="Digite nome ou ID do mutuário" disabled={!!proposal} value={clientSearch} autoComplete="off"
       onFocus={()=>{if(!selectedBorrower)setSuggestionsOpen(true);}}
       onBlur={()=>setSuggestionsOpen(false)}
       onChange={e=>{
        setClientSearch(e.target.value);
        setSelectedBorrower(null);
        setValues(v=>({...v,cliente:'',cliente_id:'',cidade:''}));
        setSuggestionsOpen(true);
        setActiveSuggestion(0);
       }}
       onKeyDown={e=>{
        if(e.key==='Escape'){setSuggestionsOpen(false);return;}
        if(e.key==='ArrowDown'){e.preventDefault();setSuggestionsOpen(true);setActiveSuggestion(i=>Math.min(i+1,matchingClients.length-1));return;}
        if(e.key==='ArrowUp'){e.preventDefault();setActiveSuggestion(i=>Math.max(0,i-1));return;}
        if(e.key==='Enter'&&suggestionsOpen&&matchingClients.length){e.preventDefault();chooseBorrower(matchingClients[activeSuggestion]??matchingClients[0]);}
       }}/>
      {suggestionsOpen&&clientSearch.trim().length>0&&<div className="cis-client-suggestion-list" role="listbox" id="cis-client-suggestions" aria-label="Mutuários correspondentes">
       {clientLoading?<div className="cis-client-suggestion-status" role="status">A pesquisar mutuários…</div>:
        matchingClients.length>0?matchingClients.map((borrower,index)=><button
         type="button" role="option" id={'cis-client-option-'+borrower.ID} key={borrower.ID}
         aria-selected={index===activeSuggestion}
         className={'cis-client-suggestion'+(index===activeSuggestion?' active':'')}
         onMouseDown={e=>e.preventDefault()}
         onClick={()=>chooseBorrower(borrower)}>
          <strong>{borrower['Nome Completo']}</strong>
          <small>ID {borrower.ID}{borrower.Cidade?' · '+borrower.Cidade:''}</small>
         </button>):
        <div className="cis-client-suggestion-status" role="status">Nenhum mutuário encontrado.</div>}
      </div>}
     </div>
     {selectedBorrower&&<small className="cis-client-confirmed">✓ Seleccionado: {selectedBorrower['Nome Completo']} · ID {selectedBorrower.ID}</small>}
     {!selectedBorrower&&clientSearch.trim().length>0&&<small>Escolha uma das sugestões para associar o mutuário ao contrato.</small>}
     {clientError&&<small role="alert">{clientError}</small>}
    </div>;
    if(key==='conta_saida')return <label key={key} className="cis-profile-field"><span>{field.label} *</span>
     <select id="cis-conta-saida" value={sourceAccount} required onChange={e=>{setSourceAccount(e.target.value);setOpeningKey('');}}>
      <option value="">{!accountsLoaded?'A carregar contas…':cashAccounts.length?'Seleccionar conta activa':'Sem contas activas'}</option>
      {cashAccounts.map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
     </select>
     <small><Info size={12}/>{!chosenAccount?field.hint:dayBalance?.problema?dayBalance.problema:values.quantia?(accountCovers?'Saldo suficiente '+(dayBalance&&!dayBalance.hoje?'nessa data':'')+' para o capital indicado.':'Saldo insuficiente nesta conta '+(dayBalance&&!dayBalance.hoje?'nessa data ':'')+'para o capital indicado.'):'Conta seleccionada.'}</small>
    </label>;
    return <label key={key} className={'cis-profile-field'+(key==='numero'?' cis-contract-number-row':'')}><span>{field.label}{field.required?' *':''}</span>
     {field.choices||key==='prazo'||key==='modalidade'||key==='estrategia_calculo'?<select value={val} disabled={disabled} required={!disabled&&field.required} onChange={e=>setValues(prev=>key==='periodo_tabela'?({...prev,periodo_tabela:e.target.value,prazo:'',modalidade:''}):key==='prazo'?({...prev,prazo:e.target.value,modalidade:''}):key==='tipo_juro'?({...prev,tipo_juro:e.target.value,estrategia_calculo:defaultStrategyByInterest[e.target.value]??''}):({...prev,[key]:e.target.value}))}>
       <option value="">Seleccionar</option>{(key==='prazo'?availableTerms:key==='modalidade'?availableModalities:key==='estrategia_calculo'?[...calculationStrategies]:field.choices??[]).map(choice=><option key={choice} value={choice} disabled={key==='estrategia_calculo'&&!strategiesForType.includes(choice)}>{key==='modalidade'&&choice==='Uma Parcela'?'Prestação única':choice}{key==='estrategia_calculo'&&!strategiesForType.includes(choice)?' — não aplicável a este juro':''}</option>)}
      </select>:<input type={field.type??'text'} disabled={disabled} value={val} required={!disabled&&field.required} min={field.type==='number'?0:undefined} max={key==='data_inicio'?todayMaputo():undefined} step={field.type==='number'?'1':undefined} placeholder={key==='numero'?(numberLoading?'A consultar próximo número…':'Número indisponível'):undefined} onChange={e=>setValues(prev=>({...prev,[key]:e.target.value}))}/>}
     {field.hint&&<small><Info size={12}/>{field.hint}</small>}
     {key==='numero'&&numberError&&<small role="alert">{numberError}</small>}
     {key==='estrategia_calculo'&&tipo&&<small role="status">{strategyCompatible?'Estratégia compatível. Os valores são calculados em MT inteiros e arredondados para cima; a última amortização reconcilia o capital.':'Escolha uma estratégia compatível com o tipo de juro.'}</small>}
     {key==='saldo_disponivel'&&dayBalance&&!dayBalance.hoje&&dayBalance.saldo_no_dia!==null&&<small role="status">Saldo da conta em {dayBalance.data.split('-').reverse().join('/')}: {Number(dayBalance.saldo_no_dia).toLocaleString('pt-MZ',{minimumFractionDigits:2})} MT{Number(dayBalance.disponivel)<Number(dayBalance.saldo_no_dia)?' · só '+Number(dayBalance.disponivel).toLocaleString('pt-MZ',{minimumFractionDigits:2})+' MT podem sair sem deixar a conta negativa nos dias seguintes':''}.</small>}
     {key==='data_inicio'&&!can('contratos.data')&&<small role="status">O seu perfil regista desembolsos só com a data de hoje.</small>}
     {key==='saldo_disponivel'&&cashError&&<small role="status">{cashError}</small>}
     {key==='juros'&&fixedRate&&<small role="status" className={priced?'cis-pricing-success':'cis-pricing-pending'}>{quoteLoading?'A consultar a tabela…':priced?`Taxa encontrada: ${Number(quote?.taxa)}% · faixa ${quote?.limite_inferior}–${quote?.limite_superior} MT · ${quote?.periodo_dias} dias`:(quoteError||quote?.message||'Seleccione o período (15 ou 30 dias), o prazo e o montante para consultar a taxa.')}</small>}
    </label>;
   })}</div>
  </section>)}
  <section className="cis-panel cis-contract-draft-section"><h3><CalendarDays size={20}/> Plano de pagamentos · preparação</h3>
   <div className="cis-contract-draft-count"><span>{weeklyTerm?'N.º de prestações semanais (pendente de regra)':'N.º previsto de prestações (regra do VBA)'}</span><strong>{numeroPrestacoes??'—'}</strong></div>
   <p>{unsupportedSchedule?'Prestações semanais de 7 em 7 dias; no prazo mensal o último vencimento coincide com a data final. A gravação requer aprovação separada dos cálculos semanais.':'Para os prazos mensais, as datas seguem DateAdd do VBA (adicionar meses ao início). O plano financeiro indicativo surge depois de validar os dados.'} Nenhuma operação é gravada nesta etapa.</p>
   {unsupportedSchedule&&dataFinal&&<p><strong>Data final:</strong> {dataFinal.split('-').reverse().join('/')} · período semanal sujeito à activação autorizada no VPS.</p>}
   {dates.length>0&&<div className="cis-contract-date-preview">{dates.map((date,i)=><div key={i}><strong>Prestação {i+1}</strong><time dateTime={date}>{date.split('-').reverse().join('/')}</time></div>)}</div>}
  </section>
  <section className="cis-panel cis-contract-draft-section"><h3><BadgePlus size={20}/> Criar e desembolsar crédito</h3>
   <p>Esta página destina-se exclusivamente à abertura de um novo contrato. As prestações reais só existem depois da abertura confirmada.</p>
   <div className="cis-contract-notice"><LockKeyhole size={17}/><span>Para aumentar crédito ou consultar cobranças, abra a ficha de um contrato já existente e seleccione a prestação correspondente. A abertura real é verificada e protegida contra valores não suportados pelo esquema legado.</span></div>
  </section>
  <section className="cis-panel cis-contract-draft-section" id="cis-contract-final-review"><h3><CalendarDays size={20}/> Regras de prazo e prestações do VBA</h3>
   <p>A data de início é preenchida automaticamente com o dia actual em Moçambique, mas pode ser alterada. Uma semana admite prestação única; duas ou três semanas admitem periodicidade semanal ou prestação única; um mês admite semanal ou prestação única; dois ou três meses admitem semanal, mensal ou prestação única. O calendário semanal está implementado, mas a gravação depende dos testes e da activação segura. Juros compostos semanais ainda aguardam definição da taxa equivalente.</p>
   {checked&&<div role="status" className="cis-contract-notice">{message}</div>}
   {reviewError&&<div className="cis-table-error" role="alert">{reviewError}</div>}
   {previewError&&<div className="cis-table-error" role="alert">{previewError}</div>}
   {preview&&<div className="cis-contract-plan-preview"><h4>Pré-visualização das prestações · estratégia seleccionada</h4>
    <p>Prévia sem gravação, segundo a estratégia seleccionada. Regra CIS: valores em MT inteiros com arredondamento para cima e capital totalmente reconciliado. As fórmulas novas são extensões ao VBA original.</p>
    <div className="cis-table-scroll"><table><thead><tr>{['Prestação','Vencimento','Capital (MT)','Amortização (MT)','Juros (MT)','Valor da prestação (MT)','Estado'].map(label=><th key={label}>{label}</th>)}</tr></thead><tbody>{preview.linhas.map(row=><tr key={row.parcela}>
     <td>{row.parcela}</td><td>{row.vencimento.split('-').reverse().join('/')}</td>{[row.capital,row.amortizacao,row.juros,row.valorPagar].map((amount,index)=><td key={index}>{new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:0}).format(amount)}</td>)}<td>{row.estado}</td>
    </tr>)}</tbody></table></div><p>Total previsto: {new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:0}).format(preview.totalPagar)} MT · Juros: {new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:0}).format(preview.totalJuros)} MT.
    {tipo==='Variável'?' Os juros variáveis são calculados na Cobrança quando for conhecida a data efectiva de pagamento.':''}</p>
   </div>}
   {review&&<div className="cis-contract-review" role="status"><strong>{review.dados_verificados?'Cadastro e tabela de preços verificados':'Verificação encontrou pendências'}</strong><p>Mutuário: {review.cliente.nome} · ID {review.cliente.id}. {review.taxa_tabela!==null?`Taxa original confirmada: ${review.taxa_tabela}%.`:''}{Number(review.taxa_operacao??0)>0?` Taxa de operação: ${Number(review.taxa_operacao).toLocaleString('pt-PT')} MT, descontada no desembolso — o mutuário recebe ${(Number(values.quantia)-Number(review.taxa_operacao)).toLocaleString('pt-PT')} MT; a taxa entra na mesma conta como receita.`:''}</p>
    {review.problemas.length>0&&<div><strong>Incompatibilidades</strong><ul>{review.problemas.map(problem=><li key={problem}>{problem}</li>)}</ul></div>}
    <div><strong>Requisitos para activar a abertura real</strong><ul>{review.pendencias.map(item=><li key={item}>{item}</li>)}</ul></div>
    <p><strong>{review.autorizado_para_gravar?'Abertura disponível mediante confirmação expressa':'Abertura ainda não activada'}.</strong> A validação isolada não grava contratos, prestações nem extractos.</p></div>}
   {openingError&&<div className="cis-table-error" role="alert">{openingError}</div>}
   {openedContract?<div className="cis-contract-review" role="status">
    <strong>Contrato criado e desembolso registado</strong>
    <p>Contrato <strong>{openedContract.numero}</strong> · {openedContract.prestacoes} prestação(ões) · desembolso de {Number(openedContract.desembolso).toLocaleString('pt-MZ')} MT{chosenAccount?' a partir da conta '+chosenAccount.code+' · '+chosenAccount.name:''}.</p>
    <p>Não submeta novamente. Consulte o contrato na carteira.</p>
    <button type="button" className="cis-contract-draft-button" onClick={onBack}>Voltar à carteira de contratos</button>
   </div>:review?.dados_verificados&&<div className="cis-contract-review cis-contract-disburse" id="cis-contract-disburse">
    <strong>Desembolsar o empréstimo</strong>
    {review.autorizado_para_gravar?<><label className="cis-contract-confirm"><input type="checkbox" checked={confirmedDisbursement} onChange={e=>setConfirmedDisbursement(e.target.checked)}/>
     <span>Confirmo que o montante indicado foi efectivamente disponibilizado ao mutuário e autorizo o lançamento da saída no Extracto.</span></label>
     <div className="cis-contract-cash-source">
      <div className="cis-contract-chosen-account"><span>Conta / Caixa de saída</span><strong>{chosenAccount?chosenAccount.code+' · '+chosenAccount.name:'— seleccione no formulário acima'}</strong>
       <small>{!chosenAccount?'Escolha a conta no grupo «Contrato e condições de crédito».':accountCovers?'Saldo '+Number(chosenAccount.saldo_disponivel??0).toLocaleString('pt-MZ',{minimumFractionDigits:2})+' MT · suficiente.':'Saldo insuficiente nesta conta para o montante pedido.'}</small></div>
      <label>Referência do comprovativo <input type="text" maxLength={60} value={disbursementRef} onChange={e=>setDisbursementRef(e.target.value)} placeholder="Recibo, transferência ou M-Pesa"/><small>Fica gravada no Extracto e no histórico.</small></label>
     </div>
     <button type="button" className="cis-contract-draft-button" disabled={!confirmedDisbursement||!chosenAccount||!accountCovers||disbursementRef.trim().length<4||openingBusy||openingCooldownSeconds>0} onClick={()=>void openContract()}>{openingBusy?'A gravar contrato e desembolso…':openingCooldownSeconds>0?'Aguarde '+openingCooldownSeconds+' s (limite de segurança)':'Criar contrato e registar desembolso'}</button>
    </>:<p>{review.pendencias[review.pendencias.length-1]??'Abertura com desembolso ainda não activada neste servidor.'}</p>}
   </div>}
   <div className="cis-edit-actions"><button type="reset" disabled={openingBusy||!!openedContract}><RotateCcw size={16}/> Limpar formulário</button><button type="submit" disabled={reviewBusy||openingBusy||!!openedContract}>{reviewBusy?"A validar no servidor…":"Validar abertura no servidor"}</button></div>
  </section></form>
 </section>;
}
