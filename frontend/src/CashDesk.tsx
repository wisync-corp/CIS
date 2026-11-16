import {useCallback,useEffect,useMemo,useState,type FormEvent} from 'react';
import {Landmark,Wallet,ArrowDownLeft,ArrowUpRight,ArrowLeftRight,RefreshCw,ShieldCheck,AlertCircle,CheckCircle2,Plus,CircleDollarSign,LockKeyhole,Clock3,Search,Filter,ArrowRight,Eye,Info,X,ClipboardCheck,ChevronRight} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type CashAccount={
 id:number;code:string;name:string;type:'banco'|'numerario'|'carteira_movel';identifier:string|null;
 currency:string;status:'em_preparacao'|'pendente_validacao'|'activa'|'suspensa';cut_at:string|null;
 opening_balance:string|null;evidence_reference:string|null;saldo_disponivel:string|null
};
type CashMovement={id:number;account_id:number;type:string;amount:string;reference:string;
 category:string;description:string;effective_at:string;evidence_reference:string|null};
type CashResponse={accounts:CashAccount[];can_write:boolean;notice:string};
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
   setAccounts(a.accounts);setMovements(m.movements);setEnabled(a.can_write);
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
 const hasAvailableBalance=active.some(a=>a.saldo_disponivel!==null);
 const total=active.reduce((sum,a)=>sum+Number(a.saldo_disponivel??0),0);
 const readableDate=(raw:string|null)=>raw?raw.replace('T',' ').slice(0,16):'—';
 function resetAction(){setAction('none');setError('');}
 function showMovementsFor(id:number){setMovementAccount(String(id));setMovementQuery('');setMovementType('todas');setSection('movimentos');setAction('none');setAccountDetail(null);}
 function beginAction(value:'create'|'opening'|'record'|'transfer'){setAction(value);setError('');setNotice('');if(value==='record'||value==='transfer')setOperationKey(crypto.randomUUID());}
 async function submit(event:FormEvent,what:'create'|'opening'|'activate'|'record'|'transfer',id?:number){
  event.preventDefault();setError('');setNotice('');
  if(!enabled){setError('As escritas financeiras não estão activadas.');return;}
  if(what==='record' && !moneyInput(record.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if(what==='transfer' && !moneyInput(transfer.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if(what==='transfer'&&!window.confirm('Confirma que a saída da origem E a entrada no destino foram ambas efectivamente executadas e comprovadas? Se qualquer uma estiver pendente, não registe a transferência como concluída.'))return;
  if(what==='record'&&!window.confirm('Confirma o registo deste movimento financeiro na Caixa? A operação não poderá ser apagada.'))return;
  if(what==='activate'&&!window.confirm('Confirma que conferiu o saldo, a data de corte e a evidência documental desta conta?'))return;
  setBusy(true);
  try{
   if(what==='create')await request('contas','POST',{...newAccount,identifier:newAccount.identifier||null});
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
   <h2>Caixa Geral</h2><p>Disponibilidades e operações financeiras de todas as contas da instituição.</p>
   <div className="cis-cash-hero-flags"><span><ShieldCheck size={14}/> Contas independentes</span><span><LockKeyhole size={14}/> Acesso protegido</span></div></div>
   <button type="button" onClick={()=>void reload()} disabled={busy||loading}><RefreshCw size={16}/> Actualizar dados</button>
  </div>
  {error&&<div className="cis-cash-message error" role="alert"><AlertCircle size={18}/><span>{error}</span><button type="button" onClick={()=>setError('')} aria-label="Fechar aviso"><X size={16}/></button></div>}
  {notice&&<div className="cis-cash-message success" role="status"><CheckCircle2 size={18}/><span>{notice}</span><button type="button" onClick={()=>setNotice('')} aria-label="Fechar confirmação"><X size={16}/></button></div>}
  {!enabled&&!loading&&<div className="cis-cash-message caution" role="status"><LockKeyhole size={18}/>
   <span><strong>Modo de consulta.</strong> Os registos e transferências continuam bloqueados até autorização financeira no servidor. A apresentação não representa activação da Caixa.</span>
  </div>}
  <div className="cis-cash-stats" aria-label="Resumo da Caixa">
   <article className="cis-cash-stat-feature"><span><Wallet size={17}/> Disponível nas contas activas</span><strong>{loading||error||!hasAvailableBalance?'—':formatMoney(total)}</strong>
    <small>{loading?'A consultar dados…':!accounts.length?'Cadastre e valide uma conta para começar.':'Saldo de abertura validado e movimentos registados na Caixa.'}</small></article>
   <article><span><Landmark size={17}/> Contas activas</span><strong>{loading||error?'—':active.length}</strong><small>{loading?'A consultar…':accounts.length+' conta(s) cadastrada(s)'}</small></article>
   <article><span><ClipboardCheck size={17}/> Por validar</span><strong>{loading||error?'—':pendingCount}</strong><small>Contas à espera de confirmação documental</small></article>
  </div>
  <div className="cis-cash-sectionbar">
   <nav className="cis-cash-tabs" aria-label="Áreas do módulo Caixa">
    <button type="button" className={section==='contas'?'active':''} aria-current={section==='contas'?'page':undefined} onClick={()=>{setSection('contas');resetAction();}}><Landmark size={17}/> Contas e saldos</button>
    <button type="button" className={section==='movimentos'?'active':''} aria-current={section==='movimentos'?'page':undefined} onClick={()=>{setSection('movimentos');resetAction();}}><ArrowLeftRight size={17}/> Movimentos</button>
   </nav>
   <span className="cis-cash-section-context">{section==='contas'?'Gestão de contas':'Últimos 200 movimentos'} <ChevronRight size={14}/></span>
  </div>
  {section==='contas'?<div className="cis-cash-panel">
   <div className="cis-cash-panel-head"><div><h3>Contas financeiras</h3><p>Consulte as disponibilidades e prepare novas contas com saldo documentado.</p></div>
    {enabled&&<button type="button" className="cis-cash-primary" onClick={()=>action==='create'?resetAction():beginAction('create')}><Plus size={17}/> Nova conta</button>}
   </div>
   <div className="cis-cash-filters" role="search" aria-label="Pesquisar contas">
    <label className="cis-cash-search"><Search size={17}/><span className="cis-sr-only">Pesquisar contas</span><input value={accountQuery} onChange={e=>setAccountQuery(e.target.value)} placeholder="Pesquisar por nome, código ou identificador…"/></label>
    <label className="cis-cash-select-filter"><Filter size={16}/><span className="cis-sr-only">Estado da conta</span><select value={accountStatus} onChange={e=>setAccountStatus(e.target.value as 'todas'|CashAccount['status'])}>
     <option value="todas">Todos os estados</option><option value="activa">Activas</option><option value="pendente_validacao">Por validar</option><option value="em_preparacao">Em preparação</option><option value="suspensa">Suspensas</option>
    </select></label>
    <span className="cis-cash-result-count">{filteredAccounts.length} de {accounts.length} conta(s)</span>
   </div>
   {action==='create'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'create')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">NOVA CONTA · 01 / 02</span><h4>Cadastrar conta financeira</h4><p>Identifique a conta. O saldo inicial será introduzido e validado posteriormente; não é criado automaticamente.</p></div>
    <label>Código único<input required maxLength={40} pattern="[A-Za-z0-9_-]{2,40}" value={newAccount.code} onChange={e=>setNewAccount(p=>({...p,code:e.target.value}))} placeholder="BCI-001"/></label>
    <label>Designação<input required minLength={3} maxLength={150} value={newAccount.name} onChange={e=>setNewAccount(p=>({...p,name:e.target.value}))} placeholder="Conta corrente principal"/></label>
    <label>Tipo<select value={newAccount.type} onChange={e=>setNewAccount(p=>({...p,type:e.target.value as CashAccount['type']}))}><option value="banco">Banco</option><option value="numerario">Numerário</option><option value="carteira_movel">Carteira móvel</option></select></label>
    <label>Identificador (conta, referência ou carteira)<input maxLength={120} value={newAccount.identifier} onChange={e=>setNewAccount(p=>({...p,identifier:e.target.value}))}/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy}>{busy?'A criar conta…':'Criar conta em preparação'} <ArrowRight size={16}/></button></div>
   </form>}
   {loading?<div className="cis-cash-loading" role="status"><RefreshCw size={18}/> A consultar contas…</div>:accounts.length===0?<div className="cis-cash-empty"><div className="cis-cash-empty-icon"><Landmark size={26}/></div><strong>Comece por cadastrar a primeira conta</strong><p>Adicione uma conta bancária, numerário ou carteira móvel real. Depois, documente o saldo de abertura para validação.</p>{!enabled&&<small>O registo encontra-se temporariamente indisponível.</small>}</div>:filteredAccounts.length===0?<div className="cis-cash-empty"><Search size={23}/><strong>Nenhuma conta corresponde à pesquisa</strong><p>Altere os filtros ou o termo de pesquisa.</p><button type="button" onClick={()=>{setAccountQuery('');setAccountStatus('todas');}}>Limpar filtros</button></div>:
    <div className="cis-cash-accounts">{filteredAccounts.map(a=><article className="cis-cash-account" key={a.id}>
     <div className="cis-cash-account-top"><div className="cis-cash-account-icon"><Landmark size={19}/></div><span className={'cis-cash-badge '+a.status}>{statusName[a.status]}</span></div>
     <span className="cis-cash-account-code">{a.code} · {typeName[a.type]}</span><h4>{a.name}</h4>
     <div className="cis-cash-account-balance"><small>{a.status==='activa'?'Saldo disponível':'Saldo não disponível'}</small><strong>{formatMoney(a.saldo_disponivel)}</strong>{a.status!=='activa'&&<small>Disponível apenas após validação</small>}</div>
     <div className="cis-cash-account-info"><span>{a.currency}</span><span>Data de corte: {readableDate(a.cut_at)}</span></div>
     <div className="cis-cash-account-actions">
      <button type="button" onClick={()=>setAccountDetail(a.id)}><Eye size={15}/> Ver detalhes</button>
      {a.status==='activa'&&<button type="button" onClick={()=>showMovementsFor(a.id)}>Movimentos <ChevronRight size={15}/></button>}
      {enabled&&a.status==='em_preparacao'&&<button type="button" className="primary" onClick={()=>{setSelected(String(a.id));beginAction('opening');setOpening({opening_balance:'',cut_at:todayCut(),evidence_reference:''});}}>Definir saldo inicial <ArrowRight size={15}/></button>}
     </div>
    </article>)}</div>}
   {detailAccount&&<div className="cis-cash-detail" role="region" aria-label={'Detalhes da conta '+detailAccount.name}>
    <div className="cis-cash-detail-head"><div><span className="cis-cash-step">FICHA DA CONTA</span><h4>{detailAccount.name}</h4><p>{detailAccount.code} · {typeName[detailAccount.type]}</p></div><button type="button" aria-label="Fechar detalhes da conta" onClick={()=>setAccountDetail(null)}><X size={19}/></button></div>
    <div className="cis-cash-detail-grid">
     <div><small>Estado</small><strong>{statusName[detailAccount.status]}</strong></div>
     <div><small>Saldo disponível</small><strong>{formatMoney(detailAccount.saldo_disponivel)}</strong></div>
     <div><small>Saldo inicial documentado</small><strong>{formatMoney(detailAccount.opening_balance)}</strong></div>
     <div><small>Data de corte</small><strong>{readableDate(detailAccount.cut_at)}</strong></div>
     <div><small>Moeda</small><strong>{detailAccount.currency}</strong></div>
     <div><small>Identificador</small><strong>{detailAccount.identifier||'Não informado'}</strong></div>
     <div className="cis-cash-detail-wide"><small>Referência documental</small><strong>{detailAccount.evidence_reference||'Não apresentada'}</strong></div>
    </div>
    {detailAccount.status==='pendente_validacao'&&<div className="cis-cash-detail-review"><Info size={17}/><span>Validação manual: confirme o documento e a data de corte antes de activar esta conta. Esta acção não realiza reconciliação bancária independente.</span></div>}
    <div className="cis-cash-detail-actions">
     {detailAccount.status==='activa'&&<button type="button" onClick={()=>showMovementsFor(detailAccount.id)}><ArrowLeftRight size={16}/> Consultar movimentos</button>}
     {enabled&&detailAccount.status==='pendente_validacao'&&<button type="button" className="primary" disabled={busy} onClick={e=>void submit(e,'activate',detailAccount.id)}><ClipboardCheck size={16}/>{busy?'A validar…':'Validar documentação e activar'}</button>}
     <button type="button" onClick={()=>setAccountDetail(null)}>Fechar</button>
    </div>
   </div>}
   {action==='opening'&&selectedAccount&&<form className="cis-cash-form cis-cash-opening" onSubmit={e=>void submit(e,'opening')}>
    <div className="cis-cash-form-title"><span className="cis-cash-step">SALDO INICIAL · 02 / 02</span><h4>Abertura · {selectedAccount.name}</h4><p>Introduza o saldo comprovado e a data de corte. A referência documental será registada, mas não verificada automaticamente.</p></div>
    <label>Saldo documentado (MT)<input type="text" inputMode="decimal" required value={opening.opening_balance} onChange={e=>setOpening(p=>({...p,opening_balance:e.target.value}))} placeholder="0.00"/></label>
    <label>Data e hora de corte (Maputo)<input type="datetime-local" required max={todayCut()} value={opening.cut_at} onChange={e=>setOpening(p=>({...p,cut_at:e.target.value}))}/></label>
    <label className="cis-cash-wide">Referência do comprovativo / extracto<input required minLength={5} maxLength={255} value={opening.evidence_reference} onChange={e=>setOpening(p=>({...p,evidence_reference:e.target.value}))} placeholder="Extrato BCI de 16/11/2026 — referência ..."/></label>
    <div className="cis-cash-form-note"><Info size={16}/> O saldo apenas ficará disponível quando a conta for validada. Não serão importados movimentos antigos.</div>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||!/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(opening.opening_balance)}>{busy?'A submeter…':'Submeter para validação'} <ArrowRight size={16}/></button></div>
   </form>}
  </div>:<div className="cis-cash-panel">
   <div className="cis-cash-panel-head"><div><h3>Livro de movimentos</h3><p>Registo operacional da Caixa. Não substitui a reconciliação bancária da Tesouraria.</p></div>
   {enabled&&<div className="cis-cash-toolbar">
    <button type="button" onClick={()=>{setAction('record');setOperationKey(crypto.randomUUID());}}><Plus size={16}/> Entrada / saída</button>
    <button type="button" onClick={()=>{setAction('transfer');setOperationKey(crypto.randomUUID());}}><ArrowLeftRight size={16}/> Transferência</button>
   </div>}</div>
   {action==='record'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'record')}>
    <div className="cis-cash-form-title"><h4>Movimento geral</h4><p>Não utilize este formulário para desembolsos nem cobranças de crédito.</p></div>
    <label>Conta<select required value={record.account_id} onChange={e=>setRecord(p=>({...p,account_id:e.target.value}))}><option value="">Seleccionar conta activa</option>{active.map(a=><option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Natureza<select value={record.type} onChange={e=>setRecord(p=>({...p,type:e.target.value as 'entrada'|'saida'}))}><option value="entrada">Entrada / receita</option><option value="saida">Saída / despesa</option></select></label>
    <label>Valor (MT)<input required inputMode="decimal" value={record.amount} onChange={e=>setRecord(p=>({...p,amount:e.target.value}))} placeholder="0.00"/></label>
    <label>Categoria<input required minLength={3} maxLength={90} value={record.category} onChange={e=>setRecord(p=>({...p,category:e.target.value}))} placeholder="Despesa operacional"/></label>
    <label className="cis-cash-wide">Descrição<input required minLength={4} maxLength={500} value={record.description} onChange={e=>setRecord(p=>({...p,description:e.target.value}))}/></label>
    <label className="cis-cash-wide">Referência ou comprovativo<input value={record.evidence_reference} onChange={e=>setRecord(p=>({...p,evidence_reference:e.target.value}))} placeholder="Obrigatório em saídas bancárias e de carteiras móveis"/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||!active.length}>Registar movimento</button></div>
   </form>}
   {action==='transfer'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'transfer')}>
    <div className="cis-cash-form-title"><h4>Transferência entre contas</h4><p>Registe apenas após confirmar e documentar a saída da origem e a entrada no destino; transferências pendentes não devem ser registadas como concluídas.</p></div>
    <label>Conta de origem<select required value={transfer.source_account_id} onChange={e=>setTransfer(p=>({...p,source_account_id:e.target.value}))}><option value="">Seleccionar origem</option>{active.map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Conta de destino<select required value={transfer.destination_account_id} onChange={e=>setTransfer(p=>({...p,destination_account_id:e.target.value}))}><option value="">Seleccionar destino</option>{active.filter(a=>String(a.id)!==transfer.source_account_id).map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Valor (MT)<input required inputMode="decimal" value={transfer.amount} onChange={e=>setTransfer(p=>({...p,amount:e.target.value}))} placeholder="0.00"/></label>
    <label>Referência / comprovativo<input required minLength={5} value={transfer.evidence_reference} onChange={e=>setTransfer(p=>({...p,evidence_reference:e.target.value}))}/></label>
    <label className="cis-cash-wide">Descrição<input required minLength={4} value={transfer.description} onChange={e=>setTransfer(p=>({...p,description:e.target.value}))}/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||active.length<2}>Confirmar transferência</button></div>
   </form>}
   {loading?<div className="cis-cash-empty">A consultar movimentos…</div>:movements.length===0?<div className="cis-cash-empty"><Clock3 size={24}/><strong>Sem movimentos</strong><p>O histórico da Caixa começará após a activação das contas. Os movimentos do Extracto antigo não foram importados automaticamente.</p></div>:
    <div className="cis-table-scroll"><table className="cis-cash-table"><thead><tr><th>Data</th><th>Referência</th><th>Conta</th><th>Categoria</th><th>Descrição</th><th>Movimento</th><th>Valor</th></tr></thead><tbody>
    {movements.map(m=><tr key={m.id}><td>{readableDate(m.effective_at)}</td><td title={m.reference}>{m.reference}</td><td>{accounts.find(a=>a.id===m.account_id)?.code??m.account_id}</td><td>{m.category}</td><td>{m.description}</td><td><span className="cis-cash-type">{['entrada','transferencia_entrada','estorno_entrada'].includes(m.type)?<ArrowDownLeft size={15}/>:<ArrowUpRight size={15}/>} {m.type.split('_').join(' ')}</span></td><td className="cis-cash-money">{formatMoney(m.amount)}</td></tr>)}
    </tbody></table></div>}
  </div>}
  <p className="cis-cash-footnote"><ShieldCheck size={15}/> A Caixa padrão regista movimentos operacionais. Os contratos e o Extracto legado não foram modificados nem atribuídos automaticamente a contas. A reconciliação bancária automática é exclusiva da Tesouraria.</p>
 </section>;
}
