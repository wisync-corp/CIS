import {useEffect,useMemo,useState} from 'react';
import {Plus,Trash2,Save,RotateCcw,Loader2,AlertTriangle,Info,Lock,ArrowDownCircle,ArrowUpCircle,Pencil,Check,X,Calculator} from 'lucide-react';
import {pushToast} from './waBridge';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Faixa={de:number|string;ate:number|string;taxa_15:string|null;taxa_30:string|null};
type Mov={id:number;tipo_movimento:'Entradas'|'Saídas';descricao:string;grupo:string|null;activo:boolean;protegido:boolean};
type Data={precos:Faixa[];lacunas:[number,number][];movimentos:Mov[];grupos:Record<string,string[]>;editavel:boolean;historico:{tabela:string;accao:string;por:string|null;em:string}[]};
const mt=new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:0});
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const n=(v:unknown)=>{const s=String(v??'').replace(/\s/g,'').replace(',','.');return s===''?NaN:Number(s);};

function useParam(token:string){
 const [data,setData]=useState<Data|null>(null),[error,setError]=useState(''),[loading,setLoading]=useState(true);
 useEffect(()=>{const c=new AbortController();setLoading(true);
  fetch(API+'/cis/parametrizacao',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:c.signal})
   .then(async r=>{const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.message||'Não foi possível carregar a parametrização.');return d;})
   .then(setData).catch(e=>{if(e.name!=='AbortError')setError(e.message);}).finally(()=>{if(!c.signal.aborted)setLoading(false);});
  return ()=>c.abort();},[token]);
 async function send(method:string,path:string,body:unknown){
  const r=await fetch(API+'/cis/parametrizacao'+path,{method,headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(body)});
  const d=await r.json().catch(()=>({}));
  if(r.status===422&&d.errors)return {errors:Object.fromEntries(Object.entries(d.errors).map(([k,v])=>[k,Array.isArray(v)?String(v[0]):String(v)])) as Record<string,string>};
  if(!r.ok)throw new Error(d.message||'Não foi possível guardar.');
  setData(d);return {data:d as Data};
 }
 return {data,error,loading,send};
}
const Notice=({data}:{data:Data})=>!data.editavel?<div className="cis-co-alert warn"><AlertTriangle size={17}/><span>Só consulta: falta activar a edição. Na VPS: <code>bash deploy/install-company-settings.sh</code>.</span></div>:null;

