import {useEffect,useState} from 'react';
type Props={module:string;token:string};
type Pagination={data:Record<string,unknown>[];current_page:number;last_page:number;total:number};
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const columns:Record<string,string[]>={
 'Clientes':['ID','Nome Completo','Cidade','Provincia'],
 'Contratos e prestações':['Nr do Contracto','Nº da Parcela','Nº de Prestações','Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Estado']
};
export default function LegacyTable({module,token}:Props){
 const [page,setPage]=useState(1);const [result,setResult]=useState<Pagination|null>(null);
 const [message,setMessage]=useState('');const [loading,setLoading]=useState(false);
 const path=module==='Clientes'?'clientes':module==='Contratos e prestações'?'contractos':null;
 useEffect(()=>{setPage(1);setResult(null);},[module]);
 useEffect(()=>{if(!path)return;const controller=new AbortController();setLoading(true);setMessage('');
 fetch(API+'/cis/'+path+'?page='+page,{headers:{Authorization:'Bearer '+token},signal:controller.signal})
 .then(async r=>{if(r.status===403)throw new Error('O seu perfil não está autorizado a consultar todos os registos.');if(!r.ok)throw new Error('Não foi possível consultar a base de dados.');return r.json();})
 .then(setResult).catch(e=>{if(e.name!=='AbortError')setMessage(e.message);})
 .finally(()=>setLoading(false));
 return ()=>controller.abort();
 },[path,page,token]);
 if(!path)return <section className="panel module"><h2>{module}</h2><p>Operações ainda não disponíveis. A escrita permanece bloqueada até à validação financeira e de permissões.</p></section>;
 return <section className="panel module"><h2>{module}</h2><p>Consulta apenas de leitura da base MySQL existente.</p>
 {loading&&<div className="table-status">A carregar…</div>}{message&&<div className="table-status" role="alert">{message}</div>}
 {result&&<><div className="table-wrap"><table><thead><tr>{columns[module].map(c=><th key={c}>{c}</th>)}</tr></thead><tbody>{result.data.map((row,index)=><tr key={String(row.ID ?? row.Ordem ?? index)}>{columns[module].map(c=><td key={c}>{String(row[c]??'—')}</td>)}</tr>)}</tbody></table></div>
 <div className="pagination"><span>{result.total} registos · Página {result.current_page} de {result.last_page}</span><div><button disabled={page<=1} onClick={()=>setPage(p=>p-1)}>Anterior</button><button disabled={page>=result.last_page} onClick={()=>setPage(p=>p+1)}>Seguinte</button></div></div></>}
 </section>;
}
