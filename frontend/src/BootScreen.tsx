// Ecrã de entrada: prepara a visão geral e o Dashboard (Streamlit) antes de mostrar o sistema.
import {useEffect,useState} from 'react';
import {CheckCircle2,Loader2,Circle,ShieldCheck,AlertTriangle} from 'lucide-react';
import logo from './assets/brand/cis-compacta-claro-transparente.svg';

export type BootStep={key:string;label:string;detail?:string;state:'done'|'now'|'wait'|'warn'};

export default function BootScreen({name,pct,current,steps,onSkip}:{name:string;pct:number;current:string;steps:BootStep[];onSkip:()=>void}){
 const [shown,setShown]=useState(0),[slow,setSlow]=useState(false);
 // Animação suave da percentagem (nunca recua).
 useEffect(()=>{const t=window.setInterval(()=>setShown(v=>v>=pct?v:Math.min(pct,v+Math.max(1,Math.round((pct-v)/6)))),40);return()=>window.clearInterval(t);},[pct]);
 useEffect(()=>{const s=window.setTimeout(()=>setSlow(true),25000);return()=>window.clearTimeout(s);},[]);
 const first=name.split(' ')[0];
 return <div className="cis-boot" role="dialog" aria-modal="true" aria-label="A preparar o CIS">
  <div className="cis-boot-card">
   <img src={logo} alt="CIS – Credit Insight Shift" width={170} height={50}/>
   <span className="cis-boot-kicker">A PREPARAR O SEU ESPAÇO DE TRABALHO</span>
   <h1>Olá, {first}</h1>
   <p>Estamos a processar a carteira e o Dashboard para entrar com tudo pronto.</p>
   <div className="cis-boot-pct" aria-live="polite"><strong>{shown}%</strong><span>{current}</span></div>
   <div className="cis-boot-bar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={shown} aria-valuetext={current}><i style={{width:shown+'%'}}/></div>
   <ul className="cis-boot-steps">{steps.map(s=><li key={s.key} className={s.state}>
    {s.state==='done'?<CheckCircle2 size={17}/>:s.state==='now'?<Loader2 size={17} className="cis-spin"/>:s.state==='warn'?<AlertTriangle size={17}/>:<Circle size={17}/>}
    <div><b>{s.label}</b>{s.detail&&<small>{s.detail}</small>}</div></li>)}</ul>
   {slow&&<button type="button" className="cis-boot-skip" onClick={onSkip}>Entrar sem esperar pelo Dashboard</button>}
   <footer><ShieldCheck size={14}/> Ligação protegida · os dados respeitam a sua agência e carteira</footer>
  </div>
 </div>;
}