export function PriceTable({token}:{token:string}){
 const {data,error,loading,send}=useParam(token);
 const [rows,setRows]=useState<Faixa[]>([]),[errs,setErrs]=useState<Record<string,string>>({}),[saving,setSaving]=useState(false);
 const [probe,setProbe]=useState('');
 useEffect(()=>{if(data)setRows(data.precos.map(r=>({...r})));},[data]);
 const dirty=useMemo(()=>!!data&&JSON.stringify(rows)!==JSON.stringify(data.precos),[rows,data]);
 // Validação imediata: faixa invertida, sobreposição, sem taxa.
 const local=useMemo(()=>{const e:Record<number,string>={};const ord=rows.map((r,i)=>({i,de:n(r.de),ate:n(r.ate)})).filter(x=>!isNaN(x.de)&&!isNaN(x.ate)).sort((a,b)=>a.de-b.de);
  rows.forEach((r,i)=>{if(isNaN(n(r.de))||isNaN(n(r.ate)))e[i]='Indique o valor inicial e final.';else if(n(r.ate)<n(r.de))e[i]='O valor final é menor que o inicial.';
   else if(isNaN(n(r.taxa_15))&&isNaN(n(r.taxa_30)))e[i]='Indique pelo menos uma taxa.';});
  let best=ord[0];for(let k=1;k<ord.length;k++){if(ord[k].de<=best.ate)e[ord[k].i]=e[ord[k].i]??`Sobrepõe-se à faixa ${mt.format(best.de)}–${mt.format(best.ate)}.`;if(ord[k].ate>best.ate)best=ord[k];}
  return e;},[rows]);
 const sorted=useMemo(()=>rows.map((r,i)=>({r,i})).sort((a,b)=>(n(a.r.de)||0)-(n(b.r.de)||0)),[rows]);
 const upd=(i:number,k:keyof Faixa,v:string)=>{setRows(rs=>rs.map((r,j)=>j===i?{...r,[k]:v}:r));setErrs({});};
 const add=()=>{const last=Math.max(-1,...rows.map(r=>n(r.ate)).filter(x=>!isNaN(x)));setRows(rs=>[...rs,{de:String(last+1),ate:'',taxa_15:'',taxa_30:''}]);};
 async function save(){setSaving(true);
  try{const res=await send('PUT','/precos',{faixas:rows.map(r=>({de:n(r.de),ate:n(r.ate),taxa_15:r.taxa_15||null,taxa_30:r.taxa_30||null}))});
   if(res.errors){setErrs(res.errors);pushToast('erro','Corrija a tabela de preços.');}else pushToast('ok','Tabela de preços guardada.');}
  catch(e:any){pushToast('erro',e.message);}finally{setSaving(false);}}
 const pv=n(probe);const hit=!isNaN(pv)?rows.find(r=>n(r.de)<=pv&&pv<=n(r.ate)):undefined;
 if(loading)return <section className="cis-panel cis-co-section"><div className="cis-co-loading"><Loader2 size={18} className="cis-spin"/> A carregar…</div></section>;
 if(error||!data)return <section className="cis-panel cis-co-section"><div className="cis-co-alert bad"><AlertTriangle size={17}/>{error}</div></section>;
 const ro=!data.editavel;
 return <div className="cis-co">
  <Notice data={data}/>
  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>Tabela de preços</h3><p>Taxa de juro por faixa de montante e prazo. Usada nas simulações e nos contratos novos.</p></div>
    <div className="cis-co-used"><small>Usado em</small><span>Simulador</span><span>Novo contrato</span><span>Renovação</span></div></div>
   <div className="cis-co-alert info"><Info size={16}/><span>Os contratos já criados mantêm a taxa com que foram gravados. Deixe a taxa em branco quando o prazo não está disponível para essa faixa.</span></div>
   <div className="cis-price-wrap"><table className="cis-price">
    <thead><tr><th>De (MT)</th><th>Até (MT)</th><th className="num">15 dias</th><th className="num">30 dias</th><th aria-label="Acções"/></tr></thead>
    <tbody>{(()=>{let top=-Infinity;return sorted.map(({r,i},k)=>{const prevTop=top;if(!isNaN(n(r.ate)))top=Math.max(top,n(r.ate));
     const gap=k>0&&isFinite(prevTop)&&!isNaN(n(r.de))&&n(r.de)>prevTop+1;
     const e=local[i]||errs['faixas.'+i];
     return [gap&&<tr key={'g'+i} className="gap"><td colSpan={5}><AlertTriangle size={13}/> Sem preço entre {mt.format(prevTop+1)} e {mt.format(n(r.de)-1)} MT</td></tr>,
      <tr key={i} className={e?'err':''}>
       <td><input value={r.de} inputMode="numeric" disabled={ro} onChange={ev=>upd(i,'de',ev.target.value)} aria-label="De"/></td>
       <td><input value={r.ate} inputMode="numeric" disabled={ro} onChange={ev=>upd(i,'ate',ev.target.value)} aria-label="Até"/></td>
       {(['taxa_15','taxa_30'] as const).map(t=><td key={t} className="num"><span className="cis-co-input"><input value={r[t]??''} inputMode="decimal" placeholder="—" disabled={ro} onChange={ev=>upd(i,t,ev.target.value)} aria-label={t==='taxa_15'?'Taxa 15 dias':'Taxa 30 dias'}/><em>%</em></span></td>)}
       <td className="act">{!ro&&<button type="button" onClick={()=>setRows(rs=>rs.filter((_,j)=>j!==i))} aria-label="Remover faixa"><Trash2 size={15}/></button>}</td>
      </tr>,e&&<tr key={'e'+i} className="msg"><td colSpan={5}>{e}</td></tr>];});})()}</tbody>
   </table></div>
   {!ro&&<button type="button" className="cis-price-add" onClick={add}><Plus size={15}/> Adicionar faixa</button>}
   <div className="cis-price-probe"><Calculator size={16}/><label>Testar montante<span className="cis-co-input"><input value={probe} inputMode="decimal" placeholder="Ex.: 7500" onChange={e=>setProbe(e.target.value)}/><em>MT</em></span></label>
    {probe&&(hit?<span>{(['15','30'] as const).map(p=>{const t=n(hit[`taxa_${p}`]);return <b key={p}>{p} dias: {isNaN(t)?'sem preço':`${t}% → juros ${money.format(pv*t/100)} MT`}</b>;})}</span>:<span className="bad">Sem faixa para este montante.</span>)}</div>
  </section>
  {!ro&&<div className={'cis-co-savebar'+(dirty?' dirty':'')}>
   <span className="state">{dirty?<span className="warn">Alterações por guardar</span>:<span>{rows.length} faixa(s) · {data.historico.find(h=>h.tabela==='tabela_juros')?`última alteração por ${data.historico.find(h=>h.tabela==='tabela_juros')!.por??'—'}`:'sem alterações registadas'}</span>}</span>
   <button type="button" disabled={!dirty||saving} onClick={()=>{setRows(data.precos.map(r=>({...r})));setErrs({});}}><RotateCcw size={15}/> Descartar</button>
   <button type="button" className="primary" disabled={!dirty||saving||Object.keys(local).length>0} onClick={save}>{saving?<Loader2 size={15} className="cis-spin"/>:<Save size={15}/>} Guardar tabela</button>
  </div>}
 </div>;
}

