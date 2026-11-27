import {useEffect,useState,type FormEvent} from 'react';
const API=import.meta.env.VITE_API_URL||'/api';
type Field=[string,string,string?,(number|string[])?];
const tabs:[string,Field[]][]=[["Dados pessoais",[["short_name","Nome abreviado","text",60],["sex","Sexo","select",["Masculino","Feminino"]],["birth","Data de nascimento","date"],["civil","Estado civil"],["nationality","Nacionalidade"],["birthplace","Naturalidade","text",80],["dependents","Dependentes","integer"]]],["Documentos",[["document_type","Tipo de documento"],["document_number","N.º do documento","text",30],["issued","Data de emissão","date"],["expiry","Validade","date"],["issue_place","Local de emissão","text",80],["nuit","NUIT","nuit"],["inss","N.º de beneficiário INSS","text",30]]],["Contactos",[["phone","Telemóvel","phone"],["email","E-mail","email"],["locality","Localidade / residência","text",120],["district","Distrito"],["postal","Código postal","text",20]]],["Dados bancários",[["bank","Banco"],["bank_code","Código do banco","text",20],["account","NIB / conta","text",40],["company_account","Conta da empresa","text",40],["establishment","Código do estabelecimento","text",20]]],["Dados profissionais",[["department","Departamento"],["profession","Profissão"],["contract_type","Tipo de contrato"],["professional_status","Situação profissional"],["retirement","Estado de reforma"],["departure","Data de saída","date"],["departure_reason","Motivo de saída","text",120],["pay_regime","Regime de pagamento","select",["Mensal","Hora","Diário"]],["base_salary","Vencimento base (MT/mês)","money"],["contribute","Ainda a canalizar?","select",["Sim","Não"]]]],["Contrato e adendas",[]]];
const blank={name:'',company:'',code:'',category:'',admission:'',status:'activo'};
type Base=typeof blank;
type Profile={employee:Base&{profile_revision:number};profile:Record<string,string|number|null>;contracts:{id:number;kind:string;parent_id:number|null;reference_date:string;review_status:string;details:{rate?:string}}[]};
export default function RhEmployeeProfile({token,id,onClose,onSaved}:{token:string;id:number|null;onClose:()=>void;onSaved:()=>void}){
 const [tab,setTab]=useState(0),[base,setBase]=useState(blank),[profile,setProfile]=useState<Profile['profile']>({});
 const [data,setData]=useState<Profile|null>(null),[busy,setBusy]=useState(false),[error,setError]=useState('');
 const url=API+'/cis/rh/registos/colaboradores'+(id===null?'':'/'+id+'/ficha');
 useEffect(()=>{
  if(id===null)return;
  const ctrl=new AbortController();
  fetch(url,{signal:ctrl.signal,headers:{Authorization:'Bearer '+token,Accept:'application/json'}})
   .then(async r=>{const body=await r.json();if(!r.ok)throw new Error(body.message);return body as Profile;})
   .then(d=>{if(!ctrl.signal.aborted){setData(d);setBase(Object.fromEntries(Object.keys(blank).map(k=>[k,d.employee[k as keyof Base]])) as Base);setProfile(d.profile);}})
   .catch(e=>{if(!ctrl.signal.aborted)setError(e.message);});
  return ()=>ctrl.abort();
 },[url,token,id]);
 const baseInput=(k:keyof Base,label:string,type='text',disabled=false)=><label className="cis-profile-field"><span>{label}</span><input type={type} disabled={disabled}
  value={base[k]} onChange={e=>setBase({...base,[k]:e.target.value})}/></label>;
 const input=([key,label,type='text',extra]:Field)=>{
  if(key==='base_salary'&&['Hora','Diário'].includes(String(profile.pay_regime)))return null;
  const change=(v:string)=>setProfile(old=>({...old,[key]:v,...(key==='pay_regime'&&v!=='Mensal'?{base_salary:null}:{})}));
  return <label className="cis-profile-field" key={key}><span>{label}</span>
   {type==='select'?<select value={String(profile[key]??'')} onChange={e=>change(e.target.value)}><option value="">Seleccione</option>{(extra as string[]).map(v=><option key={v}>{v}</option>)}</select>
   :<input type={type==='date'||type==='email'?type:type==='money'||type==='integer'?'number':'text'} value={String(profile[key]??'')}
    maxLength={typeof extra==='number'?extra:type==='nuit'?9:undefined} inputMode={type==='nuit'?'numeric':type==='phone'?'tel':undefined}
    step={type==='money'?'0.01':type==='integer'?'1':undefined} onChange={e=>change(e.target.value)}/>}
  </label>;
 };
 async function save(e:FormEvent){
  e.preventDefault();if(busy||(id!==null&&!data))return;
  if(!base.name.trim()){setError('Preencha o nome completo.');setTab(0);return;}
  if(!base.company.trim()||!base.code.trim()||!base.admission){setError('Preencha empresa, código e data de admissão.');setTab(4);return;}
  setBusy(true);setError('');
  const common={name:base.name,category:base.category||null,admission:base.admission,status:base.status,
   profile:Object.fromEntries(Object.entries(profile).map(([k,v])=>[k,v===''?null:v]))};
  try{
   const r=await fetch(url,{method:id===null?'POST':'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify(id===null?{...base,...common}:{...common,revision:data?.employee.profile_revision})});
   const d=await r.json();if(!r.ok){
    const field=Object.keys(d.errors??{})[0]?.replace('profile.','');
    const index=tabs.findIndex(([,items])=>items.some(([k])=>k===field));
    if(index>=0)setTab(index);
    throw new Error(Object.values(d.errors??{}).flat().join(' ')||d.message);
   }
   onSaved();
  }catch(err){setError((err as Error).message);}finally{setBusy(false);}
 }
 return <form className="cis-prop-decision" onSubmit={e=>void save(e)} noValidate>
  <h4>{id===null?'Novo colaborador':'Ficha do colaborador · '+base.name}</h4>
  {error&&<p role="alert" className="cis-table-error">{error}</p>}
  {id!==null&&!data&&!error&&<p role="status">A carregar a ficha…</p>}
  {(id===null||data)&&<>
   <div className="cis-prop-actions" role="tablist" aria-label="Separadores da ficha">
    {tabs.map(([label],i)=><button type="button" key={label} role="tab" id={'rh-ficha-tab-'+i} aria-selected={tab===i}
     aria-controls="rh-ficha-panel" className={tab===i?'primary':''} onClick={()=>setTab(i)} tabIndex={tab===i?0:-1}
     onKeyDown={e=>{if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;e.preventDefault();const j=(i+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length;setTab(j);document.getElementById('rh-ficha-tab-'+j)?.focus();}}>{label}</button>)}
   </div>
   <fieldset disabled={busy} id="rh-ficha-panel" role="tabpanel" aria-labelledby={'rh-ficha-tab-'+tab} style={{border:0,padding:0,minWidth:0}}>
    {tab<5&&<div className="cis-profile-fields">
     {tab===0&&baseInput('name','Nome completo *')}
     {tab===4&&<>{baseInput('code','Código *','text',id!==null)}{baseInput('company','Empresa *','text',id!==null)}{baseInput('category','Categoria')}
      {baseInput('admission','Data de admissão *','date')}
      <label className="cis-profile-field"><span>Estado</span><select value={base.status} onChange={e=>setBase({...base,status:e.target.value})}>
       <option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></label></>}
     {tabs[tab][1].map(input)}
    </div>}
    {tab===5&&(data?.contracts.length?<div className="cis-table-scroll"><table><thead><tr><th>Tipo</th><th>Referência</th><th>Data</th><th>Valor unitário</th><th>Estado</th></tr></thead><tbody>
     {data.contracts.map(row=><tr key={row.id}><td>{row.kind==='contratos'?'Contrato':'Adenda'}</td><td>#{row.id}{row.parent_id?' · contrato #'+row.parent_id:''}</td>
      <td>{row.reference_date}</td><td>{row.details.rate!=null?Number(row.details.rate).toLocaleString('pt-MZ',{minimumFractionDigits:2})+' MT':'—'}</td><td>{row.review_status??'—'}</td></tr>)}
    </tbody></table></div>:<p>{id===null?'Guarde primeiro a ficha.':'Sem contratos ou adendas associados.'}</p>)}
   </fieldset>
  </>}
  <div className="cis-prop-actions"><button type="submit" className="primary" disabled={busy||(id!==null&&!data)}>Guardar ficha</button>
   <button type="button" onClick={onClose} disabled={busy}>Voltar</button></div>
 </form>;
}
