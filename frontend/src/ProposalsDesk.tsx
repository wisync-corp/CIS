// Propostas de crédito com aprovação: criar, analisar, submeter, decidir e abrir contrato.
import {useCallback,useEffect,useMemo,useState,type FormEvent} from 'react';
import {useCreditCharges} from './encargos';
import {FilePlus2,ClipboardCheck,AlertCircle,CheckCircle2,XCircle,Undo2,Send,Ban,ArrowLeft,FileText,Gauge,UserRound,Search,ArrowUpRight,Clock,MessageCircle,PackageX,Hourglass,Inbox,BellRing,Check} from 'lucide-react';
import {useCan} from './perms';
import DossierPanel,{ChecklistView,type Check as DossierCheck} from './CreditDossier';

const API=import.meta.env.VITE_API_URL||'/api';
type Analysis={taxa:number|null;prestacoes:number|null;prestacao:string|null;total_a_pagar:string|null;juros_totais:string|null;encargo_mensal:string|null;rendimento_disponivel:string;taxa_esforco:number|null;taxa_esforco_max:number;alertas:string[];recomendacao:string};
type History={contratos:number;contratos_activos:number;contratos_em_atraso:number;contratos_abatidos:number;max_dias_atraso:number;capital_em_divida:string;prestacoes_pagas:number;pagas_em_atraso:number;score:number|null;classificacao:string;score_minimo:number};
export type Proposal={finalidade_categoria?:string|null;encargos?:{taxa_operacao:string;valor_entregue:string};id:number;numero:string;estado:string;mutuario:{id:number;nome:string};montante:number;montante_aprovado:number|null;tipo_juro:string;estrategia:string;periodo_dias:number;prazo:string;modalidade:string;taxa:number|null;taxa_aprovada:number|null;finalidade:string;actividade:string|null;rendimento_mensal:string;despesas_mensais:string;outras_dividas_mensais:string;garantias:string|null;observacoes:string|null;analise:{calculo:Analysis;historico:History}|null;decisao_notas:string|null;decidido_por:string|null;decidido_em:string|null;valida_ate:string|null;contrato:string|null;criado_por:string|null;criado_em:string;submetido_em:string|null;agencia:string|null;avisado:boolean|null};
type Limits={credito_minimo:number|null;credito_maximo:number|null;taxa_minima:number|null;taxa_maxima:number|null};
type Notice={estado:string;modelo:string;mensagem:string;contacto:string|null;telefone:string|null;enviado:boolean;enviado_em:string|null};
type Provision={classe:string;score:number|null;factores:string[];taxa:string|null;montante:number;provisao:string|null;versao:string};
type Detail=Proposal&{provisao?:Provision|null;provisao_aceite?:Provision|null;limites_agencia:Limits;aviso:Notice|null;verificacao?:DossierCheck;historico_mutuario:History;analise_actual:Analysis|null;eventos:{accao:string;de:string|null;para:string|null;notas:string|null;por:string;em:string}[];pode:{editar:boolean;submeter:boolean;cancelar:boolean;decidir:boolean;motivo_nao_decidir:string|null;abrir_contrato:boolean;nao_levantada:boolean}};
type ListResp={instalado:boolean;data:Proposal[];contagem:Record<string,number>;limite_aprovacao:number|null;validade_dias:number;avisos_por_enviar?:number;limites_agencia?:Limits};

export const ESTADOS:Record<string,{label:string;tone:string}>={rascunho:{label:'Rascunho',tone:'muted'},submetida:{label:'Para decisão',tone:'info'},devolvida:{label:'Devolvida',tone:'warn'},
 aprovada:{label:'Aprovada',tone:'ok'},rejeitada:{label:'Rejeitada',tone:'bad'},cancelada:{label:'Cancelada',tone:'muted'},desembolsada:{label:'Desembolsada',tone:'ok'},expirada:{label:'Expirada',tone:'muted'},nao_levantada:{label:'Não levantada',tone:'muted'}};
const ACCOES:Record<string,string>={risco_aceite:'Risco e provisão aceites',criada:'Criada',alterada:'Alterada',submetida:'Submetida para decisão',aprovada:'Aprovada',rejeitada:'Rejeitada',devolvida:'Devolvida para correcção',cancelada:'Cancelada',desembolsada:'Desembolsada',expirou:'Aprovação expirou',nao_levantada:'Não levantada pelo mutuário'};
const STRATS:Record<string,string[]>={Fixo:['Amortização fixa com juro fixo','Prestação fixa pelo total','Prestação fixa simples'],Composto:['Amortização fixa com juro decrescente','Tabela Price'],Variável:['Juro variável na cobrança']};
const termsFor=(p:string)=>p==='15'?['1 Semana','2 Semanas']:['1 Semana','2 Semanas','3 Semanas','1 Mês','2 Meses','3 Meses'];
const modesFor=(t:string)=>t==='1 Semana'?['Uma Parcela']:['2 Semanas','3 Semanas','1 Mês'].includes(t)?['Semanal','Uma Parcela']:['Semanal','Mensal','Uma Parcela'];
const mt=(v:unknown)=>v===null||v===undefined||v===''?'—':Number(v).toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';
const dt=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'—';
const daysLeft=(v:string|null)=>{if(!v)return null;const d=new Date(v.slice(0,10)+'T23:59:59');return Math.ceil((d.getTime()-Date.now())/864e5);};
const limitsText=(l?:Limits|null)=>{if(!l)return '';const f=(n:number)=>n.toLocaleString('pt-MZ');const parts=[];
 if(l.credito_minimo!=null||l.credito_maximo!=null)parts.push('montante '+(l.credito_minimo!=null?'de '+f(l.credito_minimo)+' ':'')+(l.credito_maximo!=null?'até '+f(l.credito_maximo)+' ':'')+'MT');
 if(l.taxa_minima!=null||l.taxa_maxima!=null)parts.push('taxa '+(l.taxa_minima!=null?'de '+l.taxa_minima+'% ':'')+(l.taxa_maxima!=null?'até '+l.taxa_maxima+'%':''));
 return parts.join(' · ');};