function MovRow({m,grupos,ro,onSave}:{m:Mov;grupos:string[];ro:boolean;onSave:(b:Partial<Mov>)=>Promise<string|null>}){
 const [edit,setEdit]=useState(false),[name,setName]=useState(m.descricao),[err,setErr]=useState(''),[busy,setBusy]=useState(false);
 useEffect(()=>{setName(m.descricao);},[m.descricao]);
 const go=async(b:Partial<Mov>)=>{setBusy(true);const e=await onSave(b);setBusy(false);setErr(e??'');if(!e)setEdit(false);};
 return <li className={(m.activo?'':'off')+(err?' err':'')}>
  {edit?<span className="cis-mov-edit"><input value={name} maxLength={32} autoFocus onChange={e=>setName(e.target.value)} onKeyDown={e=>{if(e.key==='Enter')go({descricao:name});if(e.key==='Escape'){setEdit(false);setName(m.descricao);}}}/>
    <button type="button" onClick={()=>go({descricao:name})} aria-label="Guardar nome"><Check size={14}/></button><button type="button" onClick={()=>{setEdit(false);setName(m.descricao);setErr('');}} aria-label="Cancelar"><X size={14}/></button></span>
   :<span className="name">{m.protegido&&<Lock size={12} aria-label="Tipo base do sistema"/>}{m.descricao}{!ro&&!m.protegido&&<button type="button" className="pen" onClick={()=>setEdit(true)} aria-label={'Renomear '+m.descricao}><Pencil size={12}/></button>}</span>}
  <select value={m.grupo??''} disabled={ro||busy} onChange={e=>go({grupo:e.target.value})} aria-label={'Grupo de '+m.descricao}>{!m.grupo&&<option value="">Sem grupo</option>}{grupos.map(g=><option key={g}>{g}</option>)}</select>
  <button type="button" className={'cis-mov-toggle'+(m.activo?' on':'')} disabled={ro||busy||m.protegido} onClick={()=>go({activo:!m.activo})} aria-pressed={m.activo} title={m.protegido?'Tipo base: sempre activo':''}>{m.activo?'Activo':'Inactivo'}</button>
  {err&&<small>{err}</small>}
 </li>;
}

