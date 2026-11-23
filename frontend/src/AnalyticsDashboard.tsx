// Dashboard Streamlit dentro do CIS. Fica sempre carregado (mesmo escondido) para que a troca
// entre «Visão geral» e «Dashboard» seja imediata; informa o progresso do arranque (ecrã de entrada).
import {useEffect,useRef,useState} from 'react';
import {RefreshCw} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
export type BootProgress={pct:number;text:string};

export default function AnalyticsDashboard({token,visible=true,onProgress,onReady}:{token:string;visible?:boolean;onProgress?:(p:BootProgress)=>void;onReady?:(ok:boolean,msg?:string)=>void}){
 const [attempt,setAttempt]=useState(0),[ready,setReady]=useState(false),[error,setError]=useState('');
 const frame=useRef<HTMLIFrameElement>(null);
 const cb=useRef({onProgress,onReady});cb.current={onProgress,onReady};
 const done=useRef(false);
 const finish=(ok:boolean,msg?:string)=>{if(done.current)return;done.current=true;cb.current.onReady?.(ok,msg);};
 const progress=(pct:number,text:string)=>{if(!done.current)cb.current.onProgress?.({pct,text});};

 useEffect(()=>{
  const ctrl=new AbortController();
  done.current=false;setError('');setReady(false);
  progress(20,'A abrir a sessão segura do Dashboard');
  fetch(API+'/streamlit/sessao',{method:'POST',signal:ctrl.signal,credentials:'same-origin',headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(response=>{
    if(!response.ok)throw new Error(response.status===403?'O Dashboard exige permissão de Relatórios.':'Não foi possível iniciar a sessão do Dashboard.');
    progress(32,'A ligar ao motor de análise (Streamlit)');
    return fetch('/dashboard/_stcore/health',{signal:ctrl.signal,cache:'no-store'});
   })
   .then(h=>{
    if(!h.ok)throw new Error('O motor do Dashboard não respondeu. Tente novamente dentro de instantes.');
    progress(42,'A preparar o Dashboard');
    if(!ctrl.signal.aborted)setReady(true);
   })
   .catch(e=>{if(ctrl.signal.aborted)return;const m=e instanceof Error?e.message:'Não foi possível abrir o Dashboard.';setError(m);finish(false,m);});
  return ()=>ctrl.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token,attempt]);

 // Acompanha o Streamlit dentro do iframe (mesma origem) até os gráficos estarem desenhados.
 useEffect(()=>{
  if(!ready)return;
  const started=Date.now();let stage=0;
  const tick=window.setInterval(()=>{
   const doc=frame.current?.contentDocument;
   const elapsed=(Date.now()-started)/1000;
   if(elapsed>90){window.clearInterval(tick);finish(false,'O Dashboard está a demorar; abre assim que estiver pronto.');return;}
   if(!doc||!doc.body){progress(Math.min(50,44+elapsed),'A carregar o Dashboard');return;}
   if(doc.querySelector('[data-testid="stException"]')||doc.querySelector('[data-testid="stAlertContentError"]')){
    window.clearInterval(tick);finish(false,'O Dashboard encontrou um erro ao processar os dados.');return;}
   const running=!!doc.querySelector('[data-testid="stStatusWidget"]');
   const kpis=doc.querySelectorAll('.cis-kpi').length;
   const plots=doc.querySelectorAll('.js-plotly-plot').length;
   const emptyMsg=doc.querySelector('.cis-empty');
   if((plots>0||emptyMsg)&&!running){
    progress(100,'Dashboard pronto');window.clearInterval(tick);window.setTimeout(()=>finish(true),250);return;}
   if(kpis>0){if(stage<3)stage=3;progress(Math.min(96,86+plots*2),'A desenhar os gráficos');}
   else if(doc.querySelector('[data-testid="stApp"]')){if(stage<2)stage=2;progress(Math.min(84,62+elapsed*1.5),'A calcular os indicadores da carteira');}
   else{if(stage<1)stage=1;progress(Math.min(60,50+elapsed*2),'A ler contratos e prestações');}
  },350);
  return ()=>window.clearInterval(tick);
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[ready]);

 const tema=document.documentElement.dataset.theme||'fintech';
 return <section className={'cis-panel cis-analytics'+(visible?'':' cis-analytics-hidden')} aria-label="Dashboard Streamlit" aria-hidden={!visible}>
  {error?<div className="cis-table-error" role="alert">{error} <button type="button" className="cis-edit-action" onClick={()=>setAttempt(v=>v+1)}><RefreshCw size={15}/> Tentar novamente</button></div>
   :ready?<iframe ref={frame} title="Dashboard Streamlit" src={'/dashboard/?tema='+encodeURIComponent(tema)} tabIndex={visible?0:-1} style={{display:'block',width:'100%',height:'max(85vh, 900px)',minHeight:600,border:0}}/>
   :<p role="status">A abrir o Dashboard…</p>}
 </section>;
}
