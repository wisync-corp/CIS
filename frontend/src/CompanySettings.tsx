import {useEffect,useMemo,useRef,useState} from 'react';
import {Building2,Camera,Trash2,Save,RotateCcw,Gavel,Gauge,Award,MapPin,Phone,UserRound,IdCard,AlertTriangle,CheckCircle2,Info,Loader2,Link2,Hash} from 'lucide-react';
import {setCompanyProfile} from './company';
import ContractNumberingPreview from './ContractNumberingPreview';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Vals=Record<string,string>;
type Field={k:string;label:string;type:'text'|'number'|'date'|'select'|'bool'|'email';unit?:string;help?:string;options?:[string,string][];req?:boolean;wide?:boolean;placeholder?:string;step?:string};
type Group={title:string;icon:any;fields:Field[]};
type Section={id:string;kicker:string;title:string;icon:any;desc:string;used:string[];note?:string;groups:Group[]};

const PROV:[string,string][]=[['','Seleccionar'],...['Maputo Cidade','Maputo Província','Gaza','Inhambane','Sofala','Manica','Tete','Zambézia','Nampula','Cabo Delgado','Niassa'].map(p=>[p,p] as [string,string])];
const SECTIONS:Section[]=[
 {id:'perfil',kicker:'Perfil',title:'Informações gerais',icon:Building2,desc:'Identificação da empresa nos extractos, evidências e relatórios.',used:['Extracto de conta (PDF)','Extracto geral (PDF)','Relatórios › Documento','Contratos'],groups:[
  {title:'Identificação',icon:IdCard,fields:[
   {k:'nome_empresa',label:'Nome da empresa',type:'text',req:true,wide:true,placeholder:'Ex.: CAPTA Microcrédito, Lda'},
   {k:'nuit',label:'NUIT da empresa',type:'text',req:true,placeholder:'9 dígitos',help:'Obrigatório nas comunicações ao Banco de Moçambique.'},
   {k:'licenca_bm',label:'N.º de licença / registo BM',type:'text',placeholder:'Opcional'},
   {k:'inicio_actividade',label:'Início de actividade',type:'date'},
   {k:'capital_inicial',label:'Capital inicial',type:'number',unit:'MZN',step:'0.01'},
   {k:'funcionarios',label:'N.º de funcionários',type:'number',step:'1'}]},
  {title:'Localização',icon:MapPin,fields:[
   {k:'provincia',label:'Província',type:'select',options:PROV},
   {k:'distrito',label:'Distrito',type:'text'},
   {k:'morada',label:'Morada',type:'text',wide:true,placeholder:'Avenida, número, bairro'}]},
  {title:'Contactos',icon:Phone,fields:[
   {k:'contacto_principal',label:'Contacto principal',type:'text',req:true,placeholder:'84 000 0000'},
   {k:'contacto_alternativo',label:'Contacto alternativo',type:'text',placeholder:'Opcional'},
   {k:'email',label:'Email',type:'email',placeholder:'geral@empresa.co.mz'}]},
  {title:'Proprietário',icon:UserRound,fields:[{k:'proprietario',label:'Nome do proprietário',type:'text',wide:true}]},
  {title:'Para os contratos',icon:IdCard,fields:[
   {k:'conta_reembolsos',label:'Conta para reembolsos',type:'text',wide:true,placeholder:'Ex.: BIM 347807372, titular CAPTA Microcrédito',help:'Aparece no contrato como {Conta para Reembolsos}.'},
   {k:'foro_judicial',label:'Foro judicial',type:'text',placeholder:'Ex.: Gondola',help:'Tribunal competente: {Foro Judicial}.'}]}]},
 {id:'cobranca',kicker:'Operacional',title:'Cobrança e juros de mora',icon:Gavel,desc:'Regras de mora, calendário das prestações e lembretes aos mutuários.',
  used:['Política da empresa (registo)','Lembretes › Centro de mensagens'],
  note:'Hoje o CIS calcula os juros de mora pela taxa de cada contrato e só os cobra quando o gestor marca «Cobrar juros de mora». Estas regras ficam registadas como política da empresa; os juros de mora automáticos só serão ligados depois de aprovados, para não alterar contratos em curso.',
  groups:[
  {title:'Mora',icon:Gavel,fields:[
   {k:'limite_mensal_dias_mora',label:'Limite mensal de dias de mora',type:'number',unit:'dias',help:'Máximo de dias por mês em que a taxa diária é aplicada.'},
   {k:'tolerancia_dias',label:'Período de tolerância',type:'number',unit:'dias',help:'Dias após o vencimento sem juros de mora.'},
   {k:'penalidades_automaticas',label:'Juros de mora automáticos',type:'bool',help:'Sim: os juros de mora são propostos sem intervenção do gestor.'},
   {k:'taxa_diaria_mora',label:'Taxa diária de juros de mora',type:'number',unit:'%',step:'0.01'},
   {k:'dias_carencia',label:'Período de carência',type:'number',unit:'dias',help:'Dias entre o desembolso e o início da contagem.'}]},
  {title:'Calendário e avisos',icon:Info,fields:[
   {k:'fim_de_semana',label:'Prestações ao fim de semana',type:'select',options:[['permitir','Permitir sábados e domingos'],['adiar','Adiar para segunda-feira'],['antecipar','Antecipar para sexta-feira']]},
   {k:'modalidade_calendario',label:'Calendário das prestações',type:'select',options:[['dias_corridos','Dias corridos (15 / 30 dias)'],['mesmo_dia_mes','Mesmo dia de cada mês']]},
   {k:'lembrete_dias_antes',label:'Antecedência dos avisos de cobrança',type:'number',unit:'dias',help:'Número de dias antes do vencimento. Zero mostra só as prestações que vencem hoje.'},
   {k:'canal_notificacao',label:'Canal de notificação ao mutuário',type:'select',options:[['whatsapp_web','WhatsApp Web (extensão)'],['whatsapp','Ligação WhatsApp'],['nenhum','Não notificar']]}]}]},
 {id:'scoring',kicker:'Risco',title:'Credit scoring',icon:Gauge,desc:'Limiares de aprovação e limites de crédito por mutuário.',used:['Relatórios › Mutuários para novo crédito','Propostas de crédito'],groups:[
  {title:'Score',icon:Gauge,fields:[
   {k:'score_minimo',label:'Score mínimo para aprovação',type:'number',unit:'0–100'},
   {k:'score_baixo_risco',label:'Limiar de baixo risco',type:'number',unit:'0–100'},
   {k:'score_risco_medio',label:'Limiar de risco médio',type:'number',unit:'0–100',help:'Abaixo deste valor: alto risco.'}]},
  {title:'Limites por mutuário',icon:UserRound,fields:[
   {k:'max_creditos_activos',label:'Máximo de créditos activos',type:'number'},
   {k:'max_creditos_atraso',label:'Máximo de créditos em atraso',type:'number'},
   {k:'taxa_esforco_max',label:'Taxa de esforço máxima',type:'number',unit:'%',help:'Encargo mensal (prestação + outras prestações) ÷ rendimento mensal do mutuário.'}]},
  {title:'Propostas de crédito',icon:Gauge,fields:[
   {k:'limite_aprovacao_gestor',label:'Limite de aprovação do gestor de crédito',type:'number',unit:'MT',help:'Propostas até este montante podem ser aprovadas pelo gestor de crédito; acima, só pelo administrador.'},
   {k:'validade_aprovacao_dias',label:'Validade da aprovação',type:'number',unit:'dias',help:'Depois deste prazo sem desembolso, a proposta aprovada expira.'}]}]},
 {id:'numeracao',kicker:'Contratos',title:'Numeração dos contratos',icon:Hash,desc:'Código dos contratos e dos recibos por ano: a contagem recomeça em 1 no início de cada ano (contratos pelo desembolso, recibos pelo pagamento).',used:['Novo contrato','Contratos e prestações','Recibos','Extractos','Relatórios'],groups:[
  {title:'Formato',icon:Hash,fields:[
   {k:'contrato_prefixo',label:'Prefixo',type:'text',req:true,placeholder:'CAPTA',help:'Letras ou números, sem espaços (ex.: CAPTA, EMP).'},
   {k:'contrato_digitos',label:'Dígitos da sequência',type:'select',options:[['3','3 (001)'],['4','4 (0001)'],['5','5 (00001)'],['6','6 (000001)']]},
   {k:'recibo_prefixo',label:'Prefixo dos recibos',type:'text',req:true,placeholder:'REC',help:'Recibos numerados por ano da data do pagamento (ex.: REC-2026-0001).'}]}]},
 {id:'incentivos',kicker:'Equipa',title:'Incentivos',icon:Award,desc:'Valores para o mapa de incentivos dos gestores de crédito.',used:['Relatórios › Desempenho por gestor de crédito'],groups:[
  {title:'Por operação',icon:Award,fields:[
   {k:'incentivo_cliente_novo',label:'Por mutuário novo',type:'number',unit:'MZN',step:'0.01'},
   {k:'incentivo_renovacao',label:'Por renovação',type:'number',unit:'MZN',step:'0.01'}]},
  {title:'Por carteira',icon:Gauge,fields:[
   {k:'incentivo_taxa_capital',label:'Taxa sobre o saldo em dívida (carteira)',type:'number',unit:'%',step:'0.01'},
   {k:'incentivo_taxa_juros',label:'Taxa sobre juros recebidos',type:'number',unit:'%',step:'0.01'}]}]},
];
const PROFILE_KEYS=SECTIONS[0].groups.flatMap(g=>g.fields.map(f=>f.k)).concat('logotipo');

