import {useEffect,useState} from 'react';

type Row=Record<string,string|number|null>;
type Operation='reforco'|'abate'|'recuperacao';
type Result={valor:string;valor_a_pagar?:string;saldo_recuperavel?:string;saldo_apos_operacao?:string;vencimento?:string;taxa?:number;prestacoes?:number;notice:string};
type Account={id:number;code:string;name:string;status:string};
const api=import.meta.env.VITE_API_URL||'/api';
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});

export default function CreditChanges({token,numero,installments,onChanged}:{token:string;numero:string;installments:Row[];onChanged:()=>void}){
 const open=installments.filter(row=>row.Estado==='Pendente'||row.Estado==='Pagamento Atrasado');
 const writtenOff=installments.filter(row=>row.Estado==='Abatido');
 const fixed=open.filter(row=>String(row['Tipo de Juro']).trim().toLowerCase()==='fixo');
 const [operation,setOperation]=useState<Operation|null>(null);
 const [order,setOrder]=useState('');
 const [amount,setAmount]=useState('');
 const [rate,setRate]=useState('');
 const [accounts,setAccounts]=useState<Account[]>([]);
 const [account,setAccount]=useState('');
 const [reference,setReference]=useState('');
 const [reason,setReason]=useState('');
 const [preview,setPreview]=useState<Result|null>(null);
 const [error,setError]=useState('');
 const [success,setSuccess]=useState('');
 const [saving,setSaving]=useState(false);
 const [requestKey,setRequestKey]=useState('');
 const cash=operation!==null&&operation!=='abate';
 const endpoint=api+'/cis/contractos/'+encodeURIComponent(numero)+'/alteracao-credito';
 const normalAmount=amount.trim().replace(',','.');
 const canPreview=operation==='abate'||(cash&&!!order&&/^\d+(?:\.\d{1,2})?$/.test(normalAmount)&&Number(normalAmount)>0);
 const payload=operation&&{operacao:operation,...(cash?{ordem:Number(order),valor:normalAmount}:{}),...(operation==='reforco'&&rate!==''?{taxa:Number(rate)}:{})};
 const headers={Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'};

 useEffect(()=>{
  if(!cash)return;
  const ctrl=new AbortController();
  fetch(api+'/cis/caixa/contas',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(r=>r.ok?r.json():null).then(data=>{if(!ctrl.signal.aborted)setAccounts(data?.accounts??[]);}).catch(()=>{});
  return()=>ctrl.abort();
 },[cash,token]);

 useEffect(()=>{
  if(!canPreview||!payload)return;
  const controller=new AbortController();
  const timer=window.setTimeout(async()=>{
   try{
    const response=await fetch(endpoint,{method:'POST',headers,body:JSON.stringify(payload),signal:controller.signal});
    const result=await response.json();
    if(!response.ok)throw new Error(result.message||'Não foi possível calcular a operação.');
    if(!controller.signal.aborted)setPreview(result);
   }catch(e){if(!controller.signal.aborted)setError(e instanceof Error?e.message:'Não foi possível calcular a operação.');}
  },400);
  return()=>{window.clearTimeout(timer);controller.abort();};
 },[endpoint,token,operation,order,normalAmount,rate,canPreview]);

 function resetPreview(){setPreview(null);setError('');setRequestKey('');}
 function choose(value:Operation){resetPreview();setOperation(value);setOrder('');setAmount('');setRate('');setReason('');setReference('');setAccount('');setSuccess('');}

 async function submit(){
  if(!preview||!payload||saving)return;
  setSaving(true);setError('');
  try{
   const key=requestKey||crypto.randomUUID();setRequestKey(key);
   const response=await fetch(endpoint,{method:'POST',headers,body:JSON.stringify({...payload,confirmar:true,
    idempotency_key:key,motivo:reason,valor_confirmado:preview.valor_a_pagar??preview.saldo_apos_operacao??preview.valor,
    ...(cash?{conta_id:Number(account),referencia:reference}:{} )})});
   const result=await response.json();
   if(!response.ok)throw new Error(result.message||'Não foi possível registar a operação.');
   setSuccess(result.notice);setOperation(null);resetPreview();onChanged();
  }catch(e){setError(e instanceof Error?e.message:'Não foi possível registar a operação.');}
  finally{setSaving(false);}
 }

 if(!fixed.length&&!open.length&&!writtenOff.length&&!success)return null;
 const choices=operation==='recuperacao'?writtenOff:fixed;
 return <section className="cis-collections-panel">
  <div className="cis-collections-sectionhead"><h3>Alterações de crédito</h3></div>
  <div className="cis-extracto-actions">
   {!!fixed.length&&<button type="button" disabled={saving} aria-pressed={operation==='reforco'} onClick={()=>choose('reforco')}>Reforço de crédito</button>}
   {!!open.length&&<button type="button" disabled={saving} aria-pressed={operation==='abate'} onClick={()=>choose('abate')}>Abate de crédito</button>}
   {!!writtenOff.length&&<button type="button" disabled={saving} aria-pressed={operation==='recuperacao'} onClick={()=>choose('recuperacao')}>Recuperar saldo abatido</button>}
  </div>
  {success&&<p role="status">{success}</p>}
  {operation&&<fieldset disabled={saving}>
   <legend>{operation==='reforco'?'Reforço de crédito':operation==='abate'?'Abate do contrato':'Recuperação de crédito abatido'}</legend>
   <div className="cis-collections-fields">
    {cash&&<>
     <label>Prestação<select value={order} onChange={e=>{resetPreview();setOrder(e.target.value);}}><option value="">Seleccione</option>
      {choices.map(row=><option key={String(row.Ordem)} value={String(row.Ordem)}>Prestação {row['Nº da Parcela']}</option>)}
     </select></label>
     <label>Montante (MT)<input inputMode="decimal" value={amount} onChange={e=>{resetPreview();setAmount(e.target.value);}}/></label>
     {operation==='reforco'&&<label>Taxa de juro (%), opcional<input type="number" min="0" max="100" step="1" value={rate} placeholder="Manter taxa actual" onChange={e=>{resetPreview();setRate(e.target.value);}}/></label>}
     <label>{operation==='reforco'?'Conta de origem':'Conta receptora'}<select value={account} onChange={e=>{setAccount(e.target.value);setRequestKey('');}}>
      <option value="">Seleccione uma conta activa</option>{accounts.filter(a=>a.status==='activa').map(a=><option value={a.id} key={a.id}>{a.code} · {a.name}</option>)}
     </select></label>
     <label>Referência<input value={reference} maxLength={60} onChange={e=>{setReference(e.target.value);setRequestKey('');}}/></label>
    </>}
    <label>Motivo<input value={reason} maxLength={180} onChange={e=>setReason(e.target.value)}/></label>
   </div>
   {error&&<p role="alert">{error}</p>}
   {preview&&<div className="cis-collections-quote"><p>{preview.notice}</p><dl>
    <div><dt>{operation==='abate'?'Saldo a abater':'Montante da operação'}</dt><dd>{money.format(Number(preview.valor))} MT</dd></div>
    {preview.prestacoes!==undefined&&<div><dt>Prestações afectadas</dt><dd>{preview.prestacoes}</dd></div>}
    {preview.valor_a_pagar&&<div><dt>Novo valor a pagar</dt><dd>{money.format(Number(preview.valor_a_pagar))} MT</dd></div>}
    {preview.vencimento&&<div><dt>Vencimento mantido</dt><dd>{preview.vencimento.split('-').reverse().join('/')}</dd></div>}
    {preview.taxa!==undefined&&<div><dt>Taxa de juro</dt><dd>{preview.taxa}%</dd></div>}
    {preview.saldo_apos_operacao&&<div><dt>Saldo recuperável após operação</dt><dd>{money.format(Number(preview.saldo_apos_operacao))} MT</dd></div>}
   </dl></div>}
   <button type="button" className="cis-collections-primary" disabled={saving||!preview||reason.trim().length<5||(cash&&(!account||reference.trim().length<4))}
    onClick={()=>void submit()}>{saving?'A registar…':operation==='abate'?'Confirmar abate':operation==='reforco'?'Confirmar reforço':'Registar recuperação'}</button>
  </fieldset>}
 </section>;
}