// Etapas da proposta (como no Loandisk): pedido → análise → decisão → desembolso.
const STEPS=['Pedido','Análise','Decisão','Desembolso'];
function stepOf(s:string):{at:number;bad:boolean}{
 switch(s){case 'rascunho':case 'devolvida':return {at:0,bad:false};case 'submetida':return {at:1,bad:false};case 'aprovada':return {at:3,bad:false};
  case 'desembolsada':return {at:4,bad:false};case 'rejeitada':return {at:2,bad:true};default:return {at:3,bad:true};}}
function Stepper({estado}:{estado:string}){const {at,bad}=stepOf(estado);
 return <ol className="cis-prop-stepper" aria-label="Etapas da proposta">{STEPS.map((l,i)=>{const st=i<at?'done':i===at?(bad?'bad':'now'):'next';
  return <li key={l} className={st}><span>{st==='done'?<Check size={13}/>:st==='bad'?<XCircle size={13}/>:i+1}</span>{l}</li>;})}</ol>;}
const Badge=({s}:{s:string})=><span className={'cis-prop-badge '+(ESTADOS[s]?.tone??'muted')}>{ESTADOS[s]?.label??s}</span>;

function useApi(token:string){
 return useCallback(async<T,>(path:string,method='GET',body?:unknown):Promise<T>=>{
  const r=await fetch(API+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});
  const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error((j.errors?Object.values(j.errors as Record<string,string[]>).flat()[0]:undefined)||j.message||'Operação recusada.');
  return j as T;
 },[token]);
}

