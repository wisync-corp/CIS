// Definições › Subscrição: plano do CIS, utilizadores, renovação e pagamento por M-Pesa ou e-Mola.
// Estes pagamentos são da licença do software (para a WiSync) e nunca entram na Caixa nem no Extracto.
import {useCallback,useEffect,useMemo,useState} from 'react';
import {CreditCard,CheckCircle2,AlertTriangle,Users,Minus,Plus,Smartphone,Loader2,Clock,XCircle,Receipt,Lock,Copy} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Plan={id:string;nome:string;preco:number;utilizadores:number;extra:number;descricao:string};
type Mod={id:string;nome:string;preco:number;disponivel:boolean;descricao:string};
type Method={id:'mpesa'|'emola';nome:string;operadora:string;prefixos:string;modo:'api'|'manual'|'simulacao';numero_manual:string};
type Pay={referencia:string;plano:string;plano_nome:string;utilizadores_extra:number;ciclo:string;meses:number;valor:number;metodo:string;modo:string;telefone:string;estado:'pendente'|'confirmado'|'falhado'|'cancelado';comprovativo:string|null;operadora_transacao:string|null;mensagem:string|null;valido_de:string|null;valido_ate:string|null;criado_em:string;confirmado_em:string|null};
type Sub={plano:string;plano_nome:string;ciclo:string;utilizadores_extra:number;inicio:string;valido_ate:string;limite:number;utilizadores:number;estado:'teste'|'activa'|'a_vencer'|'carencia'|'expirada';dias:number};
type Data={subscricao:Sub;planos:Plan[];modulos:Mod[];metodos:Method[];meses_gratis_anual:number;dias_carencia:number;taxa_implementacao:{min:number;max:number};pagamentos:Pay[]};

const mt=(v:number)=>{const [i,d]=(Math.round(v*100)/100).toFixed(2).split('.');return i.replace(/\B(?=(\d{3})+(?!\d))/g,'\u00a0')+(d==='00'?'':','+d)+' MT';};
const dt=(v:string|null)=>{if(!v)return '—';const d=new Date(v.replace(' ','T'));return isNaN(+d)?v:d.toLocaleDateString('pt-PT');};
const STATE:Record<Sub['estado'],{label:string;tone:string}>={teste:{label:'Período de teste',tone:'warn'},activa:{label:'Activa',tone:'ok'},a_vencer:{label:'A vencer',tone:'warn'},carencia:{label:'Vencida · em carência',tone:'bad'},expirada:{label:'Expirada',tone:'bad'}};
const PSTATE:Record<Pay['estado'],string>={pendente:'Pendente',confirmado:'Confirmado',falhado:'Falhado',cancelado:'Cancelado'};

