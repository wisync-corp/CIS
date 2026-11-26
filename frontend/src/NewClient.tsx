import {useEffect,useState,type FormEvent} from 'react';
import {ArrowLeft,UserPlus,UserRound,Phone,MapPin,FileText,Save,AlertCircle,ShieldCheck,ArrowUpRight,Building2,BriefcaseBusiness} from 'lucide-react';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Meta={pronto:boolean;campos:string[];obrigatorios:string[];proximo_numero:string|number|null;limites:Record<string,number>;colectiva?:boolean;formas_juridicas?:Record<string,string>};
// Numa empresa estes campos do cadastro de pessoa não se aplicam.
const personOnly=new Set(['Nº de BI','Data de Emissão','Nacionalidade','Naturalidade','Estado Civil']);
type Field={key:string;label:string;type?:string;list?:string;placeholder?:string};
const groups:{name:string;icon:typeof UserRound;fields:Field[]}[]=[
 {name:'Identificação',icon:UserRound,fields:[
  {key:'Nome Completo',label:'Nome completo',placeholder:'Nome e apelidos'},
  {key:'Nº de BI',label:'N.º do BI'},
  {key:'Data de Emissão',label:'Data de emissão do BI',type:'date'},
  {key:'Nacionalidade',label:'Nacionalidade'},
  {key:'Naturalidade',label:'Naturalidade'},
  {key:'Estado Civil',label:'Estado civil',list:'cis-estado-civil'}]},
 {name:'Contactos',icon:Phone,fields:[
  {key:'Contacto',label:'Contacto principal',type:'tel',placeholder:'84 123 4567'},
  {key:'Email',label:'Email',type:'email'}]},
 {name:'Morada',icon:MapPin,fields:[
  {key:'Provincia',label:'Província',list:'cis-provincias'},
  {key:'Cidade',label:'Cidade'},
  {key:'Bairro',label:'Bairro'},
  {key:'Rua',label:'Rua'},
  {key:'Nº da Casa',label:'Número da casa'},
  {key:'Nº de Quarterão',label:'Quarteirão'}]},
];
const provincias=['Maputo Cidade','Maputo Província','Gaza','Inhambane','Sofala','Manica','Tete','Zambézia','Nampula','Cabo Delgado','Niassa'];
const estados=['Solteiro(a)','Casado(a)','União de facto','Divorciado(a)','Viúvo(a)'];

