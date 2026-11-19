import {useState} from 'react';
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

const nav=[
 {label:'Visão geral',icon:LayoutDashboard},{label:'Mutuários',icon:Users},
 {label:'Contratos e prestações',icon:FileText},{label:'Simulador',icon:CalendarDays},
 {label:'Extractos',icon:ReceiptText},{label:'Mensagens',icon:MessageSquare},{label:'Caixa',icon:Landmark},{label:'Relatórios',icon:BarChart3},
 {label:'Definições',icon:Settings}
];
export default function Dashboard({token, name, role, theme, onThemeChange, onLogout}:{token:string;name:string;role:string;theme:ThemeId;onThemeChange:(value:ThemeId)=>void;onLogout:()=>void}){
 const [active,setActive]=useState('Visão geral'),[open,setOpen]=useState(false);
 setWaToken(token);
 const [clientProfileOpen,setClientProfileOpen]=useState(false);
 const [extractoContract,setExtractoContract]=useState<string|null>(null);
 const [extractoPreset,setExtractoPreset]=useState<(ExtractoPreset&{key:number})|null>(null);
 return <div className="cis-layout">
  <aside className={'cis-side '+(open?'visible':'')}>
   <div className="cis-brand"><img src={logoCompactaClaroTransp} alt="CIS – Credit Insight Shift" width={170} height={50}/></div>
   <div className="cis-workspace">ESPAÇO DE TRABALHO</div>
   <nav aria-label="Navegação principal">{nav.filter(({label})=>!['Caixa','Mensagens','Relatórios'].includes(label)||role==='administrador').map(({label,icon:Icon})=><button type="button" key={label} className={active===label?'current':''} onClick={()=>{setExtractoContract(null);setActive(label);setOpen(false)}}><Icon size={18}/><span>{label}</span></button>)}</nav>
   <div className="cis-side-bottom"><div className="secure-mark"><ShieldCheck size={18}/><span>Ambiente de desenvolvimento<br/><b>Acesso protegido</b></span></div><div className="cis-profile"><div className="cis-initial">{name.charAt(0).toUpperCase()}</div><div className="cis-profile-name"><strong>{name}</strong><small>{role}</small></div><button type="button" onClick={onLogout} title="Terminar sessão">Sair</button></div></div>
  </aside>
  <div className="cis-stage">
   <header className="cis-top"><div className="cis-breadcrumb"><button type="button" className="cis-hamburger" onClick={()=>setOpen(!open)} aria-label="Abrir navegação"><Menu size={22}/></button><img className="cis-top-logo" src={logoCompactaClaro} alt="CIS" width={92} height={30}/><span>Plataforma CIS</span><span className="cis-separator">/</span><strong>{active}</strong></div><div className="cis-top-right"><span className="cis-env"><span/> Desenvolvimento</span><span className="cis-avatar">{name.charAt(0).toUpperCase()}</span></div></header>
   <main className="cis-main">{!(active==='Mutuários'&&clientProfileOpen)&&active!=='Caixa'&&active!=='Simulador'&&<><div className="cis-kicker">CIS · SISTEMA DE INFORMAÇÃO DE CRÉDITO</div>
    <div className="cis-title-row"><div><h1>{active==='Visão geral'?'Bem-vindo ao CIS':active}</h1><p>{active==='Visão geral'?'Visão integrada da carteira de microcrédito e das operações financeiras.' :active==='Caixa'?'Gestão de contas, saldos e movimentos financeiros da instituição.':active==='Mensagens'?'Modelos, envio pelo WhatsApp Web e registo das mensagens enviadas aos mutuários.':active==='Relatórios'?'Relatórios de gestão (cobranças, atrasos, gestores de crédito, evolução, Caixa, novo crédito) e regulatórios para o Banco de Moçambique.':active==='Definições'?'Dados da empresa, regras de crédito, parametrização e preferências do sistema.':'Consulta segura dos registos do sistema de microcrédito.'}</p></div><span className="cis-readonly">{active==='Mutuários'||active==='Caixa'||active==='Mensagens'||active==='Relatórios'||active==='Definições'?<><ShieldCheck size={15}/> Acesso protegido</>:<><LockKeyhole size={15}/> Só leitura</>}</span></div></>}
    {active==='Visão geral'?<Overview token={token} onNavigate={setActive} onOpenExtracto={p=>{setExtractoPreset({...p,key:Date.now()});setActive('Extractos');}}/>:active==='Caixa'?<CashDesk token={token}/>:active==='Simulador'?<SimulatorDesk token={token}/>:active==='Mensagens'?<MessageCenter token={token}/>:active==='Relatórios'?<ReportsDesk token={token}/>:active==='Extractos'?<ExtractoDesk key={extractoPreset?.key??0} token={token} role={role} preset={extractoPreset} onClearPreset={()=>setExtractoPreset(null)} onOpenContracts={numero=>{setExtractoContract(numero??null);setActive('Contratos e prestações');window.scrollTo({top:0});}}/>:active==='Definições'?<ConnectionSettings token={token} role={role} theme={theme} onThemeChange={onThemeChange}/>:active==='Contratos e prestações'&&extractoContract?<ContractProfile numero={extractoContract} token={token} onBack={()=>setExtractoContract(null)}/>:<LegacyTable module={active} token={token} onProfileChange={setClientProfileOpen}/>}
   </main>
  </div>
  <Toaster/>
 </div>;
}
