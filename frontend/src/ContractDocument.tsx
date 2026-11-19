import {useEffect,useState} from 'react';
import {createPortal} from 'react-dom';
import {Printer,X,Loader2,AlertTriangle,FileSignature} from 'lucide-react';
import CompanyBrand,{PoweredBy} from './CompanyBrand';
import {useCompanyProfile} from './company';
import {ContractBody,usedVariables,type ContractValues} from './contractTemplate';

const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
type Modelo={id:number;nome:string;corpo:string;padrao:boolean|number};

/** Ficha do contrato › Documento do contrato: gera o contrato a partir do modelo escolhido. */
export default function ContractDocument({token,numero}:{token:string;numero:string}){
 const company=useCompanyProfile(token);
 const [modelos,setModelos]=useState<Modelo[]|null>(null),[mid,setMid]=useState<number|null>(null);
 const [vals,setVals]=useState<ContractValues|null>(null),[error,setError]=useState(''),[open,setOpen]=useState(false);
 useEffect(()=>{const H={Authorization:'Bearer '+token,Accept:'application/json'};setError('');
  Promise.all([fetch(API+'/cis/contratos/modelos',{headers:H}),fetch(API+'/cis/contractos/'+encodeURIComponent(numero)+'/variaveis-contrato',{headers:H})])
   .then(async([a,b])=>{const da=await a.json().catch(()=>({}));const db=await b.json().catch(()=>({}));
    if(!a.ok)throw new Error(da.message||'Modelos de contrato indisponíveis.');if(!b.ok)throw new Error(db.message||'Dados do contrato indisponíveis.');
    setModelos(da.modelos);setMid((da.modelos.find((m:Modelo)=>m.padrao)??da.modelos[0])?.id??null);setVals(db);})
   .catch(e=>setError(e.message));},[token,numero]);
 const m=modelos?.find(x=>x.id===mid);
 const missing=m&&vals?usedVariables(m.corpo,Object.keys(vals.valores)).used.filter(v=>v in vals.valores&&!vals.valores[v]):[];
 function print(){const prev=document.title;document.title=`Contrato ${vals?.contrato??numero}`;document.body.classList.add('cis-printing-evidence');
  const done=()=>{document.body.classList.remove('cis-printing-evidence');document.title=prev;window.removeEventListener('afterprint',done);};
  window.addEventListener('afterprint',done);window.print();setTimeout(done,1500);}
 if(error)return <div className="cis-co-alert warn"><AlertTriangle size={16}/><span>{error}</span></div>;
 if(!modelos||!vals)return <div className="cis-co-loading"><Loader2 size={16} className="cis-spin"/> A preparar o contrato…</div>;
 if(!m)return <div className="cis-co-alert warn"><AlertTriangle size={16}/><span>Ainda não há modelos de contrato. Crie um em Definições › Modelos de contrato.</span></div>;
 return <div className="cis-ct-gen">
  <div className="cis-ct-genbar">
   <label>Modelo<select value={mid??''} onChange={e=>setMid(Number(e.target.value))}>{modelos.map(x=><option key={x.id} value={x.id}>{x.nome}{x.padrao?' (padrão)':''}</option>)}</select></label>
   <button type="button" className="primary" onClick={()=>setOpen(true)}><FileSignature size={16}/> Gerar contrato</button>
  </div>
  {missing.length>0&&<div className="cis-co-alert warn"><AlertTriangle size={16}/><span>Dados em falta neste contrato (ficam em branco para preencher à mão): <b>{missing.join(', ')}</b>. Pode completá-los na ficha do mutuário ou nas Definições.</span></div>}
  <div className="cis-ct-paper small"><ContractBody body={m.corpo} values={vals.valores} plano={vals.plano} companyName={vals.valores['Nome da Empresa']}/></div>
  {open&&createPortal(<div className="cis-evidence-overlay" role="dialog" aria-modal="true" aria-label="Contrato">
   <div className="cis-evidence-bar"><b>Contrato {vals.contrato}</b><span>Use «Imprimir / Guardar PDF» e escolha «Guardar como PDF».</span>
    <button type="button" className="primary" onClick={print}><Printer size={15}/> Imprimir / Guardar PDF</button>
    <button type="button" onClick={()=>setOpen(false)} aria-label="Fechar"><X size={16}/></button></div>
   <article className="cis-evidence cis-ct-print">
    <header><CompanyBrand company={company}/><div><p>Contrato n.º <b>{vals.contrato}</b></p><p>{vals.valores['Data de Hoje']}</p></div></header>
    <ContractBody body={m.corpo} values={vals.valores} plano={vals.plano} mode="print" companyName={vals.valores['Nome da Empresa']}/>
    <footer><span>Documento gerado pelo CIS a partir do modelo «{m.nome}». Valores em Meticais (MT).</span><PoweredBy/></footer>
   </article></div>,document.body)}
 </div>;
}
