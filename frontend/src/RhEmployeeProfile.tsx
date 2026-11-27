import {useEffect,useMemo,useState,type FormEvent} from 'react';
import {ArrowLeft,Save} from 'lucide-react';
import {Badge,date,money} from './rh/ui';
const API=import.meta.env.VITE_API_URL||'/api';
type Field=[string,string,string?,(number|string[])?];
// Seis separadores da ficha (iguais ao cadastro de RH). Os campos base (nome, código, empresa, admissão, estado)
// ficam em «Dados pessoais» e «Dados profissionais».
const tabs:[string,Field[]][]=[["Dados pessoais",[["short_name","Nome abreviado","text",60],["sex","Sexo","select",["Masculino","Feminino"]],["birth","Data de nascimento","date"],["civil","Estado civil"],["nationality","Nacionalidade"],["birthplace","Naturalidade","text",80],["dependents","Dependentes","integer"]]],["Documentos",[["document_type","Tipo de documento"],["document_number","N.º do documento","text",30],["issued","Data de emissão","date"],["expiry","Validade","date"],["issue_place","Local de emissão","text",80],["nuit","NUIT","nuit"],["inss","N.º de beneficiário INSS","text",30]]],["Contactos",[["phone","Telemóvel","phone"],["email","E-mail","email"],["locality","Localidade / residência","text",120],["district","Distrito"],["postal","Código postal","text",20]]],["Dados bancários",[["bank","Banco"],["bank_code","Código do banco","text",20],["account","NIB / conta","text",40],["company_account","Conta da empresa","text",40],["establishment","Código do estabelecimento","text",20]]],["Dados profissionais",[["department","Departamento"],["profession","Profissão"],["contract_type","Tipo de contrato"],["professional_status","Situação profissional"],["retirement","Estado de reforma"],["departure","Data de saída","date"],["departure_reason","Motivo de saída","text",120],["pay_regime","Regime de pagamento","select",["Mensal","Hora","Diário"]],["base_salary","Vencimento base (MT/mês)","money"],["contribute","Ainda a canalizar?","select",["Sim","Não"]]]],["Contrato e adendas",[]]];
const HINTS:Record<string,string>={nuit:'9 dígitos',phone:'Só dígitos; pode começar por +',expiry:'Validade do documento de identificação',account:'NIB ou número de conta para o pagamento do salário'};
const blank={name:'',company:'',code:'',category:'',admission:'',status:'activo'};
type Base=typeof blank;
type Profile={employee:Base&{profile_revision:number};profile:Record<string,string|number|null>;contracts:{id:number;kind:string;parent_id:number|null;reference_date:string;review_status:string;details:{rate?:string}}[]};
const filled=(v:unknown)=>v!=null&&String(v).trim()!=='';
export default function RhEmployeeProfile({token,id,onClose,onSaved}:{token:string;id:number|null;onClose:()=>void;onSaved:()=>void}){
 const [tab,setTab]=useState(0),[base,setBase]=useState(blank),[profile,setProfile]=useState<Profile['profile']>({});
 const [data,setData]=useState<Profile|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const [errorTab,setErrorTab]=useState<number|null>(null),[dirty,setDirty]=useState(false),[leaving,setLeaving]=useState(false);
 const url=API+'/cis/rh/registos/colaboradores'+(id===null?'':'/'+id+'/ficha');
 useEffect(()=>{
  if(id===null)return;
  const ctrl=new AbortController();
  fetch(url,{signal:ctrl.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const body=await r.json();if(!r.ok)throw new Error(body.message);return body as Profile;})
   .then(d=>{if(!ctrl.signal.aborted){setData(d);setBase(Object.fromEntries(Object.keys(blank).map(k=>[k,d.employee[k as keyof Base]??''])) as Base);setProfile(d.profile);}})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e.message);});
  return ()=>ctrl.abort();
 },[url,token,id]);
 // Preenchimento por separador (ajuda a ver o que falta na ficha).
 const progress=useMemo(()=>tabs.map(([,fields],i)=>{
  const keys=fields.filter(([k])=>!(k==='base_salary'&&['Hora','Diário'].includes(String(profile.pay_regime)))).map(([k])=>profile[k]);
  const extra=i===0?[base.name]:i===4?[base.code,base.company,base.category,base.admission]:[];
  const all=[...keys,...extra];return {done:all.filter(filled).length,total:all.length};
 }),[profile,base]);
 const setB=(k:keyof Base,v:string)=>{setBase(b=>({...b,[k]:v}));setDirty(true);};
 const baseInput=(k:keyof Base,label:string,type='text',disabled=false,required=false)=><label className="cis-profile-field" key={k}><span>{label}{required&&<b aria-hidden="true" className="rh-req"> *</b>}</span>
  <input type={type} disabled={disabled} required={required} value={base[k]} onChange={e=>setB(k,e.target.value)}/>{disabled&&<small className="rh-note">Identifica o colaborador; não pode ser alterado.</small>}</label>;
 const input=([key,label,type='text',extra]:Field)=>{
  if(key==='base_salary'&&['Hora','Diário'].includes(String(profile.pay_regime)))return null;
  const change=(v:string)=>{setProfile(old=>({...old,[key]:v,...(key==='pay_regime'&&v!=='Mensal'?{base_salary:null}:{})}));setDirty(true);};
  return <label className="cis-profile-field" key={key}><span>{label}</span>
   {type==='select'?<select value={String(profile[key]??'')} onChange={e=>change(e.target.value)}><option value="">Seleccione</option>{(extra as string[]).map(v=><option key={v}>{v}</option>)}</select>
   :<input type={type==='date'||type==='email'?type:type==='money'||type==='integer'?'number':'text'} value={String(profile[key]??'')}
    maxLength={typeof extra==='number'?extra:type==='nuit'?9:undefined} inputMode={type==='nuit'?'numeric':type==='phone'?'tel':undefined}
    step={type==='money'?'0.01':type==='integer'?'1':undefined} onChange={e=>change(e.target.value)}/>}
   {HINTS[key]&&<small className="rh-note">{HINTS[key]}</small>}
  </label>;
 };
 async function save(e:FormEvent){
  e.preventDefault();if(busy||(id!==null&&!data))return;
  if(!base.name.trim()){setError('Preencha o nome completo.');setTab(0);setErrorTab(0);return;}
  if(!base.company.trim()||!base.code.trim()||!base.admission){setError('Preencha empresa, código e data de admissão.');setTab(4);setErrorTab(4);return;}
  setBusy(true);setError('');setErrorTab(null);
  const common={name:base.name,category:base.category||null,admission:base.admission,status:base.status,
   profile:Object.fromEntries(Object.entries(profile).map(([k,v])=>[k,v===''?null:v]))};
  try{
   const r=await fetch(url,{method:id===null?'POST':'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify(id===null?{...base,...common}:{...common,revision:data?.employee.profile_revision})});
   const d=await r.json();if(!r.ok){
    const field=Object.keys(d.errors??{})[0]?.replace('profile.','');
    const index=tabs.findIndex(([,items])=>items.some(([k])=>k===field));
    if(index>=0){setTab(index);setErrorTab(index);}
    throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message);
   }
   setDirty(false);onSaved();
  }catch(err){setError((err as Error).message);}finally{setBusy(false);}
 }
 const back=()=>{if(dirty&&!leaving){setLeaving(true);return;}onClose();};
 const loading=id!==null&&!data&&!error;
 return <form className="rh-ficha" onSubmit={e=>void save(e)} noValidate>
  <button type="button" className="rh-back" onClick={back}><ArrowLeft size={16}/> Colaboradores</button>
  <header className="rh-ficha-head">
   <div className="rh-avatar" aria-hidden="true">{(base.name||'?').trim().charAt(0).toUpperCase()}</div>
   <div className="rh-ficha-id">
    <h4>{id===null?'Novo colaborador':base.name||'—'}</h4>
    <p>{id===null?'Preencha os dados base e guarde; os restantes separadores podem ser completados depois.'
     :[base.code,base.company,base.category].filter(Boolean).join(' · ')+(base.admission?' · admitido em '+date(base.admission):'')}</p>
   </div>
   {id!==null&&data&&<Badge state={base.status}/>}
  </header>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {leaving&&<ul className="rh-alerts" role="alert"><li className="warn"><div><strong>Há alterações por guardar.</strong><small>Se sair agora, perde-as.</small></div>
   <button type="button" className="cis-edit-action" onClick={onClose}>Sair sem guardar</button><button type="button" className="cis-edit-action" onClick={()=>setLeaving(false)}>Continuar a editar</button></li></ul>}
  {loading&&<p role="status">A carregar a ficha…</p>}
  {(id===null||data)&&<>
   <div className="cis-home-tabs rh-tabs rh-ficha-tabs" role="tablist" aria-label="Separadores da ficha">
    {tabs.map(([label],i)=>{const p=progress[i];return <button type="button" key={label} role="tab" id={'rh-ficha-tab-'+i} aria-selected={tab===i}
     aria-controls="rh-ficha-panel" className={tab===i?'selected':''} onClick={()=>setTab(i)} tabIndex={tab===i?0:-1}
     onKeyDown={e=>{if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;e.preventDefault();const j=(i+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length;setTab(j);document.getElementById('rh-ficha-tab-'+j)?.focus();}}>
     {label}{errorTab===i?<em className="rh-tab-dot bad" title="Há um erro neste separador">!</em>
      :i<5&&p.total>0&&<em className={'rh-tab-dot'+(p.done===p.total?' ok':'')} title={p.done+' de '+p.total+' campos preenchidos'}>{p.done===p.total?'✓':p.done+'/'+p.total}</em>}
    </button>;})}
   </div>
   <fieldset disabled={busy} id="rh-ficha-panel" role="tabpanel" aria-labelledby={'rh-ficha-tab-'+tab} className="rh-ficha-panel">
    {tab<5&&<div className="rh-fields">
     {tab===0&&baseInput('name','Nome completo','text',false,true)}
     {tab===4&&<>{baseInput('code','Código','text',id!==null,true)}{baseInput('company','Empresa','text',id!==null,true)}{baseInput('category','Categoria')}
      {baseInput('admission','Data de admissão','date',false,true)}
      <label className="cis-profile-field"><span>Estado</span><select value={base.status} onChange={e=>setB('status',e.target.value)}>
       <option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></label></>}
     {tabs[tab][1].map(input)}
    </div>}
    {tab===5&&(data?.contracts.length?<div className="cis-table-scroll"><table className="rh-table rh-cards"><thead><tr><th>Tipo</th><th>Referência</th><th>Data</th><th className="num">Valor unitário</th><th>Estado</th></tr></thead><tbody>
     {data.contracts.map(row=><tr key={row.id}><td><strong>{row.kind==='contratos'?'Contrato':'Adenda'}</strong></td><td data-label="Referência">#{row.id}{row.parent_id?' · contrato #'+row.parent_id:''}</td>
      <td data-label="Data">{date(row.reference_date)}</td><td data-label="Valor unitário" className="num">{row.details.rate!=null?money(row.details.rate):'—'}</td><td data-label="Estado"><Badge state={row.review_status??'pendente'}/></td></tr>)}
    </tbody></table></div>:<div className="rh-empty"><strong>{id===null?'Guarde primeiro a ficha.':'Sem contratos ou adendas.'}</strong><p>{id===null?'Depois de guardar, os contratos e as adendas deste colaborador aparecem aqui.':'Registe o contrato em Pessoas › Contratos de trabalho.'}</p></div>)}
   </fieldset>
  </>}
  <div className="rh-ficha-actions">
   <span className="rh-note">{dirty?'Alterações por guardar.':id!==null&&data?'Sem alterações.':''}</span>
   <button type="button" className="cis-edit-action" onClick={back} disabled={busy}>Voltar</button>
   <button type="submit" className="rh-primary" disabled={busy||(id!==null&&!data)||(id!==null&&!dirty)}><Save size={15}/> Guardar ficha</button>
  </div>
 </form>;
}