export default function ProposalsDesk({token,onOpenContract,onSendNotices}:{token:string;onOpenContract:(p:Proposal)=>void;onSendNotices?:()=>void}){
 const api=useApi(token);const can=useCan();
 const [tab,setTab]=useState('todas'),[q,setQ]=useState(''),[list,setList]=useState<ListResp|null>(null),[error,setError]=useState('');
 const [open,setOpen]=useState<number|null>(null),[editing,setEditing]=useState<Proposal|'new'|null>(null);
 const load=useCallback(async()=>{setError('');try{setList(await api<ListResp>('/cis/propostas?estado='+tab+(q?'&q='+encodeURIComponent(q):'')));}catch(e){setError((e as Error).message);}},[api,tab,q]);
 useEffect(()=>{const t=window.setTimeout(()=>void load(),q?300:0);return()=>window.clearTimeout(t);},[load,q]);
 if(editing)return <ProposalForm token={token} initial={editing==='new'?null:editing} onCancel={()=>setEditing(null)} onSaved={id=>{setEditing(null);setOpen(id);void load();}}/>;
 if(open!==null)return <ProposalDetail token={token} id={open} onBack={()=>{setOpen(null);void load();}} onEdit={p=>setEditing(p)} onOpenContract={onOpenContract}/>;
 const c=list?.contagem??{};
 const tabs=[['todas','Todas'],...(can('propostas.aprovar')?[['para_decidir','Para eu decidir']]:[]),['rascunho','Rascunhos'],['submetida','Submetidas'],['devolvida','Devolvidas'],['aprovada','Aprovadas'],['desembolsada','Desembolsadas'],['rejeitada','Rejeitadas'],['nao_levantada','Não levantadas']];
 const avisos=list?.avisos_por_enviar??0;const lim=limitsText(list?.limites_agencia);
 const kpis:[string,string,number,typeof Inbox,string][]=[...(can('propostas.aprovar')?[['para_decidir','À espera da sua decisão',c.para_decidir??0,Inbox,'info'] as [string,string,number,typeof Inbox,string]]:[]),
  ['submetida','Em análise',c.submetida??0,Hourglass,'muted'],['devolvida','Devolvidas para correcção',c.devolvida??0,Undo2,'warn'],['aprovada','Aprovadas por levantar',c.aprovada??0,CheckCircle2,'ok']];
 return <section className="cis-panel cis-proposals">
  <div className="cis-users-head"><div><h3><ClipboardCheck size={18}/> Propostas de crédito</h3>
   <p>Todo o crédito começa numa proposta: análise do mutuário, decisão de quem aprova e, só depois, desembolso.{list?.limite_aprovacao!=null&&list.limite_aprovacao>0?` O seu limite de aprovação: ${mt(list.limite_aprovacao)}.`:''}{list?.validade_dias?` Uma aprovação vale ${list.validade_dias} dias.`:''}</p></div>
   {can('propostas.criar')&&<button type="button" className="primary" onClick={()=>setEditing('new')}><FilePlus2 size={16}/> Nova proposta</button>}</div>
  {list?.instalado&&<div className="cis-prop-kpis">{kpis.map(([k,l,n,Icon,tone])=><button type="button" key={k} className={'cis-prop-kpi '+tone+(tab===k?' selected':'')} onClick={()=>setTab(tab===k?'todas':k)}>
    <Icon size={18}/><b>{n}</b><span>{l}</span></button>)}
   {avisos>0&&<button type="button" className="cis-prop-kpi alert" onClick={()=>onSendNotices?onSendNotices():setTab('aprovada')} title={onSendNotices?'Abrir a fila de avisos no Centro de Mensagens':undefined}>
    <BellRing size={18}/><b>{avisos}</b><span>{onSendNotices?'Mutuários por avisar · enviar':'Mutuários por avisar'}</span></button>}</div>}
  {lim&&<p className="cis-prop-limits"><AlertCircle size={14}/> Limites da sua agência: {lim}.</p>}
  {list&&!list.instalado&&<div className="cis-contract-notice"><AlertCircle size={17}/><span>As propostas ainda não estão instaladas. Na VPS execute: <code>bash deploy/install-credit-proposals.sh</code></span></div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  <div className="cis-prop-tabs" role="tablist">{tabs.map(([k,l])=><button key={k} role="tab" aria-selected={tab===k} className={tab===k?'selected':''} onClick={()=>setTab(k)}>
   {l}{k==='todas'?null:<b>{k==='para_decidir'?(c.para_decidir??0):(c[k]??0)}</b>}</button>)}</div>
  <label className="cis-prop-search"><Search size={15}/><input placeholder="Procurar mutuário, n.º da proposta ou contrato" value={q} onChange={e=>setQ(e.target.value)}/></label>
  {list&&list.data.length===0&&<p className="cis-msg-muted">{tab==='para_decidir'?'Não há propostas à espera da sua decisão.':'Sem propostas neste separador.'}</p>}
  {list&&list.data.length>0&&<div className="cis-table-scroll"><table><thead><tr><th>Proposta</th><th>Mutuário</th><th>Montante</th><th>Condições</th><th>Proponente</th><th>Estado</th><th></th></tr></thead>
   <tbody>{list.data.map(p=><tr key={p.id} className="cis-prop-row" onClick={()=>setOpen(p.id)}>
    <td><b>{p.numero}</b><small>{dt(p.submetido_em??p.criado_em)}</small></td><td>{p.mutuario.nome}</td>
    <td>{mt(p.montante_aprovado??p.montante)}{p.montante_aprovado!=null&&p.montante_aprovado!==p.montante&&<small>pedido {mt(p.montante)}</small>}</td>
    <td>{p.prazo} · {p.modalidade}</td><td>{p.criado_por??'—'}</td>
    <td><Badge s={p.estado}/>{p.estado==='aprovada'&&p.valida_ate&&(()=>{const n=daysLeft(p.valida_ate);return <small className={n!=null&&n<=3?'cis-prop-expiring':''}>{n!=null&&n<=0?'expira hoje':n===1?'expira amanhã':'expira em '+n+' dias'}</small>;})()}
     {p.contrato&&<small>{p.contrato}</small>}{p.avisado===false&&<small className="cis-prop-unnoticed"><BellRing size={11}/> por avisar</small>}</td>
    <td><ArrowUpRight size={15}/></td></tr>)}</tbody></table></div>}
 </section>;
}

function ClientPicker({token,value,onChange}:{token:string;value:{id:number;nome:string}|null;onChange:(v:{id:number;nome:string})=>void}){
 const [q,setQ]=useState(value?.nome??''),[opts,setOpts]=useState<{ID:number;'Nome Completo':string;Cidade:string|null}[]>([]),[show,setShow]=useState(false);
 useEffect(()=>{if(!show||q.trim().length<2){setOpts([]);return;}const c=new AbortController();const t=window.setTimeout(()=>{
  fetch(API+'/cis/clientes?q='+encodeURIComponent(q.trim()),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j)setOpts(j.data??[]);}).catch(()=>{});},250);return()=>{c.abort();window.clearTimeout(t);};},[q,show,token]);
 return <div className="cis-prop-picker"><input required placeholder="Nome ou ID do mutuário" value={q} onFocus={()=>setShow(true)} onChange={e=>{setQ(e.target.value);setShow(true);}}/>
  {show&&opts.length>0&&<ul role="listbox">{opts.slice(0,10).map(o=><li key={o.ID} role="option" aria-selected={value?.id===o.ID} onMouseDown={()=>{onChange({id:o.ID,nome:o['Nome Completo']});setQ(o['Nome Completo']);setShow(false);}}>
   <b>{o['Nome Completo']}</b><small>ID {o.ID}{o.Cidade?' · '+o.Cidade:''}</small></li>)}</ul>}</div>;
}

