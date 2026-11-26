// Incumprimento prolongado: ao entrar, propõe transferir para a lista negra quem passa do critério interno.
import {useMemo,useState} from 'react';
import {createPortal} from 'react-dom';
import {Ban,AlertTriangle,CheckCircle2,X} from 'lucide-react';

const API=import.meta.env.VITE_API_URL||'/api';
export type Candidate={id:number;nome:string;dias:number;contratos:number;prestacoes:number;valor_atraso:number;valor_divida:number};
export type Candidates={instalado:boolean;pode_decidir:boolean;dias:number;propor_ao_entrar?:boolean;candidatos:Candidate[];total_atraso?:number;total_divida?:number};
const mt=(v:number)=>v.toLocaleString('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2})+' MT';

export default function LongDelinquency({token,data,onClose,onDone}:{token:string;data:Candidates;onClose:()=>void;onDone:(msg:string)=>void}){
 const [sel,setSel]=useState<Set<number>>(()=>new Set(data.candidatos.map(c=>c.id)));
 const [busy,setBusy]=useState(false),[error,setError]=useState('');
 const chosen=useMemo(()=>data.candidatos.filter(c=>sel.has(c.id)),[sel,data]);
 const all=sel.size===data.candidatos.length;
 async function transfer(){
  setBusy(true);setError('');
  try{const r=await fetch(API+'/cis/lista-negra/automatica',{method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({clientes:[...sel]})});
   const j=await r.json().catch(()=>({})) as {notice?:string;message?:string};
   if(!r.ok)throw new Error(j.message||'Não foi possível transferir.');
   onDone(j.notice??'Transferência concluída.');}
  catch(e){setError((e as Error).message);}finally{setBusy(false);}
 }
 return createPortal(<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-labelledby="cis-ld-title">
  <div className="cis-panel cis-users-form cis-ld">
   <div className="cis-users-form-head"><h3 id="cis-ld-title"><AlertTriangle size={19}/> Incumprimento prolongado</h3><button type="button" aria-label="Fechar" onClick={onClose}><X size={17}/></button></div>
   <p><b>{data.candidatos.length} mutuário(s)</b> têm prestações em atraso há <b>mais de {data.dias} dias</b> (critério interno em Definições › Cobrança).
    Em atraso: <b>{mt(data.total_atraso??0)}</b> · dívida em aberto: <b>{mt(data.total_divida??0)}</b>.</p>
   <p className="cis-ld-q">Quer transferi-los para a <b>lista negra</b>?</p>
   <ul className="cis-ld-effects">
    <li>Motivo registado: <i>«Incumprimento prolongado: N dias de atraso no pagamento (critério interno: mais de {data.dias} dias)»</i>.</li>
    <li>Saem da carteira corrente; o crédito passa a aparecer em <b>«Crédito em lista negra»</b> (Visão geral e Dashboard).</li>
    <li>Deixam de poder receber crédito ou ser avalistas. A cobrança continua e podem voltar a «Padrão» na ficha.</li>
   </ul>
   <div className="cis-table-scroll cis-ld-table"><table><thead><tr>
    <th><input type="checkbox" aria-label="Seleccionar todos" checked={all} onChange={e=>setSel(e.target.checked?new Set(data.candidatos.map(c=>c.id)):new Set())}/></th>
    <th>Mutuário</th><th>Dias de atraso</th><th>Contratos</th><th>Em atraso</th><th>Dívida em aberto</th></tr></thead>
    <tbody>{data.candidatos.map(c=><tr key={c.id} className={sel.has(c.id)?'':'off'}>
     <td><input type="checkbox" aria-label={'Seleccionar '+c.nome} checked={sel.has(c.id)} onChange={e=>setSel(s=>{const n=new Set(s);if(e.target.checked)n.add(c.id);else n.delete(c.id);return n;})}/></td>
     <td><b>{c.nome}</b><small>#{c.id}</small></td><td><span className="cis-ld-days">{c.dias} dias</span></td><td>{c.contratos}</td><td>{mt(c.valor_atraso)}</td><td>{mt(c.valor_divida)}</td></tr>)}</tbody></table></div>
   {error&&<p className="cis-pw-error" role="alert"><AlertTriangle size={15}/> {error}</p>}
   <div className="cis-pw-actions">
    <button type="button" className="danger" disabled={busy||sel.size===0} onClick={()=>void transfer()}><Ban size={15}/> {busy?'A transferir…':`Sim, transferir ${sel.size} para a lista negra`}</button>
    <button type="button" onClick={onClose}>Agora não</button>
    <span className="cis-ld-sum"><CheckCircle2 size={14}/> {chosen.length} seleccionado(s) · {mt(chosen.reduce((a,c)=>a+c.valor_divida,0))}</span>
   </div>
  </div></div>,document.body);
}
