import {useEffect,useState} from 'react';
import {CheckCircle2,AlertCircle,Info,QrCode,X} from 'lucide-react';
import {onToasts,dismissToast,type Toast} from './waBridge';

// Avisos no canto superior direito (envio de mensagens, login do WhatsApp Web…).
export default function Toaster(){
 const [list,setList]=useState<Toast[]>([]);
 useEffect(()=>onToasts(setList),[]);
 if(!list.length)return null;
 return <div className="cis-toaster" role="region" aria-label="Avisos" aria-live="polite">
  {list.map(t=><div key={t.id} className={'cis-toast '+t.kind} role={t.kind==='erro'?'alert':'status'}>
   {t.kind==='ok'?<CheckCircle2 size={18}/>:t.kind==='erro'?<AlertCircle size={18}/>:t.kind==='login'?<QrCode size={18}/>:<Info size={18}/>}
   <span>{t.text}</span>
   <button type="button" onClick={()=>dismissToast(t.id)} aria-label="Fechar aviso"><X size={14}/></button>
  </div>)}
 </div>;
}
