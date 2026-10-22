import {useEffect,useState} from 'react';
import {Search,RefreshCw,ChevronLeft,ChevronRight,LockKeyhole,Database,AlertCircle} from 'lucide-react';

type Props={module:string;token:string};
type Row=Record<string,unknown>;
type Paged={data:Row[];current_page:number;last_page:number;total:number};
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const fields:Record<string,{key:string;label:string}[]>={
 'Clientes':[{key:'ID',label:'ID'},{key:'Nome Completo',label:'Nome completo'},{key:'Cidade',label:'Cidade'},{key:'Provincia',label:'Província'}],
 'Contratos e prestações':[{key:'Nr do Contracto',label:'Contrato'},{key:'Nº da Parcela',label:'Parcela'},{key:'Nº de Prestações',label:'Total prestações'},{key:'Amortização',label:'Amortização'},{key:'Valor de Juros',label:'Juros'},{key:'Valor de Multa',label:'Multa'},{key:'Valor a Pagar',label:'Valor a pagar'},{key:'Valor Pago',label:'Valor pago'},{key:'Estado',label:'Estado'}]
};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const monetary=new Set(['Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago']);
export default function LegacyTable({module,token}:Props){
 const endpoint=module==='Clientes'?'clientes':module==='Contratos e prestações'?'contractos':null;
 const [page,setPage]=useState(1),[search,setSearch]=useState(''),[term,setTerm]=useState('');
 const [data,setData]=useState<Paged|null>(null),[loading,setLoading]=useState(false),[error,setError]=useState(''),[refresh,setRefresh]=useState(0);
 useEffect(()=>{setPage(1);setTerm('');setSearch('');setData(null);},[module]);
 useEffect(()=>{if(!endpoint)return;const controller=new AbortController();setLoading(true);setError('');
 const query=new URLSearchParams({page:String(page)});if(term.trim())query.set('q',term.trim());
 fetch(API+'/cis/'+endpoint+'?'+query.toString(),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
 .then(async response=>{if(response.status===403)throw new Error('O seu perfil ainda não tem acesso a estes dados.');if(!response.ok)throw new Error('A consulta não está disponível. Confirme a ligação à API e à base de testes.');return response.json();})
 .then((result:Paged)=>setData(result)).catch(e=>{if(e.name!=='AbortError'){setData(null);setError(e.message);}})
 .finally(()=>{if(!controller.signal.aborted)setLoading(false);});
 return ()=>controller.abort();},[endpoint,token,page,term,refresh]);
 if(!endpoint)return <section className="cis-panel cis-module-empty"><LockKeyhole size={32}/><h2>{module}</h2><p>Este módulo ainda não está disponível para utilização. As operações de escrita estão bloqueadas até à validação das regras financeiras e de segurança.</p></section>;
 return <section className="cis-panel cis-list-panel">
  <div className="cis-panel-head"><div><h2>{module==='Clientes'?'Cadastro de clientes':'Carteira de contratos'}</h2><p>Informação existente na base de dados · consulta apenas de leitura</p></div><span className="cis-list-tag"><Database size={14}/> MySQL</span></div>
  <div className="cis-table-controls"><form onSubmit={e=>{e.preventDefault();setPage(1);setTerm(search);}}><Search size={18}/><input value={search} onChange={e=>setSearch(e.target.value)} placeholder={endpoint==='clientes'?'Pesquisar por nome ou ID':'Pesquisar por número de contrato'} aria-label="Pesquisar"/><button type="submit">Pesquisar</button></form><button type="button" className="cis-refresh" onClick={()=>setRefresh(n=>n+1)} aria-label="Actualizar"><RefreshCw size={18}/></button></div>
  {loading&&<div className="cis-loading" role="status">A consultar os registos…</div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{error}</div>}
  {!loading&&!error&&data&&<><div className="cis-table-scroll"><table><thead><tr>{fields[module].map(c=><th key={c.key}>{c.label}</th>)}</tr></thead><tbody>{data.data.map((row,i)=><tr key={String(row.ID??row.Ordem??i)}>{fields[module].map(c=><td key={c.key}>{monetary.has(c.key)&&row[c.key]!=null&&Number.isFinite(Number(row[c.key]))?money.format(Number(row[c.key]))+' MT':String(row[c.key]??'—')}</td>)}</tr>)}</tbody></table>{data.data.length===0&&<div className="cis-table-none">Não foram encontrados registos para esta pesquisa.</div>}</div><div className="cis-table-footer"><span>{data.total} registos · Página {data.current_page} de {data.last_page}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(p=>Math.max(1,p-1))}><ChevronLeft size={17}/> Anterior</button><button type="button" disabled={page>=data.last_page} onClick={()=>setPage(p=>p+1)}>Seguinte <ChevronRight size={17}/></button></div></div></>}
 </section>;
}
