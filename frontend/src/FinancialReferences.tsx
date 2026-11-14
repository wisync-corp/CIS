import {useEffect,useState} from 'react';
import {BookOpen,RefreshCw,AlertTriangle,ArrowDownCircle,ArrowUpCircle} from 'lucide-react';

type InterestRow={limite_inferior:number;limite_superior:number;taxa:string|number;periodo_referencia:number};
type MovementRow={tipo_movimento:'Entradas'|'Saídas';descricao:string};
type References={juros:InterestRow[];movimentos:MovementRow[];unidade_periodo_confirmada:boolean;consulta_apenas:boolean};
const API=import.meta.env.VITE_API_URL||'/api';
const number=new Intl.NumberFormat('pt-MZ',{maximumFractionDigits:2});

export default function FinancialReferences({token}:{token:string}){
 const [refs,setRefs]=useState<References|null>(null);
 const [error,setError]=useState('');
 const [loading,setLoading]=useState(true);
 const [reload,setReload]=useState(0);
 useEffect(()=>{
  const controller=new AbortController();setLoading(true);setError('');
  fetch(API+'/admin/financial-references',{
   headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal
  }).then(async response=>{
   if(!response.ok){
    const body=await response.json().catch(()=>null) as {message?:string}|null;
    throw new Error(body?.message??'Não foi possível consultar as parametrizações.');
   }
   return response.json() as Promise<References>;
  }).then(data=>{if(!controller.signal.aborted)setRefs(data);})
   .catch(e=>{if(e.name!=='AbortError')setError(e.message);})
   .finally(()=>{if(!controller.signal.aborted)setLoading(false);});
  return ()=>controller.abort();
 },[token,reload]);
 return <div className="cis-financial-references">
  <div className="cis-financial-references-heading"><h2><BookOpen size={20}/> Parametrizações financeiras</h2>
   <button type="button" onClick={()=>setReload(x=>x+1)}><RefreshCw size={16}/> Actualizar</button>
  </div>
  <p className="cis-financial-references-subtitle">Duas tabelas de referência independentes, transcritas da folha Definições do Excel original. Consulta exclusiva do administrador.</p>
  {loading&&<p role="status">A consultar as tabelas…</p>}
  {error&&<div className="cis-table-error" role="alert">{error}</div>}
  {refs&&<div className="cis-financial-reference-grid">
   <section className="cis-panel cis-financial-reference-section">
    <h3>Tabela de preços de juros</h3>
    <p>Montante mínimo e máximo (MT), taxa e período de 15 ou 30 dias, conforme a tabela original.</p>
    <div className="cis-contract-notice"><AlertTriangle size={17}/><span>Os períodos da tabela são 15 ou 30 dias. A taxa é consultada automaticamente no formulário de preparação de contratos com juro fixo, mas ainda não autoriza a emissão de contratos.</span></div>
    <div className="cis-table-scroll"><table>
     <thead><tr><th>Limite inferior (MT)</th><th>Limite superior (MT)</th><th>Taxa (%)</th><th>Período (dias)</th></tr></thead>
     <tbody>{refs.juros.map((row,index)=><tr key={index}>
      <td>{number.format(row.limite_inferior)}</td><td>{number.format(row.limite_superior)}</td>
      <td>{number.format(Number(row.taxa))}%</td><td>{row.periodo_referencia}</td>
     </tr>)}</tbody>
    </table></div>
   </section>
   <section className="cis-panel cis-financial-reference-section">
    <h3>Tipos de movimentos empresariais</h3>
    <p>Classificações de entradas e saídas usadas como referências para futuros movimentos do Extracto.</p>
    <div className="cis-table-scroll"><table>
     <thead><tr><th>Tipo de movimento</th><th>Descrição</th></tr></thead>
     <tbody>{refs.movimentos.map((row,index)=><tr key={index}>
      <td><span className={'cis-movement-type '+(row.tipo_movimento==='Entradas'?'incoming':'outgoing')}>
       {row.tipo_movimento==='Entradas'?<ArrowDownCircle size={15}/>:<ArrowUpCircle size={15}/>}
       {row.tipo_movimento}
      </span></td><td>{row.descricao}</td>
     </tr>)}</tbody>
    </table></div>
    <p>Estas categorias ainda não registam transacções nem modificam os movimentos existentes.</p>
   </section>
  </div>}
 </div>;
}