async function shrink(file:File):Promise<string>{
 const url=await new Promise<string>((ok,ko)=>{const r=new FileReader();r.onload=()=>ok(String(r.result));r.onerror=ko;r.readAsDataURL(file);});
 if(file.size<=350_000&&/image\/(png|jpeg|webp)/.test(file.type))return url;
 const img=await new Promise<HTMLImageElement>((ok,ko)=>{const i=new Image();i.onload=()=>ok(i);i.onerror=ko;i.src=url;});
 const k=Math.min(1,512/Math.max(img.width,img.height));const c=document.createElement('canvas');c.width=Math.round(img.width*k);c.height=Math.round(img.height*k);
 c.getContext('2d')!.drawImage(img,0,0,c.width,c.height);return c.toDataURL('image/png');
}

export type CompanySectionId='perfil'|'cobranca'|'scoring'|'numeracao'|'incentivos';
export const COMPANY_SECTIONS=SECTIONS.map(s=>({id:s.id as CompanySectionId,title:s.title,icon:s.icon,desc:s.desc}));

export default function CompanySettings({token,section}:{token:string;section:CompanySectionId}){
 const [saved,setSaved]=useState<Vals|null>(null),[form,setForm]=useState<Vals>({}),[errors,setErrors]=useState<Record<string,string>>({});
 const [installed,setInstalled]=useState(true),[meta,setMeta]=useState<{em:string|null;por:string|null}>({em:null,por:null});
 const [loading,setLoading]=useState(true),[saving,setSaving]=useState(false),[msg,setMsg]=useState<{ok:boolean;text:string}|null>(null),[fail,setFail]=useState('');
 const fileRef=useRef<HTMLInputElement>(null);
 const apply=(d:any)=>{setSaved(d.valores);setForm(d.valores);setInstalled(d.instalado!==false);setMeta({em:d.actualizado_em,por:d.actualizado_por});};
 useEffect(()=>{const ctrl=new AbortController();setLoading(true);
  fetch(API+'/cis/configuracoes',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:ctrl.signal})
   .then(async r=>{if(!r.ok)throw new Error('Não foi possível carregar as configurações.');return r.json();}).then(apply)
   .catch(e=>{if(e.name!=='AbortError')setFail(e.message);}).finally(()=>{if(!ctrl.signal.aborted)setLoading(false);});
  return ()=>ctrl.abort();},[token]);
 const dirty=useMemo(()=>saved?Object.keys(form).filter(k=>(form[k]??'')!==(saved[k]??'')):[],[form,saved]);
 const set=(k:string,v:string)=>{setForm(f=>({...f,[k]:v}));setErrors(e=>{const n={...e};delete n[k];return n;});setMsg(null);};
 const missing=useMemo(()=>SECTIONS[0].groups.flatMap(g=>g.fields).filter(f=>f.req&&!(form[f.k]??'').trim()),[form]);
 const filled=PROFILE_KEYS.filter(k=>(form[k]??'').trim()&&!(k==='capital_inicial'&&Number(form[k])===0)&&!(k==='funcionarios'&&form[k]==='0')).length;
 const pct=Math.round(100*filled/PROFILE_KEYS.length);
 async function save(){
  if(!dirty.length)return;setSaving(true);setMsg(null);
  try{
   const r=await fetch(API+'/cis/configuracoes',{method:'PUT',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({valores:Object.fromEntries(dirty.map(k=>[k,form[k]??'']))})});
   const d=await r.json().catch(()=>({}));
   if(r.status===422&&d.errors){const e:Record<string,string>={};for(const [k,v] of Object.entries(d.errors))e[k]=Array.isArray(v)?String(v[0]):String(v);setErrors(e);
    setMsg({ok:false,text:`Corrija ${Object.keys(e).length} campo(s) assinalado(s).`});
    setTimeout(()=>document.querySelector('.cis-co-field.err')?.scrollIntoView({behavior:'smooth',block:'center'}),50);return;}
   if(!r.ok)throw new Error(d.message||'Não foi possível guardar.');
   apply(d);setErrors({});setCompanyProfile(d.perfil);setMsg({ok:true,text:`${d.guardados?.length??dirty.length} alteração(ões) guardada(s).`});
  }catch(e:any){setMsg({ok:false,text:e.message});}finally{setSaving(false);}
 }
 async function pickLogo(f?:File){if(!f)return;try{const u=await shrink(f);if(u.length>540_000){setErrors(e=>({...e,logotipo:'Logótipo até 400 KB.'}));return;}set('logotipo',u);}catch{setErrors(e=>({...e,logotipo:'A imagem não é válida.'}));}}

 if(loading)return <section className="cis-panel cis-co"><div className="cis-co-loading"><Loader2 size={18} className="cis-spin"/> A carregar as configurações da empresa…</div></section>;
 if(fail||!saved)return <section className="cis-panel cis-co"><div className="cis-co-alert bad"><AlertTriangle size={17}/>{fail||'Sem dados.'}</div></section>;

 const renderField=(f:Field)=>{const v=form[f.k]??'';const e=errors[f.k];const ch=v!==(saved[f.k]??'');
  return <label key={f.k} className={'cis-co-field'+(f.wide?' wide':'')+(e?' err':'')+(ch?' changed':'')}>
   <span className="lbl">{f.label}{f.req&&<i>*</i>}</span>
   {f.type==='bool'?<span className="cis-co-switch" role="radiogroup" aria-label={f.label}>
     {[['1','Sim'],['0','Não']].map(([val,l])=><button key={val} type="button" role="radio" aria-checked={v===val} className={v===val?'on':''} onClick={()=>set(f.k,val)}>{l}</button>)}</span>
   :f.type==='select'?<select value={v} onChange={ev=>set(f.k,ev.target.value)}>{f.options!.map(([o,l])=><option key={o} value={o}>{l}</option>)}</select>
   :<span className="cis-co-input"><input type={f.type} value={v} step={f.step} min={f.type==='number'?0:undefined} placeholder={f.placeholder}
      max={f.type==='date'?new Date().toISOString().slice(0,10):undefined} inputMode={f.k==='nuit'?'numeric':undefined} maxLength={f.k==='nuit'?9:undefined}
      onChange={ev=>set(f.k,(f.k==='contrato_prefixo'||f.k==='recibo_prefixo')?ev.target.value.toUpperCase().replace(/\s/g,''):ev.target.value)}/>{f.unit&&<em>{f.unit}</em>}</span>}
   {e?<small className="e">{e}</small>:f.help&&<small>{f.help}</small>}
  </label>;};

 return <div className="cis-co">
  {!installed&&<div className="cis-co-alert bad"><AlertTriangle size={17}/><span>Tabela das configurações ainda não instalada. Na VPS: <code>bash deploy/install-company-settings.sh</code>. Até lá são usados os valores por defeito.</span></div>}
  {section==='perfil'&&missing.length>0&&<div className="cis-co-alert warn"><AlertTriangle size={17}/><span>Falta preencher: <b>{missing.map(f=>f.label).join(', ')}</b>.</span></div>}
  {SECTIONS.filter(s=>s.id===section).map(s=><section key={s.id} className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>{s.title}</h3><p>{s.desc}</p>{s.id==='perfil'&&<span className="cis-co-meter"><i><u style={{width:pct+'%'}}/></i><b>{pct}% preenchido</b></span>}</div>
    <div className="cis-co-used"><small>Usado em</small>{s.used.map(u=><span key={u}>{u}</span>)}</div></div>
   {s.note&&<div className="cis-co-alert info"><Info size={16}/><span>{s.note}</span></div>}
   {s.id==='perfil'&&<div className={'cis-co-logo'+(errors.logotipo?' err':'')}>
    <button type="button" className="cis-co-logo-circle" onClick={()=>fileRef.current?.click()} aria-label="Alterar logótipo">
     {form.logotipo?<img src={form.logotipo} alt="Logótipo da empresa"/>:<Building2 size={30}/>}<span className="cam"><Camera size={14}/></span></button>
    <div><b>Logótipo da empresa</b><p>PNG, JPG ou WEBP até 400 KB (imagens maiores são reduzidas). Aparece nos documentos emitidos.</p>
     {errors.logotipo&&<small className="e">{errors.logotipo}</small>}
     <div className="cis-co-logo-actions"><button type="button" onClick={()=>fileRef.current?.click()}><Camera size={14}/> Escolher imagem</button>
      {form.logotipo&&<button type="button" className="danger" onClick={()=>set('logotipo','')}><Trash2 size={14}/> Remover</button>}</div></div>
    <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={e=>{pickLogo(e.target.files?.[0]);e.target.value='';}}/></div>}
   {s.groups.map(g=><fieldset key={g.title} className="cis-co-group"><legend><g.icon size={14}/>{g.title}</legend><div className="cis-co-grid">{g.fields.map(renderField)}</div></fieldset>)}
   {s.id==='numeracao'&&<ContractNumberingPreview token={token} prefixo={form.contrato_prefixo??''} digitos={form.contrato_digitos??'4'} dirty={['contrato_prefixo','contrato_digitos'].some(k=>dirty.includes(k))} recibo={(form.recibo_prefixo||'REC').toUpperCase()}/>}
  </section>)}

  <div className={'cis-co-savebar'+(dirty.length?' dirty':'')} role="region" aria-label="Guardar configurações">
   <span className="state">{msg?<span className={msg.ok?'ok':'bad'}>{msg.ok?<CheckCircle2 size={15}/>:<AlertTriangle size={15}/>}{msg.text}</span>
    :dirty.length?<span className="warn">{dirty.length} alteração(ões) por guardar</span>
    :<span>{meta.em?`Última alteração: ${new Date(meta.em.replace(' ','T')).toLocaleString('pt-PT',{dateStyle:'short',timeStyle:'short'})}${meta.por?` por ${meta.por}`:''}`:'Valores por defeito — ainda sem alterações.'}</span>}</span>
   <button type="button" onClick={()=>{setForm(saved);setErrors({});setMsg(null);}} disabled={!dirty.length||saving}><RotateCcw size={15}/> Descartar</button>
   <button type="button" className="primary" onClick={save} disabled={!dirty.length||saving}>{saving?<Loader2 size={15} className="cis-spin"/>:<Save size={15}/>} Guardar alterações</button>
  </div>
 </div>;
}
