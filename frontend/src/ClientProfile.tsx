import {useCallback,useEffect,useState,type FormEvent} from 'react';
import {ArrowLeft,UserRound,MapPin,BriefcaseBusiness,Phone,ShieldCheck,FileText,Save,AlertCircle,CheckCircle2,LockKeyhole} from 'lucide-react';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Profile=Record<string,string|number|null>;
type Tab='Identificação'|'Contactos'|'Morada'|'Actividade'|'Referências'|'Administração';
const sections:{name:Tab;icon:typeof UserRound;fields:{key:string;label:string}[]}[]=[
 {name:'Identificação',icon:UserRound,fields:[
  {key:'Numeração',label:'Número do mutuário'}, {key:'Nome Completo',label:'Nome completo'},
  {key:'tipo_cliente',label:'Tipo de mutuário'}, {key:'genero',label:'Género'},
  {key:'tipo_documento',label:'Tipo de documento'}, {key:'Nº de BI',label:'N.º do BI (cadastro original)'},
  {key:'Data de Emissão',label:'Data de emissão do BI'},
  {key:'local_emissao_documento',label:'Local de emissão do documento'},
  {key:'validade_documento',label:'Validade do documento'}, {key:'nuit',label:'NUIT'},
  {key:'Nacionalidade',label:'Nacionalidade'}, {key:'Naturalidade',label:'Naturalidade'},
  {key:'Estado Civil',label:'Estado civil'}]},
 {name:'Contactos',icon:Phone,fields:[
  {key:'Contacto',label:'Contacto principal'}, {key:'Email',label:'Email'},
  {key:'contacto_alternativo',label:'Contacto alternativo'},
  {key:'melhor_horario_contacto',label:'Melhor horário para contacto'}]},
 {name:'Morada',icon:MapPin,fields:[
  {key:'Provincia',label:'Província'}, {key:'Cidade',label:'Cidade'},
  {key:'Bairro',label:'Bairro'}, {key:'Rua',label:'Rua'},
  {key:'Nº da Casa',label:'Número da casa'}, {key:'Nº de Quarterão',label:'Quarteirão'},
  {key:'ponto_referencia',label:'Ponto de referência'}]},
 {name:'Actividade',icon:BriefcaseBusiness,fields:[
  {key:'profissao',label:'Profissão'},
  {key:'local_trabalho_actividade',label:'Local de trabalho / actividade'},
  {key:'fonte_rendimento_principal',label:'Principal fonte de rendimento'}]},
 {name:'Referências',icon:ShieldCheck,fields:[
  {key:'nome_pai',label:'Nome do pai'}, {key:'nome_mae',label:'Nome da mãe'},
  {key:'pessoa_referencia_nome',label:'Pessoa de referência / emergência'},
  {key:'pessoa_referencia_contacto',label:'Contacto de emergência'},
  {key:'grau_parentesco_referencia',label:'Grau de parentesco'}]},
 {name:'Administração',icon:FileText,fields:[
  {key:'ID',label:'ID interno'}, {key:'Conduta',label:'Conduta'},
  {key:'usuario',label:'Utilizador associado'}]}
];
// Ficha original: tudo editável excepto o n.º do mutuário (Numeração), o ID interno e o utilizador associado.
// O servidor indica em «editaveis» as colunas com permissão de gravação activa.
const editableOriginal=new Set(['Nome Completo','Bairro','Cidade','Provincia','Rua','Contacto','Email','Nº de BI','Data de Emissão',
 'Nacionalidade','Naturalidade','Estado Civil','Nº da Casa','Nº de Quarterão','Conduta']);
const lockedFields=new Set(['Numeração','ID','usuario']);
const secondaryFields=new Set([
 'ponto_referencia','local_trabalho_actividade','fonte_rendimento_principal',
 'contacto_alternativo','pessoa_referencia_nome','pessoa_referencia_contacto','melhor_horario_contacto',
 'tipo_cliente','genero','profissao','tipo_documento','nuit','local_emissao_documento','validade_documento',
 'nome_pai','nome_mae','grau_parentesco_referencia']);
const originalFields=[...editableOriginal];
function normalized(value:string|number|null|undefined){return value===null||value===undefined?'':String(value);}
const dateFields=new Set(['Data de Emissão','validade_documento']);
const timeFields=new Set(['melhor_horario_contacto']);
// Limites iguais aos do servidor (evita recusas ao guardar).
const maxLen:Record<string,number>={ponto_referencia:255,contacto_alternativo:40,pessoa_referencia_contacto:40,tipo_cliente:60,genero:50,
 tipo_documento:80,nuit:30,profissao:150,local_emissao_documento:150,grau_parentesco_referencia:100};
