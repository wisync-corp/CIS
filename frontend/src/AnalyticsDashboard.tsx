import {useEffect,useState} from 'react';
import {RefreshCw} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';

export default function AnalyticsDashboard({token}:{token:string}){
 const [attempt,setAttempt]=useState(0),[ready,setReady]=useState(false),[error,setError]=useState('');
 useEffect(()=>{
  const ctrl=new AbortController();
  setError('');setReady(false);
  fetch(API+'/streamlit/sessao',{method:'POST',signal:ctrl.signal,credentials:'same-origin',headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(response=>{
    if(!response.ok)throw new Error(response.status===403?'O Dashboard exige permissão de Relatórios.':'Não foi possível iniciar a sessão do Dashboard.');
    if(!ctrl.signal.aborted)setReady(true);
   })
   .catch(e=>{if(!ctrl.signal.aborted)setError(e instanceof Error?e.message:'Não foi possível abrir o Dashboard.');});
  return ()=>ctrl.abort();
 },[token,attempt]);
 return <section className="cis-panel cis-analytics" aria-label="Dashboard Streamlit">
  {error?<div className="cis-table-error" role="alert">{error} <button type="button" className="cis-edit-action" onClick={()=>setAttempt(v=>v+1)}><RefreshCw size={15}/> Tentar novamente</button></div>
   :ready?<iframe title="Dashboard Streamlit" src={'/dashboard/?tema='+encodeURIComponent(document.documentElement.dataset.theme||'fintech')} style={{display:'block',width:'100%',height:'max(85vh, 900px)',minHeight:600,border:0}}/>
   :<p role="status">A abrir o Dashboard…</p>}
 </section>;
}
