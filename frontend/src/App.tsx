import { useEffect, useState } from 'react';
import Dashboard from './Dashboard';

type User = { name: string; email: string; role: string };
const API = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
export default function App() {
  const [token,setToken]=useState<string|null>(sessionStorage.getItem('cis_token'));
  const [user,setUser]=useState<User|null>(null);
  const [email,setEmail]=useState('');
  const [password,setPassword]=useState('');
  const [busy,setBusy]=useState(false);
  const [error,setError]=useState('');
  useEffect(()=>{if (!token){setUser(null);return;}
    fetch(API+'/auth/me',{headers:{Authorization:'Bearer '+token}})
      .then(async r=>{if(!r.ok) throw new Error('Sessão expirada'); return r.json();})
      .then(setUser)
      .catch(()=>{sessionStorage.removeItem('cis_token');setToken(null);setUser(null);});
  },[token]);
  async function login(e:React.FormEvent) {
    e.preventDefault();setBusy(true);setError('');
    try {
      const res=await fetch(API+'/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password})});
      if(!res.ok) throw new Error('Credenciais inválidas ou serviço indisponível');
      const data=await res.json(); sessionStorage.setItem('cis_token',data.token);setPassword('');setUser(data.user);setToken(data.token);
    }catch(err){setError(err instanceof Error?err.message:'Falha de autenticação');}finally{setBusy(false);}
  }
  async function logout(){
    if(token) await fetch(API+'/auth/logout',{method:'POST',headers:{Authorization:'Bearer '+token}}).catch(()=>{});
    sessionStorage.removeItem('cis_token');setToken(null);setUser(null);
  }
  if(!token || !user) return <div className="login-shell"><form className="login-card" onSubmit={login}>
     <span className="eyebrow">CAPTA MICROCRÉDITO</span><h1>CIS</h1><p>Acesso reservado a colaboradores autorizados.</p>
     <label>Email<input type="email" required autoComplete="username" value={email} onChange={e=>setEmail(e.target.value)} /></label>
     <label>Palavra-passe<input type="password" required autoComplete="current-password" value={password} onChange={e=>setPassword(e.target.value)} /></label>
     {error&&<div className="login-error" role="alert">{error}</div>}
     <button type="submit" disabled={busy}>{busy?'A autenticar…':'Entrar no CIS'}</button>
     <small>A primeira conta é criada pelo administrador do servidor. Nunca utilize a senha do Excel.</small>
   </form></div>;
  return <><div className="session-strip"><span>{user.name} · {user.role}</span><button onClick={logout}>Terminar sessão</button></div><Dashboard /></>;
}
