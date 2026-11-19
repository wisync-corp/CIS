import {useState} from 'react';
import logoCompactaClaro from './assets/brand/cis-compacta-claro.svg';
import logoCompactaClaroTransp from './assets/brand/cis-compacta-claro-transparente.svg';
import {LayoutDashboard, Users, FileText, Wallet, ReceiptText, BarChart3, Settings, ShieldCheck, Menu, Search, Bell, Landmark, ArrowUpRight, ArrowDownRight, LockKeyhole, CalendarDays} from 'lucide-react';
import LegacyTable from './LegacyTable';
import CashDesk from './CashDesk';
import ExtractoDesk from './ExtractoDesk';
import Overview,{type ExtractoPreset} from './Overview';
import SimulatorDesk from './SimulatorDesk';
import ConnectionSettings from './ConnectionSettings';
import type {ThemeId} from './theme';

const nav=[
 {label:'Visão geral',icon:LayoutDashboard},{label:'Clientes',icon:Users},
 {label:'Contratos e prestações',icon:FileText},{label:'Simulador',icon:CalendarDays},
 {label:'Extractos',icon:ReceiptText},{label:'Caixa',icon:Landmark},{label:'Relatórios',icon:BarChart3},
 {label:'Definições',icon:Settings}
];
export default function Dashboard({token, name, role, theme, onThemeChange, onLogout}:{token:string;name:string;role:string;theme:ThemeId;onThemeChange:(value:ThemeId)=>void;onLogout:()=>void}){
 const [active,setActive]=useState('Visão geral'),[open,setOpen]=useState(false);
 const [clientProfileOpen,setClientProfileOpen]=useState(false);
 const [extractoPreset,setExtractoPreset]=useState<(ExtractoPreset&{key:number})|null>(null);
 return <div className="cis-layout">
  <aside className={'cis-side '+(open?'visible':'')}>
   <div className="cis-brand"><img src={logoCompactaClaroTransp} alt="CIS – Credit Insight Shift" width={170} height={50}/></div>
   <div className="cis-workspace">ESPAÇO DE TRABALHO</div>
   <nav aria-label="Navegação principal">{nav.filter(({label})=>label!=='Caixa'||role==='administrador').map(({label,icon:Icon})=><button type="button" key={label} className={active===label?'current':''} onClick={()=>{setActive(label);setOpen(false)}}><Icon size={18}/><span>{label}</span></button>)}</nav>
   <div className="cis-side-bottom"><div className="secure-mark"><ShieldCheck size={18}/><span>Ambiente de desenvolvimento<br/><b>Acesso protegido</b></span></div><div className="cis-profile"><div className="cis-initial">{name.charAt(0).toUpperCase()}</div><div className="cis-profile-name"><strong>{name}</strong><small>{role}</small></div><button type="button" onClick={onLogout} title="Terminar sessão">Sair</button></div></div>
  </aside>
  <div className="cis-stage">
   <header className="cis-top"><div className="cis-breadcrumb"><button type="button" className="cis-hamburger" onClick={()=>setOpen(!open)} aria-label="Abrir navegação"><Menu size={22}/></button><img className="cis-top-logo" src={logoCompactaClaro} alt="CIS" width={92} height={30}/><span>Plataforma CIS</span><span className="cis-separator">/</span><strong>{active}</strong></div><div className="cis-top-right"><span className="cis-env"><span/> Desenvolvimento</span><span className="cis-avatar">{name.charAt(0).toUpperCase()}</span></div></header>
   <main className="cis-main">{!(active==='Clientes'&&clientProfileOpen)&&active!=='Caixa'&&active!=='Simulador'&&<><div className="cis-kicker">CIS · SISTEMA DE INFORMAÇÃO DE CRÉDITO</div>
    <div className="cis-title-row"><div><h1>{active==='Visão geral'?'Bem-vindo ao CIS':active}</h1><p>{active==='Visão geral'?'Visão integrada da carteira de microcrédito e das operações financeiras.' :active==='Caixa'?'Gestão de contas, saldos e movimentos financeiros da instituição.':'Consulta segura dos registos do sistema de microcrédito.'}</p></div><span className="cis-readonly">{active==='Clientes'||active==='Caixa'?<><ShieldCheck size={15}/> Acesso protegido</>:<><LockKeyhole size={15}/> Só leitura</>}</span></div></>}
    {active==='Visão geral'?<Overview token={token} onNavigate={setActive} onOpenExtracto={p=>{setExtractoPreset({...p,key:Date.now()});setActive('Extractos');}}/>:active==='Caixa'?<CashDesk token={token}/>:active==='Simulador'?<SimulatorDesk token={token}/>:active==='Extractos'?<ExtractoDesk key={extractoPreset?.key??0} token={token} role={role} preset={extractoPreset} onClearPreset={()=>setExtractoPreset(null)}/>:active==='Definições'?<ConnectionSettings token={token} role={role} theme={theme} onThemeChange={onThemeChange}/>:<LegacyTable module={active} token={token} onProfileChange={setClientProfileOpen}/>}
   </main>
  </div>
 </div>;
}
