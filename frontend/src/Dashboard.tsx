import LegacyTable from './LegacyTable';
import { useState } from 'react';
import { LayoutDashboard, Users, FileText, Wallet, Receipt, BarChart3, Settings, ShieldAlert, Landmark, Bell, Search, Menu } from 'lucide-react';

const navigation = [
  {label:'Visão geral', icon:LayoutDashboard}, {label:'Clientes', icon:Users},
  {label:'Contratos e prestações', icon:FileText}, {label:'Cobranças', icon:Wallet},
  {label:'Extractos', icon:Receipt}, {label:'Relatórios', icon:BarChart3},
  {label:'Administração', icon:Settings}
];
const mockStats = [
  {label:'Capital em carteira', value:'—', help:'Sem ligação à base de dados'},
  {label:'Juros recebidos', value:'—', help:'Requer distribuição validada'},
  {label:'Multas recebidas', value:'—', help:'Requer distribuição validada'},
  {label:'Prestações em atraso', value:'—', help:'Sem ligação à base de dados'}
];
export default function Dashboard({token}:{token:string}){
 const [active,setActive]=useState('Visão geral'); const [open,setOpen]=useState(false);
 return <div className="shell"><aside className={open?'sidebar open':'sidebar'}><div className="brand"><div className="brand-icon"><Landmark size={23}/></div><div><strong>CAPTA</strong><span>MICROCRÉDITO · CIS</span></div></div><div className="side-label">PLATAFORMA</div><nav>{navigation.map(item=><button key={item.label} className={active===item.label?'selected':''} onClick={()=>{setActive(item.label);setOpen(false)}}><item.icon size={19}/>{item.label}</button>)}</nav><div className="sidebar-foot"><ShieldAlert size={18}/><span>Ambiente de desenvolvimento<br/>Sem dados reais</span></div></aside><div className="content"><header><button className="menu" onClick={()=>setOpen(!open)} aria-label="Abrir menu"><Menu/></button><div className="crumb">Capta Microcrédito <span>/</span> <b>{active}</b></div><div className="header-actions"><Search size={18}/><Bell size={18}/><div className="avatar">C</div></div></header><main><div className="eyebrow">CIS · CENTRO OPERACIONAL</div><div className="page-head"><div><h1>{active}</h1><p>Gestão segura de crédito e acompanhamento financeiro da Capta.</p></div><span className="badge"><span className="dot"/> Estrutura inicial</span></div>{active==='Visão geral'?<><div className="notice"><ShieldAlert size={20}/><div><strong>Modo seguro: não existem dados de produção ligados.</strong><span>Os indicadores serão apresentados quando a autenticação e o acesso apenas de leitura estiverem validados.</span></div></div><div className="cards">{mockStats.map(s=><section className="card" key={s.label}><div className="stat-label">{s.label}</div><div className="stat-value">{s.value}</div><div className="hint">{s.help}</div></section>)}</div><div className="panels"><section className="panel"><h2>Operações recentes</h2><p>Histórico de movimentos e pagamentos.</p><div className="empty"><Receipt size={30}/><strong>Nenhum movimento apresentado</strong><span>A base de dados não está ligada a esta interface.</span></div></section><section className="panel"><h2>Composição dos pagamentos</h2><p>Distribuição por componente financeiro.</p><div className="component-lines">{['Amortização','Juros','Multa'].map(x=><div key={x}><span className="small-dot"/><span>{x}</span><b>—</b></div>)}</div><div className="panel-note">Capital, amortização, juros e multas serão apurados separadamente, sem presumir uma prioridade de distribuição.</div></section></div></>:<LegacyTable module={active} token={token}/>}</main></div></div>
}