export default function Subscription({token}:{token:string}){
 const [data,setData]=useState<Data|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 const [plan,setPlan]=useState(''),[extra,setExtra]=useState(0),[cycle,setCycle]=useState<'mensal'|'anual'>('mensal');
 const [method,setMethod]=useState<'mpesa'|'emola'>('mpesa'),[phone,setPhone]=useState('');
 const [busy,setBusy]=useState(false),[result,setResult]=useState<{ok:boolean;msg:string;pay?:Pay}|null>(null);
 const [proof,setProof]=useState(''),[copied,setCopied]=useState('');
 const call=useCallback(async(path:string,init?:RequestInit)=>{
  const r=await fetch(API+path,{...init,headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json',...(init?.headers??{})}});
  const j=await r.json().catch(()=>({}));
  return {ok:r.ok,status:r.status,j};},[token]);
 const load=useCallback(async()=>{
  const {ok,j}=await call('/cis/subscricao');
  if(!ok){setError(j.message||'Não foi possível carregar a subscrição.');setLoading(false);return;}
  const d=j as Data;setData(d);setError('');setLoading(false);
  setPlan(p=>p||d.subscricao.plano);setExtra(e=>e||d.subscricao.utilizadores_extra);
 },[call]);
 useEffect(()=>{load();},[load]);

 const p=data?.planos.find(x=>x.id===plan);
 const minExtra=p&&data?Math.max(0,data.subscricao.utilizadores-p.utilizadores):0;
 useEffect(()=>{if(extra<minExtra)setExtra(minExtra);},[minExtra,extra]);
 const quote=useMemo(()=>{if(!p||!data)return null;const mensal=p.preco+extra*p.extra;const pagos=cycle==='anual'?12-data.meses_gratis_anual:1;
  return {mensal,total:mensal*pagos,meses:cycle==='anual'?12:1,poupanca:cycle==='anual'?mensal*data.meses_gratis_anual:0,users:p.utilizadores+extra};},[p,extra,cycle,data]);
 const m=data?.metodos.find(x=>x.id===method);
 const digits=phone.replace(/\D/g,'').replace(/^258/,'');
 const phoneOk=/^8[2-7]\d{7}$/.test(digits)&&(method==='mpesa'?/^8[45]/.test(digits):/^8[67]/.test(digits));

 async function pay(){
  if(!quote||!phoneOk)return;setBusy(true);setResult(null);
  try{const {ok,j}=await call('/cis/subscricao/pagamentos',{method:'POST',body:JSON.stringify({plano:plan,utilizadores_extra:extra,ciclo:cycle,metodo:method,telefone:digits})});
   const pay=j.pagamento as Pay|undefined;
   setResult({ok:ok&&pay?.estado!=='falhado',msg:j.mensagem||j.message||(ok?'Pedido registado.':'Pagamento recusado.'),pay});
   if(pay)await load();
  }catch{setResult({ok:false,msg:'Sem ligação ao servidor. Verifique o SMS antes de tentar de novo.'});}finally{setBusy(false);}
 }
 async function sendProof(ref:string){
  const {ok,j}=await call(`/cis/subscricao/pagamentos/${ref}/comprovativo`,{method:'POST',body:JSON.stringify({comprovativo:proof.trim()})});
  if(ok){setProof('');setResult({ok:true,msg:'Comprovativo registado. A WiSync confirma o pagamento e o acesso é renovado.',pay:j.pagamento});load();}
  else setResult(r=>({ok:false,msg:j.message||'Não foi possível registar o comprovativo.',pay:r?.pay}));
 }
 async function cancel(ref:string){const {ok,j}=await call(`/cis/subscricao/pagamentos/${ref}/cancelar`,{method:'POST'});if(ok){setResult(null);load();}else setError(j.message||'Não foi possível cancelar.');}
 const copy=(v:string)=>{navigator.clipboard?.writeText(v).then(()=>{setCopied(v);setTimeout(()=>setCopied(''),1500);}).catch(()=>{});};

 if(loading)return <section className="cis-panel cis-co"><div className="cis-co-loading"><Loader2 size={18} className="cis-spin"/> A carregar a subscrição…</div></section>;
 if(error&&!data)return <section className="cis-panel cis-co"><div className="cis-co-alert bad"><AlertTriangle size={17}/>{error}</div></section>;
 if(!data)return null;
 const s=data.subscricao;const st=STATE[s.estado];
 const pct=Math.min(100,Math.round(s.utilizadores/Math.max(1,s.limite)*100));
 const pendingManual=data.pagamentos.find(x=>x.estado==='pendente'&&x.modo==='manual');
 const shown=result?.pay&&result.pay.estado==='pendente'&&result.pay.modo==='manual'?result.pay:pendingManual;
 const shownMethod=data.metodos.find(x=>x.id===shown?.metodo);

 return <div className="cis-sub">
  <section className="cis-panel cis-sub-head">
   <div className="cis-sub-current">
    <span className="cis-sub-ico"><CreditCard size={22}/></span>
    <div><span className="cis-sub-kicker">Subscrição do CIS</span><h3>Plano {s.plano_nome} <em className={'cis-sub-badge '+st.tone}>{st.label}</em></h3>
     <p>{s.estado==='expirada'?<>Expirou em <b>{dt(s.valido_ate)}</b>. Renove para voltar a activar utilizadores.</>:s.estado==='carencia'?<>Venceu em <b>{dt(s.valido_ate)}</b> · {data.dias_carencia+s.dias} dia(s) de carência restantes.</>:<>Válido até <b>{dt(s.valido_ate)}</b> · {s.dias===0?'termina hoje':`faltam ${s.dias} dia(s)`}{s.ciclo==='anual'?' · pagamento anual':''}.</>}</p></div>
   </div>
   <div className="cis-sub-usage"><span><Users size={15}/> Utilizadores activos</span><b>{s.utilizadores} de {s.limite}</b><i><u style={{width:pct+'%'}} className={pct>=100?'full':''}/></i>
    <small>{pct>=100?'Limite atingido: acrescente utilizadores para criar novas contas.':`${s.limite-s.utilizadores} lugar(es) livre(s).`}</small></div>
  </section>

  {error&&<div className="cis-co-alert bad"><AlertTriangle size={17}/>{error}</div>}

  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>1. Escolha o plano</h3><p>Preços mensais por instituição. Todos os planos incluem mutuários, propostas, contratos, cobranças, extractos, Caixa e relatórios.</p></div></div>
   <div className="cis-sub-plans" role="radiogroup" aria-label="Planos">
    {data.planos.map(x=><button key={x.id} type="button" role="radio" aria-checked={plan===x.id} className={'cis-sub-plan'+(plan===x.id?' on':'')} onClick={()=>setPlan(x.id)}>
     <span className="cis-sub-plan-top"><b>{x.nome}</b>{s.plano===x.id&&<em>Actual</em>}</span>
     <span className="cis-sub-price">{mt(x.preco)}<small>/mês</small></span>
     <span className="cis-sub-feat"><Users size={14}/> {x.utilizadores} utilizadores incluídos</span>
     <span className="cis-sub-feat"><Plus size={14}/> {mt(x.extra)} por utilizador extra</span>
     <span className="cis-sub-desc">{x.descricao}</span>
    </button>)}
   </div>
   {data.modulos.length>0&&<div className="cis-sub-mods">{data.modulos.map(x=><span key={x.id} className="cis-sub-mod" title={x.descricao}><Lock size={13}/> {x.nome} <b>+{mt(x.preco)}/mês</b> <em>{x.disponivel?'Disponível':'Brevemente'}</em></span>)}</div>}
  </section>

  {p&&quote&&<section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>2. Utilizadores e período</h3><p>Tem <b>{s.utilizadores}</b> utilizador(es) activo(s){minExtra>0?<>: o plano {p.nome} precisa de pelo menos <b>{minExtra}</b> extra</>:null}.</p></div></div>
   <div className="cis-sub-config">
    <div className="cis-sub-stepper"><span>Utilizadores extra</span>
     <div><button type="button" aria-label="Menos um utilizador" disabled={extra<=minExtra} onClick={()=>setExtra(e=>Math.max(minExtra,e-1))}><Minus size={15}/></button>
      <b aria-live="polite">{extra}</b><button type="button" aria-label="Mais um utilizador" disabled={extra>=200} onClick={()=>setExtra(e=>Math.min(200,e+1))}><Plus size={15}/></button></div>
     <small>{p.utilizadores} incluídos + {extra} extra = <b>{quote.users} utilizadores</b></small></div>
    <div className="cis-sub-cycle" role="radiogroup" aria-label="Período">
     <button type="button" role="radio" aria-checked={cycle==='mensal'} className={cycle==='mensal'?'on':''} onClick={()=>setCycle('mensal')}><b>Mensal</b><small>renova todos os meses</small></button>
     <button type="button" role="radio" aria-checked={cycle==='anual'} className={cycle==='anual'?'on':''} onClick={()=>setCycle('anual')}><b>Anual</b><small>{data.meses_gratis_anual} meses grátis</small></button>
    </div>
    <div className="cis-sub-total"><span>Total a pagar</span><strong>{mt(quote.total)}</strong>
     <small>{mt(quote.mensal)}/mês{cycle==='anual'?<> × {12-data.meses_gratis_anual} meses · poupa <b>{mt(quote.poupanca)}</b></>:null} · acesso por {quote.meses===12?'12 meses':'1 mês'}</small></div>
   </div>
  </section>}

  {p&&quote&&<section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>3. Pagamento</h3><p>Pagamento da licença do CIS à WiSync. Fica separado da Caixa e dos reembolsos dos mutuários.</p></div></div>
   <div className="cis-sub-methods" role="radiogroup" aria-label="Método de pagamento">
    {data.metodos.map(x=><button key={x.id} type="button" role="radio" aria-checked={method===x.id} className={'cis-sub-method k-'+x.id+(method===x.id?' on':'')} onClick={()=>{setMethod(x.id);setResult(null);}}>
     <span className="logo">{x.id==='mpesa'?'M':'e'}</span><span><b>{x.nome}</b><small>{x.operadora} · números {x.prefixos}</small></span>
     <em>{x.modo==='api'?'Pagamento directo':x.modo==='simulacao'?'Simulação':'Manual'}</em></button>)}
   </div>
   {m&&<p className="cis-sub-howto">{m.modo==='api'?<><Smartphone size={15}/><span>Vai receber no telemóvel um pedido de PIN {m.nome}. Confirme e o acesso é renovado na hora.</span></>
    :m.modo==='simulacao'?<><AlertTriangle size={15}/><span>Ambiente de testes: o pagamento é simulado e não movimenta dinheiro.</span></>
    :<><Clock size={15}/><span>A ligação directa à {m.operadora} ainda não está activa: depois de gerar a referência, envie o valor {m.numero_manual?<>para <b>{m.numero_manual}</b></>:'para o número da WiSync'} e registe o ID da transacção do SMS.</span></>}</p>}
   <div className="cis-sub-payrow">
    <label><span>Número {m?.nome} de quem paga</span><input inputMode="tel" autoComplete="tel" placeholder={method==='mpesa'?'84 123 4567':'86 123 4567'} value={phone} onChange={e=>setPhone(e.target.value)} aria-invalid={phone!==''&&!phoneOk}/>
     {phone!==''&&!phoneOk&&<small className="bad">Use um número {m?.operadora} começado por {m?.prefixos}.</small>}</label>
    <button type="button" className="cis-sub-paybtn" disabled={!phoneOk||busy} onClick={pay}>{busy?<><Loader2 size={16} className="cis-spin"/> {m?.modo==='api'?'A aguardar o PIN no telemóvel…':'A registar…'}</>:<>{m?.modo==='manual'?'Gerar referência de':'Pagar'} {mt(quote.total)}</>}</button>
   </div>
   {result&&!(result.pay&&result.pay.estado==='pendente'&&result.pay.modo==='manual')&&<div className={'cis-co-alert '+(result.ok?'ok':'bad')} role="status">{result.ok?<CheckCircle2 size={17}/>:<XCircle size={17}/>}<span>{result.msg}{result.pay?.estado==='confirmado'&&result.pay.valido_ate?<> Acesso válido até <b>{dt(result.pay.valido_ate)}</b>.</>:null}</span></div>}
  </section>}

  {shown&&<section className="cis-panel cis-co-section cis-sub-manual" aria-live="polite">
   <div className="cis-co-section-head"><div><h3>Pagamento pendente · {shown.referencia}</h3><p>Envie exactamente o valor com a referência na descrição. A WiSync confirma e o plano é activado.</p></div></div>
   <div className="cis-sub-manual-grid">
    <div><span>Valor</span><b>{mt(shown.valor)}</b></div>
    <div><span>{shownMethod?.nome??shown.metodo} para</span><b>{shownMethod?.numero_manual||'número indicado pela WiSync'}</b>{shownMethod?.numero_manual&&<button type="button" onClick={()=>copy(shownMethod.numero_manual)}><Copy size={13}/>{copied===shownMethod.numero_manual?'Copiado':'Copiar'}</button>}</div>
    <div><span>Referência</span><b>{shown.referencia}</b><button type="button" onClick={()=>copy(shown.referencia)}><Copy size={13}/>{copied===shown.referencia?'Copiado':'Copiar'}</button></div>
   </div>
   {shown.comprovativo?<div className="cis-co-alert ok"><Clock size={17}/><span>Comprovativo <b>{shown.comprovativo}</b> registado: a aguardar confirmação da WiSync.</span></div>
   :<div className="cis-sub-payrow"><label><span>ID da transacção (do SMS de confirmação)</span><input value={proof} onChange={e=>setProof(e.target.value)} placeholder="ex.: 9KJ3XYZ1AB"/></label>
     <button type="button" className="cis-sub-paybtn" disabled={proof.trim().length<6} onClick={()=>sendProof(shown.referencia)}>Registar comprovativo</button>
     <button type="button" className="cis-client-edit" onClick={()=>cancel(shown.referencia)}>Cancelar</button></div>}
  </section>}

  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3><Receipt size={17} style={{verticalAlign:'-3px',marginRight:6}}/>Histórico de pagamentos</h3><p>Taxa de implementação (migração, configuração e formação): {mt(data.taxa_implementacao.min)} a {mt(data.taxa_implementacao.max)}, paga uma vez.</p></div></div>
   {data.pagamentos.length===0?<p className="cis-sub-empty">Ainda sem pagamentos.</p>:<div className="cis-table-scroll"><table><thead><tr><th>Data</th><th>Referência</th><th>Plano</th><th>Período</th><th>Método</th><th>Valor</th><th>Estado</th><th>Validade</th></tr></thead><tbody>
    {data.pagamentos.map(x=><tr key={x.referencia}><td>{dt(x.criado_em)}</td><td>{x.referencia}</td><td>{x.plano_nome}{x.utilizadores_extra?` + ${x.utilizadores_extra} utiliz.`:''}</td><td>{x.ciclo==='anual'?'Anual':'Mensal'}</td>
     <td>{x.metodo==='mpesa'?'M-Pesa':'e-Mola'} · {x.telefone}</td><td>{mt(x.valor)}</td><td><span className={'cis-days '+(x.estado==='confirmado'?'ok':x.estado==='pendente'?'warn':'bad')} title={x.mensagem??''}>{PSTATE[x.estado]}</span></td>
     <td>{x.valido_ate?`${dt(x.valido_de)} – ${dt(x.valido_ate)}`:'—'}</td></tr>)}
   </tbody></table></div>}
  </section>
 </div>;
}
