import {useEffect,useState} from 'react';
import {ShieldCheck,AlertCircle,RefreshCw} from 'lucide-react';
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Status={backend:string;legacy_connection_configured:boolean;tls_ca_configured:boolean;test_data_gate_open:boolean;records_access:string;note:string};
export default function ConnectionSettings({token,role}:{token:string;role:string}){
 const [status,setStatus]=useState<Status|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true),[reload,setReload]=useState(0);
 useEffect(()=>{if(role!=='administrador'){setLoading(false);return;}const ctrl=new AbortController();setLoading(true);setError('');
 fetch(API+'/admin/connection-status',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
 .then(async response=>{if(!response.ok)throw new Error('Não foi possível obter o estado da ligação.');return response.json();})
 .then((data:Status)=>setStatus(data)).catch(e=>{if(e.name!=='AbortError')setError(e.message);})
 .finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});return ()=>ctrl.abort();
 },[token,role,reload]);
 if(role!=='administrador')return <section className="cis-panel"><h2>Administração</h2><p>Esta área é exclusiva para administradores.</p></section>;
 return <section className="cis-panel"><div className="cis-panel-head"><div><h2>Configuração da ligação MySQL</h2><p>Estado de preparação da integração com a base original.</p></div><button className="cis-client-edit" onClick={()=>setReload(x=>x+1)} aria-label="Actualizar estado"><RefreshCw size={18}/></button></div>
 <div className="cis-banner"><ShieldCheck size={20}/><div><b>As credenciais não devem ser introduzidas neste site.</b><p>Guarda os acessos apenas no ambiente privado do servidor ou num gestor de segredos. Esta página não transmite nem apresenta passwords, chaves ou endereços internos.</p></div></div>
 {loading&&<p>A verificar a configuração…</p>}{error&&<div className="cis-table-error"><AlertCircle size={18}/>{error}</div>}
 {status&&<div className="cis-ledger">
 <div><span className="cis-ledger-dot"/><span><b>API Laravel</b><small>Servidor CIS</small></span><strong>{status.backend}</strong></div>
 <div><span className="cis-ledger-dot"/><span><b>Configuração MySQL</b><small>Não valida ligação de dados</small></span><strong>{status.legacy_connection_configured?'Presente':'Incompleta'}</strong></div>
 <div><span className="cis-ledger-dot"/><span><b>Certificado CA</b><small>Ficheiro de certificado legível no servidor</small></span><strong>{status.tls_ca_configured?'Configurado':'Não configurado'}</strong></div>
 <div><span className="cis-ledger-dot"/><span><b>Acesso aos dados</b><small>Condição de homologação</small></span><strong>{status.test_data_gate_open?'Aprovação configurada':'Bloqueado'}</strong></div>
 </div>}
 <p style={{marginTop:20}}>O fornecedor actual reportou TLS desactivado. Ter um certificado CA configurado não activa TLS no fornecedor. A migração para um MySQL com TLS exige infraestrutura e permissões adequadas antes de consultar dados reais.</p>
 </section>;
}
