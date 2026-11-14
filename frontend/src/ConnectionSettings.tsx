import {useEffect,useState} from 'react';
import {ShieldCheck,AlertCircle,RefreshCw,CheckCircle2,Palette} from 'lucide-react';
import {themeOptions,type ThemeId} from './theme';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Status={backend:string;legacy_connection_configured:boolean;tls_ca_configured:boolean;test_data_gate_open:boolean;records_access:string;note:string};

export default function ConnectionSettings({token,role,theme,onThemeChange}:{token:string;role:string;theme:ThemeId;onThemeChange:(value:ThemeId)=>void}){
 const [status,setStatus]=useState<Status|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true),[reload,setReload]=useState(0);
 useEffect(()=>{if(role!=='administrador'){setLoading(false);return;}const ctrl=new AbortController();setLoading(true);setError('');
 fetch(API+'/admin/connection-status',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
 .then(async response=>{if(!response.ok)throw new Error('Não foi possível obter o estado da ligação.');return response.json();})
 .then((data:Status)=>setStatus(data)).catch(e=>{if(e.name!=='AbortError')setError(e.message);})
 .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});return ()=>ctrl.abort();
 },[token,role,reload]);
 return <div className="cis-settings-page">
  <section className="cis-panel cis-settings-appearance" aria-labelledby="appearance-title">
   <div className="cis-panel-head"><div><h2 id="appearance-title"><Palette size={19} style={{verticalAlign:'middle',marginRight:9}}/>Aparência do CIS</h2><p>Escolha a identidade visual do sistema. A alteração é imediata e não afecta os dados financeiros.</p></div></div>
   <div className="cis-theme-grid" role="group" aria-label="Temas disponíveis">
    {themeOptions.map(option=><button type="button" key={option.id} className="cis-theme-option" aria-pressed={theme===option.id} onClick={()=>onThemeChange(option.id)}>
     <span className="cis-theme-preview" aria-hidden="true">
      <span className="cis-theme-preview-side" style={{background:option.swatches[0]}}><i/><i/><i/></span>
      <span className="cis-theme-preview-body" style={{background:option.swatches[2]}}><i/><i style={{borderColor:option.swatches[1]}}/></span>
     </span>
     <span className="cis-theme-option-title">{option.name}{theme===option.id&&<CheckCircle2 size={18}/>}</span>
     <span className="cis-theme-option-sub">{option.subtitle}</span>
     <span className="cis-theme-option-desc">{option.description}</span>
    </button>)}
   </div>
   <div className="cis-settings-status"><CheckCircle2 size={16}/>Tema {themeOptions.find(option=>option.id===theme)?.name} activo · Guardado neste navegador</div>
   <p>A escolha fica guardada apenas neste navegador. Em outro dispositivo, poderá seleccionar o tema novamente.</p>
  </section>
  {role==='administrador'&&<section className="cis-panel">
   <div className="cis-panel-head"><div><h2>Estado da ligação MySQL</h2><p>Verificação de configuração, sem exposição de credenciais.</p></div><button type="button" className="cis-client-edit" onClick={()=>setReload(x=>x+1)} aria-label="Actualizar estado"><RefreshCw size={18}/></button></div>
   <div className="cis-banner"><ShieldCheck size={20}/><div><b>As credenciais não devem ser introduzidas neste site.</b><p>Guarda os acessos apenas no ambiente privado do servidor. Esta página não apresenta palavras-passe ou chaves.</p></div></div>
   {loading&&<p>A verificar a configuração…</p>}{error&&<div className="cis-table-error"><AlertCircle size={18}/>{error}</div>}
   {status&&<div className="cis-ledger">
    <div><span className="cis-ledger-dot"/><span><b>API Laravel</b><small>Servidor CIS</small></span><strong>{status.backend}</strong></div>
    <div><span className="cis-ledger-dot"/><span><b>Configuração MySQL</b><small>Presença das configurações de ligação</small></span><strong>{status.legacy_connection_configured?'Presente':'Incompleta'}</strong></div>
    <div><span className="cis-ledger-dot"/><span><b>Certificado CA</b><small>Certificado opcional para ligações externas TLS</small></span><strong>{status.tls_ca_configured?'Configurado':'Não configurado'}</strong></div>
    <div><span className="cis-ledger-dot"/><span><b>Consulta financeira em testes</b><small>Não determina acesso à consulta privada de Clientes</small></span><strong>{status.test_data_gate_open?'Aprovada':'Bloqueada'}</strong></div>
   </div>}
   <p style={{marginTop:20}}>As informações acima indicam apenas configuração. Não comprovam a ligação efectiva, os privilégios MySQL ou a segurança TLS da sessão. O MySQL local do VPS permanece numa rede privada.</p>
  </section>}
 </div>;
}
