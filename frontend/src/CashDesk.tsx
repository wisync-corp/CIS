import {useCallback,useEffect,useMemo,useState,type FormEvent} from 'react';
import {Landmark,Wallet,ArrowDownLeft,ArrowUpRight,ArrowLeftRight,RefreshCw,ShieldCheck,AlertCircle,CheckCircle2,Plus,CircleDollarSign,LockKeyhole,Clock3} from 'lucide-react';

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
 const total=active.reduce((sum,a)=>sum+Number(a.saldo_disponivel??0),0);
 const readableDate=(raw:string|null)=>raw?raw.replace('T',' ').slice(0,16):'—';
 function resetAction(){setAction('none');setError('');}
 async function submit(event:FormEvent,what:'create'|'opening'|'activate'|'record'|'transfer',id?:number){
  event.preventDefault();setError('');setNotice('');
  if(!enabled){setError('As escritas financeiras não estão activadas.');return;}
  if(what==='record' && !moneyInput(record.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if(what==='transfer' && !moneyInput(transfer.amount)){setError('Montante inválido. Utilize até duas casas decimais.');return;}
  if((what==='record'||what==='transfer')&&!window.confirm('Confirma o registo desta operação financeira na Caixa? A operação não poderá ser apagada.'))return;
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
    destination_account_id:Number(transfer.destination_account_id),idempotency_key:operationKey
   });
   setNotice(what==='create'?'Conta criada em preparação.':what==='opening'?'Saldo submetido para validação documental.':what==='activate'?'Conta activada com base na validação documental.':'Operação financeira registada.');
   if(what==='record'||what==='transfer')setOperationKey(crypto.randomUUID());
   setAction('none');await reload();
  }catch(e){setError(e instanceof Error?e.message:'Não foi possível efectuar a operação. Não repita sem verificar o histórico.');}
  finally{setBusy(false);}
 }
 return <section className="cis-cash">
  <div className="cis-cash-intro"><div><div className="cis-cash-eyebrow">GESTÃO FINANCEIRA · CAIXA PADRÃO</div>
   <h2>Caixa Geral</h2><p>Contas, disponibilidades e movimentos da instituição. A reconciliação bancária avançada pertence à Tesouraria opcional.</p></div>
   <button type="button" onClick={()=>void reload()} disabled={busy||loading}><RefreshCw size={16}/> Actualizar</button>
  </div>
  {error&&<div className="cis-cash-message error" role="alert"><AlertCircle size={18}/>{error}</div>}
  {notice&&<div className="cis-cash-message success" role="status"><CheckCircle2 size={18}/>{notice}</div>}
  {!enabled&&!loading&&!error&&<div className="cis-cash-message caution"><LockKeyhole size={18}/> A Caixa está em modo de consulta. Para activar operações é necessária migração, reconciliação e autorização no servidor.</div>}
  <div className="cis-cash-stats">
   <article><span><Wallet size={17}/> Disponível nas contas activas</span><strong>{formatMoney(total)}</strong><small>Saldo inicial validado + movimentos da Caixa CIS</small></article>
   <article><span><Landmark size={17}/> Contas activas</span><strong>{active.length}</strong><small>{accounts.length} conta(s) cadastrada(s)</small></article>
   <article><span><CircleDollarSign size={17}/> Movimentos apresentados</span><strong>{movements.length}</strong><small>Últimos 200 movimentos, não inclui Extracto legado</small></article>
  </div>
  <nav className="cis-cash-tabs" aria-label="Área de Caixa">
   <button type="button" className={section==='contas'?'active':''} onClick={()=>{setSection('contas');resetAction();}}><Landmark size={17}/> Contas e saldos</button>
   <button type="button" className={section==='movimentos'?'active':''} onClick={()=>{setSection('movimentos');resetAction();}}><ArrowLeftRight size={17}/> Livro de movimentos</button>
  </nav>
  {section==='contas'?<div className="cis-cash-panel">
   <div className="cis-cash-panel-head"><div><h3>Contas financeiras</h3><p>Cadastre contas reais e valide os respectivos saldos de abertura.</p></div>
    {enabled&&<button type="button" className="cis-cash-primary" onClick={()=>{setAction(action==='create'?'none':'create');setError('');}}><Plus size={17}/> Nova conta</button>}
   </div>
   {action==='create'&&<form className="cis-cash-form" onSubmit={e=>void submit(e,'create')}>
    <label>Código único<input required maxLength={40} pattern="[A-Za-z0-9_-]{2,40}" value={newAccount.code} onChange={e=>setNewAccount(p=>({...p,code:e.target.value}))} placeholder="BCI-001"/></label>
    <label>Designação<input required minLength={3} maxLength={150} value={newAccount.name} onChange={e=>setNewAccount(p=>({...p,name:e.target.value}))} placeholder="Conta corrente principal"/></label>
    <label>Tipo<select value={newAccount.type} onChange={e=>setNewAccount(p=>({...p,type:e.target.value as CashAccount['type']}))}><option value="banco">Banco</option><option value="numerario">Numerário</option><option value="carteira_movel">Carteira móvel</option></select></label>
    <label>Identificador (conta, referência ou carteira)<input maxLength={120} value={newAccount.identifier} onChange={e=>setNewAccount(p=>({...p,identifier:e.target.value}))}/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy}>Criar conta</button></div>
   </form>}
   {loading?<div className="cis-cash-empty">A consultar contas…</div>:accounts.length===0?<div className="cis-cash-empty"><Landmark size={26}/><strong>Sem contas cadastradas</strong><p>Crie uma conta real e submeta o saldo de abertura com comprovativo para validação.</p></div>:
    <div className="cis-cash-accounts">{accounts.map(a=><article className="cis-cash-account" key={a.id}>
     <div className="cis-cash-account-top"><div className="cis-cash-account-icon"><Landmark size={19}/></div><span className={'cis-cash-badge '+a.status}>{statusName[a.status]}</span></div>
     <span className="cis-cash-account-code">{a.code} · {typeName[a.type]}</span><h4>{a.name}</h4>
     <div className="cis-cash-account-balance"><small>Saldo disponível</small><strong>{formatMoney(a.saldo_disponivel)}</strong></div>
     <div className="cis-cash-account-info"><span>Moeda: {a.currency}</span><span>Corte: {readableDate(a.cut_at)}</span></div>
     {enabled&&a.status==='em_preparacao'&&<button type="button" onClick={()=>{setSelected(String(a.id));setAction('opening');setOpening({opening_balance:'',cut_at:todayCut(),evidence_reference:''});}}>Registar saldo inicial</button>}
     {enabled&&a.status==='pendente_validacao'&&<button type="button" onClick={e=>void submit(e,'activate',a.id)}>Validar documentação e activar</button>}
    </article>)}</div>}
   {action==='opening'&&selectedAccount&&<form className="cis-cash-form cis-cash-opening" onSubmit={e=>void submit(e,'opening')}>
    <div className="cis-cash-form-title"><h4>Abertura · {selectedAccount.name}</h4><p>Indique o saldo efectivamente comprovado na data de corte. O documento será referenciado, mas não verificado automaticamente.</p></div>
    <label>Saldo documentado (MT)<input type="text" inputMode="decimal" required value={opening.opening_balance} onChange={e=>setOpening(p=>({...p,opening_balance:e.target.value}))} placeholder="0.00"/></label>
    <label>Data e hora de corte (Maputo)<input type="datetime-local" required max={todayCut()} value={opening.cut_at} onChange={e=>setOpening(p=>({...p,cut_at:e.target.value}))}/></label>
    <label className="cis-cash-wide">Referência do comprovativo / extracto<input required minLength={5} maxLength={255} value={opening.evidence_reference} onChange={e=>setOpening(p=>({...p,evidence_reference:e.target.value}))} placeholder="Extrato BCI de 16/11/2026 — referência ..."/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy}>Submeter para validação</button></div>
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
    <div className="cis-cash-form-title"><h4>Transferência entre contas</h4><p>Movimentação interna: uma saída e uma entrada atómicas.</p></div>
    <label>Conta de origem<select required value={transfer.source_account_id} onChange={e=>setTransfer(p=>({...p,source_account_id:e.target.value}))}><option value="">Seleccionar origem</option>{active.map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Conta de destino<select required value={transfer.destination_account_id} onChange={e=>setTransfer(p=>({...p,destination_account_id:e.target.value}))}><option value="">Seleccionar destino</option>{active.filter(a=>String(a.id)!==transfer.source_account_id).map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}</select></label>
    <label>Valor (MT)<input required inputMode="decimal" value={transfer.amount} onChange={e=>setTransfer(p=>({...p,amount:e.target.value}))} placeholder="0.00"/></label>
    <label>Referência / comprovativo<input required minLength={5} value={transfer.evidence_reference} onChange={e=>setTransfer(p=>({...p,evidence_reference:e.target.value}))}/></label>
    <label className="cis-cash-wide">Descrição<input required minLength={4} value={transfer.description} onChange={e=>setTransfer(p=>({...p,description:e.target.value}))}/></label>
    <div className="cis-cash-form-actions"><button type="button" onClick={resetAction}>Cancelar</button><button type="submit" disabled={busy||active.length<2}>Confirmar transferência</button></div>
   </form>}
   {loading?<div className="cis-cash-empty">A consultar movimentos…</div>:movements.length===0?<div className="cis-cash-empty"><Clock3 size={24}/><strong>Sem movimentos</strong><p>O histórico da Caixa começará após a activação das contas. Os movimentos do Extracto antigo não foram importados automaticamente.</p></div>:
    <div className="cis-table-scroll"><table className="cis-cash-table"><thead><tr><th>Data</th><th>Referência</th><th>Conta</th><th>Categoria</th><th>Descrição</th><th>Movimento</th><th>Valor</th></tr></thead><tbody>
    {movements.map(m=><tr key={m.id}><td>{readableDate(m.effective_at)}</td><td title={m.reference}>{m.reference}</td><td>{accounts.find(a=>a.id===m.account_id)?.code??m.account_id}</td><td>{m.category}</td><td>{m.description}</td><td><span className="cis-cash-type">{['entrada','transferencia_entrada','estorno_entrada'].includes(m.type)?<ArrowDownLeft size={15}/>:<ArrowUpRight size={15}/>} {m.type.replaceAll('_',' ')}</span></td><td className="cis-cash-money">{formatMoney(m.amount)}</td></tr>)}
    </tbody></table></div>}
  </div>}
  <p className="cis-cash-footnote"><ShieldCheck size={15}/> A Caixa padrão regista movimentos operacionais. Os contratos e o Extracto legado não foram modificados nem atribuídos automaticamente a contas. A reconciliação bancária automática é exclusiva da Tesouraria.</p>
 </section>;
}
