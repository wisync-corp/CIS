import {useEffect,useRef,useState} from 'react';
import logoCompactaClaro from './assets/brand/cis-compacta-claro.svg';
import logoCompactaClaroTransp from './assets/brand/cis-compacta-claro-transparente.svg';
import {Ban,AlertTriangle,CalendarClock,X as XIcon,CreditCard} from 'lucide-react';
import BlacklistDesk from './BlacklistDesk';
import LongDelinquency,{type Candidates} from './LongDelinquency';
import {LayoutDashboard, Users, FileText, Wallet, ReceiptText, BarChart3, Settings, ShieldCheck, Menu, Search, Bell, Landmark, ArrowUpRight, ArrowDownRight, LockKeyhole, CalendarDays, MessageSquare} from 'lucide-react';
import LegacyTable from './LegacyTable';
import CashDesk from './CashDesk';
import ExtractoDesk from './ExtractoDesk';
import ContractProfile from './ContractProfile';
import MessageCenter from './MessageCenter';
import ReportsDesk from './ReportsDesk';
import Toaster from './Toaster';
import {setWaToken} from './waBridge';
import AnalyticsDashboard,{type BootProgress} from './AnalyticsDashboard';
import BootScreen,{type BootStep} from './BootScreen';
import Overview,{type ExtractoPreset} from './Overview';
import SimulatorDesk from './SimulatorDesk';
import ConnectionSettings from './ConnectionSettings';
import AdminProfile,{type ProfileTab} from './AdminProfile';
import type {ThemeId} from './theme';
import {PermsContext,type Perm} from './perms';
import PasswordChange from './PasswordChange';
import {KeyRound,ClipboardCheck,CalendarCheck} from 'lucide-react';
import CollectionAgenda from './CollectionAgenda';
import ProposalsDesk from './ProposalsDesk';

const noticesApi=import.meta.env.VITE_API_URL||'/api';
type NoticeKind='cobranca'|'atraso'|'lista_negra';
type NoticeData={atraso:number;cobranca:number;lista_negra?:number;antecedencia:number;paginas:number;resumo?:{atraso:{valor:number;dias_max:number|null};cobranca:{valor:number;dias_min:number|null};lista_negra:{valor:number;dias_max:number|null;mutuarios:number}};linhas:{contrato:string;parcela:number;mutuário:string;vencimento:string;valor:string|null;dias?:number|null}[]};
const NOTICE_TABS:{kind:NoticeKind;label:string}[]=[{kind:'lista_negra',label:'Lista negra'},{kind:'atraso',label:'Pagamentos em atraso'},{kind:'cobranca',label:'Cobranças a vencer'}];
function noticeDays(d:number|null|undefined):{text:string;tone:string}{
 if(d==null||Number.isNaN(Number(d)))return{text:'—',tone:''};
 const n=Number(d);
 if(n>0)return{text:n+(n===1?' dia em atraso':' dias em atraso'),tone:n>90?'black':'bad'};
 if(n===0)return{text:'Vence hoje',tone:'warn'};
 return{text:'Faltam '+(-n)+(n===-1?' dia':' dias'),tone:'ok'};
}
const noticeTotal=(n:NoticeData|null)=>n?n.atraso+n.cobranca+(n.lista_negra??0):0;
const noticeMoney=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});