function AnalysisView({a,h}:{a:Analysis|null;h:History|null}){
 if(!a&&!h)return null;
 return <div className="cis-prop-analysis">
  {a&&<article><h4><Gauge size={15}/> Capacidade de pagamento</h4><dl>
   <div><dt>Prestação</dt><dd>{a.prestacao?mt(a.prestacao)+(a.prestacoes&&a.prestacoes>1?' × '+a.prestacoes:''):'—'}</dd></div>
   <div><dt>Taxa de juro</dt><dd>{a.taxa!=null?a.taxa+'%':'—'}</dd></div>
   <div><dt>Total a pagar</dt><dd>{mt(a.total_a_pagar)}</dd></div>
   <div><dt>Encargo mensal equivalente</dt><dd>{mt(a.encargo_mensal)}</dd></div>
   <div><dt>Rendimento disponível</dt><dd>{mt(a.rendimento_disponivel)}</dd></div>
   <div><dt>Taxa de esforço</dt><dd className={a.taxa_esforco!=null&&a.taxa_esforco_max>0&&a.taxa_esforco>a.taxa_esforco_max?'bad':''}>{a.taxa_esforco!=null?a.taxa_esforco+'%':'—'} <small>(máx. {a.taxa_esforco_max}%)</small></dd></div>
  </dl></article>}
  {h&&<article><h4><UserRound size={15}/> Histórico do mutuário</h4><dl>
   <div><dt>Score</dt><dd>{h.score??'—'} <small>{h.classificacao}</small></dd></div>
   <div><dt>Contratos</dt><dd>{h.contratos} <small>({h.contratos_activos} activo(s), {h.contratos_em_atraso} em atraso)</small></dd></div>
   <div><dt>Prestações pagas</dt><dd>{h.prestacoes_pagas} <small>({h.pagas_em_atraso} com atraso)</small></dd></div>
   <div><dt>Capital em dívida</dt><dd>{mt(h.capital_em_divida)}</dd></div>
   {h.max_dias_atraso>0&&<div><dt>Maior atraso actual</dt><dd className="bad">{h.max_dias_atraso} dias</dd></div>}
   {h.contratos_abatidos>0&&<div><dt>Créditos abatidos</dt><dd className="bad">{h.contratos_abatidos}</dd></div>}
  </dl></article>}
  {a&&<div className={'cis-prop-reco '+(a.alertas.length?'warn':'ok')}>{a.alertas.length?<AlertCircle size={17}/>:<CheckCircle2 size={17}/>}<div><strong>{a.recomendacao}</strong>{a.alertas.length>0&&<ul>{a.alertas.map(x=><li key={x}>{x}</li>)}</ul>}</div></div>}
 </div>;
}

