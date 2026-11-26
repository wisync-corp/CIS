// Mutuário ao nível do GEMIC: fotografia, pessoa singular/colectiva (empresa) e lista negra.
import {useCallback,useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Camera,Building2,UserRound,Ban,ShieldCheck,AlertTriangle,X,Save,Loader2,Users,ArrowUpRight} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
type Company={forma_juridica:string|null;nuit:string|null;actividade:string|null;data_constituicao:string|null;representante_nome:string|null;representante_cargo:string|null;representante_documento:string|null;representante_contacto:string|null};
export type Registry={instalado:boolean;tipo:'singular'|'colectiva';empresa:Company|null;tem_foto:boolean;
 lista_negra:{motivo:string;desde:string|null;por:string|null}|null;
 historico_lista_negra:{motivo:string;created_at:string|null;por:string|null;removed_at:string|null;retirado_por:string|null;removed_motivo:string|null}[];
 avalista_em:{id:number;numero:string;mutuario:string;estado:string;montante:string}[];
 formas_juridicas:Record<string,string>;pode_editar:boolean;pode_lista_negra:boolean;conduta?:string|null};

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

/** Janela para pôr ou tirar da lista negra (sempre com motivo). */
function BlacklistDialog({token,id,adding,onClose,onDone}:{token:string;id:number;adding:boolean;onClose:()=>void;onDone:(r:Registry)=>void}){
 const [motivo,setMotivo]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 async function submit(){if(motivo.trim().length<5){setError('Explique o motivo (mínimo 5 letras).');return;}setBusy(true);setError('');
  try{onDone(await call<Registry>(token,'/cis/mutuarios/'+id+'/lista-negra',adding?'POST':'DELETE',{motivo}));}
  catch(err){setError((err as Error).message);}finally{setBusy(false);}}
 // Portal no <body>: a janela fica centrada mesmo dentro de painéis com layout próprio.
 return createPortal(<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label={adding?'Pôr na lista negra':'Voltar à situação padrão'} onClick={onClose}>
  <div className="cis-panel cis-users-form cis-narrow" onClick={e=>e.stopPropagation()} onKeyDown={e=>{if(e.key==='Escape')onClose();}}>
   <div className="cis-users-form-head"><h3>{adding?'Conduta: Lista Negra':'Conduta: Padrão'}</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <p className="cis-msg-muted">{adding?'O mutuário deixa de poder receber propostas, contratos ou ser avalista, em todas as agências. Fica registado quem o fez e porquê.':'O mutuário sai da lista negra e volta a poder pedir crédito e ser avalista.'}</p>
   <label>{adding?'Motivo':'Porque sai da lista'}<textarea autoFocus required minLength={5} maxLength={255} rows={3} value={motivo} onChange={e=>setMotivo(e.target.value)} placeholder={adding?'Ex.: abandonou o crédito e não atende':'Ex.: dívida regularizada em acordo'}/></label>
   {error&&<p className="cis-pw-error" role="alert"><AlertTriangle size={15}/> {error}</p>}
   <div className="cis-pw-actions"><button type="button" className={adding?'danger':'primary'} disabled={busy||motivo.trim().length<5} onClick={()=>void submit()}>{busy?'A gravar…':adding?'Pôr na lista negra':'Voltar a Padrão'}</button><button type="button" onClick={onClose}>Cancelar</button></div>
  </div></div>,document.body);
}

/** Faixa no topo da ficha quando o mutuário está na lista negra. */
export function BlacklistBar({token,id,reg,setReg}:{token:string;id:number;reg:Registry;setReg:(r:Registry)=>void}){
 const [open,setOpen]=useState(false);
 const b=reg.lista_negra;
 if(!b)return null;
 return <>
  <div className="cis-blacklist-banner" role="alert"><Ban size={20}/><div><strong>Mutuário na lista negra</strong>
   <span>{b.motivo} · desde {when(b.desde)}{b.por?' · por '+b.por:''}. Não pode receber crédito nem ser avalista.</span></div>
   {reg.pode_lista_negra&&<button type="button" onClick={()=>setOpen(true)}>Voltar a Padrão</button>}</div>
  {open&&<BlacklistDialog token={token} id={id} adding={false} onClose={()=>setOpen(false)} onDone={r=>{setReg(r);setOpen(false);}}/>}
 </>;
}

/** Administração › Conduta: Padrão ou Lista Negra (combobox ligado à lista negra e ao cadastro). */
export function ClientSituation({token,id,reg,setReg}:{token:string;id:number;reg:Registry;setReg:(r:Registry)=>void}){
 const [target,setTarget]=useState<'padrao'|'lista'|null>(null);
 const current=reg.lista_negra?'lista':'padrao';
 const other=reg.conduta&&!['padrão','padrao','lista negra'].includes(reg.conduta.trim().toLowerCase())?reg.conduta:null;
 return <><label className={'cis-profile-field cis-situation-field'+(reg.lista_negra?' black':'')}><span>Conduta</span>
  <select value={target??current} disabled={!reg.pode_lista_negra} onChange={e=>{const v=e.target.value as 'padrao'|'lista';if(v!==current)setTarget(v);}}
   title={reg.pode_lista_negra?undefined:'Só gestores e administradores mudam a conduta.'}>
   <option value="padrao">Padrão</option><option value="lista">Lista Negra</option></select>
  <small>{reg.lista_negra?<>Lista negra{reg.lista_negra.desde?' desde '+when(reg.lista_negra.desde):''}: {reg.lista_negra.motivo}</>:'Pode pedir crédito e ser avalista.'}{other&&<> · No cadastro antigo: «{other}»</>}{!reg.pode_lista_negra&&' · Só gestores e administradores mudam a conduta.'}</small>
 </label>
 {target&&<BlacklistDialog token={token} id={id} adding={target==='lista'} onClose={()=>setTarget(null)} onDone={r=>{setReg(r);setTarget(null);}}/>}</>;
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
