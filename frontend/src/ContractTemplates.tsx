import {useEffect,useMemo,useRef,useState} from 'react';
import {Plus,Copy,Trash2,Save,RotateCcw,Star,Loader2,AlertTriangle,Heading1,Heading2,Bold,Table2,PenLine,Search,FileSignature,Info} from 'lucide-react';
import {pushToast} from './waBridge';
import {ContractBody,usedVariables,type TemplateVar,type ContractValues} from './contractTemplate';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Modelo={id:number;nome:string;corpo:string;padrao:boolean|number;updated_by:string|null;updated_at:string|null};

/** Definições › Modelos de contrato: editor com variáveis e pré-visualização com um contrato real. */
export default function ContractTemplates({token}:{token:string}){
 const [modelos,setModelos]=useState<Modelo[]|null>(null),[vars,setVars]=useState<TemplateVar[]>([]),[error,setError]=useState('');
 const [sel,setSel]=useState<number|'novo'|null>(null),[nome,setNome]=useState(''),[corpo,setCorpo]=useState('');
 const [errs,setErrs]=useState<Record<string,string>>({}),[saving,setSaving]=useState(false),[q,setQ]=useState('');
 const [amostra,setAmostra]=useState(''),[vals,setVals]=useState<ContractValues|null>(null),[valsErr,setValsErr]=useState('');
 const ta=useRef<HTMLTextAreaElement>(null);
 const H={Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'};
 const apply=(d:{modelos:Modelo[];variaveis:TemplateVar[]},pick?:number)=>{setModelos(d.modelos);setVars(d.variaveis);
  const m=d.modelos.find(x=>x.id===(pick??(typeof sel==='number'?sel:undefined)))??d.modelos.find(x=>x.padrao)??d.modelos[0];
  if(m){setSel(m.id);setNome(m.nome);setCorpo(m.corpo);}};
 useEffect(()=>{fetch(API+'/cis/contratos/modelos',{headers:H}).then(async r=>{const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.message||'Não foi possível carregar os modelos.');return d;})
  .then(d=>apply(d)).catch(e=>setError(e.message));},[token]);// eslint-disable-line
 // Valores de um contrato real para a pré-visualização (por defeito, exemplos).
 useEffect(()=>{if(!amostra.trim()){setVals(null);setValsErr('');return;}const c=new AbortController();const t=setTimeout(()=>{
  fetch(API+'/cis/contractos/'+encodeURIComponent(amostra.trim())+'/variaveis-contrato',{headers:H,signal:c.signal})
   .then(async r=>{const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.message||'Contrato não encontrado.');return d;}).then(d=>{setVals(d);setValsErr('');})
   .catch(e=>{if(e.name!=='AbortError'){setVals(null);setValsErr(e.message);}});},400);return ()=>{clearTimeout(t);c.abort();};},[amostra,token]);// eslint-disable-line
 const current=modelos?.find(m=>m.id===sel);
 const dirty=sel==='novo'||(current?(current.nome!==nome||current.corpo!==corpo):false);
 const known=vars.map(v=>v.nome);
 const {unknown}=useMemo(()=>usedVariables(corpo,known),[corpo,vars]);// eslint-disable-line
 const sampleVals=useMemo(()=>vals?.valores??Object.fromEntries(vars.filter(v=>v.grupo!=='Blocos').map(v=>[v.nome,v.exemplo])),[vals,vars]);
 const plano=vals?.plano??[{prestacao:'1',vencimento:'15/11/2026',capital:'4 000,00',juros:'800,00',valor:'4 800,00'}];
 const groups=useMemo(()=>{const f=vars.filter(v=>!q.trim()||(v.nome+' '+v.descricao).toLowerCase().includes(q.trim().toLowerCase()));
  return [...new Set(f.map(v=>v.grupo))].map(g=>({g,items:f.filter(v=>v.grupo===g)}));},[vars,q]);

 function insert(before:string,after='',line=false){
  const el=ta.current;if(!el)return;const s=el.selectionStart,e=el.selectionEnd;const sel=corpo.slice(s,e);
  let ins=before+sel+after;if(line){const atStart=s===0||corpo[s-1]==='\n';ins=(atStart?'':'\n')+before+(sel||'')+after+'\n';}
  const next=corpo.slice(0,s)+ins+corpo.slice(e);setCorpo(next);
  requestAnimationFrame(()=>{el.focus();const pos=s+ins.length;el.setSelectionRange(pos,pos);});
 }
 function pick(id:number|'novo'){
  if(dirty&&!window.confirm('Há alterações por guardar neste modelo. Continuar sem guardar?'))return;
  setErrs({});
  if(id==='novo'){setSel('novo');setNome('Novo modelo');setCorpo('# CONTRATO N.º {Nr do Contrato}\n\nEntre a **{Nome da Empresa}** e **{Nome do Mutuário}**…\n\n## PRIMEIRA\n### (Objecto)\n1. …\n\n{Cidade}, {Data de Hoje}\n\n{Assinaturas}\n');return;}
  const m=modelos?.find(x=>x.id===id);if(m){setSel(m.id);setNome(m.nome);setCorpo(m.corpo);}
 }
 async function call(method:string,path:string,body?:unknown){
  const r=await fetch(API+'/cis/contratos/modelos'+path,{method,headers:H,body:body?JSON.stringify(body):undefined});
  const d=await r.json().catch(()=>({}));
  if(r.status===422&&d.errors){setErrs(Object.fromEntries(Object.entries(d.errors).map(([k,v])=>[k,String((v as string[])[0])])));throw new Error('Corrija os campos assinalados.');}
  if(!r.ok)throw new Error(d.message||'Operação recusada.');return d;
 }
 async function save(){setSaving(true);setErrs({});
  try{const d=sel==='novo'?await call('POST','',{nome,corpo}):await call('PUT','/'+sel,{nome,corpo});apply(d,d.id??(typeof sel==='number'?sel:undefined));pushToast('ok','Modelo de contrato guardado.');}
  catch(e:any){pushToast('erro',e.message);}finally{setSaving(false);}}
 async function makeDefault(){if(typeof sel!=='number')return;try{apply(await call('PUT','/'+sel,{padrao:true}),sel);pushToast('ok','Modelo padrão actualizado.');}catch(e:any){pushToast('erro',e.message);}}
 async function remove(){if(typeof sel!=='number'||!window.confirm(`Eliminar o modelo «${nome}»?`))return;try{const d=await call('DELETE','/'+sel);setSel(null);apply(d);pushToast('ok','Modelo eliminado.');}catch(e:any){pushToast('erro',e.message);}}
 function duplicate(){if(!current)return;setSel('novo');setNome(current.nome+' (cópia)');setCorpo(current.corpo);}

 if(error)return <section className="cis-panel cis-co-section"><div className="cis-co-alert bad"><AlertTriangle size={17}/><span>{error}</span></div></section>;
 if(!modelos)return <section className="cis-panel cis-co-section"><div className="cis-co-loading"><Loader2 size={18} className="cis-spin"/> A carregar os modelos…</div></section>;
 return <div className="cis-co">
  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>Modelos de contrato</h3><p>Escreva o contrato uma vez, com variáveis como <code>{'{Nome do Mutuário}'}</code>: o CIS preenche-as com os dados de cada contrato.</p></div>
    <div className="cis-co-used"><small>Usado em</small><span>Contratos › Documento do contrato</span></div></div>
   <div className="cis-ct-models">
    {modelos.map(m=><button key={m.id} type="button" className={sel===m.id?'on':''} onClick={()=>pick(m.id)}>{m.padrao?<Star size={13}/>:<FileSignature size={13}/>}{m.nome}</button>)}
    {sel==='novo'&&<button type="button" className="on"><FileSignature size={13}/>{nome||'Novo modelo'}</button>}
    <button type="button" className="add" onClick={()=>pick('novo')}><Plus size={14}/> Novo modelo</button>
   </div>
   <div className="cis-ct-editor">
    <div className="cis-ct-left">
     <label className={'cis-co-field'+(errs.nome?' err':'')}><span className="lbl">Nome do modelo</span><span className="cis-co-input"><input value={nome} maxLength={120} onChange={e=>setNome(e.target.value)}/></span>{errs.nome&&<small className="e">{errs.nome}</small>}</label>
     <div className="cis-ct-toolbar" role="toolbar" aria-label="Formatação">
      <button type="button" onClick={()=>insert('# ','',true)} title="Título do documento"><Heading1 size={15}/> Título</button>
      <button type="button" onClick={()=>insert('## ','',true)} title="Nova cláusula"><Heading2 size={15}/> Cláusula</button>
      <button type="button" onClick={()=>insert('### (',')',true)} title="Subtítulo entre parênteses">( ) Subtítulo</button>
      <button type="button" onClick={()=>insert('**','**')} title="Negrito"><Bold size={15}/></button>
      <button type="button" onClick={()=>insert('[[',']]')} title="Espaço a preencher à mão"><PenLine size={15}/> A preencher</button>
      <button type="button" onClick={()=>insert('{Plano de Pagamentos}','',true)}><Table2 size={15}/> Plano</button>
      <button type="button" onClick={()=>insert('{Assinaturas}','',true)}><FileSignature size={15}/> Assinaturas</button>
     </div>
     <textarea ref={ta} rows={28} className={'cis-ct-text'+(errs.corpo?' err':'')} value={corpo} onChange={e=>setCorpo(e.target.value)} spellCheck aria-label="Texto do contrato"/>
     {errs.corpo&&<small className="cis-ct-err">{errs.corpo}</small>}
     {unknown.length>0&&<div className="cis-co-alert warn"><AlertTriangle size={16}/><span>Variáveis desconhecidas (ficam em branco no contrato): {unknown.map(u=><code key={u}>{'{'+u+'}'}</code>)}</span></div>}
     <details className="cis-ct-help"><summary><Info size={14}/> Como escrever</summary>
      <ul><li><code># Título</code> título do documento · <code>## PRIMEIRA</code> cláusula · <code>### (Assunto)</code> subtítulo</li>
       <li><code>**texto**</code> negrito · <code>a) …</code> alínea · uma linha = um parágrafo</li>
       <li><code>{'{Variável}'}</code> dado do contrato · <code>[[valor]]</code> espaço em branco para preencher à mão</li>
       <li><code>{'{Plano de Pagamentos}'}</code> e <code>{'{Assinaturas}'}</code> sozinhos numa linha</li></ul></details>
    </div>
    <aside className="cis-ct-vars">
     <label className="cis-rep-search"><Search size={14}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="Procurar variável…" aria-label="Procurar variável"/></label>
     <div className="cis-ct-varlist">{groups.map(({g,items})=><div key={g}><span>{g}</span>
      {items.map(v=><button key={v.nome} type="button" onClick={()=>insert('{'+v.nome+'}')} title={v.descricao+' · ex.: '+v.exemplo}><code>{'{'+v.nome+'}'}</code><small>{v.descricao}</small></button>)}</div>)}</div>
    </aside>
   </div>
  </section>
  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>Pré-visualização</h3><p>{vals?<>Com os dados do contrato <b>{vals.contrato}</b>.</>:'Com valores de exemplo. Escreva um número de contrato para ver com dados reais.'}</p></div>
    <label className="cis-rep-search"><input value={amostra} onChange={e=>setAmostra(e.target.value)} placeholder="N.º do contrato (ex.: C-0451)" aria-label="Contrato para pré-visualizar"/></label></div>
   {valsErr&&<div className="cis-co-alert warn"><AlertTriangle size={16}/><span>{valsErr}</span></div>}
   <div className="cis-ct-paper"><ContractBody body={corpo} values={sampleVals} plano={plano} companyName={sampleVals['Nome da Empresa']}/></div>
  </section>
  <div className={'cis-co-savebar'+(dirty?' dirty':'')}>
   <span className="state">{dirty?<span className="warn">Alterações por guardar</span>:<span>{current?.padrao?'Modelo padrão · ':''}{current?.updated_at?`Actualizado em ${new Date(String(current.updated_at).replace(' ','T')).toLocaleString('pt-PT',{dateStyle:'short',timeStyle:'short'})}${current.updated_by?` por ${current.updated_by}`:''}`:''}</span>}</span>
   {typeof sel==='number'&&!current?.padrao&&<button type="button" onClick={makeDefault}><Star size={15}/> Tornar padrão</button>}
   {current&&<button type="button" onClick={duplicate}><Copy size={15}/> Duplicar</button>}
   {typeof sel==='number'&&!current?.padrao&&<button type="button" onClick={remove}><Trash2 size={15}/> Eliminar</button>}
   <button type="button" disabled={!dirty||saving} onClick={()=>{if(current){setNome(current.nome);setCorpo(current.corpo);}else pick(modelos[0]?.id);}}><RotateCcw size={15}/> Descartar</button>
   <button type="button" className="primary" disabled={!dirty||saving} onClick={save}>{saving?<Loader2 size={15} className="cis-spin"/>:<Save size={15}/>} Guardar modelo</button>
  </div>
 </div>;
}
