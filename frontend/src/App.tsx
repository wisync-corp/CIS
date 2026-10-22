import {useEffect,useState,type FormEvent} from 'react';
import {ShieldCheck, ArrowRight, Eye, EyeOff, LockKeyhole, Landmark} from 'lucide-react';
import Dashboard from './Dashboard';

type User={name:string;email:string;role:string};
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

export default function App(){
 const [token,setToken]=useState<string|null>(sessionStorage.getItem('cis_token'));
 const [user,setUser]=useState<User|null>(null);
 const [email,setEmail]=useState(''),[password,setPassword]=useState('');
 const [showPassword,setShowPassword]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
 useEffect(()=>{if(!token){setUser(null);return;}const controller=new AbortController();
 fetch(API+'/auth/me',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
  .then(async response=>{if(!response.ok)throw new Error('Sessão inválida');return response.json();})
  .then((data:User)=>setUser(data)).catch(e=>{if(e.name!=='AbortError'){sessionStorage.removeItem('cis_token');setToken(null);}});
 return ()=>controller.abort();},[token]);
 async function login(event:FormEvent){event.preventDefault();setBusy(true);setError('');
  try{const response=await fetch(API+'/auth/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email,password})});
    if(!response.ok)throw new Error(response.status===422?'Credenciais inválidas.':'Não foi possível iniciar sessão. Verifique a API.');
    const data:{token:string;user:User}=await response.json();
    sessionStorage.setItem('cis_token',data.token);setPassword('');setUser(data.user);setToken(data.token);
  }catch(err){setError(err instanceof Error?err.message:'Falha de autenticação.');}finally{setBusy(false);}
 }
 async function logout(){if(token)await fetch(API+'/auth/logout',{method:'POST',headers:{Authorization:'Bearer '+token}}).catch(()=>{});
 sessionStorage.removeItem('cis_token');setToken(null);setUser(null);}
 if(token&&!user)return <div className="cis-auth-pending" role="status">A validar a sessão do CIS…</div>;
 if(token&&user)return <Dashboard token={token} name={user.name} role={user.role} onLogout={logout}/>;
 return <div className="cis-login"><div className="cis-login-left"><div className="cis-login-brand"><div>C<span>·</span></div><strong>CAPTA<small>MICROCRÉDITO</small></strong></div><div className="cis-login-story"><div className="cis-login-eyebrow">PLATAFORMA DE GESTÃO FINANCEIRA</div><h1>Crédito com<br/>mais <em>clareza.</em><br/>Gestão com<br/>mais confiança.</h1><p>Um só espaço para acompanhar clientes, contratos, prestações e operações de microcrédito.</p><div className="cis-login-feature"><ShieldCheck size={21}/><span>Protecção de dados e controlo de acessos</span></div></div><div className="cis-login-caption">CIS · Sistema de Informação de Crédito</div></div>
 <div className="cis-login-right"><form className="cis-login-form" onSubmit={login}><div className="cis-login-mobile-mark"><Landmark size={19}/> CAPTA</div><div className="cis-form-caption">ÁREA RESERVADA</div><h2>Entrar na plataforma</h2><p>Introduza as credenciais do CIS Web para continuar.</p>
 <label htmlFor="email">Endereço de email</label><input id="email" type="email" autoComplete="username" required placeholder="nome@empresa.co.mz" value={email} onChange={e=>setEmail(e.target.value)}/>
 <label htmlFor="password">Palavra-passe</label><div className="cis-password"><input id="password" type={showPassword?'text':'password'} autoComplete="current-password" required value={password} placeholder="A sua palavra-passe" onChange={e=>setPassword(e.target.value)}/><button type="button" aria-label={showPassword?'Ocultar palavra-passe':'Mostrar palavra-passe'} onClick={()=>setShowPassword(v=>!v)}>{showPassword?<EyeOff size={19}/>:<Eye size={19}/>}</button></div>
 {error&&<div className="cis-login-error" role="alert">{error}</div>}<button className="cis-signin" type="submit" disabled={busy}>{busy?'A autenticar…':'Entrar no CIS'}<ArrowRight size={18}/></button>
 <div className="cis-login-safe"><LockKeyhole size={17}/><span>Primeiro acesso: conta criada por um administrador do servidor. As credenciais do Excel não são utilizadas.</span></div></form><div className="cis-login-footer">Capta Microcrédito · Ambiente de desenvolvimento</div></div></div>;
}
