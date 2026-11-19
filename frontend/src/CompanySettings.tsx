import {useEffect,useMemo,useRef,useState} from 'react';
import {Building2,Camera,Trash2,Save,RotateCcw,Gavel,Gauge,Award,MapPin,Phone,UserRound,IdCard,AlertTriangle,CheckCircle2,Info,Loader2,Link2} from 'lucide-react';
import {setCompanyProfile} from './company';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Vals=Record<string,string>;
type Field={k:string;label:string;type:'text'|'number'|'date'|'select'|'bool'|'email';unit?:string;help?:string;options?:[string,string][];req?:boolean;wide?:boolean;placeholder?:string;step?:string};
type Group={title:string;icon:any;fields:Field[]};
type Section={id:string;kicker:string;title:string;icon:any;desc:string;used:string[];note?:string;groups:Group[]};

const PROV:[string,string][]=[['','Seleccionar'],...['Maputo Cidade','Maputo Província','Gaza','Inhambane','Sofala','Manica','Tete','Zambézia','Nampula','Cabo Delgado','Niassa'].map(p=>[p,p] as [string,string])];
const SECTIONS:Section[]=[
 {id:'perfil',kicker:'Perfil',title:'Informações gerais',icon:Building2,desc:'Identificação da empresa nos extractos, evidências e relatórios.',used:['Extracto do cliente (PDF)','Extracto geral (PDF)','Relatórios › Documento'],groups:[
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
  {title:'Proprietário',icon:UserRound,fields:[{k:'proprietario',label:'Nome do proprietário',type:'text',wide:true}]}]},
 {id:'cobranca',kicker:'Operacional',title:'Cobrança e multa',icon:Gavel,desc:'Regras de mora, calendário das prestações e lembretes aos clientes.',
  used:['Política da empresa (registo)','Lembretes › Centro de mensagens'],
  note:'Hoje o CIS calcula a multa pela taxa de cada contrato («% de Multa») e só a cobra quando o operador marca «Cobrar multa». Estas regras ficam registadas como política da empresa; a multa automática só será ligada depois de aprovada, para não alterar contratos em curso.',
  groups:[
  {title:'Mora',icon:Gavel,fields:[
   {k:'limite_mensal_dias_mora',label:'Limite mensal de dias de mora',type:'number',unit:'dias',help:'Máximo de dias por mês em que a taxa diária é aplicada.'},
   {k:'tolerancia_dias',label:'Tolerância antes da mora',type:'number',unit:'dias',help:'Dias após o vencimento sem multa.'},
   {k:'penalidades_automaticas',label:'Aplicar penalidades automaticamente',type:'bool',help:'Sim: a multa é proposta sem intervenção do operador.'},
   {k:'taxa_diaria_mora',label:'Taxa diária de juros de mora',type:'number',unit:'%',step:'0.01'},
   {k:'dias_carencia',label:'Dias de carência por defeito',type:'number',unit:'dias',help:'Dias entre o desembolso e o início da contagem.'}]},
  {title:'Calendário e avisos',icon:Info,fields:[
   {k:'fim_de_semana',label:'Prestações ao fim de semana',type:'select',options:[['permitir','Permitir sábados e domingos'],['adiar','Adiar para segunda-feira'],['antecipar','Antecipar para sexta-feira']]},
   {k:'modalidade_calendario',label:'Modalidade do calendário',type:'select',options:[['dias_corridos','Dias corridos (15 / 30 dias)'],['mesmo_dia_mes','Mesmo dia de cada mês']]},
   {k:'lembrete_dias_antes',label:'Lembrete antes do vencimento',type:'number',unit:'dias'},
   {k:'canal_notificacao',label:'Canal de notificação ao cliente',type:'select',options:[['whatsapp_web','WhatsApp Web (extensão)'],['whatsapp','Ligação WhatsApp'],['nenhum','Não notificar']]}]}]},
 {id:'scoring',kicker:'Risco',title:'Credit scoring',icon:Gauge,desc:'Limiares de aprovação e limites de crédito por cliente.',used:['Relatórios › Clientes para novo crédito'],groups:[
  {title:'Score',icon:Gauge,fields:[
   {k:'score_minimo',label:'Score mínimo para aprovação',type:'number',unit:'0–100'},
   {k:'score_baixo_risco',label:'Limiar de baixo risco',type:'number',unit:'0–100'},
   {k:'score_risco_medio',label:'Limiar de risco médio',type:'number',unit:'0–100',help:'Abaixo deste valor: alto risco.'}]},
  {title:'Limites por cliente',icon:UserRound,fields:[
   {k:'max_creditos_activos',label:'Máximo de créditos activos',type:'number'},
   {k:'max_creditos_atraso',label:'Máximo de créditos em atraso',type:'number'},
   {k:'taxa_esforco_max',label:'Taxa de esforço máxima',type:'number',unit:'%',help:'Prestação ÷ rendimento mensal do cliente.'}]}]},
 {id:'incentivos',kicker:'Equipa',title:'Incentivos',icon:Award,desc:'Valores para o mapa de incentivos dos promotores e gestores.',used:['Relatórios › Desempenho por operador'],groups:[
  {title:'Por operação',icon:Award,fields:[
   {k:'incentivo_cliente_novo',label:'Por cliente novo',type:'number',unit:'MZN',step:'0.01'},
   {k:'incentivo_renovacao',label:'Por renovação',type:'number',unit:'MZN',step:'0.01'}]},
  {title:'Por carteira',icon:Gauge,fields:[
   {k:'incentivo_taxa_capital',label:'Taxa sobre o capital vigente',type:'number',unit:'%',step:'0.01'},
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

export default function CompanySettings({token}:{token:string}){
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
 const go=(id:string)=>document.getElementById('cis-co-'+id)?.scrollIntoView({behavior:'smooth',block:'start'});

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
      onChange={ev=>set(f.k,ev.target.value)}/>{f.unit&&<em>{f.unit}</em>}</span>}
   {e?<small className="e">{e}</small>:f.help&&<small>{f.help}</small>}
  </label>;};

 return <section className="cis-co" aria-labelledby="cis-co-title">
  <header className="cis-co-banner">
   <div className="cis-co-banner-top"><span className="cis-co-banner-ico"><Building2 size={22}/></span>
    <div><h2 id="cis-co-title">Configurações da empresa</h2><p>Perfil, regras de cobrança e multa, credit scoring e incentivos.</p></div>
    <div className="cis-co-progress" title="Perfil preenchido"><b>{pct}%</b><span>perfil</span><i style={{['--p' as any]:pct+'%'}}/></div></div>
   <nav className="cis-co-shortcuts">{SECTIONS.map(s=><button key={s.id} type="button" onClick={()=>go(s.id)}><span>{s.kicker}</span><b><s.icon size={15}/>{s.title}</b></button>)}</nav>
  </header>
  {!installed&&<div className="cis-co-alert bad"><AlertTriangle size={17}/><span>Tabela das configurações ainda não instalada. Na VPS: <code>bash deploy/install-company-settings.sh</code>. Até lá são usados os valores por defeito.</span></div>}
  {missing.length>0&&<div className="cis-co-alert warn"><AlertTriangle size={17}/><span>Falta preencher: <b>{missing.map(f=>f.label).join(', ')}</b>.</span></div>}

  {SECTIONS.map(s=><section key={s.id} id={'cis-co-'+s.id} className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><span className="cis-co-sico"><s.icon size={18}/></span><div><span className="cis-co-kicker">{s.kicker}</span><h3>{s.title}</h3><p>{s.desc}</p></div>
    <div className="cis-co-used"><Link2 size={13}/>{s.used.map(u=><span key={u}>{u}</span>)}</div></div>
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
  </section>)}

  <div className={'cis-co-savebar'+(dirty.length?' dirty':'')} role="region" aria-label="Guardar configurações">
   <span className="state">{msg?<span className={msg.ok?'ok':'bad'}>{msg.ok?<CheckCircle2 size={15}/>:<AlertTriangle size={15}/>}{msg.text}</span>
    :dirty.length?<span className="warn">{dirty.length} alteração(ões) por guardar</span>
    :<span>{meta.em?`Última alteração: ${new Date(meta.em.replace(' ','T')).toLocaleString('pt-PT',{dateStyle:'short',timeStyle:'short'})}${meta.por?` por ${meta.por}`:''}`:'Valores por defeito — ainda sem alterações.'}</span>}</span>
   <button type="button" onClick={()=>{setForm(saved);setErrors({});setMsg(null);}} disabled={!dirty.length||saving}><RotateCcw size={15}/> Descartar</button>
   <button type="button" className="primary" onClick={save} disabled={!dirty.length||saving}>{saving?<Loader2 size={15} className="cis-spin"/>:<Save size={15}/>} Salvar configurações</button>
  </div>
 </section>;
}