const nav:{label:string;icon:typeof Users;perm?:Perm}[]=[
 {label:'Visão geral',icon:LayoutDashboard,perm:'ver'},{label:'Mutuários',icon:Users,perm:'ver'},
 {label:'Propostas',icon:ClipboardCheck,perm:'propostas.criar'},{label:'Agenda de cobrança',icon:CalendarCheck,perm:'cobrancas'},{label:'Contratos e prestações',icon:FileText,perm:'ver'},{label:'Simulador',icon:CalendarDays,perm:'ver'},
 {label:'Extractos',icon:ReceiptText,perm:'extractos'},{label:'Mensagens',icon:MessageSquare,perm:'mensagens'},{label:'Caixa',icon:Landmark,perm:'caixa'},{label:'Relatórios',icon:BarChart3,perm:'relatorios'},
 {label:'Definições',icon:Settings}
];
export default function Dashboard({token, name, role, permissions, agency, theme, onThemeChange, onLogout}:{token:string;name:string;role:string;permissions:string[];agency?:string|null;theme:ThemeId;onThemeChange:(value:ThemeId)=>void;onLogout:()=>void}){
 const can=(p?:Perm)=>!p||permissions.includes(p);
 const allowedNav=nav.filter(n=>can(n.perm));
 const [active,setActiveRaw]=useState(()=>allowedNav[0]?.label??'Definições'),[open,setOpen]=useState(false);
 const setActive=(label:string)=>{if(allowedNav.some(n=>n.label===label))setActiveRaw(label);};
 // «Perfil» não está no menu: abre-se pelo nome do utilizador (conta e, para o administrador, a subscrição).
 const [profileTab,setProfileTab]=useState<{tab:ProfileTab;key:number}>({tab:'conta',key:0});
 const openProfile=(tab:ProfileTab)=>{setProfileTab({tab,key:Date.now()});setActiveRaw('Perfil');setOpen(false);};
 const [pwOpen,setPwOpen]=useState(false);
 // Visão geral e Dashboard num só separador; o ecrã de entrada prepara os dois antes de mostrar o sistema.
 const hasDash=can('relatorios'),hasOverview=can('ver');
 const [booted,setBooted]=useState(false);
 // Incumprimento prolongado (critério interno): verificado na entrada para quem pode decidir.
 const canDecideBlack=can('propostas.aprovar');
 const [ld,setLd]=useState<Candidates|null>(null);
 const [ldReady,setLdReady]=useState(!canDecideBlack);
 const [ldOpen,setLdOpen]=useState(false);
 const [ldNotice,setLdNotice]=useState('');
 // Subscrição do CIS: aviso de teste, vencimento próximo ou expiração (fecha-se por sessão).
 const [sub,setSub]=useState<{instalado:boolean;estado?:string;dias?:number;plano_nome?:string;valido_ate?:string;pode_gerir?:boolean}|null>(null);
 const [subHidden,setSubHidden]=useState(()=>{try{return sessionStorage.getItem('cis_sub_aviso')==='1';}catch{return false;}});
 useEffect(()=>{const c=new AbortController();
  fetch(noticesApi+'/cis/subscricao/estado',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(v=>{if(!c.signal.aborted)setSub(v);}).catch(()=>{});
  return()=>c.abort();},[token,active]);
 const subShow=!!sub?.instalado&&!subHidden&&(sub.estado==='carencia'||sub.estado==='expirada'||(!!sub.pode_gerir&&(sub.estado==='teste'||sub.estado==='a_vencer')));
 const [ovKey,setOvKey]=useState(0);
 const ldKey='cis_ld_'+new Date().toISOString().slice(0,10);
 useEffect(()=>{if(!canDecideBlack)return;const c=new AbortController();
  fetch((import.meta.env.VITE_API_URL||'/api')+'/cis/lista-negra/candidatos',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j)setLd(j as Candidates);}).catch(()=>{}).finally(()=>{if(!c.signal.aborted)setLdReady(true);});
  return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token]);
 useEffect(()=>{if(booted&&ld?.pode_decidir&&ld.propor_ao_entrar&&ld.candidatos.length>0&&!sessionStorage.getItem(ldKey))setLdOpen(true);
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[booted,ld]);
 const [homeTab,setHomeTab]=useState<'resumo'|'dashboard'>('resumo');
 const [ovReady,setOvReady]=useState(!hasOverview);
 const [dash,setDash]=useState<BootProgress>({pct:hasDash?5:100,text:'A aguardar'});
 const [dashDone,setDashDone]=useState<null|{ok:boolean;msg?:string}>(hasDash?null:{ok:true});
 useEffect(()=>{const t=window.setTimeout(()=>setOvReady(true),30000);return()=>window.clearTimeout(t);},[]);
 setWaToken(token);
 const [clientProfileOpen,setClientProfileOpen]=useState(false);
 // Mutuários: cadastro e lista negra no mesmo separador.
 const [mutTab,setMutTab]=useState<'cadastro'|'lista'>('cadastro');
 const [blackCount,setBlackCount]=useState<number|null>(null);
 useEffect(()=>{if(active!=='Mutuários'||!can('ver'))return;const c=new AbortController();
  fetch(noticesApi+'/cis/lista-negra',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(r=>r.ok?r.json():null).then(j=>{if(j&&j.instalado)setBlackCount((j.data??[]).length);}).catch(()=>{});
  return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[active,token,mutTab]);
 const [extractoContract,setExtractoContract]=useState<string|null>(null);
 const [proposalToOpen,setProposalToOpen]=useState<number|null>(null);
 const [remindersMode,setRemindersMode]=useState(0);
 const [remindersGroup,setRemindersGroup]=useState<true|'propostas'>(true);
 const [extractoPreset,setExtractoPreset]=useState<(ExtractoPreset&{key:number})|null>(null);
 const [notices,setNotices]=useState<NoticeData|null>(null);
 const [noticeKind,setNoticeKind]=useState<NoticeKind>('atraso');
 const [noticePage,setNoticePage]=useState(1);
 const [noticeOpen,setNoticeOpen]=useState(false);
 // «Avisos de hoje» é um informe temporário: fechado, fica fechado nesta sessão (até ao dia seguinte).
 const stripKey='cis_avisos_hoje_fechado_'+new Date().toLocaleDateString('en-CA',{timeZone:'Africa/Maputo'});
 const [stripClosed,setStripClosed]=useState(()=>{try{return sessionStorage.getItem(stripKey)==='1';}catch{return false;}});
 const closeStrip=()=>{setStripClosed(true);try{sessionStorage.setItem(stripKey,'1');}catch{/* sem armazenamento */}};
 const noticeDialog=useRef<HTMLDialogElement>(null);
 // Os avisos só aparecem depois do ecrã de entrada (nunca por cima dele).
 useEffect(()=>{
  const dialog=noticeDialog.current;
  if(!dialog)return;
  if(noticeOpen&&booted&&!ldOpen&&!dialog.open)dialog.showModal();
  if((!noticeOpen||!booted||ldOpen)&&dialog.open)dialog.close();
 },[noticeOpen,role,booted,ldOpen]);
 const [noticeReady,setNoticeReady]=useState(false);
 const [noticeBusy,setNoticeBusy]=useState(false);
 const [noticeError,setNoticeError]=useState(false);
 const [noticeRefresh,setNoticeRefresh]=useState(0);
 useEffect(()=>{
  if(!can('ver'))return;
  const ctrl=new AbortController();
  setNoticeBusy(true);setNoticeError(false);
  fetch(noticesApi+'/cis/contractos/avisos?'+new URLSearchParams({tipo:noticeKind,page:String(noticePage)}),{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal
  }).then(async r=>{if(!r.ok)throw new Error();return await r.json() as NoticeData;})
   .then(value=>{
    if(ctrl.signal.aborted)return;
    setNotices(value);
    if(!noticeReady&&value.atraso+value.cobranca>0){
     if(value.atraso===0)setNoticeKind('cobranca');
     setNoticeOpen(true);
    }
   }).catch(()=>{if(!ctrl.signal.aborted)setNoticeError(true);})
   .finally(()=>{if(!ctrl.signal.aborted){setNoticeBusy(false);setNoticeReady(true);}});
  return()=>ctrl.abort();
 },[token,role,noticeKind,noticePage,noticeRefresh]);
 const avisosOk=(!hasOverview||noticeReady)&&ldReady;
 const bootPct=Math.round(8+(avisosOk?7:0)+(ovReady?(hasDash?15:85):0)+(hasDash?Math.min(100,dash.pct)*0.7:0));
 const bootSteps:BootStep[]=[
  {key:'sessao',label:'Sessão e permissões validadas',detail:role,state:'done'},
  ...(hasOverview?[{key:'visao',label:'Visão geral da carteira',detail:ovReady?'Indicadores do mês calculados':'A calcular carteira, recebimentos e Caixa',state:ovReady?'done':'now'} as BootStep]:[]),
  ...(hasOverview?[{key:'avisos',label:'Avisos de cobrança e atraso',detail:noticeReady?(notices?`${notices.atraso} em atraso · ${notices.cobranca} a vencer`:'Verificados'):'A verificar prestações em atraso e a vencer',state:noticeReady?'done':'now'} as BootStep]:[]),
  ...(canDecideBlack?[{key:'ld',label:'Incumprimento prolongado',detail:ldReady?(ld&&ld.candidatos.length?`${ld.candidatos.length} mutuário(s) com mais de ${ld.dias} dias de atraso`:'Nenhum mutuário acima do critério'):'A verificar atrasos acima do critério interno',state:ldReady?(ld&&ld.candidatos.length?'warn':'done'):'now'} as BootStep]:[]),
  ...(hasDash?[{key:'dash',label:'Dashboard de análise',detail:dashDone?(dashDone.ok?'Pronto':dashDone.msg):dash.text,state:dashDone?(dashDone.ok?'done':'warn'):'now'} as BootStep]:[]),
 ];
 const bootCurrent=!avisosOk?'A verificar os avisos de cobrança':!ovReady?'A calcular a visão geral da carteira':!dashDone?dash.text:dashDone.ok?'Tudo pronto. A entrar…':'A entrar (o Dashboard abre quando estiver pronto)';
 useEffect(()=>{if(!booted&&ovReady&&dashDone&&avisosOk){const t=window.setTimeout(()=>setBooted(true),dashDone.ok?450:1400);return()=>window.clearTimeout(t);}},[booted,ovReady,dashDone,avisosOk]);
 return <PermsContext.Provider value={permissions}>{!booted&&<BootScreen name={name} pct={booted?100:bootPct} current={bootCurrent} steps={bootSteps} onSkip={()=>setBooted(true)}/>}<div className="cis-layout" aria-hidden={!booted}>
  <aside className={'cis-side '+(open?'visible':'')}>
   <div className="cis-brand"><img src={logoCompactaClaroTransp} alt="CIS – Credit Insight Shift" width={170} height={50}/></div>
   <div className="cis-workspace">ESPAÇO DE TRABALHO</div>
   <nav aria-label="Navegação principal">{allowedNav.map(({label,icon:Icon})=><button type="button" key={label} className={active===label?'current':''} onClick={()=>{setExtractoContract(null);setRemindersMode(0);setActive(label);setOpen(false)}}><Icon size={18}/><span>{label}</span></button>)}</nav>
   <div className="cis-side-bottom"><div className="secure-mark"><ShieldCheck size={18}/><span>Ambiente de desenvolvimento<br/><b>Acesso protegido</b></span></div><div className="cis-profile"><div className="cis-initial">{name.charAt(0).toUpperCase()}</div><button type="button" className={'cis-profile-name cis-profile-open'+(active==='Perfil'?' on':'')} onClick={()=>openProfile('conta')} title="Abrir o meu perfil"><strong>{name}</strong><small>{role}{agency?' · '+agency:''}</small></button><button type="button" className="cis-profile-key" onClick={()=>setPwOpen(true)} title="Alterar a minha palavra-passe" aria-label="Alterar a minha palavra-passe"><KeyRound size={15}/></button><button type="button" onClick={onLogout} title="Terminar sessão">Sair</button></div></div>
  </aside>
  <div className="cis-stage">
   <header className="cis-top"><div className="cis-breadcrumb"><button type="button" className="cis-hamburger" onClick={()=>setOpen(!open)} aria-label="Abrir navegação"><Menu size={22}/></button><img className="cis-top-logo" src={logoCompactaClaro} alt="CIS" width={92} height={30}/><span>Plataforma CIS</span><span className="cis-separator">/</span><strong>{active}</strong></div><div className="cis-top-right">{can('ver')&&<button type="button" className="cis-client-edit" aria-haspopup="dialog" aria-controls="cis-notice-dialog" aria-expanded={noticeOpen} onClick={()=>{setNoticeOpen(true);setNoticeRefresh(v=>v+1);}}><Bell size={16}/> Avisos {notices&&'('+String(noticeTotal(notices))+')'}</button>}<span className="cis-env"><span/> Desenvolvimento</span><span className="cis-avatar">{name.charAt(0).toUpperCase()}</span></div></header>
   <main className="cis-main">{can('ver')&&<dialog id="cis-notice-dialog" ref={noticeDialog} className="cis-panel cis-notice-dialog" aria-labelledby="cis-payment-notices" onCancel={event=>{event.preventDefault();setNoticeOpen(false);}}>
    <div className="cis-panel-head"><div><h2 id="cis-payment-notices">Avisos de cobrança e atraso</h2><p>Antecedência do aviso: {notices?.antecedencia??'—'} dia(s).</p></div><button type="button" className="cis-client-edit" onClick={()=>setNoticeOpen(false)}>Fechar avisos</button></div>
    <div className="cis-extracto-modes cis-notice-tabs" role="tablist" aria-label="Tipo de aviso">{NOTICE_TABS.map(({kind,label})=><button key={kind} type="button" role="tab" aria-selected={noticeKind===kind} className={(noticeKind===kind?'selected ':'')+'k-'+kind} onClick={()=>{setNoticeKind(kind);setNoticePage(1);}}>{kind==='lista_negra'?<Ban size={15}/>:kind==='atraso'?<AlertTriangle size={15}/>:<CalendarClock size={15}/>}{label}<em>{notices?(notices[kind]??0):'…'}</em></button>)}</div>
    {notices?.resumo&&<p className="cis-notice-summary">{noticeKind==='lista_negra'?<>{notices.resumo.lista_negra.mutuarios} mutuário(s) em lista negra · {noticeMoney.format(notices.resumo.lista_negra.valor)} MT por cobrar{notices.resumo.lista_negra.dias_max!=null&&notices.resumo.lista_negra.dias_max>0?<> · atraso máximo de <b>{notices.resumo.lista_negra.dias_max} dias</b></>:null}. Fora da carteira corrente.</>:noticeKind==='atraso'?<>{noticeMoney.format(notices.resumo.atraso.valor)} MT em atraso{notices.resumo.atraso.dias_max!=null?<> · atraso máximo de <b>{notices.resumo.atraso.dias_max} dias</b></>:null}.</>:<>{noticeMoney.format(notices.resumo.cobranca.valor)} MT a cobrar nos próximos {notices.antecedencia} dia(s){notices.resumo.cobranca.dias_min!=null?<> · a próxima {notices.resumo.cobranca.dias_min===0?<b>vence hoje</b>:<>vence em <b>{notices.resumo.cobranca.dias_min} dia(s)</b></>}</>:null}.</>}</p>}
    {noticeBusy?<p role="status">A consultar avisos…</p>:noticeError?<p role="alert">Não foi possível consultar os avisos.</p>:!notices?.linhas.length?<p>Sem prestações a apresentar.</p>:<>
     <div className="cis-table-scroll"><table><thead><tr><th>Contrato</th><th>Mutuário</th><th>Prestação</th><th>Vencimento</th><th>Dias</th><th>Valor previsto</th><th>Acção</th></tr></thead><tbody>
      {notices.linhas.map((row,index)=><tr key={row.contrato+'-'+row.parcela+'-'+index}><td>{row.contrato}</td><td>{row.mutuário}</td><td>{row.parcela}</td><td>{row.vencimento?.slice(0,10)}</td><td>{(()=>{const d=noticeDays(row.dias);return <span className={'cis-days '+d.tone}>{d.text}</span>;})()}</td><td>{row.valor!=null?noticeMoney.format(Number(row.valor))+' MT':'—'}</td><td><button type="button" className="cis-client-edit" aria-label={'Abrir contrato '+row.contrato} onClick={()=>{setExtractoContract(row.contrato);setActive('Contratos e prestações');setNoticeOpen(false);window.scrollTo({top:0});}}>Abrir contrato <ArrowUpRight size={15}/></button></td></tr>)}
     </tbody></table></div>
     {notices.paginas>1&&<div className="cis-collections-pagination"><button type="button" disabled={noticePage<=1} onClick={()=>setNoticePage(p=>p-1)}>Anterior</button><span>{noticePage} / {notices.paginas}</span><button type="button" disabled={noticePage>=notices.paginas} onClick={()=>setNoticePage(p=>p+1)}>Seguinte</button></div>}
    </>}
   </dialog>}{!(active==='Mutuários'&&clientProfileOpen)&&active!=='Perfil'&&active!=='Caixa'&&active!=='Simulador'&&active!=='Visão geral'&&<><div className="cis-kicker">CIS · SISTEMA DE INFORMAÇÃO DE CRÉDITO</div>
    <div className="cis-title-row"><div><h1>{active==='Visão geral'?'Bem-vindo ao CIS':active}</h1><p>{active==='Visão geral'?(homeTab==='dashboard'?'Dashboard de análise: carteira, mutuários e desempenho financeiro.':'Visão integrada da carteira de microcrédito e das operações financeiras.') :active==='Caixa'?'Gestão de contas, saldos e movimentos financeiros da instituição.':active==='Mensagens'?'Modelos, envio pelo WhatsApp Web e registo das mensagens enviadas aos mutuários.':active==='Agenda de cobrança'?'Folha de cobrança do dia: promessas de pagamento, contactos agendados, atrasos sem contacto e lembretes automáticos.':active==='Propostas'?'Propostas de crédito: análise do mutuário, aprovação por limites e desembolso só a partir de propostas aprovadas.':active==='Relatórios'?'Relatórios de gestão (cobranças, atrasos, gestores de crédito, evolução, Caixa, novo crédito) e regulatórios para o Banco de Moçambique.':active==='Definições'?'Dados da empresa, regras de crédito, parametrização e preferências do sistema.':'Consulta segura dos registos do sistema de microcrédito.'}</p></div><span className="cis-readonly">{active==='Mutuários'||active==='Propostas'||active==='Agenda de cobrança'||active==='Caixa'||active==='Mensagens'||active==='Relatórios'||active==='Definições'?<><ShieldCheck size={15}/> Acesso protegido</>:<><LockKeyhole size={15}/> Só leitura</>}</span></div></>}
    {active==='Visão geral'&&<div className="cis-home-head">
     <div><span className="cis-kicker">CIS · {homeTab==='dashboard'?'DASHBOARD DE ANÁLISE':'VISÃO GERAL'}</span><h1>Bem-vindo, {name.split(' ')[0]}</h1></div>
     {hasDash&&<div className="cis-home-tabs" role="tablist" aria-label="Visão geral e Dashboard">
      <button type="button" role="tab" aria-selected={homeTab==='resumo'} className={homeTab==='resumo'?'selected':''} onClick={()=>setHomeTab('resumo')}><LayoutDashboard size={16}/> Visão geral</button>
      <button type="button" role="tab" aria-selected={homeTab==='dashboard'} className={homeTab==='dashboard'?'selected':''} onClick={()=>setHomeTab('dashboard')}><BarChart3 size={16}/> Dashboard{dashDone&&!dashDone.ok?<em>!</em>:null}</button>
     </div>}
    </div>}
    {subShow&&sub&&<div className={'cis-sub-banner '+(sub.estado==='carencia'||sub.estado==='expirada'?'bad':'warn')} role="status"><CreditCard size={18}/>
     <span>{sub.estado==='teste'?<>Período de teste do CIS (plano {sub.plano_nome}): {sub.dias===0?'termina hoje':`faltam ${sub.dias} dia(s)`}.</>:sub.estado==='a_vencer'?<>A subscrição do CIS vence {sub.dias===0?'hoje':`dentro de ${sub.dias} dia(s)`}.</>:sub.estado==='carencia'?<>A subscrição do CIS venceu: está em período de carência.</>:<>A subscrição do CIS expirou: não é possível activar novos utilizadores.</>}
      {!sub.pode_gerir&&' Fale com o administrador.'}</span>
     {sub.pode_gerir&&<button type="button" className="go" onClick={()=>openProfile('subscricao')}>{sub.estado==='teste'?'Escolher plano':'Renovar agora'}</button>}
     <button type="button" className="close" aria-label="Fechar aviso da subscrição" onClick={()=>{setSubHidden(true);try{sessionStorage.setItem('cis_sub_aviso','1');}catch{/* */}}}><XIcon size={16}/></button></div>}
    {ldNotice&&<div className="cis-secondary-success" role="status"><Ban size={17}/>{ldNotice}<button type="button" className="cis-edit-action" onClick={()=>setLdNotice('')}>Fechar</button></div>}
    {ldOpen&&ld&&<LongDelinquency token={token} data={ld} onClose={()=>{setLdOpen(false);sessionStorage.setItem(ldKey,'1');}}
      onDone={msg=>{setLdOpen(false);sessionStorage.setItem(ldKey,'1');setLdNotice(msg);setOvKey(k=>k+1);setLd(v=>v?{...v,candidatos:[]}:v);}}/>}
    {active==='Visão geral'&&homeTab==='resumo'&&!stripClosed&&notices&&noticeTotal(notices)>0&&<div className="cis-home-alerts" role="status">
     <Bell size={18}/><strong>Avisos de hoje</strong>
     {(notices.lista_negra??0)>0&&<button type="button" className="black" onClick={()=>{setNoticeKind('lista_negra');setNoticePage(1);setNoticeOpen(true);}}>{notices.lista_negra} prestação(ões) em lista negra{notices.resumo?.lista_negra.dias_max?` · até ${notices.resumo.lista_negra.dias_max} dias`:''}</button>}
     {notices.atraso>0&&<button type="button" className="bad" onClick={()=>{setNoticeKind('atraso');setNoticePage(1);setNoticeOpen(true);}}>{notices.atraso} pagamento(s) em atraso{notices.resumo?.atraso.dias_max?` · até ${notices.resumo.atraso.dias_max} dias`:''}</button>}
     {notices.cobranca>0&&<button type="button" className="warn" onClick={()=>{setNoticeKind('cobranca');setNoticePage(1);setNoticeOpen(true);}}>{notices.cobranca} cobrança(s) a vencer{notices.antecedencia?` em ${notices.antecedencia} dia(s)`:''}</button>}
     <button type="button" className="link" onClick={()=>{setNoticeOpen(true);setNoticeRefresh(v=>v+1);}}>Ver todos os avisos</button><button type="button" className="close" onClick={closeStrip} title="Fechar este informe (os avisos continuam no botão «Avisos»)" aria-label="Fechar avisos de hoje"><XIcon size={16}/></button>
    </div>}
    {active==='Mutuários'&&!clientProfileOpen&&blackCount!==null&&<div className="cis-home-tabs cis-mut-tabs" role="tablist" aria-label="Mutuários e lista negra">
     <button type="button" role="tab" aria-selected={mutTab==='cadastro'} className={mutTab==='cadastro'?'selected':''} onClick={()=>setMutTab('cadastro')}><Users size={16}/> Cadastro</button>
     <button type="button" role="tab" aria-selected={mutTab==='lista'} className={mutTab==='lista'?'selected':''} onClick={()=>setMutTab('lista')}><Ban size={16}/> Lista negra{blackCount>0&&<em>{blackCount}</em>}</button>
    </div>}
    {active==='Visão geral'?(hasOverview?<div className="cis-home-overview" hidden={homeTab!=='resumo'}><Overview key={ovKey} token={token} onNavigate={setActive} onLoaded={()=>setOvReady(true)} onOpenExtracto={p=>{setExtractoPreset({...p,key:Date.now()});setActive('Extractos');}}/></div>:null):active==='Caixa'?<CashDesk token={token}/>:active==='Simulador'?<SimulatorDesk token={token}/>:active==='Mensagens'?<MessageCenter key={remindersMode} token={token} autoReminders={remindersMode>0?remindersGroup:false}/>:active==='Agenda de cobrança'?<CollectionAgenda token={token} onOpenContract={n=>{setExtractoContract(n);setActive('Contratos e prestações');window.scrollTo({top:0});}} onSendReminders={()=>{setRemindersGroup(true);setRemindersMode(Date.now());setActive('Mensagens');}}/>:active==='Relatórios'?<ReportsDesk token={token}/>:active==='Propostas'?<ProposalsDesk token={token} onOpenContract={p=>{setExtractoContract(null);setProposalToOpen(p.id);setActive('Contratos e prestações');window.scrollTo({top:0});}} onSendNotices={can('mensagens')?()=>{setRemindersGroup('propostas');setRemindersMode(Date.now());setActive('Mensagens');}:undefined}/>:active==='Extractos'?<ExtractoDesk key={extractoPreset?.key??0} token={token} role={can('extractos.geral')?'administrador':role} preset={extractoPreset} onClearPreset={()=>setExtractoPreset(null)} onOpenContracts={numero=>{setExtractoContract(numero??null);setActive('Contratos e prestações');window.scrollTo({top:0});}}/>:active==='Perfil'?<AdminProfile key={profileTab.key} token={token} name={name} role={role} agency={agency} initialTab={profileTab.tab} onChangePassword={()=>setPwOpen(true)} onLogout={onLogout}/>:active==='Definições'?<ConnectionSettings token={token} role={role} theme={theme} onThemeChange={onThemeChange} onChangePassword={()=>setPwOpen(true)}/>:active==='Contratos e prestações'&&extractoContract?<ContractProfile numero={extractoContract} token={token} onBack={()=>setExtractoContract(null)}/>:active==='Mutuários'&&mutTab==='lista'?<BlacklistDesk token={token} onProfileChange={setClientProfileOpen} onCount={setBlackCount}/>:<LegacyTable module={active} token={token} onProfileChange={setClientProfileOpen} openProposal={active==='Contratos e prestações'?proposalToOpen:null} onProposalHandled={()=>setProposalToOpen(null)}/>}
    {hasDash&&<AnalyticsDashboard token={token} visible={active==='Visão geral'&&homeTab==='dashboard'} onProgress={setDash} onReady={(ok,msg)=>setDashDone({ok,msg})}/>}
   </main>
  </div>
  <Toaster/>
  {pwOpen&&<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Alterar palavra-passe" onClick={()=>setPwOpen(false)}><div onClick={e=>e.stopPropagation()} style={{maxWidth:520}}><PasswordChange token={token} name={name} onDone={()=>setPwOpen(false)} onCancel={()=>setPwOpen(false)}/></div></div>}
 </div></PermsContext.Provider>;
}