export default function NewClient({token,onBack,onCreated,onOpen}:{token:string;onBack:()=>void;onCreated:(id:number)=>void;onOpen:(id:number)=>void}){
 const [meta,setMeta]=useState<Meta|null>(null);
 const [values,setValues]=useState<Record<string,string>>({Nacionalidade:'Moçambicana'});
 const [error,setError]=useState('');
 const [loadError,setLoadError]=useState('');
 const [saving,setSaving]=useState(false);
 const [sameName,setSameName]=useState<{id:number;cidade:string|null}[]|null>(null);
 const [existing,setExisting]=useState<number|null>(null);
 const [confirmDup,setConfirmDup]=useState(false);
 const [tipo,setTipo]=useState<'singular'|'colectiva'>('singular');
 const [emp,setEmp]=useState<Record<string,string>>({});
 const firm=tipo==='colectiva';

 useEffect(()=>{const ctrl=new AbortController();
  fetch(API+'/cis/clientes/novo',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(async r=>{const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.message||'Não foi possível preparar a nova ficha.');setMeta(j as Meta);})
   .catch(e=>{if(e.name!=='AbortError')setLoadError(e.message);});
  return()=>ctrl.abort();},[token]);

 const required=new Set(meta?.obrigatorios??['Nome Completo']);
 const available=(k:string)=>(!meta||meta.campos.includes(k))&&!(firm&&personOnly.has(k));
 async function submit(e:FormEvent){
  e.preventDefault();setError('');setExisting(null);
  if((values['Nome Completo']??'').trim().length<3){setError(firm?'Indique a denominação da empresa.':'Indique o nome completo (mínimo 3 letras).');return;}
  if(firm&&!/^\d{9}$/.test((emp.nuit??'').trim())){setError('O NUIT da empresa tem 9 algarismos.');return;}
  if(firm&&!(emp.forma_juridica??'')){setError('Escolha a forma jurídica.');return;}
  if(firm&&(emp.representante_nome??'').trim().length<3){setError('Indique o representante legal.');return;}
  if(sameName&&!confirmDup){setError('Confirme que se trata de outra pessoa com o mesmo nome.');return;}
  setSaving(true);
  try{
   const dados=Object.fromEntries(Object.entries(values).filter(([k,v])=>available(k)&&v.trim()!==''));
   const r=await fetch(API+'/cis/clientes',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({dados,confirmar_duplicado:confirmDup,tipo,...(firm?{empresa:Object.fromEntries(Object.entries(emp).map(([k,v])=>[k,v.trim()||null]))}:{})})});
   const j=await r.json().catch(()=>({})) as {id?:number;message?:string;errors?:Record<string,string[]>;existente?:number;duplicado_nome?:{id:number;cidade:string|null}[]};
   if(r.status===409&&j.duplicado_nome){setSameName(j.duplicado_nome);setError(j.message||'Já existe mutuário com o mesmo nome.');return;}
   if(r.status===409&&j.existente){setExisting(j.existente);setError(j.message||'Já existe um mutuário com este BI.');return;}
   if(!r.ok||!j.id)throw new Error((j.errors?Object.values(j.errors).flat()[0]:undefined)||j.message||'Não foi possível registar o mutuário.');
   onCreated(j.id);
  }catch(err){setError(err instanceof Error?err.message:'Erro ao registar.');}
  finally{setSaving(false);}
 }

 return <section className="cis-profile-page">
  <div className="cis-profile-back"><button type="button" onClick={onBack}><ArrowLeft size={18}/> Voltar aos mutuários</button><span>Cadastro / Nova ficha</span></div>
  {loadError&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{loadError}</div>}
  <form onSubmit={e=>void submit(e)}>
   <div className="cis-profile-hero">
    <div className="cis-profile-avatar">{firm?<Building2 size={32}/>:<UserPlus size={32}/>}</div>
    <div className="cis-profile-heading"><span>NOVA FICHA DE MUTUÁRIO · {firm?'PESSOA COLECTIVA':'PESSOA SINGULAR'}</span><h2>{values['Nome Completo']?.trim()||(firm?'Nova empresa':'Novo mutuário')}</h2>
     <p>Mutuário n.º {meta?.proximo_numero!=null?<b>{String(meta.proximo_numero)}</b>:'—'} <span aria-hidden="true">·</span> atribuído automaticamente ao gravar</p></div>
    <div className="cis-profile-hero-actions">
     <button type="button" className="secondary" disabled={saving} onClick={onBack}>Cancelar</button>
     <button type="submit" disabled={saving||!meta||!meta.pronto}><Save size={16}/>{saving?'A registar…':'Registar mutuário'}</button>
    </div>
   </div>
   {meta&&!meta.pronto&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>O registo de novas fichas ainda não está activado. Na VPS execute: bash deploy/enable-client-create.sh</div>}
   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/><span>{error}
    {existing!=null&&<> <button type="button" className="cis-edit-action" onClick={()=>onOpen(existing)}>Abrir ficha existente <ArrowUpRight size={14}/></button></>}</span></div>}
   {sameName&&<div className="cis-newclient-dup">
    <p>Mutuários com o mesmo nome:</p>
    <ul>{sameName.map(s=><li key={s.id}>ID {s.id}{s.cidade?' · '+s.cidade:''} <button type="button" className="cis-edit-action" onClick={()=>onOpen(s.id)}>Abrir <ArrowUpRight size={13}/></button></li>)}</ul>
    <label><input type="checkbox" checked={confirmDup} onChange={e=>setConfirmDup(e.target.checked)}/> É outra pessoa — registar mesmo assim</label>
   </div>}
   {meta?.colectiva&&<div className="cis-newclient-type" role="radiogroup" aria-label="Tipo de mutuário">
    <button type="button" role="radio" aria-checked={!firm} className={!firm?'on':''} onClick={()=>setTipo('singular')}><UserRound size={18}/><span><b>Pessoa singular</b><small>Particular, com BI</small></span></button>
    <button type="button" role="radio" aria-checked={firm} className={firm?'on':''} onClick={()=>setTipo('colectiva')}><Building2 size={18}/><span><b>Pessoa colectiva</b><small>Empresa, associação, cooperativa, ONG</small></span></button>
   </div>}
   <div className="cis-newclient-grid">
    {firm&&<fieldset className="cis-newclient-group"><legend><Building2 size={17}/>Empresa</legend>
     <div className="cis-profile-fields">
      <label className="cis-profile-field"><span>Forma jurídica<em aria-label="obrigatório"> *</em></span><select required value={emp.forma_juridica??''} onChange={e=>setEmp(p=>({...p,forma_juridica:e.target.value}))}><option value="">Escolher…</option>{Object.entries(meta?.formas_juridicas??{}).map(([k,l])=><option key={k} value={k}>{l}</option>)}</select></label>
      <label className="cis-profile-field"><span>NUIT<em aria-label="obrigatório"> *</em></span><input required inputMode="numeric" pattern="\d{9}" maxLength={9} placeholder="9 algarismos" value={emp.nuit??''} onChange={e=>setEmp(p=>({...p,nuit:e.target.value.replace(/\D/g,'')}))}/></label>
      <label className="cis-profile-field"><span>Actividade económica</span><input maxLength={160} placeholder="Ex.: comércio de produtos agrícolas" value={emp.actividade??''} onChange={e=>setEmp(p=>({...p,actividade:e.target.value}))}/></label>
      <label className="cis-profile-field"><span>Data de constituição</span><input type="date" max={new Date().toISOString().slice(0,10)} value={emp.data_constituicao??''} onChange={e=>setEmp(p=>({...p,data_constituicao:e.target.value}))}/></label>
     </div></fieldset>}
    {firm&&<fieldset className="cis-newclient-group"><legend><BriefcaseBusiness size={17}/>Representante legal</legend>
     <div className="cis-profile-fields">
      <label className="cis-profile-field"><span>Nome<em aria-label="obrigatório"> *</em></span><input required maxLength={160} value={emp.representante_nome??''} onChange={e=>setEmp(p=>({...p,representante_nome:e.target.value}))}/></label>
      <label className="cis-profile-field"><span>Cargo</span><input maxLength={80} placeholder="Ex.: sócio-gerente" value={emp.representante_cargo??''} onChange={e=>setEmp(p=>({...p,representante_cargo:e.target.value}))}/></label>
      <label className="cis-profile-field"><span>Documento</span><input maxLength={80} placeholder="Ex.: BI 110100000000A" value={emp.representante_documento??''} onChange={e=>setEmp(p=>({...p,representante_documento:e.target.value}))}/></label>
      <label className="cis-profile-field"><span>Contacto</span><input type="tel" inputMode="tel" maxLength={40} value={emp.representante_contacto??''} onChange={e=>setEmp(p=>({...p,representante_contacto:e.target.value}))}/></label>
     </div></fieldset>}
    {groups.map(({name,icon:Icon,fields})=>{if(firm&&name==='Outros')return null;const shown=fields.filter(f=>available(f.key));if(!shown.length)return null;
     return <fieldset key={name} className="cis-newclient-group"><legend><Icon size={17}/>{name}</legend>
      <div className="cis-profile-fields">{shown.map(f=><label className="cis-profile-field" key={f.key}><span>{firm&&f.key==='Nome Completo'?'Denominação social':f.label}{required.has(f.key)&&<em aria-label="obrigatório"> *</em>}</span>
       <input type={f.type??'text'} list={f.list} placeholder={firm&&f.key==='Nome Completo'?'Ex.: Agro Boane, Lda.':f.placeholder} inputMode={f.type==='tel'?'tel':undefined} required={required.has(f.key)}
        maxLength={meta?.limites[f.key]??155} max={f.type==='date'?new Date().toISOString().slice(0,10):undefined}
        value={values[f.key]??''} onChange={e=>{const v=e.target.value;setValues(p=>({...p,[f.key]:v}));if(f.key==='Nome Completo'){setSameName(null);setConfirmDup(false);}}}/></label>)}</div>
     </fieldset>;})}
   </div>
   <datalist id="cis-provincias">{provincias.map(p=><option key={p} value={p}/>)}</datalist>
   <datalist id="cis-estado-civil">{estados.map(p=><option key={p} value={p}/>)}</datalist>
   <p className="cis-profile-safety"><ShieldCheck size={16}/> Depois de registar, a ficha abre automaticamente para completar as informações complementares (documento, NUIT, profissão, referências).</p>
  </form>
 </section>;
}
