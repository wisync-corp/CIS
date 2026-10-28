import {useState} from 'react';
import {LayoutDashboard, Users, FileText, Wallet, ReceiptText, BarChart3, Settings, ShieldCheck, Menu, Search, Bell, Landmark, ArrowUpRight, ArrowDownRight, LockKeyhole, CalendarDays} from 'lucide-react';
import LegacyTable from './LegacyTable';
import ClientDrafts from './ClientDrafts';

const nav=[
 {label:'Visão geral',icon:LayoutDashboard},{label:'Clientes',icon:Users},
 {label:'Contratos e prestações',icon:FileText},{label:'Cobranças',icon:Wallet},
 {label:'Extractos',icon:ReceiptText},{label:'Relatórios',icon:BarChart3},
 {label:'Administração',icon:Settings}
];
const stats=[
 {name:'Capital em carteira',icon:Landmark,hint:'Depende da reconciliação financeira'},
 {name:'Juros efectivamente recebidos',icon:ArrowUpRight,hint:'Separados da amortização'},
 {name:'Multas recebidas',icon:ArrowDownRight,hint:'Exclui multas dispensadas'},
 {name:'Prestações vencidas',icon:CalendarDays,hint:'Aguardam consulta e validação'}
];
export default function Dashboard({token, name, role, onLogout}:{token:string;name:string;role:string;onLogout:()=>void}){
 const [active,setActive]=useState('Visão geral'),[open,setOpen]=useState(false);
 return <div className="cis-layout">
  <aside className={'cis-side '+(open?'visible':'')}>
   <div className="cis-brand"><div className="cis-logo">C<span>·</span></div><div><strong>CIS</strong><small>CREDIT INSIGHT SHIFT</small></div></div>
   <div className="cis-workspace">ESPAÇO DE TRABALHO</div>
   <nav aria-label="Navegação principal">{nav.map(({label,icon:Icon})=><button type="button" key={label} className={active===label?'current':''} onClick={()=>{setActive(label);setOpen(false)}}><Icon size={18}/><span>{label}</span></button>)}</nav>
   <div className="cis-side-bottom"><div className="secure-mark"><ShieldCheck size={18}/><span>Ambiente de desenvolvimento<br/><b>Acesso protegido</b></span></div><div className="cis-profile"><div className="cis-initial">{name.charAt(0).toUpperCase()}</div><div className="cis-profile-name"><strong>{name}</strong><small>{role}</small></div><button type="button" onClick={onLogout} title="Terminar sessão">Sair</button></div></div>
  </aside>
  <div className="cis-stage">
   <header className="cis-top"><div className="cis-breadcrumb"><button type="button" className="cis-hamburger" onClick={()=>setOpen(!open)} aria-label="Abrir navegação"><Menu size={22}/></button><span>Plataforma CIS</span><span className="cis-separator">/</span><strong>{active}</strong></div><div className="cis-top-right"><span className="cis-env"><span/> Desenvolvimento</span><span className="cis-avatar">{name.charAt(0).toUpperCase()}</span></div></header>
   <main className="cis-main"><div className="cis-kicker">CIS · SISTEMA DE INFORMAÇÃO DE CRÉDITO</div>
    <div className="cis-title-row"><div><h1>{active==='Visão geral'?'Bem-vindo ao CIS':active}</h1><p>{active==='Visão geral'?'Visão integrada da carteira de microcrédito e das operações financeiras.':'Consulta segura dos registos do sistema de microcrédito.'}</p></div><span className="cis-readonly"><LockKeyhole size={15}/> Só leitura</span></div>
    {active==='Visão geral'?<><section className="cis-banner"><ShieldCheck size={21}/><div><b>Ligação aos dados em validação</b><p>Os indicadores financeiros permanecem indisponíveis até reconciliar capital, amortização, juros e multas com o sistema VBA.</p></div></section>
    <div className="cis-stat-grid">{stats.map(({name,icon:Icon,hint})=><article className="cis-stat" key={name}><div className="cis-stat-top"><span>{name}</span><Icon size={18}/></div><strong>—</strong><small>{hint}</small></article>)}</div>
    <div className="cis-home-grid"><section className="cis-panel"><div className="cis-panel-head"><div><h2>Registos do sistema</h2><p>Comece por consultar os dados existentes na base MySQL.</p></div></div><div className="cis-action-row"><button type="button" onClick={()=>setActive('Clientes')}><Users size={20}/><span><b>Clientes</b><small>Consultar o cadastro</small></span><ArrowUpRight size={18}/></button><button type="button" onClick={()=>setActive('Contratos e prestações')}><FileText size={20}/><span><b>Contratos</b><small>Consultar prestações e saldos</small></span><ArrowUpRight size={18}/></button></div></section>
    <section className="cis-panel"><h2>Distribuição dos pagamentos</h2><p>Componentes financeiros separados e rastreáveis.</p><div className="cis-ledger">{[['Capital','Saldo de principal'],['Amortização','Recuperação de capital'],['Juros','Receita financeira'],['Multa','Encargo por atraso'],['Prestação','Obrigação contratual']].map(([title,sub])=><div key={title}><span className="cis-ledger-dot"/><span><b>{title}</b><small>{sub}</small></span><strong>—</strong></div>)}</div></section></div></>:active==='Clientes'?<ClientDrafts token={token}/>:<LegacyTable module={active} token={token}/>}
   </main>
  </div>
 </div>;
}
