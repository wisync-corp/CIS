import {useEffect,useRef,useState} from 'react';
import logoCompactaClaro from './assets/brand/cis-compacta-claro.svg';
import logoCompactaClaroTransp from './assets/brand/cis-compacta-claro-transparente.svg';
import {LayoutDashboard, Users, FileText, Wallet, ReceiptText, BarChart3, Settings, ShieldCheck, Menu, Search, Bell, Landmark, ArrowUpRight, ArrowDownRight, LockKeyhole, CalendarDays, MessageSquare} from 'lucide-react';
import LegacyTable from './LegacyTable';
import CashDesk from './CashDesk';
import ExtractoDesk from './ExtractoDesk';
import ContractProfile from './ContractProfile';
import MessageCenter from './MessageCenter';
import ReportsDesk from './ReportsDesk';
import Toaster from './Toaster';
import {setWaToken} from './waBridge';
import Overview,{type ExtractoPreset} from './Overview';
import SimulatorDesk from './SimulatorDesk';
import ConnectionSettings from './ConnectionSettings';
import type {ThemeId} from './theme';
import {PermsContext,type Perm} from './perms';
import PasswordChange from './PasswordChange';
import {KeyRound} from 'lucide-react';

const noticesApi=import.meta.env.VITE_API_URL||'/api';
type NoticeKind='cobranca'|'atraso';
type NoticeData={atraso:number;cobranca:number;antecedencia:number;paginas:number;linhas:{contrato:string;parcela:number;mutuário:string;vencimento:string;valor:string|null}[]};
const noticeMoney=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});