function ProposalForm({token,initial,onCancel,onSaved}:{token:string;initial:Proposal|null;onCancel:()=>void;onSaved:(id:number)=>void}){
 const api=useApi(token);
 const enc=useCreditCharges(token);
 const [client,setClient]=useState<{id:number;nome:string}|null>(initial?initial.mutuario:null);
 const [v,setV]=useState<Record<string,string>>(()=>initial?{montante:String(initial.montante),tipo_juro:initial.tipo_juro,estrategia:initial.estrategia,periodo_dias:String(initial.periodo_dias),prazo:initial.prazo,modalidade:initial.modalidade,taxa:initial.taxa!=null?String(initial.taxa):'',finalidade:initial.finalidade,finalidade_categoria:initial.finalidade_categoria??'',actividade:initial.actividade??'',rendimento_mensal:String(Number(initial.rendimento_mensal)),despesas_mensais:String(Number(initial.despesas_mensais)),outras_dividas_mensais:String(Number(initial.outras_dividas_mensais)),garantias:initial.garantias??'',observacoes:initial.observacoes??''}
  :{montante:'',tipo_juro:'Fixo',estrategia:'Amortização fixa com juro fixo',periodo_dias:'30',prazo:'1 Mês',modalidade:'Uma Parcela',taxa:'',finalidade:'',finalidade_categoria:'',actividade:'',rendimento_mensal:'',despesas_mensais:'0',outras_dividas_mensais:'0',garantias:'',observacoes:''});
 const [an,setAn]=useState<{analise:Analysis;historico_mutuario:History;limites_agencia?:Limits}|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
 const set=(k:string,val:string)=>setV(p=>{const n={...p,[k]:val};
  if(k==='tipo_juro'){n.estrategia=STRATS[val][0];if(val==='Variável')n.modalidade='Uma Parcela';if(val==='Composto')n.modalidade='Mensal';}
  if(k==='periodo_dias'&&!termsFor(val).includes(n.prazo))n.prazo=termsFor(val)[0];
  if((k==='prazo'||k==='periodo_dias')&&!modesFor(n.prazo).includes(n.modalidade))n.modalidade=modesFor(n.prazo)[0];return n;});
 const body=useMemo(()=>({client_id:client?.id,montante:Number(v.montante),tipo_juro:v.tipo_juro,estrategia:v.estrategia,periodo_dias:Number(v.periodo_dias),prazo:v.prazo,modalidade:v.modalidade,
  taxa:v.tipo_juro==='Fixo'?null:(v.taxa||null),finalidade:v.finalidade,finalidade_categoria:v.finalidade_categoria||null,actividade:v.actividade||null,rendimento_mensal:Number(v.rendimento_mensal||0),despesas_mensais:Number(v.despesas_mensais||0),
  outras_dividas_mensais:Number(v.outras_dividas_mensais||0),garantias:v.garantias||null,observacoes:v.observacoes||null}),[client,v]);
 useEffect(()=>{if(!client||!(Number(v.montante)>0)||v.finalidade.trim().length<3||v.rendimento_mensal===''){setAn(null);return;}
  const t=window.setTimeout(()=>{api<{analise:Analysis;historico_mutuario:History;limites_agencia?:Limits}>('/cis/propostas/analise','POST',body).then(setAn).catch(e=>{setAn(null);setError((e as Error).message);});},400);
  return()=>window.clearTimeout(t);},[api,body,client,v.montante,v.finalidade,v.rendimento_mensal]);
 async function save(e:FormEvent,submit:boolean){e.preventDefault();if(!client){setError('Seleccione o mutuário.');return;}setBusy(true);setError('');
  try{const p=initial?await api<Proposal>('/cis/propostas/'+initial.id,'PUT',body):await api<Proposal>('/cis/propostas','POST',body);
   if(submit)await api('/cis/propostas/'+p.id+'/submeter','POST',{});onSaved(p.id);}catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 const F=(k:string,label:string,extra:Record<string,unknown>={})=><label className="cis-profile-field"><span>{label}</span><input value={v[k]} onChange={e=>set(k,e.target.value)} {...extra}/></label>;
 return <section className="cis-panel cis-proposals">
  <div className="cis-profile-back"><button type="button" onClick={onCancel}><ArrowLeft size={18}/> Voltar às propostas</button><span>{initial?initial.numero:'Nova proposta'}</span></div>
  <form onSubmit={e=>void save(e,true)}>
   <fieldset className="cis-newclient-group"><legend><UserRound size={16}/> Mutuário</legend><ClientPicker token={token} value={client} onChange={setClient}/>
    <small className="cis-msg-muted">Se ainda não existe, cadastre-o primeiro em Mutuários › Novo mutuário.</small></fieldset>
   <fieldset className="cis-newclient-group"><legend><FileText size={16}/> Crédito pedido</legend><div className="cis-profile-fields">
    {F('montante','Montante pedido (MT)',{type:'number',min:1,step:1,required:true})}
    <label className="cis-profile-field"><span>Tipo de juro</span><select value={v.tipo_juro} onChange={e=>set('tipo_juro',e.target.value)}>{Object.keys(STRATS).map(o=><option key={o}>{o}</option>)}</select></label>
    <label className="cis-profile-field"><span>Sistema de amortização</span><select value={v.estrategia} onChange={e=>set('estrategia',e.target.value)}>{STRATS[v.tipo_juro].map(o=><option key={o}>{o}</option>)}</select></label>
    <label className="cis-profile-field"><span>Período da tabela de juros</span><select value={v.periodo_dias} onChange={e=>set('periodo_dias',e.target.value)}><option value="30">30 dias</option><option value="15">15 dias</option></select></label>
    <label className="cis-profile-field"><span>Prazo</span><select value={v.prazo} onChange={e=>set('prazo',e.target.value)}>{termsFor(v.periodo_dias).map(o=><option key={o}>{o}</option>)}</select></label>
    <label className="cis-profile-field"><span>Periodicidade</span><select value={v.modalidade} onChange={e=>set('modalidade',e.target.value)}>{modesFor(v.prazo).map(o=><option key={o}>{o}</option>)}</select></label>
    {v.tipo_juro!=='Fixo'&&F('taxa','Taxa de juro (%)',{type:'number',min:1,max:100,step:1,required:true})}
    {an&&limitsText(an.limites_agencia)&&<p className="cis-prop-limits cis-prop-wide"><AlertCircle size={14}/> Limites da agência: {limitsText(an.limites_agencia)}.</p>}
    <label className="cis-profile-field"><span>Categoria da finalidade</span><select required value={v.finalidade_categoria} onChange={e=>set('finalidade_categoria',e.target.value)}><option value="">Seleccione</option>{(enc?.finalidades??[]).map(o=><option key={o}>{o}</option>)}</select></label>
    {F('finalidade','Finalidade (detalhe)',{required:true,minLength:3,maxLength:255,placeholder:'Ex.: compra de stock para a banca'})}
    {enc&&Number(v.montante)>0&&(enc.taxa_operacao>0||enc.taxa_operacao_minima>0)&&(()=>{const cap=Number(v.montante);const fee=Math.min(cap,Math.max(Math.ceil(cap*enc.taxa_operacao/100-1e-9),Math.ceil(enc.taxa_operacao_minima)));return <p className="cis-prop-limits cis-prop-wide"><AlertCircle size={14}/> Taxa de operação: {fee.toLocaleString('pt-PT')} MT, descontada no desembolso. O mutuário recebe {(cap-fee).toLocaleString('pt-PT')} MT.</p>;})()}
   </div></fieldset>
   <fieldset className="cis-newclient-group"><legend><Gauge size={16}/> Situação financeira do mutuário</legend><div className="cis-profile-fields">
    {F('actividade','Actividade / fonte de rendimento',{maxLength:160})}
    {F('rendimento_mensal','Rendimento mensal (MT)',{type:'number',min:0,step:'0.01',required:true})}
    {F('despesas_mensais','Despesas mensais (MT)',{type:'number',min:0,step:'0.01'})}
    {F('outras_dividas_mensais','Outras prestações mensais (MT)',{type:'number',min:0,step:'0.01'})}
    <label className="cis-profile-field cis-prop-wide"><span>Garantias / fiador</span><textarea rows={2} maxLength={2000} value={v.garantias} onChange={e=>set('garantias',e.target.value)}/></label>
    <label className="cis-profile-field cis-prop-wide"><span>Observações</span><textarea rows={2} maxLength={2000} value={v.observacoes} onChange={e=>set('observacoes',e.target.value)}/></label>
   </div></fieldset>
   {an&&<AnalysisView a={an.analise} h={an.historico_mutuario}/>}
   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
   <div className="cis-pw-actions"><button type="submit" className="primary" disabled={busy}><Send size={15}/> {busy?'A gravar…':'Gravar e submeter'}</button>
    <button type="button" disabled={busy} onClick={e=>void save(e as unknown as FormEvent,false)}>Gravar rascunho</button><button type="button" onClick={onCancel}>Cancelar</button></div>
  </form>
 </section>;
}

function ProvisionView({assessment,amount,accepted}:{assessment:Provision;amount:number;accepted:boolean}){
 const estimate=assessment.taxa===null||!Number.isInteger(amount)||amount<=0?null:Math.round(amount*Number(assessment.taxa))/100;
 const value=accepted?assessment.provisao:estimate;
 return <article className="cis-panel cis-prop-decision" aria-label="Risco e provisão">
  <h4>{accepted?'Risco e provisão aceites':'Risco e provisão'}</h4>
  <p><strong>{assessment.classe}</strong>{assessment.score!==null&&' · Score '+assessment.score+'/100'}</p>
  <ul>{assessment.factores.map(f=><li key={f}>{f}</li>)}</ul>
  {assessment.taxa===null?<p role="status">Falta configurar a taxa desta classe em Definições › Credit scoring.</p>
   :<p>Montante {mt(amount)} · Taxa {assessment.taxa}% · <strong>Provisão estimada {value===null?'—':mt(Number(value))}</strong></p>}
 </article>;
}
function ProposalDetail({token,id,onBack,onEdit,onOpenContract}:{token:string;id:number;onBack:()=>void;onEdit:(p:Proposal)=>void;onOpenContract:(p:Proposal)=>void}){
 const api=useApi(token);
 const [d,setD]=useState<Detail|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false),[notice,setNotice]=useState('');
 const [decision,setDecision]=useState<'aprovar'|'devolver'|'rejeitar'|'cancelar'|'nao_levantada'|null>(null),[notes,setNotes]=useState(''),[amount,setAmount]=useState(''),[rate,setRate]=useState('');
 const [riskConfirmed,setRiskConfirmed]=useState(false);
 const load=useCallback(async()=>{setRiskConfirmed(false);try{setD(await api<Detail>('/cis/propostas/'+id));}catch(e){setError((e as Error).message);}},[api,id]);
 useEffect(()=>{void load();},[load]);
 async function act(path:string,body:unknown,msg:string){setBusy(true);setError('');try{setD(await api<Detail>('/cis/propostas/'+id+path,'POST',body));setNotice(msg);setDecision(null);setNotes('');}catch(e){setError((e as Error).message);}finally{setBusy(false);}}
 if(!d)return <section className="cis-panel">{error?<div className="cis-table-error"><AlertCircle size={16}/>{error}</div>:<p className="cis-msg-muted">A carregar…</p>}</section>;
 const calc=d.analise?.calculo??d.analise_actual;const hist=d.analise?.historico??d.historico_mutuario;
 return <section className="cis-panel cis-proposals">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar às propostas</button><span>{d.numero}</span></div>
  <header className="cis-prop-head"><div><span>PROPOSTA DE CRÉDITO · {d.numero}</span><h3>{d.mutuario.nome}</h3>
   <p><Badge s={d.estado}/> {mt(d.montante)} · {d.prazo} · {d.modalidade} · {d.tipo_juro}{d.taxa!=null?' '+d.taxa+'%':''}{d.agencia?' · '+d.agencia:''}</p></div>
   <div className="cis-prop-actions">
    {d.pode.editar&&<button type="button" onClick={()=>onEdit(d)}>Editar</button>}
    {d.pode.submeter&&<button type="button" className="primary" disabled={busy} onClick={()=>void act('/submeter',{},'Proposta submetida para decisão.')}><Send size={15}/> Submeter</button>}
    {d.pode.decidir&&<><button type="button" className="primary" onClick={()=>{setDecision('aprovar');setRiskConfirmed(false);setAmount(String(d.montante));setRate(d.taxa!=null?String(d.taxa):'');}}><CheckCircle2 size={15}/> Aprovar</button>
     <button type="button" onClick={()=>setDecision('devolver')}><Undo2 size={15}/> Devolver</button><button type="button" className="danger" onClick={()=>setDecision('rejeitar')}><XCircle size={15}/> Rejeitar</button></>}
    {d.pode.abrir_contrato&&<button type="button" className="primary" onClick={()=>onOpenContract(d)}><ArrowUpRight size={15}/> Abrir contrato</button>}
    {d.pode.nao_levantada&&<button type="button" onClick={()=>setDecision('nao_levantada')}><PackageX size={15}/> Não levantada</button>}
    {d.pode.cancelar&&<button type="button" className="danger-link" onClick={()=>setDecision('cancelar')}><Ban size={15}/> Cancelar</button>}
   </div></header>
  <Stepper estado={d.estado}/>
  {d.provisao&&decision!=='aprovar'&&<ProvisionView assessment={d.provisao} amount={d.montante} accepted={false}/>}
  {d.provisao_aceite&&<ProvisionView assessment={d.provisao_aceite} amount={d.provisao_aceite.montante} accepted/>}
  {d.aviso&&<NoticeCard n={d.aviso} busy={busy} onSent={()=>void act('/avisado',{},'Aviso registado como enviado.')}/>}
  {d.pode.motivo_nao_decidir&&<div className="cis-contract-notice"><AlertCircle size={17}/><span>{d.pode.motivo_nao_decidir}</span></div>}
  {notice&&<div className="cis-msg-notice" role="status"><CheckCircle2 size={16}/>{notice}</div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={16}/>{error}</div>}
  {decision&&<form className="cis-prop-decision" onSubmit={e=>{e.preventDefault();
    if(decision==='cancelar')void act('/cancelar',{notas:notes},'Proposta cancelada.');
    else if(decision==='nao_levantada')void act('/nao-levantada',{notas:notes},'Proposta marcada como não levantada. Avise o mutuário.');
    else void act('/decisao',{decisao:decision,notas:notes,...(decision==='aprovar'?{montante_aprovado:Number(amount),risco_confirmado:riskConfirmed,risco_versao:d.provisao?.versao,...(d.tipo_juro!=='Fixo'&&rate?{taxa_aprovada:Number(rate)}:{})}:{})},decision==='aprovar'?'Proposta aprovada.':decision==='devolver'?'Proposta devolvida ao proponente.':'Proposta rejeitada.');}}>
   <h4>{decision==='aprovar'?'Aprovar a proposta':decision==='devolver'?'Devolver para correcção':decision==='rejeitar'?'Rejeitar a proposta':decision==='nao_levantada'?'O mutuário não levantou o crédito':'Cancelar a proposta'}</h4>
   {decision==='aprovar'&&<ChecklistView c={d.verificacao}/>}
   {decision==='aprovar'&&<div className="cis-profile-fields"><label className="cis-profile-field"><span>Montante aprovado (MT)</span><input type="number" min={1} max={d.montante} step={1} required value={amount} onChange={e=>{setAmount(e.target.value);setRiskConfirmed(false);}}/><small>Até {mt(d.montante)} (pedido).</small></label>
    {d.tipo_juro!=='Fixo'&&<label className="cis-profile-field"><span>Taxa aprovada (%)</span><input type="number" min={1} max={100} step={1} value={rate} onChange={e=>setRate(e.target.value)}/></label>}</div>}
   {decision==='aprovar'&&d.provisao&&<>
    <ProvisionView assessment={d.provisao} amount={Number(amount)} accepted={false}/>
    <label className="cis-collections-check"><input type="checkbox" checked={riskConfirmed} disabled={d.provisao.taxa===null} onChange={e=>setRiskConfirmed(e.target.checked)}/>
     Confirmo que revi a classe de risco e a provisão estimada para o montante aprovado.</label>
    <button type="button" className="cis-edit-action" onClick={()=>void load()} disabled={busy}>Actualizar avaliação</button>
   </>}
   <label className="cis-profile-field cis-prop-wide"><span>{decision==='aprovar'?'Notas (opcional)':decision==='devolver'?'O que deve ser corrigido':decision==='nao_levantada'?'Motivo (ex.: desistiu, já não precisa)':'Motivo'}</span><textarea rows={2} required={decision!=='aprovar'} minLength={decision==='aprovar'?0:5} value={notes} onChange={e=>setNotes(e.target.value)}/></label>
   <div className="cis-pw-actions"><button type="submit" className={decision==='rejeitar'||decision==='cancelar'||decision==='nao_levantada'?'danger':'primary'} disabled={busy||(decision==='aprovar'&&(!riskConfirmed||d.provisao?.taxa==null))}>{busy?'A gravar…':'Confirmar'}</button><button type="button" onClick={()=>setDecision(null)}>Voltar</button></div>
  </form>}
  {d.estado==='aprovada'&&<div className="cis-prop-reco ok"><CheckCircle2 size={17}/><div><strong>Aprovada: {mt(d.montante_aprovado)}{d.taxa_aprovada!=null?' a '+d.taxa_aprovada+'%':''}</strong> por {d.decidido_por} em {dt(d.decidido_em)}. Válida até {dt(d.valida_ate)}.{d.decisao_notas?' '+d.decisao_notas:''}</div></div>}
  {d.estado==='aprovada'&&(()=>{const n=daysLeft(d.valida_ate);return n!=null&&n<=3?<div className="cis-contract-notice"><Hourglass size={17}/><span>A aprovação {n<=0?'expira hoje':'expira em '+n+' dia(s)'}. Abra o contrato ou marque como não levantada.</span></div>:null;})()}
  {d.estado==='nao_levantada'&&<div className="cis-prop-reco warn"><PackageX size={17}/><div><strong>Não levantada.</strong> {d.eventos.filter(e=>e.accao==='nao_levantada').slice(-1)[0]?.notas}</div></div>}
  {(d.estado==='rejeitada'||d.estado==='devolvida')&&d.decisao_notas&&<div className="cis-prop-reco warn"><AlertCircle size={17}/><div><strong>{d.estado==='rejeitada'?'Rejeitada':'Devolvida'} por {d.decidido_por}:</strong> {d.decisao_notas}</div></div>}
  {d.contrato&&<div className="cis-prop-reco ok"><CheckCircle2 size={17}/><div><strong>Contrato {d.contrato}</strong> aberto e desembolsado a partir desta proposta.</div></div>}
  <AnalysisView a={calc} h={hist}/>
  <DossierPanel token={token} proposalId={d.id} clientId={d.mutuario.id} onChange={()=>void load()}/>
  <div className="cis-prop-grid">
   <article><h4><FileText size={15}/> Pedido</h4><dl>
    <div><dt>Finalidade</dt><dd>{d.finalidade_categoria?<b>{d.finalidade_categoria} · </b>:null}{d.finalidade}</dd></div>{d.encargos&&Number(d.encargos.taxa_operacao)>0&&<div><dt>Taxa de operação</dt><dd>{Number(d.encargos.taxa_operacao).toLocaleString('pt-PT')} MT · entregue {Number(d.encargos.valor_entregue).toLocaleString('pt-PT')} MT</dd></div>}<div><dt>Sistema</dt><dd>{d.estrategia}</dd></div><div><dt>Período da tabela</dt><dd>{d.periodo_dias} dias</dd></div>
    <div><dt>Actividade</dt><dd>{d.actividade||'—'}</dd></div><div><dt>Rendimento / despesas / outras prestações</dt><dd>{mt(d.rendimento_mensal)} / {mt(d.despesas_mensais)} / {mt(d.outras_dividas_mensais)}</dd></div>
    <div><dt>Garantias / fiador</dt><dd>{d.garantias||'—'}</dd></div><div><dt>Observações</dt><dd>{d.observacoes||'—'}</dd></div>
    <div><dt>Proponente</dt><dd>{d.criado_por} · {dt(d.criado_em)}</dd></div></dl></article>
   <article><h4><Clock size={15}/> Histórico da proposta</h4><ol className="cis-prop-timeline">{d.eventos.map((e,i)=><li key={i}><b>{ACCOES[e.accao]??e.accao}</b><small>{e.por} · {e.em?.slice(0,16).replace('T',' ')}</small>{e.notas&&<p>{e.notas}</p>}</li>)}</ol></article>
  </div>
 </section>;
}