async function serverError(res:Response,fallback:string){
 const j=await res.json().catch(()=>({})) as {message?:string;errors?:Record<string,string[]>};
 const first=j.errors?Object.values(j.errors).flat()[0]:undefined;
 return first||j.message||fallback;
}
function formValue(key:string,value:string|number|null|undefined):string{
 const text=normalized(value);
 if(timeFields.has(key)&&/^\d{2}:\d{2}(:\d{2})?$/.test(text))return text.slice(0,5);
 if(dateFields.has(key)&&/^\d{4}-\d{2}-\d{2}/.test(text))return text.slice(0,10);
 return text;
}

export default function ClientProfile({id,token,onBack}:{id:number;token:string;onBack:()=>void}){
 const [tab,setTab]=useState<Tab>('Identificação');
 const [profile,setProfile]=useState<Profile|null>(null);
 const [draft,setDraft]=useState<Record<string,string>>({});
 const [loading,setLoading]=useState(true);
 const [saving,setSaving]=useState(false);
 const [editing,setEditing]=useState(false);
 const [writable,setWritable]=useState<Set<string>>(new Set());
 const [error,setError]=useState('');
 const [success,setSuccess]=useState('');
 const [secondaryReady,setSecondaryReady]=useState(false);
 const [originalEditReady,setOriginalEditReady]=useState(false);
 const [informed,setInformed]=useState(false);

 const load=useCallback(async(signal?:AbortSignal)=>{
  setLoading(true);setError('');setSuccess('');
  try{
   const res=await fetch(API+'/cis/clientes/'+id,{
    headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal
   });
   if(!res.ok)throw new Error(res.status===409?'O identificador do mutuário não é exclusivo.':'Não foi possível abrir a ficha do mutuário.');
   const base=await res.json() as Profile;
   // A leitura adicional é opcional e nunca interfere com os 18 campos antigos.
   let extras:Profile={};
   let active=false;
   try{
    const ext=await fetch(API+'/cis/clientes/'+id+'/complementares',{
     headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal
    });
    if(ext.ok){extras=await ext.json() as Profile;active=true;}
   }catch(e){if((e as Error).name==='AbortError')throw e;}
   if(signal?.aborted)return;
   const current={...base,...extras};
   setSecondaryReady(active);
   setProfile(current);
   setDraft(Object.fromEntries(Object.entries(current).map(([k,v])=>[k,formValue(k,v)])));
   // A conta de edição directa tem aprovação própria; qualquer falha não bloqueia a consulta.
   try{
    const edit=await fetch(API+'/cis/clientes/'+id+'/editar',{
     headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal
    });
    setOriginalEditReady(edit.ok);
    if(edit.ok){const j=await edit.json().catch(()=>({})) as {editaveis?:string[]};
     setWritable(new Set(j.editaveis??['Nome Completo','Bairro','Cidade','Provincia','Rua']));}
   }catch{setOriginalEditReady(false);}
  }catch(e){
   if((e as Error).name!=='AbortError')setError(e instanceof Error?e.message:'Erro ao abrir ficha.');
  }finally{if(!signal?.aborted)setLoading(false);}
 },[id,token]);
 useEffect(()=>{const ctrl=new AbortController();void load(ctrl.signal);return()=>ctrl.abort();},[load]);

 const canEdit=(key:string)=>lockedFields.has(key)?false:editableOriginal.has(key)?originalEditReady&&writable.has(key):secondaryFields.has(key)?secondaryReady:false;
 const hasChanges=Boolean(profile&&Object.entries(draft).some(([key,value])=>canEdit(key)&&value!==formValue(key,profile[key])));
 async function save(e:FormEvent){
  e.preventDefault();if(!profile||!hasChanges)return;
  const originalChanges:Record<string,string>={},before:Record<string,string|null>={};
  for(const key of originalFields){
   if(draft[key]!==formValue(key,profile[key])){
    originalChanges[key]=draft[key];before[key]=profile[key]==null?null:String(profile[key]);
   }
  }
  const secondaryChanged=[...secondaryFields].some(k=>draft[k]!==formValue(k,profile[k]));
  const thirdParty=Boolean(draft.pessoa_referencia_nome?.trim()||draft.pessoa_referencia_contacto?.trim());
  if(secondaryChanged&&thirdParty&&!informed){
   setError('Confirma que o mutuário foi informado sobre a finalidade dos dados da pessoa de referência.');
   return;
  }
  if(!window.confirm('Confirma a gravação directa dos campos alterados na ficha original deste mutuário?'))return;
  setSaving(true);setError('');setSuccess('');
  try{
   if(Object.keys(originalChanges).length){
    const res=await fetch(API+'/cis/clientes/'+id,{
     method:'PATCH',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
     body:JSON.stringify({changes:originalChanges,original:before})});
    if(!res.ok)throw new Error(res.status===409?'O registo foi alterado por outra pessoa; recarrega a ficha.':'Dados principais não guardados: '+await serverError(res,'verifique os campos e repita.'));
   }
   if(secondaryChanged){
    const values=Object.fromEntries([...secondaryFields].map(k=>[k,draft[k]??'']));
    const res=await fetch(API+'/cis/clientes/'+id+'/complementares',{
     method:'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
     body:JSON.stringify({...values,referencia_contacto_informado:informed})});
    if(!res.ok)throw new Error('Informações complementares não guardadas: '+await serverError(res,'verifique os campos e repita.'));
   }
   await load();setEditing(false);setSuccess('Alterações guardadas. A ficha foi actualizada.');
  }catch(e){setError(e instanceof Error?e.message:'Erro ao guardar.');}
  finally{setSaving(false);}
 }
 const active=sections.find(s=>s.name===tab)!;
 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos mutuários</button><span>Cadastro / Ficha individual</span></div>
  {loading&&!profile&&<div className="cis-panel">A carregar a ficha do mutuário…</div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{error}</div>}
  {success&&<div className="cis-secondary-success" role="status"><CheckCircle2 size={18}/>{success}</div>}
  {profile&&<form onSubmit={e=>void save(e)}>
   <div className="cis-profile-hero">
    <div className="cis-profile-avatar"><UserRound size={34}/></div>
    <div className="cis-profile-heading"><span>FICHA DO MUTUÁRIO</span><h2>{normalized(profile['Nome Completo'])||'Mutuário sem nome'}</h2><p>Mutuário n.º {normalized(profile['Numeração'])||'—'} <span aria-hidden="true">·</span> ID #{id}</p></div>
    <div className="cis-profile-hero-actions">
      {!editing?<button type="button" onClick={()=>{setError('');setEditing(true);}}>Editar ficha</button>:<>
       <button type="button" className="secondary" disabled={saving} onClick={()=>{setEditing(false);setDraft(Object.fromEntries(Object.entries(profile).map(([k,v])=>[k,formValue(k,v)])));setError('');}}>Cancelar</button>
       <button type="submit" disabled={!hasChanges||saving}><Save size={16}/>{saving?'A guardar…':'Guardar alterações'}</button>
      </>}
    </div>
   </div>
   <div className="cis-profile-layout">
    <nav className="cis-profile-tabs" aria-label="Secções da ficha">
     {sections.map(({name,icon:Icon})=><button key={name} type="button" aria-current={tab===name?'page':undefined} className={tab===name?'selected':''} onClick={()=>setTab(name)}><Icon size={18}/>{name}</button>)}
    </nav>
    <div className="cis-profile-content">
     <header className="cis-profile-section-title"><div><h3>{active.name}</h3><p>Informação do cadastro do mutuário, sem duplicação de campos.</p></div>{editing&&<span>Campos autorizados editáveis</span>}</header>
     <div className="cis-profile-fields">{active.fields.map(({key,label})=>{
       const isExtra=secondaryFields.has(key);
       const enabled=editing&&canEdit(key);
       const display=isExtra&&!secondaryReady?'Ainda não activado':formValue(key,profile[key])||'Não informado';
       return <label className="cis-profile-field" key={key}><span>{label}</span>
        {enabled?<input type={dateFields.has(key)?'date':timeFields.has(key)?'time':key==='Email'?'email':key==='Contacto'?'tel':'text'} inputMode={key==='Contacto'?'tel':undefined} step={timeFields.has(key)?60:undefined} value={draft[key]??''} maxLength={maxLen[key]??(editableOriginal.has(key)?155:180)} required={key==='Nome Completo'} onChange={e=>setDraft(prev=>({...prev,[key]:e.target.value}))}/>:<div className={'cis-profile-field-value '+(isExtra&&!secondaryReady?'muted':'')}>{display}</div>}
        {editing&&!canEdit(key)&&<small><LockKeyhole size={12}/> {lockedFields.has(key)?'Não editável':editableOriginal.has(key)&&originalEditReady?'Necessita activação (enable-client-full-edit.sh)':'Campo protegido'}{isExtra?' · necessita activação':''}</small>}
       </label>;
     })}</div>
     {tab==='Identificação'&&<p className="cis-profile-safety"><ShieldCheck size={16}/> O número do documento existente corresponde ao BI. Outros documentos não reutilizam esse campo sem revisão do cadastro legado.</p>}
     {tab==='Referências'&&editing&&secondaryReady&&<label className="cis-secondary-confirm"><input type="checkbox" checked={informed} onChange={e=>setInformed(e.target.checked)}/> Confirmo que o mutuário foi informado sobre a finalidade da recolha do contacto da pessoa de referência.</label>}
     {editing&&<p className="cis-profile-safety"><ShieldCheck size={16}/> Apenas os campos autorizados podem ser gravados. Outros dados permanecem protegidos até validação das permissões.</p>}
    </div>
   </div>
  </form>}
 </section>;
}
