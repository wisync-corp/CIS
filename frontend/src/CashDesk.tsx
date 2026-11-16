import {useCallback,useEffect,useMemo,useState,type FormEvent} from 'react';
import {Landmark,Wallet,ArrowDownLeft,ArrowUpRight,ArrowLeftRight,RefreshCw,ShieldCheck,AlertCircle,CheckCircle2,Plus,Building2,Smartphone,Layers,Hash,BookOpen,LockKeyhole,Clock3,Search,Filter,ArrowRight,Eye,Info,X,ClipboardCheck,ChevronRight} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type CashAccount={
 id:number;code:string;name:string;type:'banco'|'numerario'|'carteira_movel';identifier:string|null;
 currency:string;status:'em_preparacao'|'pendente_validacao'|'activa'|'suspensa';cut_at:string|null;
 opening_balance:string|null;evidence_reference:string|null;saldo_disponivel:string|null
};
type CashMovement={id:number;account_id:number;type:string;amount:string;reference:string;
 category:string;description:string;effective_at:string;evidence_reference:string|null};
type CashResponse={accounts:CashAccount[];can_write:boolean;can_create_accounts:boolean;notice:string};
const fmt=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const formatMoney=(v:string|number|null)=>v===null||!Number.isFinite(Number(v))?'—':fmt.format(Number(v))+' MT';
const typeName:Record<CashAccount['type'],string>={banco:'Banco',numerario:'Numerário',carteira_movel:'Carteira móvel'};
const statusName:Record<CashAccount['status'],string>={em_preparacao:'Em preparação',pendente_validacao:'Por validar',activa:'Activa',suspensa:'Suspensa'};
const moneyInput=(value:string)=>/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(value)&&Number(value)>0;
const todayCut=()=>{const d=new Date();const parts=new Intl.DateTimeFormat('sv-SE',{timeZone:'Africa/Maputo',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(d);return parts.replace(' ','T').slice(0,16);};

export default function CashDesk({token}:{token:string}){
 const [accounts,setAccounts]=useState<CashAccount[]>([]);
 const [movements,setMovements]=useState<CashMovement[]>([]);
 const [enabled,setEnabled]=useState(false);
 const [canCreateAccounts,setCanCreateAccounts]=useState(false);
 const [busy,setBusy]=useState(false);
 const [loading,setLoading]=useState(true);
 const [error,setError]=useState('');
 const [notice,setNotice]=useState('');
 const [section,setSection]=useState<'contas'|'movimentos'>('contas');
 const [accountQuery,setAccountQuery]=useState('');
 const [accountStatus,setAccountStatus]=useState<'todas'|CashAccount['status']>('todas');
 const [movementQuery,setMovementQuery]=useState('');
 const [movementType,setMovementType]=useState('todas');
 const [movementAccount,setMovementAccount]=useState('');
 const [accountDetail,setAccountDetail]=useState<number|null>(null);
 const [showIdentifier,setShowIdentifier]=useState(false);
 const [action,setAction]=useState<'none'|'create'|'opening'|'record'|'transfer'>('none');
 const [selected,setSelected]=useState('');
 const [newAccount,setNewAccount]=useState({code:'',name:'',type:'banco' as CashAccount['type'],identifier:''});
 const [opening,setOpening]=useState({opening_balance:'',cut_at:todayCut(),evidence_reference:''});
 const [record,setRecord]=useState({account_id:'',type:'entrada' as 'entrada'|'saida',amount:'',category:'',description:'',evidence_reference:''});
 const [transfer,setTransfer]=useState({source_account_id:'',destination_account_id:'',amount:'',description:'',evidence_reference:''});
 const [operationKey,setOperationKey]=useState(()=>crypto.randomUUID());

 const request=useCallback(async<T,>(path:string,method:'GET'|'POST'='GET',body?:unknown):Promise<T>=>{
  const res=await fetch(API+'/cis/caixa/'+path,{
   method,headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},
   ...(body?{body:JSON.stringify(body)}:{})
  });
  const json=await res.json().catch(()=>({})) as T&{message?:string;errors?:Record<string,string[]>};
  if(!res.ok)throw new Error(json.message||Object.values(json.errors??{}).flat()[0]||
   (res.status===503?'A Caixa ainda não está activada ou não foi migrada.':'Falha na comunicação com a Caixa.'));
  return json;
 },[token]);

 const reload=useCallback(async()=>{setLoading(true);setError('');
  try{
   const [a,m]=await Promise.all([request<CashResponse>('contas'),request<{movements:CashMovement[]}>('movimentos')]);
   setAccounts(a.accounts);setMovements(m.movements);setEnabled(a.can_write);setCanCreateAccounts(Boolean(a.can_create_accounts));
  }catch(e){setError(e instanceof Error?e.message:'Erro ao consultar a Caixa.');}
  finally{setLoading(false);}
 },[request]);
 useEffect(()=>{void reload();},[reload]);
 const active=useMemo(()=>accounts.filter(a=>a.status==='activa'),[accounts]);
 const selectedAccount=accounts.find(a=>String(a.id)===selected);
 const detailAccount=accounts.find(a=>a.id===accountDetail);
 const filteredAccounts=useMemo(()=>accounts.filter(a=>{
  const matches=accountStatus==='todas'||a.status===accountStatus;
  const q=accountQuery.trim().toLocaleLowerCase('pt');
  return matches&&(!q||[a.code,a.name,a.type,a.identifier??''].some(x=>x.toLocaleLowerCase('pt').includes(q)));
 }),[accounts,accountQuery,accountStatus]);
 const filteredMovements=useMemo(()=>movements.filter(m=>{
  const matchesAccount=!movementAccount||String(m.account_id)===movementAccount;
  const matchesType=movementType==='todas'||(movementType==='entradas'
   ?['entrada','transferencia_entrada','estorno_entrada'].includes(m.type)
   :['saida','transferencia_saida','estorno_saida'].includes(m.type));
  const q=movementQuery.trim().toLocaleLowerCase('pt');
  return matchesAccount&&matchesType&&(!q||[m.reference,m.category,m.description,String(m.amount),
   accounts.find(a=>a.id===m.account_id)?.code??''].some(x=>x.toLocaleLowerCase('pt').includes(q)));
 }),[movements,accounts,movementAccount,movementType,movementQuery]);
 const pendingCount=accounts.filter(a=>a.status==='pendente_validacao').length;
 const preparingCount=accounts.filter(a=>a.status==='em_preparacao').length;
 const hasAvailableBalance=active.some(a=>a.saldo_disponivel!==null);
 const total=active.reduce((sum,a)=>sum+Number(a.saldo_disponivel??0),0);
 const readableDate=(raw:string|null)=>raw?raw.replace('T',' ').slice(0,16):'—';
 function resetAction(){setAction('none');setError('');}
 function showMovementsFor(id:number){setMovementAccount(String(id));setMovementQuery('');setMovementType('todas');setSection('movimentos');setAction('none');setAccountDetail(null);}
 function beginAction(value:'create'|'opening'|'record'|'transfer'){setAction(value);setError('');setNotice('');if(value==='record'||value==='transfer')setOperationKey(crypto.randomUUID());}
 async function submit(event:FormEvent,what:'create'|'opening'|'activate'|'record'|'transfer',id?:number){
  event.preventDefault();setError('');setNotice('');
  if(what==='create'?!canCreateAccounts:!enabled){setError('Esta operação ainda não está autorizada neste ambiente.');return;}
  if(what==='record' && !moneyInput(record.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if(what==='transfer' && !moneyInput(transfer.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if(what==='transfer'&&!window.confirm('Confirma que a saída da origem E a entrada no destino foram ambas efectivamente executadas e comprovadas? Se qualquer uma estiver pendente, não registe a transferência como concluída.'))return;
  if(what==='record'&&!window.confirm('Confirma o registo deste movimento financeiro na Caixa? A operação não poderá ser apagada.'))return;
  if(what==='activate'&&!window.confirm('Confirma que conferiu o saldo, a data de corte e a evidência documental desta conta?'))return;
  setBusy(true);
  try{
   if(what==='create')await request('contas','POST',{...newAccount,code:newAccount.code.trim().toUpperCase(),name:newAccount.name.trim(),identifier:newAccount.identifier.trim()||null});
   if(what==='opening')await request('contas/'+selected+'/abertura','POST',{
    opening_balance:opening.opening_balance,cut_at:opening.cut_at.replace('T',' ')+':00',
    evidence_reference:opening.evidence_reference
   });
   if(what==='activate')await request('contas/'+id+'/activar','POST',{confirmed_document:true});
   if(what==='record')await request('movimentos','POST',{...record,account_id:Number(record.account_id),idempotency_key:operationKey});
   if(what==='transfer')await request('transferencias','POST',{
    ...transfer,source_account_id:Number(transfer.source_account_id),
    destination_account_id:Number(transfer.destination_account_id),confirmed_source_debit:true,confirmed_destination_credit:true,idempotency_key:operationKey
   });
   setNotice(what==='create'?'Conta criada em preparação.':what==='opening'?'Saldo submetido para validação documental.':what==='activate'?'Conta activada com base na validação documental.':'Operação financeira registada.');
   if(what==='record'||what==='transfer')setOperationKey(crypto.randomUUID());
   setAction('none');await reload();
  }catch(e){setError(e instanceof Error?e.message:'Não foi possível efectuar a operação. Não repita sem verificar o histórico.');}
  finally{setBusy(false);}
 }
 return <section className="cis-cash">
  <div className="cis-cash-intro"><div><div className="cis-cash-eyebrow"><span className="cis-cash-live-dot"/> GESTÃO FINANCEIRA · MÓDULO PADRÃO</div>
   <h2>Caixa Geral</h2><p>Organize as contas financeiras da instituição num único espaço seguro.</p>
   <div className="cis-cash-hero-flags"><span><ShieldCheck size={14}/> Uma fonte financeira: Extracto</span><span><LockKeyhole size={14}/> Movimentos protegidos</span></div></div>
   <button type="button" onClick={()=>void reload()} disabled={busy||loading}><RefreshCw size={16}/> Actualizar dados</button>
  </div>
  {error&&<div className="cis-cash-message error" role="alert"><AlertCircle size={18}/><span>{error}</span><button type="button" onClick={()=>setError('')} aria-label="Fechar aviso"><X size={16}/></button></div>}
  {notice&&<div className="cis-cash-message success" role="status"><CheckCircle2 size={18}/><span>{notice}</span><button type="button" onClick={()=>setNotice('')} aria-label="Fechar confirmação"><X size={16}/></button></div>}
  {!enabled&&!loading&&<div className="cis-cash-message caution" role="status"><LockKeyhole size={18}/>
   <span><strong>{canCreateAccounts?'Preparação de contas activa.':'Modo de consulta.'}</strong> {canCreateAccounts?'Pode cadastrar contas sem saldo. Abertura, validação, transferências e desembolsos permanecem bloqueados.':'Cadastros e movimentos financeiros continuam bloqueados até autorização no servidor.'}</span>
  </div>}
  <div className="cis-cash-overview">
    <article className="cis-cash-metric featured"><span className="cis-cash-metric-icon"><Wallet size={19}/></span><div><small>Saldo disponível</small><strong>{loading||error||!hasAvailableBalance?'—':formatMoney(total)}</strong><p>{active.length?'Contas activas com saldo confirmado':'Indisponível até validação dos saldos iniciais'}</p></div></article>
    <article className="cis-cash-metric"><span className="cis-cash-metric-icon"><Landmark size={19}/></span><div><small>Contas cadastradas</small><strong>{loading||error?'—':accounts.length}</strong><p>Bancos, numerário e carteiras móveis</p></div></article>
    <article className="cis-cash-metric"><span className="cis-cash-metric-icon"><Layers size={19}/></span><div><small>Em preparação</small><strong>{loading||error?'—':preparingCount}</strong><p>Contas ainda sem saldo validado</p></div></article>
    <article className="cis-cash-metric"><span className="cis-cash-metric-icon"><ClipboardCheck size={19}/></span><div><small>Por validar</small><strong>{loading||error?'—':pendingCount}</strong><p>Validação documental pendente</p></div></article>
  </div>
  <div className="cis-cash-process" role="status"><div className={canCreateAccounts?'ready':''}><span>01</span><b>Cadastro de contas</b>{canCreateAccounts?<CheckCircle2 size={15}/>:<LockKeyhole size={14}/>}</div><ChevronRight size={15}/><div><span>02</span><b>Validação de saldos</b><LockKeyhole size={14}/></div><ChevronRight size={15}/><div><span>03</span><b>Movimentos financeiros</b><LockKeyhole size={14}/></div></div>
  <div className="cis-cash-sectionbar">
   <nav className="cis-cash-tabs" aria-label="Áreas do módulo Caixa">
    <button type="button" className={section==='contas'?'active':''} aria-current={section==='contas'?'page':undefined} onClick={()=>{setSection('contas');resetAction();}}><Landmark size={17}/> Contas e saldos</button>
    <button type="button" className={section==='movimentos'?'active':''} aria-current={section==='movimentos'?'page':undefined} onClick={()=>{setSection('movimentos');resetAction();}}><ArrowLeftRight size={17}/> Movimentos</button>
   </nav>
   <span className="cis-cash-section-context">{section==='contas'?'Gestão de contas':'Últimos 200 movimentos'} <ChevronRight size={14}/></span>
  </div>
  {section==='contas'?<div className="cis-cash-panel">
   <div className="cis-cash-panel-head"><div><h3>Contas financeiras</h3><p>Consulte as disponibilidades e prepare novas contas com saldo documentado.</p></div>
    {canCreateAccounts&&<button type="button" className="cis-cash-primary" onClick={()=>action==='create'?resetAction():beginAction('create')}><Plus size={17}/> Nova conta</button>}
   </div>
   <div className="cis-cash-filters" role="search" aria-label="Pesquisar contas">
    <label className="cis-cash-search"><Search size={17}/><span className="cis-sr-only">Pesquisar contas</span><input value={accountQuery} onChange={e=>setAccountQuery(e.target.value)} placeholder="Pesquisar por nome, código ou identificador…"/></label>
    <label className="cis-cash-select-filter"><Filter size={16}/><span className="cis-sr-only">Estado da conta</span><select value={accountStatus} onChange={e=>setAccountStatus(e.target.value as 'todas'|CashAccount['status'])}>
     <option value="todas">Todos os estados</option><option value="activa">Activas</option><option value="pendente_validacao">Por validar</option><option value="em_preparacao">Em preparação</option><option value="suspensa">Suspensas</option>
    </select></label>
    <span className="cis-cash-result-count">{filteredAccounts.length} de {accounts.length} conta(s)</span>
   </div>
   {action==='create'&&<div className="cis-cash-dialog-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)resetAction();}}><form className="cis-cash-form cis-cash-dialog" role="dialog" aria-modal="true" aria-labelledby="cash-form-title" onSubmit={e=>void submit(e,'create')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">NOVA CONTA · 01 / 02</span><h4 id="cash-form-title">Cadastrar conta financeira</h4><p>Identifique a conta. O saldo inicial será introduzido e validado posteriormente; não é criado automaticamente.</p></div>
    <label>Código único <small>Obrigatório</small><input autoFocus required maxLength={40} pattern="[A-Za-z0-9_-]{2,40}" value={newAccount.code} onChange={e=>setNewAccount(p=>({...p,code:e.target.value}))} placeholder="Ex.: CONTA-001"/><small>Letras, números, hífen ou sublinhado.</small>{accounts.some(a=>a.code.toUpperCase()===newAccount.code.trim().toUpperCase())&&<small className="cis-cash-validation">Este código já existe.</small>}</label>
    <label>Designação <small>Obrigatório</small><input required minLength={3} maxLength={150} value={newAccount.name} onChange={e=>setNewAccount(p=>({...p,name:e.target.value}))} placeholder="Ex.: Conta corrente principal"/></label>
    <div className="cis-cash-kind-options"><span>Tipo de conta</span><div>{([['banco','Conta bancária'],['numerario','Numerário'],['carteira_movel','Carteira móvel']] as const).map(([key,label])=><button key={key} type="button" aria-pressed={newAccount.type===key} className={newAccount.type===key?'selected':''} onClick={()=>setNewAccount(p=>({...p,type:key}))}>{key==='banco'?<Landmark size={18}/>:key==='numerario'?<Wallet size={18}/>:<Smartphone size={18}/>}<span>{label}</span>{newAccount.type===key&&<CheckCircle2 size={14}/>}</button>)}</div></div>
    <label className="cis-cash-wide">{newAccount.type==='banco'?'Número / identificador bancário':newAccount.type==='carteira_movel'?'Identificador da carteira':'Referência da caixa'} <small>Opcional</small><input maxLength={120} value={newAccount.identifier} onChange={e=>setNewAccount(p=>({...p,identifier:e.target.value}))} placeholder="Introduza apenas se aplicável"/></label><div className="cis-cash-form-note"><Info size={16}/> Esta conta será criada em preparação, sem saldo disponível e sem autorização para movimentações.</div>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction} disabled={busy}>Cancelar</button><button type="submit" disabled={busy||newAccount.name.trim().length<3||accounts.some(a=>a.code.toUpperCase()===newAccount.code.trim().toUpperCase())}>{busy?'A guardar…':'Gravar conta'} <ArrowRight size={16}/></button></div>
   </form></div>}
   {loading?<div className="cis-cash-loading" role="status"><RefreshCw size={18}/> A consultar contas…</div>:accounts.length===0?<div className="cis-cash-empty"><div className="cis-cash-empty-icon"><Landmark size={26}/></div><strong>Comece por cadastrar a primeira conta</strong><p>Adicione uma conta bancária, numerário ou carteira móvel real. Depois, documente o saldo de abertura para validação.</p>{!canCreateAccounts&&<small>O cadastro encontra-se temporariamente indisponível.</small>}</div>:filteredAccounts.length===0?<div className="cis-cash-empty"><Search size={23}/><strong>Nenhuma conta corresponde à pesquisa</strong><p>Altere os filtros ou o termo de pesquisa.</p><button type="button" onClick={()=>{setAccountQuery('');setAccountStatus('todas');}}>Limpar filtros</button></div>:
    <div className="cis-cash-accounts">{filteredAccounts.map(a=><article className="cis-cash-account" key={a.id}>
     <div className="cis-cash-account-top"><div className="cis-cash-account-icon">{a.type==='banco'?<Landmark size={19}/>:a.type==='numerario'?<Wallet size={19}/>:<Smartphone size={19}/>}</div><span className={'cis-cash-badge '+a.status}>{statusName[a.status]}</span></div>
     <span className="cis-cash-account-code">{a.code} · {typeName[a.type]}</span><h4>{a.name}</h4>
     <div className="cis-cash-account-balance"><small>{a.status==='activa'?'Saldo disponível':'Saldo pendente de validação'}</small><strong>{formatMoney(a.saldo_disponivel)}</strong>{a.status!=='activa'&&<small>Nenhuma movimentação autorizada</small>}</div>
     <div className="cis-cash-account-info"><span>{a.currency}</span><span>Data de corte: {readableDate(a.cut_at)}</span></div>
     <div className="cis-cash-account-actions">
      <button type="button" onClick={()=>setAccountDetail(a.id)}><Eye size={15}/> Ver detalhes</button>
      {a.status==='activa'&&<button type="button" onClick={()=>showMovementsFor(a.id)}>Movimentos <ChevronRight size={15}/></button>}
     </div>
    </article>)}</div>}
   {detailAccount&&<div className="cis-cash-dialog-backdrop" onMouseDown={e=>{if(e.target===e.currentTarget)setAccountDetail(null);}}><div className="cis-cash-detail cis-cash-dialog" role="dialog" aria-modal="true" aria-label={'Detalhes da conta '+detailAccount.name}>
    <div className="cis-cash-detail-head"><div><span className="cis-cash-step">FICHA DA CONTA</span><h4>{detailAccount.name}</h4><p>{detailAccount.code} · {typeName[detailAccount.type]}</p></div><button type="button" aria-label="Fechar detalhes da conta" onClick={()=>setAccountDetail(null)}><X size={19}/></button></div>
    <div className="cis-cash-detail-grid">
     <div><small>Estado</small><strong>{statusName[detailAccount.status]}</strong></div>
     <div><small>Saldo disponível</small><strong>{formatMoney(detailAccount.saldo_disponivel)}</strong></div>
     <div><small>Saldo inicial documentado</small><strong>{formatMoney(detailAccount.opening_balance)}</strong></div>
     <div><small>Data de corte</small><strong>{readableDate(detailAccount.cut_at)}</strong></div>
     <div><small>Moeda</small><strong>{detailAccount.currency}</strong></div>
     <div><small>Identificador</small><strong>{detailAccount.identifier?(showIdentifier?detailAccount.identifier:'•••• '+detailAccount.identifier.slice(-4)):'Não informado'}</strong>{detailAccount.identifier&&<button type="button" className="cis-cash-reveal" onClick={()=>setShowIdentifier(v=>!v)}>{showIdentifier?'Ocultar':'Mostrar'}</button>}</div>
     <div className="cis-cash-detail-wide"><small>Referência documental</small><strong>{detailAccount.evidence_reference||'Não apresentada'}</strong></div>
    </div>
    {detailAccount.status==='pendente_validacao'&&<div className="cis-cash-detail-review"><Info size={17}/><span>Validação manual: confirme o documento e a data de corte antes de activar esta conta. Esta acção não realiza reconciliação bancária independente.</span></div>}
    <div className="cis-cash-detail-actions">
     {detailAccount.status==='activa'&&<button type="button" onClick={()=>showMovementsFor(detailAccount.id)}><ArrowLeftRight size={16}/> Consultar movimentos</button>}
     <button type="button" onClick={()=>setAccountDetail(null)}>Fechar</button>
    </div>
   </div></div>}
   {action==='opening'&&selectedAccount&&<form className="cis-cash-form cis-cash-opening" onSubmit={e=>void submit(e,'opening')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">SALDO INICIAL · 02 / 02</span><h4>Abertura · {selectedAccount.name}</h4><p>Introduza o saldo comprovado e a data de corte. A referência documental será registada, mas não verificada automaticamente.</p></div>
    <label>Saldo documentado (MT)<input type="text" inputMode="decimal" required value={opening.opening_balance} onChange={e=>setOpening(p=>({...p,opening_balance:e.target.value}))} placeholder="0.00"/></label>
    <label>Data e hora de corte (Maputo)<input type="datetime-local" required max={todayCut()} value={opening.cut_at} onChange={e=>setOpening(p=>({...p,cut_at:e.target.value}))}/></label>
    <label className="cis-cash-wide">Referência do comprovativo / extracto<input required minLength={5} maxLength={255} value={opening.evidence_reference} onChange={e=>setOpening(p=>({...p,evidence_reference:e.target.value}))} placeholder="Extrato BCI de 16/11/2026 — referência ..."/></label>
    <div className="cis-cash-form-note"><Info size={16}/> O saldo apenas ficará disponível quando a conta for validada. Não serão importados movimentos antigos.</div>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||!/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(opening.opening_balance)}>{busy?'A submeter…':'Submeter para validação'} <ArrowRight size={16}/></button></div>
   </form>}
  </div>:<div className="cis-cash-panel">
   <div className="cis-cash-panel-head"><div><h3>Livro de movimentos</h3><p>Entradas, saídas e transferências confirmadas; não inclui movimentos do Extracto antigo.</p></div>
   {enabled&&<div className="cis-cash-toolbar">
    <button type="button" onClick={()=>beginAction('record')}><Plus size={16}/> Novo movimento</button>
    <button type="button" onClick={()=>beginAction('transfer')}><ArrowLeftRight size={16}/> Transferir entre contas</button>
   </div>}</div>
   <div className="cis-cash-filters" role="search" aria-label="Filtros do livro de Caixa">
    <label className="cis-cash-search"><Search size={17}/><span className="cis-sr-only">Pesquisar movimentos</span><input placeholder="Pesquisar referência, categoria ou descrição…" value={movementQuery} onChange={e=>setMovementQuery(e.target.value)}/></label>
    <label className="cis-cash-select-filter"><Landmark size={16}/><span className="cis-sr-only">Filtrar conta</span><select value={movementAccount} onChange={e=>setMovementAccount(e.target.value)}><option value="">Todas as contas</option>{accounts.map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label className="cis-cash-select-filter"><Filter size={16}/><span className="cis-sr-only">Filtrar tipo</span><select value={movementType} onChange={e=>setMovementType(e.target.value)}><option value="todas">Todos os movimentos</option><option value="entradas">Entradas</option><option value="saidas">Saídas</option></select></label>
    <span className="cis-cash-result-count">{filteredMovements.length} de {movements.length} movimentos</span>
   </div>
   {action==='record'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'record')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">MOVIMENTO FINANCEIRO</span><h4>Registar entrada ou saída geral</h4><p>Exclusivo para receitas e despesas gerais. Desembolsos de crédito e pagamentos de prestações terão integração própria.</p></div>
    <label>Conta financeira<select required value={record.account_id} onChange={e=>setRecord(p=>({...p,account_id:e.target.value}))}><option value="">Seleccionar conta activa</option>{active.map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Natureza<select value={record.type} onChange={e=>setRecord(p=>({...p,type:e.target.value as 'entrada'|'saida'}))}><option value="entrada">Entrada / receita</option><option value="saida">Saída / despesa</option></select></label>
    <label>Valor (MT)<input required inputMode="decimal" value={record.amount} onChange={e=>setRecord(p=>({...p,amount:e.target.value}))} placeholder="0.00"/><small>Utilize um valor positivo com até duas casas decimais.</small></label>
    <label>Categoria<input required minLength={3} maxLength={90} value={record.category} onChange={e=>setRecord(p=>({...p,category:e.target.value}))} placeholder="Despesa operacional"/></label>
    <label className="cis-cash-wide">Descrição<input required minLength={4} maxLength={500} value={record.description} onChange={e=>setRecord(p=>({...p,description:e.target.value}))}/></label>
    <label className="cis-cash-wide">Referência ou comprovativo<input value={record.evidence_reference} onChange={e=>setRecord(p=>({...p,evidence_reference:e.target.value}))} placeholder="Obrigatório em saídas bancárias e de carteiras móveis"/></label>
    {record.account_id&&<div className="cis-cash-form-summary">
     <div><span>Conta seleccionada</span><strong>{active.find(a=>String(a.id)===record.account_id)?.name??'—'}</strong></div>
     <div><span>Saldo actual</span><strong>{formatMoney(active.find(a=>String(a.id)===record.account_id)?.saldo_disponivel??null)}</strong></div>
     <div><span>{record.type==='entrada'?'Entrada prevista':'Saída prevista'}</span><strong>{moneyInput(record.amount)?formatMoney(record.amount):'—'}</strong></div>
     <div className="cis-cash-form-summary-total"><span>Saldo após lançamento (indicativo)</span><strong>{moneyInput(record.amount)?formatMoney(Number(active.find(a=>String(a.id)===record.account_id)?.saldo_disponivel??0)+(record.type==='entrada'?1:-1)*Number(record.amount)):'—'}</strong></div>
    </div>}
    <div className="cis-cash-form-note"><Info size={16}/> O servidor verifica novamente o saldo e confirma a operação apenas após a validação. Não se trata de reconciliação bancária.</div>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||!active.length||!record.account_id||!moneyInput(record.amount)}>{busy?'A registar…':record.type==='entrada'?'Confirmar entrada':'Confirmar saída'} <ArrowRight size={16}/></button></div>
   </form>}
   {action==='transfer'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'transfer')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">TRANSFERÊNCIA INTERNA</span><h4>Movimentar entre contas</h4><p>Registe somente quando a saída da origem e a entrada no destino estiverem ambas comprovadas. Transferências pendentes ainda não são suportadas.</p></div>
    <label>Conta de origem<select required value={transfer.source_account_id} onChange={e=>setTransfer(p=>({...p,source_account_id:e.target.value}))}><option value="">Seleccionar origem</option>{active.map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Conta de destino<select required value={transfer.destination_account_id} onChange={e=>setTransfer(p=>({...p,destination_account_id:e.target.value}))}><option value="">Seleccionar destino</option>{active.filter(a=>String(a.id)!==transfer.source_account_id).map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Valor (MT)<input required inputMode="decimal" value={transfer.amount} onChange={e=>setTransfer(p=>({...p,amount:e.target.value}))} placeholder="0.00"/></label>
    <label>Referência / comprovativo<input required minLength={5} value={transfer.evidence_reference} onChange={e=>setTransfer(p=>({...p,evidence_reference:e.target.value}))}/></label>
    <label className="cis-cash-wide">Descrição<input required minLength={4} value={transfer.description} onChange={e=>setTransfer(p=>({...p,description:e.target.value}))}/></label>
    {transfer.source_account_id&&transfer.destination_account_id&&<div className="cis-cash-form-summary">
     <div><span>Origem</span><strong>{active.find(a=>String(a.id)===transfer.source_account_id)?.name??'—'}</strong></div>
     <div><span>Destino</span><strong>{active.find(a=>String(a.id)===transfer.destination_account_id)?.name??'—'}</strong></div>
     <div><span>Valor da transferência</span><strong>{moneyInput(transfer.amount)?formatMoney(transfer.amount):'—'}</strong></div>
     <div className="cis-cash-form-summary-total"><span>Saldo previsto na origem</span><strong>{moneyInput(transfer.amount)?formatMoney(Number(active.find(a=>String(a.id)===transfer.source_account_id)?.saldo_disponivel??0)-Number(transfer.amount)):'—'}</strong></div>
    </div>}
    <div className="cis-cash-form-note"><Info size={16}/> A transferência regista uma saída e uma entrada vinculadas. É obrigatória a confirmação de ambas as operações.</div>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||active.length<2||!moneyInput(transfer.amount)||!transfer.source_account_id||!transfer.destination_account_id||transfer.source_account_id===transfer.destination_account_id}>{busy?'A registar…':'Confirmar transferência'} <ArrowRight size={16}/></button></div>
   </form>}
   {loading?<div className="cis-cash-loading" role="status"><RefreshCw size={18}/> A consultar movimentos…</div>:movements.length===0?
    <div className="cis-cash-empty"><div className="cis-cash-empty-icon"><Clock3 size={25}/></div><strong>O livro de Caixa ainda está vazio</strong><p>Os movimentos serão apresentados aqui após a activação das contas. O Extracto legado permanece separado.</p></div>:
    filteredMovements.length===0?<div className="cis-cash-empty"><Search size={23}/><strong>Sem movimentos para estes filtros</strong><p>Altere a pesquisa, conta ou tipo de operação para visualizar outros resultados.</p><button type="button" onClick={()=>{setMovementQuery('');setMovementAccount('');setMovementType('todas');}}>Limpar filtros</button></div>:
    <div className="cis-table-scroll"><table className="cis-cash-table"><thead><tr><th>Data</th><th>Operação</th><th>Conta</th><th>Categoria / Descrição</th><th>Valor</th><th>Estado</th></tr></thead><tbody>
    {filteredMovements.map(m=>{
     const entry=['entrada','transferencia_entrada','estorno_entrada'].includes(m.type);
     const account=accounts.find(a=>a.id===m.account_id);
     return <tr key={m.id}>
      <td><time dateTime={m.effective_at}>{readableDate(m.effective_at)}</time></td>
      <td><div className="cis-cash-movement-kind"><span className={'cis-cash-movement-icon '+(entry?'is-entry':'is-exit')}>{entry?<ArrowDownLeft size={16}/>:<ArrowUpRight size={16}/>}</span>
       <div><strong>{m.type.split('_').join(' ')}</strong><small title={m.reference}>{m.reference}</small></div></div></td>
      <td><strong>{account?.code??String(m.account_id)}</strong><small className="cis-cash-table-sub">{account?.name??'Conta não localizada'}</small></td>
      <td><strong>{m.category}</strong><small className="cis-cash-table-sub">{m.description}</small></td>
      <td className={'cis-cash-money '+(entry?'positive':'negative')}>{entry?'+':'−'} {formatMoney(m.amount)}</td>
      <td><span className="cis-cash-verified"><CheckCircle2 size={14}/> Confirmado</span></td>
     </tr>;
    })}
    </tbody></table></div>}
    <div className="cis-cash-table-foot"><span>Consulta limitada aos últimos 200 movimentos confirmados da Caixa.</span><span>Movimentos não reconciliados com bancos.</span></div>
  </div>}
  <p className="cis-cash-footnote"><ShieldCheck size={15}/> A Caixa padrão regista movimentos operacionais. Os contratos e o Extracto legado não foram modificados nem atribuídos automaticamente a contas. A reconciliação bancária automática é exclusiva da Tesouraria.</p>
 </section>;
}