function NoticeCard({n,busy,onSent}:{n:Notice;busy:boolean;onSent:()=>void}){
 const [text,setText]=useState(n.mensagem);
 useEffect(()=>setText(n.mensagem),[n.mensagem]);
 const label=n.estado==='aprovada'?'aprovação':n.estado==='rejeitada'?'recusa':'não levantamento';
 return <div className={'cis-prop-notice'+(n.enviado?' sent':'')}>
  <div className="cis-prop-notice-head"><MessageCircle size={17}/><div><strong>{n.enviado?'Mutuário avisado':'Avisar o mutuário da '+label}</strong>
   <small>{n.enviado?'Aviso enviado'+(n.enviado_em?' em '+n.enviado_em.slice(0,16).replace('T',' '):'')+'.':n.contacto?'WhatsApp para '+n.contacto+' · modelo «'+n.modelo+'»':'O mutuário não tem contacto registado.'}</small></div></div>
  {!n.enviado&&<><textarea rows={3} value={text} onChange={e=>setText(e.target.value)} aria-label="Mensagem para o mutuário"/>
   <div className="cis-pw-actions">{n.telefone&&<a className="primary cis-btn-link" href={'https://wa.me/'+n.telefone+'?text='+encodeURIComponent(text)} target="_blank" rel="noreferrer" onClick={()=>window.setTimeout(onSent,300)}><MessageCircle size={15}/> Enviar por WhatsApp</a>}
    <button type="button" disabled={busy} onClick={onSent}><Check size={15}/> Já avisei</button></div></>}
 </div>;
}
