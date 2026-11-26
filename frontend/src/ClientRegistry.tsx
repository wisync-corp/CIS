// Mutuário ao nível do GEMIC: fotografia, pessoa singular/colectiva (empresa) e lista negra.
import {useCallback,useEffect,useRef,useState} from 'react';
import {Camera,Building2,UserRound,Ban,ShieldCheck,AlertTriangle,X,Save,Loader2,Users,ArrowUpRight} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Company={forma_juridica:string|null;nuit:string|null;actividade:string|null;data_constituicao:string|null;representante_nome:string|null;representante_cargo:string|null;representante_documento:string|null;representante_contacto:string|null};
export type Registry={instalado:boolean;tipo:'singular'|'colectiva';empresa:Company|null;tem_foto:boolean;
 lista_negra:{motivo:string;desde:string|null;por:string|null}|null;
 historico_lista_negra:{motivo:string;created_at:string|null;por:string|null;removed_at:string|null;retirado_por:string|null;removed_motivo:string|null}[];
 avalista_em:{id:number;numero:string;mutuario:string;estado:string;montante:string}[];
 formas_juridicas:Record<string,string>;pode_editar:boolean;pode_lista_negra:boolean};

async function call<T>(token:string,path:string,method='GET',body?:unknown):Promise<T>{
 const form=body instanceof FormData;
 const r=await fetch(API+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json',...(body&&!form?{'Content-Type':'application/json'}:{})},body:body?(form?body:JSON.stringify(body)):undefined});
 const j=await r.json().catch(()=>({}));
 if(r.status===413)throw new Error('A fotografia é demasiado grande (máx. 5 MB).');
 if(!r.ok)throw new Error((j.errors?Object.values(j.errors as Record<string,string[]>).flat()[0]:undefined)||j.message||'Operação recusada.');
 return j as T;
}
const when=(v:string|null)=>v?v.slice(0,10).split('-').reverse().join('/'):'';

export function useClientRegistry(token:string,id:number){
 const [reg,setReg]=useState<Registry|null>(null);
 const [photo,setPhoto]=useState<string|null>(null);
 const load=useCallback(async()=>{
  try{const r=await call<Registry>(token,'/cis/mutuarios/'+id+'/perfil');setReg(r);
   if(r.instalado&&r.tem_foto){const f=await fetch(API+'/cis/mutuarios/'+id+'/foto?t='+Date.now(),{headers:{Authorization:'Bearer '+token}});
    if(f.ok){const url=URL.createObjectURL(await f.blob());setPhoto(old=>{if(old)URL.revokeObjectURL(old);return url;});}}
  }catch{setReg(null);}
 },[token,id]);
 useEffect(()=>{void load();},[load]);
 return {reg,setReg,photo,reload:load};
}

/** Fotografia na ficha: carregar ficheiro ou tirar com a câmara (telemóvel/tablet). */
export function ClientAvatar({token,id,reg,photo,onChanged,firm}:{token:string;id:number;reg:Registry|null;photo:string|null;onChanged:()=>void;firm:boolean}){
 const input=useRef<HTMLInputElement>(null);
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const canEdit=!!reg?.instalado&&reg.pode_editar;
 async function upload(f:File){
  if(f.size>5*1024*1024){setError('A fotografia tem mais de 5 MB.');return;}
  setBusy(true);setError('');
  try{const fd=new FormData();fd.append('foto',f);await call(token,'/cis/mutuarios/'+id+'/foto','POST',fd);onChanged();}
  catch(e){setError((e as Error).message);}finally{setBusy(false);if(input.current)input.current.value='';}
 }
 return <div className={'cis-profile-avatar cis-avatar-photo'+(reg?.lista_negra?' black':'')}>
  {photo?<img src={photo} alt="Fotografia do mutuário"/>:firm?<Building2 size={34}/>:<UserRound size={34}/>}
  {canEdit&&<>
   <button type="button" className="cis-avatar-edit" title={photo?'Mudar a fotografia':'Adicionar fotografia'} aria-label={photo?'Mudar a fotografia':'Adicionar fotografia'} disabled={busy} onClick={()=>input.current?.click()}>
    {busy?<Loader2 size={14} className="cis-spin"/>:<Camera size={14}/>}</button>
   <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={e=>{const f=e.target.files?.[0];if(f)void upload(f);}}/>
  </>}
  {error&&<span className="cis-avatar-error" role="alert">{error}</span>}
 </div>;
}

/** Faixa da lista negra e botão para adicionar/retirar (gestor ou administrador). */
export function BlacklistBar({token,id,reg,setReg}:{token:string;id:number;reg:Registry;setReg:(r:Registry)=>void}){
 const [open,setOpen]=useState(false),[motivo,setMotivo]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const b=reg.lista_negra;
 async function submit(){if(motivo.trim().length<5){setError('Explique o motivo (mínimo 5 letras).');return;}setBusy(true);setError('');
  try{setReg(await call<Registry>(token,'/cis/mutuarios/'+id+'/lista-negra',b?'DELETE':'POST',{motivo}));setOpen(false);setMotivo('');}
  catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 return <>
  {b&&<div className="cis-blacklist-banner" role="alert"><Ban size={20}/><div><strong>Mutuário na lista negra</strong>
   <span>{b.motivo} · desde {when(b.desde)}{b.por?' · por '+b.por:''}. Não pode receber crédito nem ser avalista.</span></div>
   {reg.pode_lista_negra&&<button type="button" onClick={()=>setOpen(true)}>Retirar da lista</button>}</div>}
  {!b&&reg.pode_lista_negra&&<button type="button" className="cis-blacklist-add" onClick={()=>setOpen(true)}><Ban size={15}/> Adicionar à lista negra</button>}
  {open&&<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label={b?'Retirar da lista negra':'Adicionar à lista negra'} onClick={()=>setOpen(false)}>
   <div className="cis-panel cis-users-form" onClick={e=>e.stopPropagation()} onKeyDown={e=>{if(e.key==='Escape')setOpen(false);}}>
    <div className="cis-users-form-head"><h3>{b?'Retirar da lista negra':'Adicionar à lista negra'}</h3><button type="button" aria-label="Fechar" onClick={()=>setOpen(false)}><X size={17}/></button></div>
    <p className="cis-msg-muted">{b?'O mutuário volta a poder pedir crédito e ser avalista.':'O mutuário deixa de poder receber propostas, contratos ou ser avalista, em todas as agências. Fica registado quem o fez e porquê.'}</p>
    <label>{b?'Porque sai da lista':'Motivo'}<textarea required minLength={5} maxLength={255} rows={3} value={motivo} onChange={e=>setMotivo(e.target.value)} placeholder={b?'Ex.: dívida regularizada em acordo':'Ex.: abandonou o crédito e não atende'}/></label>
    {error&&<p className="cis-pw-error" role="alert"><AlertTriangle size={15}/> {error}</p>}
    <div className="cis-pw-actions"><button type="button" className={b?'primary':'danger'} disabled={busy||motivo.trim().length<5} onClick={()=>void submit()}>{busy?'A gravar…':b?'Retirar da lista':'Adicionar à lista negra'}</button><button type="button" onClick={()=>setOpen(false)}>Cancelar</button></div>
   </div></div>}
 </>;
}

/** Tipo de mutuário e dados da empresa (pessoa colectiva). */
export function CompanyCard({token,id,reg,setReg,onOpenProposal}:{token:string;id:number;reg:Registry;setReg:(r:Registry)=>void;onOpenProposal?:(id:number)=>void}){
 const [edit,setEdit]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState(''),[ok,setOk]=useState('');
 const blank:Company={forma_juridica:null,nuit:null,actividade:null,data_constituicao:null,representante_nome:null,representante_cargo:null,representante_documento:null,representante_contacto:null};
 const [tipo,setTipo]=useState(reg.tipo);
 const [f,setF]=useState<Company>({...blank,...(reg.empresa??{})});
 useEffect(()=>{setTipo(reg.tipo);setF({...blank,...(reg.empresa??{})});},[reg]);// eslint-disable-line react-hooks/exhaustive-deps
 const firm=tipo==='colectiva';
 async function save(){setBusy(true);setError('');setOk('');
  try{const r=await call<Registry&{notice?:string}>(token,'/cis/mutuarios/'+id+'/perfil','PUT',{tipo,...Object.fromEntries(Object.entries(f).map(([k,v])=>[k,v===''?null:v]))});
   setReg(r);setEdit(false);setOk(r.notice??'Guardado.');}
  catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 const F=(k:keyof Company,l:string,x:Record<string,unknown>={})=><label className="cis-profile-field" key={k}><span>{l}</span>
  {edit?<input value={f[k]??''} onChange={e=>setF(p=>({...p,[k]:k==='nuit'?e.target.value.replace(/\D/g,''):e.target.value}))} {...x}/>:<output>{k==='data_constituicao'?when(f[k])||'Não informado':(f[k]||'Não informado')}</output>}</label>;
 return <div className="cis-panel cis-registry-card" role="group" aria-label="Tipo de mutuário e empresa">
  <header><h3>{firm?<Building2 size={18}/>:<UserRound size={18}/>} {firm?'Pessoa colectiva (empresa)':'Pessoa singular'}</h3>
   {reg.pode_editar&&!edit&&<button type="button" onClick={()=>{setEdit(true);setOk('');}}>Editar</button>}
   {edit&&<span className="cis-registry-actions"><button type="button" onClick={()=>{setEdit(false);setTipo(reg.tipo);setF({...blank,...(reg.empresa??{})});setError('');}}>Cancelar</button><button type="button" className="primary" disabled={busy} onClick={()=>void save()}><Save size={14}/> {busy?'A guardar…':'Guardar'}</button></span>}
  </header>
  {edit&&<div className="cis-newclient-type compact" role="radiogroup" aria-label="Tipo de mutuário">
   <button type="button" role="radio" aria-checked={!firm} className={!firm?'on':''} onClick={()=>setTipo('singular')}><UserRound size={16}/><span><b>Pessoa singular</b></span></button>
   <button type="button" role="radio" aria-checked={firm} className={firm?'on':''} onClick={()=>setTipo('colectiva')}><Building2 size={16}/><span><b>Pessoa colectiva</b></span></button></div>}
  {firm?<div className="cis-profile-fields">
   <label className="cis-profile-field"><span>Forma jurídica</span>{edit?<select value={f.forma_juridica??''} onChange={e=>setF(p=>({...p,forma_juridica:e.target.value}))}><option value="">Escolher…</option>{Object.entries(reg.formas_juridicas).map(([k,l])=><option key={k} value={k}>{l}</option>)}</select>:<output>{(f.forma_juridica&&reg.formas_juridicas[f.forma_juridica])||'Não informado'}</output>}</label>
   {F('nuit','NUIT',{inputMode:'numeric',maxLength:9})}
   {F('actividade','Actividade económica',{maxLength:160})}
   {F('data_constituicao','Data de constituição',{type:'date'})}
   {F('representante_nome','Representante legal',{maxLength:160})}
   {F('representante_cargo','Cargo do representante',{maxLength:80})}
   {F('representante_documento','Documento do representante',{maxLength:80})}
   {F('representante_contacto','Contacto do representante',{type:'tel',maxLength:40})}
  </div>:<p className="cis-msg-muted">Particular. Os dados pessoais (BI, NUIT, género, profissão) estão nas secções da ficha abaixo.</p>}
  {error&&<p className="cis-pw-error" role="alert"><AlertTriangle size={15}/> {error}</p>}
  {ok&&<p className="cis-registry-ok" role="status"><ShieldCheck size={15}/> {ok}</p>}
  {reg.avalista_em.length>0&&<div className="cis-registry-guarantor"><strong><Users size={15}/> Avalista em {reg.avalista_em.length} proposta(s)</strong>
   <ul>{reg.avalista_em.map(p=><li key={p.id}><span>{p.numero} · {p.mutuario} · {Number(p.montante).toLocaleString('pt-MZ')} MT · {p.estado}</span>{onOpenProposal&&<button type="button" className="cis-edit-action" onClick={()=>onOpenProposal(p.id)}>Abrir <ArrowUpRight size={13}/></button>}</li>)}</ul></div>}
  {reg.historico_lista_negra.length>0&&<details className="cis-registry-history"><summary>Histórico da lista negra ({reg.historico_lista_negra.length})</summary>
   <ul>{reg.historico_lista_negra.map((h,i)=><li key={i}><b>Entrou</b> em {when(h.created_at)}{h.por?' por '+h.por:''}: {h.motivo}{h.removed_at&&<><br/><b>Saiu</b> em {when(h.removed_at)}{h.retirado_por?' por '+h.retirado_por:''}: {h.removed_motivo}</>}</li>)}</ul></details>}
 </div>;
}
