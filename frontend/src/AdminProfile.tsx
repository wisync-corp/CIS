// Perfil do utilizador (botão com o nome, em baixo na barra lateral): conta e, para o administrador, a subscrição do CIS.
import {useState} from 'react';
import {KeyRound,LogOut,CreditCard,UserRound,ShieldCheck} from 'lucide-react';
import {useCan} from './perms';
import Subscription from './Subscription';

export type ProfileTab='conta'|'subscricao';
export default function AdminProfile({token,name,role,agency,initialTab,onChangePassword,onLogout}:{token:string;name:string;role:string;agency?:string|null;initialTab:ProfileTab;onChangePassword:()=>void;onLogout:()=>void}){
 const can=useCan();const admin=can('definicoes');
 const [tab,setTab]=useState<ProfileTab>(admin?initialTab:'conta');
 return <div className="cis-me">
  <section className="cis-me-hero"><span className="cis-me-avatar">{name.charAt(0).toUpperCase()}</span>
   <div><span>O MEU PERFIL</span><h1>{name}</h1><p><ShieldCheck size={14}/> {role}{agency?' · '+agency:''}</p></div></section>
  <div className="cis-home-tabs cis-me-tabs" role="tablist" aria-label="Perfil">
   <button type="button" role="tab" aria-selected={tab==='conta'} className={tab==='conta'?'selected':''} onClick={()=>setTab('conta')}><UserRound size={16}/> A minha conta</button>
   {admin&&<button type="button" role="tab" aria-selected={tab==='subscricao'} className={tab==='subscricao'?'selected':''} onClick={()=>setTab('subscricao')}><CreditCard size={16}/> Subscrição</button>}
  </div>
  {tab==='conta'&&<section className="cis-panel cis-co-section"><div className="cis-co-section-head"><div><h3>A minha conta</h3><p>Sessão iniciada como <b>{role}</b>. Pode alterar a sua palavra-passe a qualquer momento.</p></div></div>
   <div className="cis-me-actions"><button type="button" className="cis-client-edit" onClick={onChangePassword}><KeyRound size={15}/> Alterar a minha palavra-passe</button>
    <button type="button" className="cis-client-edit" onClick={onLogout}><LogOut size={15}/> Terminar sessão</button></div></section>}
  {admin&&tab==='subscricao'&&<Subscription token={token}/>}
 </div>;
}