const nav:{label:string;icon:typeof Users;perm?:Perm}[]=[
 {label:'Visão geral',icon:LayoutDashboard,perm:'ver'},{label:'Mutuários',icon:Users,perm:'ver'},
 {label:'Contratos e prestações',icon:FileText,perm:'ver'},{label:'Simulador',icon:CalendarDays,perm:'ver'},
 {label:'Extractos',icon:ReceiptText,perm:'extractos'},{label:'Mensagens',icon:MessageSquare,perm:'mensagens'},{label:'Caixa',icon:Landmark,perm:'caixa'},{label:'Relatórios',icon:BarChart3,perm:'relatorios'},
 {label:'Definições',icon:Settings}
];
export default function Dashboard({token, name, role, permissions, agency, theme, onThemeChange, onLogout}:{token:string;name:string;role:string;permissions:string[];agency?:string|null;theme:ThemeId;onThemeChange:(value:ThemeId)=>void;onLogout:()=>void}){
 const can=(p?:Perm)=>!p||permissions.includes(p);
 const allowedNav=nav.filter(n=>can(n.perm));
 const [active,setActiveRaw]=useState(()=>allowedNav[0]?.label??'Definições'),[open,setOpen]=useState(false);
 const setActive=(label:string)=>{if(allowedNav.some(n=>n.label===label))setActiveRaw(label);};
 const [pwOpen,setPwOpen]=useState(false);
 setWaToken(token);
 const [clientProfileOpen,setClientProfileOpen]=useState(false);
 const [extractoContract,setExtractoContract]=useState<string|null>(null);
 const [extractoPreset,setExtractoPreset]=useState<(ExtractoPreset&{key:number})|null>(null);
 const [notices,setNotices]=useState<NoticeData|null>(null);
 const [noticeKind,setNoticeKind]=useState<NoticeKind>('atraso');
 const [noticePage,setNoticePage]=useState(1);
 const [noticeOpen,setNoticeOpen]=useState(false);
 const noticeDialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{
  const dialog=noticeDialog.current;
  if(!dialog)return;
  if(noticeOpen&&!dialog.open)dialog.showModal();
  if(!noticeOpen&&dialog.open)dialog.close();
 },[noticeOpen,role]);
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
 return <PermsContext.Provider value={permissions}><div className="cis-layout">
  <aside className={'cis-side '+(open?'visible':'')}>
   <div className="cis-brand"><img src={logoCompactaClaroTransp} alt="CIS – Credit Insight Shift" width={170} height={50}/></div>
   <div className="cis-workspace">ESPAÇO DE TRABALHO</div>
   <nav aria-label="Navegação principal">{allowedNav.map(({label,icon:Icon})=><button type="button" key={label} className={active===label?'current':''} onClick={()=>{setExtractoContract(null);setActive(label);setOpen(false)}}><Icon size={18}/><span>{label}</span></button>)}</nav>
   <div className="cis-side-bottom"><div className="secure-mark"><ShieldCheck size={18}/><span>Ambiente de desenvolvimento<br/><b>Acesso protegido</b></span></div><div className="cis-profile"><div className="cis-initial">{name.charAt(0).toUpperCase()}</div><div className="cis-profile-name"><strong>{name}</strong><small>{role}{agency?' · '+agency:''}</small></div><button type="button" className="cis-profile-key" onClick={()=>setPwOpen(true)} title="Alterar a minha palavra-passe" aria-label="Alterar a minha palavra-passe"><KeyRound size={15}/></button><button type="button" onClick={onLogout} title="Terminar sessão">Sair</button></div></div>
  </aside>
  <div className="cis-stage">
   <header className="cis-top"><div className="cis-breadcrumb"><button type="button" className="cis-hamburger" onClick={()=>setOpen(!open)} aria-label="Abrir navegação"><Menu size={22}/></button><img className="cis-top-logo" src={logoCompactaClaro} alt="CIS" width={92} height={30}/><span>Plataforma CIS</span><span className="cis-separator">/</span><strong>{active}</strong></div><div className="cis-top-right">{role==='administrador'&&<button type="button" className="cis-client-edit" aria-haspopup="dialog" aria-controls="cis-notice-dialog" aria-expanded={noticeOpen} onClick={()=>{setNoticeOpen(true);setNoticeRefresh(v=>v+1);}}><Bell size={16}/> Avisos {notices&&'('+String(notices.atraso+notices.cobranca)+')'}</button>}<span className="cis-env"><span/> Desenvolvimento</span><span className="cis-avatar">{name.charAt(0).toUpperCase()}</span></div></header>
   <main className="cis-main">{can('ver')&&<dialog id="cis-notice-dialog" ref={noticeDialog} className="cis-panel cis-notice-dialog" aria-labelledby="cis-payment-notices" onCancel={event=>{event.preventDefault();setNoticeOpen(false);}}>
    <div className="cis-panel-head"><div><h2 id="cis-payment-notices">Avisos de cobrança e atraso</h2><p>Antecedência do aviso: {notices?.antecedencia??'—'} dia(s).</p></div><button type="button" className="cis-client-edit" onClick={()=>setNoticeOpen(false)}>Fechar avisos</button></div>
    <div className="cis-extracto-modes">{(['atraso','cobranca'] as const).map(kind=><button key={kind} type="button" aria-pressed={noticeKind===kind} className={noticeKind===kind?'selected':''} onClick={()=>{setNoticeKind(kind);setNoticePage(1);}}>{kind==='atraso'?'Pagamentos em atraso':'Cobranças a vencer'} {notices&&'('+notices[kind]+')'}</button>)}</div>
    {noticeBusy?<p role="status">A consultar avisos…</p>:noticeError?<p role="alert">Não foi possível consultar os avisos.</p>:!notices?.linhas.length?<p>Sem prestações a apresentar.</p>:<>
     <div className="cis-table-scroll"><table><thead><tr><th>Contrato</th><th>Mutuário</th><th>Prestação</th><th>Vencimento</th><th>Valor previsto</th><th>Acção</th></tr></thead><tbody>
      {notices.linhas.map((row,index)=><tr key={row.contrato+'-'+row.parcela+'-'+index}><td>{row.contrato}</td><td>{row.mutuário}</td><td>{row.parcela}</td><td>{row.vencimento?.slice(0,10)}</td><td>{row.valor!=null?noticeMoney.format(Number(row.valor))+' MT':'—'}</td><td><button type="button" className="cis-client-edit" aria-label={'Abrir contrato '+row.contrato} onClick={()=>{setExtractoContract(row.contrato);setActive('Contratos e prestações');setNoticeOpen(false);window.scrollTo({top:0});}}>Abrir contrato <ArrowUpRight size={15}/></button></td></tr>)}
     </tbody></table></div>
     {notices.paginas>1&&<div className="cis-collections-pagination"><button type="button" disabled={noticePage<=1} onClick={()=>setNoticePage(p=>p-1)}>Anterior</button><span>{noticePage} / {notices.paginas}</span><button type="button" disabled={noticePage>=notices.paginas} onClick={()=>setNoticePage(p=>p+1)}>Seguinte</button></div>}
    </>}
   </dialog>}{!(active==='Mutuários'&&clientProfileOpen)&&active!=='Caixa'&&active!=='Simulador'&&<><div className="cis-kicker">CIS · SISTEMA DE INFORMAÇÃO DE CRÉDITO</div>
    <div className="cis-title-row"><div><h1>{active==='Visão geral'?'Bem-vindo ao CIS':active}</h1><p>{active==='Visão geral'?'Visão integrada da carteira de microcrédito e das operações financeiras.' :active==='Caixa'?'Gestão de contas, saldos e movimentos financeiros da instituição.':active==='Mensagens'?'Modelos, envio pelo WhatsApp Web e registo das mensagens enviadas aos mutuários.':active==='Relatórios'?'Relatórios de gestão (cobranças, atrasos, gestores de crédito, evolução, Caixa, novo crédito) e regulatórios para o Banco de Moçambique.':active==='Definições'?'Dados da empresa, regras de crédito, parametrização e preferências do sistema.':'Consulta segura dos registos do sistema de microcrédito.'}</p></div><span className="cis-readonly">{active==='Mutuários'||active==='Caixa'||active==='Mensagens'||active==='Relatórios'||active==='Definições'?<><ShieldCheck size={15}/> Acesso protegido</>:<><LockKeyhole size={15}/> Só leitura</>}</span></div></>}
    {active==='Visão geral'?<Overview token={token} onNavigate={setActive} onOpenExtracto={p=>{setExtractoPreset({...p,key:Date.now()});setActive('Extractos');}}/>:active==='Caixa'?<CashDesk token={token}/>:active==='Simulador'?<SimulatorDesk token={token}/>:active==='Mensagens'?<MessageCenter token={token}/>:active==='Relatórios'?<ReportsDesk token={token}/>:active==='Extractos'?<ExtractoDesk key={extractoPreset?.key??0} token={token} role={can('extractos.geral')?'administrador':role} preset={extractoPreset} onClearPreset={()=>setExtractoPreset(null)} onOpenContracts={numero=>{setExtractoContract(numero??null);setActive('Contratos e prestações');window.scrollTo({top:0});}}/>:active==='Definições'?<ConnectionSettings token={token} role={role} theme={theme} onThemeChange={onThemeChange} onChangePassword={()=>setPwOpen(true)}/>:active==='Contratos e prestações'&&extractoContract?<ContractProfile numero={extractoContract} token={token} onBack={()=>setExtractoContract(null)}/>:<LegacyTable module={active} token={token} onProfileChange={setClientProfileOpen}/>}
   </main>
  </div>
  <Toaster/>
  {pwOpen&&<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Alterar palavra-passe" onClick={()=>setPwOpen(false)}><div onClick={e=>e.stopPropagation()} style={{maxWidth:520}}><PasswordChange token={token} name={name} onDone={()=>setPwOpen(false)} onCancel={()=>setPwOpen(false)}/></div></div>}
 </div></PermsContext.Provider>;
}