export function MovementTypes({token}:{token:string}){
 const {data,error,loading,send}=useParam(token);
 const [draft,setDraft]=useState<Record<string,{d:string;g:string;e:Record<string,string>}>>({});
 if(loading)return <section className="cis-panel cis-co-section"><div className="cis-co-loading"><Loader2 size={18} className="cis-spin"/> A carregar…</div></section>;
 if(error||!data)return <section className="cis-panel cis-co-section"><div className="cis-co-alert bad"><AlertTriangle size={17}/>{error}</div></section>;
 const ro=!data.editavel;
 const save=async(id:number,b:Partial<Mov>)=>{try{const r=await send('PUT','/tipos-movimento/'+id,b);if(r.errors)return Object.values(r.errors)[0];pushToast('ok','Tipo de movimento actualizado.');return null;}catch(e:any){return e.message;}};
 const create=async(tipo:string)=>{const f=draft[tipo]??{d:'',g:data.grupos[tipo][0],e:{}};
  try{const r=await send('POST','/tipos-movimento',{tipo_movimento:tipo,descricao:f.d,grupo:f.g||data.grupos[tipo][0]});
   if(r.errors){setDraft(x=>({...x,[tipo]:{...f,e:r.errors!}}));return;}setDraft(x=>({...x,[tipo]:{d:'',g:f.g,e:{}}}));pushToast('ok',`«${f.d}» adicionado.`);}catch(e:any){pushToast('erro',e.message);}};
 return <div className="cis-co">
  <Notice data={data}/>
  <section className="cis-panel cis-co-section">
   <div className="cis-co-section-head"><div><h3>Tipos de movimento</h3><p>Categorias das entradas e saídas da Caixa, organizadas por grupo.</p></div>
    <div className="cis-co-used"><small>Usado em</small><span>Caixa › Entrada / saída</span><span>Relatórios › Caixa</span></div></div>
   <div className="cis-co-alert info"><Info size={16}/><span>Tipos inactivos deixam de aparecer na Caixa, mas os movimentos antigos mantêm a categoria. <Lock size={12}/> Tipos base do sistema não podem ser renomeados nem desactivados.</span></div>
   <div className="cis-mov-cols">{(['Entradas','Saídas'] as const).map(tipo=>{const list=data.movimentos.filter(m=>m.tipo_movimento===tipo);const f=draft[tipo]??{d:'',g:data.grupos[tipo][0],e:{}};
    return <div key={tipo} className={'cis-mov-col '+(tipo==='Entradas'?'in':'out')}>
     <h4>{tipo==='Entradas'?<ArrowDownCircle size={17}/>:<ArrowUpCircle size={17}/>}{tipo}<span>{list.filter(m=>m.activo).length} activos</span></h4>
     {!ro&&<form className="cis-mov-add" onSubmit={e=>{e.preventDefault();create(tipo);}}>
      <input value={f.d} maxLength={32} placeholder={tipo==='Entradas'?'Novo tipo de entrada':'Novo tipo de saída'} onChange={e=>setDraft(x=>({...x,[tipo]:{...f,d:e.target.value,e:{}}}))} aria-label={'Nome do novo tipo de '+tipo}/>
      <select value={f.g} onChange={e=>setDraft(x=>({...x,[tipo]:{...f,g:e.target.value}}))} aria-label="Grupo">{data.grupos[tipo].map(g=><option key={g}>{g}</option>)}</select>
      <button type="submit" disabled={f.d.trim().length<2}><Plus size={15}/> Adicionar</button>
      {Object.values(f.e)[0]&&<small>{Object.values(f.e)[0]}</small>}</form>}
     {data.grupos[tipo].concat(list.some(m=>!m.grupo)?['']:[]).map(g=>{const items=list.filter(m=>(m.grupo??'')===g);if(!items.length)return null;
      return <div key={g||'none'} className="cis-mov-group"><span className="cis-mov-gname">{g||'Sem grupo'}</span>
       <ul>{items.map(m=><MovRow key={m.id} m={m} grupos={data.grupos[tipo]} ro={ro} onSave={b=>save(m.id,b)}/>)}</ul></div>;})}
    </div>;})}</div>
  </section>
 </div>;
}
