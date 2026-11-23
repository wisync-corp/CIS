// Troca da própria palavra-passe. «required»: primeiro acesso com palavra-passe temporária
// (ecrã inteiro, sem acesso ao resto do CIS até trocar).
import {useState,type FormEvent} from 'react';
import {KeyRound,Eye,EyeOff,LogOut,CheckCircle2,AlertCircle} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Props={token:string;name:string;required?:boolean;onDone:(user:unknown)=>void;onCancel?:()=>void;onLogout?:()=>void};

export default function PasswordChange({token,name,required,onDone,onCancel,onLogout}:Props){
 const [actual,setActual]=useState(''),[nova,setNova]=useState(''),[conf,setConf]=useState('');
 const [show,setShow]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState(''),[ok,setOk]=useState(false);
 const rules=[{ok:nova.length>=10,t:'Pelo menos 10 caracteres'},{ok:/[A-Za-z]/.test(nova)&&/[0-9]/.test(nova),t:'Letras e números'},{ok:!!nova&&nova===conf,t:'Confirmação igual'},{ok:!!nova&&nova!==actual,t:'Diferente da actual'}];
 const valid=!!actual&&rules.every(r=>r.ok);
 async function submit(e:FormEvent){e.preventDefault();if(!valid||busy)return;setBusy(true);setError('');
  try{const r=await fetch(API+'/auth/palavra-passe',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({actual,nova,nova_confirmation:conf})});
   const j=await r.json().catch(()=>({})) as {message?:string;errors?:Record<string,string[]>;user?:unknown};
   if(!r.ok)throw new Error(Object.values(j.errors??{}).flat()[0]||j.message||'Não foi possível alterar a palavra-passe.');
   setOk(true);setTimeout(()=>onDone(j.user),900);
  }catch(err){setError(err instanceof Error?err.message:'Falha ao alterar.');}finally{setBusy(false);}
 }
 const type=show?'text':'password';
 const form=<form className="cis-panel cis-pw" onSubmit={submit}>
  <div className="cis-pw-head"><span className="cis-pw-icon"><KeyRound size={20}/></span><div>
   <h2>{required?'Defina a sua palavra-passe':'Alterar a minha palavra-passe'}</h2>
   <p>{required?<>Olá {name.split(' ')[0]}. Entrou com uma palavra-passe temporária; escolha agora a sua palavra-passe pessoal para continuar.</>:'A nova palavra-passe passa a valer já; as outras sessões abertas são terminadas.'}</p></div></div>
  <label>{required?'Palavra-passe temporária':'Palavra-passe actual'}<input type={type} autoComplete="current-password" value={actual} onChange={e=>setActual(e.target.value)} autoFocus/></label>
  <label>Nova palavra-passe<span className="cis-pw-field"><input type={type} autoComplete="new-password" value={nova} onChange={e=>setNova(e.target.value)}/>
   <button type="button" onClick={()=>setShow(v=>!v)} aria-label={show?'Ocultar':'Mostrar'}>{show?<EyeOff size={17}/>:<Eye size={17}/>}</button></span></label>
  <label>Confirmar a nova palavra-passe<input type={type} autoComplete="new-password" value={conf} onChange={e=>setConf(e.target.value)}/></label>
  <ul className="cis-pw-rules">{rules.map(r=><li key={r.t} className={r.ok?'ok':''}><CheckCircle2 size={14}/> {r.t}</li>)}</ul>
  {error&&<p className="cis-pw-error" role="alert"><AlertCircle size={15}/> {error}</p>}
  {ok&&<p className="cis-pw-ok" role="status"><CheckCircle2 size={15}/> Palavra-passe alterada.</p>}
  <div className="cis-pw-actions">
   <button type="submit" className="primary" disabled={!valid||busy}>{busy?'A guardar…':'Guardar palavra-passe'}</button>
   {onCancel&&<button type="button" onClick={onCancel}>Cancelar</button>}
   {required&&onLogout&&<button type="button" onClick={onLogout}><LogOut size={14}/> Sair</button>}
  </div>
 </form>;
 return required?<div className="cis-pw-page">{form}</div>:form;
}
