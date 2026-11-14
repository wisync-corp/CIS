import {useEffect,useState} from 'react';
import {Search,RefreshCw,ChevronLeft,ChevronRight,LockKeyhole,Database,AlertCircle,ArrowUpRight,ArrowDownUp,ChevronUp,ChevronDown,Filter} from 'lucide-react';
import ClientProfile from './ClientProfile';
import ContractProfile from './ContractProfile';
import ContractDraft from './ContractDraft';

type Props={module:string;token:string;onProfileChange?:(isOpen:boolean)=>void};
type Row=Record<string,unknown>;
type Paged={data:Row[];current_page:number;last_page:number;total:number};
type ContractSummary={total:number;por_estado:{estado:string;total:number}[]};
const API=import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const fields:Record<string,{key:string;label:string}[]>={
 'Clientes':[{key:'ID',label:'ID'},{key:'Nome Completo',label:'Nome completo'},{key:'Cidade',label:'Cidade'},{key:'Provincia',label:'Província'}],
 'Extractos':[{key:'Data da Operação',label:'Data'},{key:'Contracto',label:'Contrato'},{key:'Parcela',label:'Parcela'},{key:'Tipo de Movimento',label:'Tipo de movimento'},{key:'Descrição',label:'Descrição'},{key:'Valor',label:'Valor'}],
 'Contratos e prestações':[{key:'numero',label:'N.º do contrato'},{key:'cliente',label:'Mutuário'},{key:'cliente_id',label:'ID do cliente'},{key:'linhas_prestacoes',label:'Prestações registadas'},{key:'estado',label:'Estado do contrato'}]
};
const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const monetary=new Set(['Valor','Amortização','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago']);
export default function LegacyTable({module,token,onProfileChange}:Props){
 const endpoint=module==='Clientes'?'clientes':module==='Contratos e prestações'?'contractos':module==='Extractos'?'extracto':null;
 const [selected,setSelected]=useState<number|null>(null);
 const [selectedContract,setSelectedContract]=useState<string|null>(null);
 const [contractDraftOpen,setContractDraftOpen]=useState(false);
 useEffect(()=>{onProfileChange?.((module==='Clientes'&&selected!==null)||(module==='Contratos e prestações'&&(selectedContract!==null||contractDraftOpen)));return ()=>onProfileChange?.(false);},[module,selected,selectedContract,contractDraftOpen,onProfileChange]);
 const [page,setPage]=useState(1),[search,setSearch]=useState(''),[term,setTerm]=useState('');
 const [contractStates,setContractStates]=useState<string[]>([]);
 const [contractSummary,setContractSummary]=useState<ContractSummary|null>(null);
 const [summaryError,setSummaryError]=useState('');
 const [stateFilter,setStateFilter]=useState('');
 const [contractSort,setContractSort]=useState('numero');
 const [sortDirection,setSortDirection]=useState<'asc'|'desc'>('asc');
 const [stateError,setStateError]=useState('');
 const contractSortTypes:Record<string,string>={numero:'texto',cliente:'texto',cliente_id:'identificador',linhas_prestacoes:'número',estado:'texto'};
 function toggleContractSort(field:string){
  if(endpoint!=='contractos')return;
  setPage(1);
  if(contractSort===field)setSortDirection(previous=>previous==='asc'?'desc':'asc');
  else{setContractSort(field);setSortDirection('asc');}
 }
 const [startDate,setStartDate]=useState(''),[endDate,setEndDate]=useState(''),[appliedDates,setAppliedDates]=useState({inicio:'',fim:''});
 const [data,setData]=useState<Paged|null>(null),[loading,setLoading]=useState(false),[error,setError]=useState(''),[refresh,setRefresh]=useState(0);
 useEffect(()=>{
  if(endpoint!=='contractos')return;
  const controller=new AbortController();
  setContractSummary(null);setSummaryError('');
  fetch(API+'/cis/contractos/resumo',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
   .then(async res=>{if(!res.ok)throw new Error('Não foi possível carregar o resumo de contratos.');return res.json() as Promise<ContractSummary>;})
   .then(result=>{if(!controller.signal.aborted)setContractSummary(result);})
   .catch(e=>{if(e.name!=='AbortError')setSummaryError(e.message);});
  return ()=>controller.abort();
 },[endpoint,token,refresh]);
 useEffect(()=>{
  if(endpoint!=='contractos')return;
  const controller=new AbortController();setStateError('');
  fetch(API+'/cis/contractos/estados',{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
   .then(async response=>{if(!response.ok)throw new Error('Não foi possível carregar os estados registados no MySQL.');return response.json() as Promise<{states:string[]}>;})
   .then(body=>setContractStates(Array.isArray(body.states)?body.states:[]))
   .catch(e=>{if(e.name!=='AbortError')setStateError(e.message);});
  return ()=>controller.abort();
 },[endpoint,token,refresh]);

 useEffect(()=>{setSelected(null);setSelectedContract(null);setContractDraftOpen(false);setPage(1);setTerm('');setSearch('');setStateFilter('');setContractSort('numero');setSortDirection('asc');setData(null);setStartDate('');setEndDate('');setAppliedDates({inicio:'',fim:''});},[module]);
 useEffect(()=>{
  if(!endpoint)return;
  const timer=window.setTimeout(()=>{const next=search.trim();if(next!==term){setPage(1);setTerm(next);}},350);
  return ()=>window.clearTimeout(timer);
 },[search,endpoint,term]);
 useEffect(()=>{
  if(!endpoint||selected!==null||selectedContract!==null||contractDraftOpen)return;
  const controller=new AbortController();setLoading(true);setError('');
  const query=new URLSearchParams({page:String(page)});
  if(term.trim())query.set(endpoint==='extracto'?'contracto':'q',term.trim());
  if(endpoint==='extracto'){if(appliedDates.inicio)query.set('inicio',appliedDates.inicio);if(appliedDates.fim)query.set('fim',appliedDates.fim);}
  if(endpoint==='contractos'){if(stateFilter)query.set('estado',stateFilter);query.set('sort',contractSort);query.set('direction',sortDirection);}
  fetch(API+'/cis/'+endpoint+'?'+query.toString(),{headers:{Authorization:'Bearer '+token,Accept:'application/json'},signal:controller.signal})
   .then(async response=>{
    if(response.status===403)throw new Error('O seu perfil não tem acesso a estes dados.');
    if(response.status===503)throw new Error('Esta consulta está bloqueada neste ambiente.');
    if(!response.ok)throw new Error('Não foi possível consultar o cadastro.');
    return response.json();
   })
   .then((result:Paged)=>setData(result)).catch(e=>{if(e.name!=='AbortError'){setData(null);setError(e.message);}})
   .finally(()=>{if(!controller.signal.aborted)setLoading(false);});
  return ()=>controller.abort();
 },[endpoint,token,page,term,appliedDates,refresh,selected,selectedContract,contractDraftOpen,stateFilter,contractSort,sortDirection]);
 if(endpoint==='clientes'&&selected!==null)return <ClientProfile id={selected} token={token} onBack={()=>{setSelected(null);setRefresh(v=>v+1);}}/>;
 if(endpoint==='contractos'&&contractDraftOpen)return <ContractDraft token={token} onBack={()=>setContractDraftOpen(false)}/>;
 if(endpoint==='contractos'&&selectedContract!==null)return <ContractProfile numero={selectedContract} token={token} onBack={()=>{setSelectedContract(null);setRefresh(v=>v+1);}}/>;
 if(!endpoint)return <section className="cis-panel cis-module-empty"><LockKeyhole size={32}/><h2>{module}</h2><p>Este módulo ainda não está disponível. As operações financeiras continuam sujeitas a validação.</p></section>;
 return <section className="cis-panel cis-list-panel">
  <div className="cis-panel-head"><div><h2>{module==='Clientes'?'Cadastro de clientes':module==='Extractos'?'Movimentos do extracto':'Carteira de contratos'}</h2><p>Consulta da base MySQL · acesso condicionado ao perfil</p></div><div className="cis-contract-head-actions">{endpoint==='contractos'&&<button className="cis-contract-draft-button" type="button" onClick={()=>setContractDraftOpen(true)}>Preparar contrato <ArrowUpRight size={16}/></button>}<span className="cis-list-tag"><Database size={14}/> MySQL</span></div></div>
  {endpoint==='contractos'&&contractSummary&&<div className="cis-contract-summary" aria-label="Resumo dos contratos por estado">
   <button type="button" className={'cis-contract-summary-card '+(stateFilter===''?'selected':'')} aria-pressed={stateFilter===''} onClick={()=>{setStateFilter('');setPage(1);}}>
    <span>Todos os contratos</span><strong>{contractSummary.total.toLocaleString('pt-MZ')}</strong><small>Contratos distintos</small>
   </button>
   {contractSummary.por_estado.map(({estado,total})=><button key={estado} type="button"
    className={'cis-contract-summary-card '+(stateFilter===estado?'selected':'')+(estado==='Estados divergentes'?' warning':'')}
    aria-pressed={stateFilter===estado} onClick={()=>{setStateFilter(estado);setPage(1);}}>
     <span>{estado}</span><strong>{total.toLocaleString('pt-MZ')}</strong><small>{estado==='Estados divergentes'?'Revisão necessária':'Filtrar contratos'}</small>
    </button>)}
  </div>}
  {endpoint==='contractos'&&summaryError&&<div className="cis-table-error" role="alert">{summaryError}</div>}
  <div className="cis-table-controls"><form onSubmit={e=>{e.preventDefault();if(endpoint==='extracto'&&startDate&&endDate&&startDate>endDate){setError('A data inicial não pode ser posterior à data final.');return;}setPage(1);setTerm(search.trim());setAppliedDates({inicio:startDate,fim:endDate});}}><Search size={18}/><input value={search} onChange={e=>setSearch(e.target.value)} placeholder={endpoint==='clientes'?'Digite ou digitalize o nome ou ID':endpoint==='extracto'?'Digite ou digitalize o contrato':'Pesquise contrato, cliente ou ID (automático)'} aria-label="Pesquisa automática" autoComplete="off"/><span className="cis-auto-search-hint">Pesquisa automática</span></form><button type="button" className="cis-refresh" onClick={()=>setRefresh(n=>n+1)} aria-label="Actualizar"><RefreshCw size={18}/></button></div>
  {endpoint==='contractos'&&<div className="cis-contract-filters">
   <label htmlFor="cis-contract-state"><Filter size={17}/> Estado do contrato</label>
   <select id="cis-contract-state" value={stateFilter} onChange={e=>{setStateFilter(e.target.value);setPage(1);}} aria-label="Filtrar pelo estado do contrato">
    <option value="">Todos os estados</option>
    {contractStates.map(state=><option key={state} value={state}>{state}</option>)}
   </select>
   {(stateFilter||term)&&<button className="cis-clear-contract-filters" type="button" onClick={()=>{setStateFilter('');setSearch('');setTerm('');setPage(1);}}>Limpar filtros</button>}
   <span className="cis-contract-filters-note">Estados originais do MySQL · ordenação aplicada a todos os resultados</span>
  </div>}
  {endpoint==='contractos'&&stateError&&<div className="cis-table-error" role="alert">{stateError}</div>}
  {endpoint==='extracto'&&<div className="cis-extract-dates"><label>De <input type="date" value={startDate} onChange={e=>setStartDate(e.target.value)}/></label><label>Até <input type="date" value={endDate} onChange={e=>setEndDate(e.target.value)}/></label><span>Para aplicar datas, prima Enter no campo de pesquisa.</span></div>}
  {loading&&<div className="cis-loading" role="status">A consultar os registos…</div>}
  {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{error}</div>}
  {!loading&&!error&&data&&<><div className="cis-table-scroll"><table><thead><tr>{fields[module].map(c=><th key={c.key} aria-sort={endpoint==='contractos'&&contractSort===c.key?(sortDirection==='asc'?'ascending':'descending'):undefined}>{endpoint==='contractos'?<button className="cis-contract-sort" type="button" onClick={()=>toggleContractSort(c.key)} title={`Ordenar por ${c.label} (${contractSortTypes[c.key]??'texto'})`} aria-label={`Ordenar por ${c.label}, ${contractSortTypes[c.key]??'texto'}, ${contractSort===c.key?(sortDirection==='asc'?'actualmente crescente':'actualmente decrescente'):'clique para crescente'}`}>{c.label}{contractSort===c.key?(sortDirection==='asc'?<ChevronUp size={15}/>:<ChevronDown size={15}/>):<ArrowDownUp size={14}/>}</button>:c.label}</th>)}{(endpoint==='clientes'||endpoint==='contractos')&&<th>Ficha</th>}</tr></thead><tbody>{data.data.map((row,i)=><tr key={String(row.ID??row.Ordem??row.Nr??i)}>{fields[module].map(c=><td key={c.key}>{monetary.has(c.key)&&row[c.key]!=null&&Number.isFinite(Number(row[c.key]))?money.format(Number(row[c.key]))+' MT':String(row[c.key]??'—')}</td>)}{endpoint==='clientes'&&<td><button className="cis-edit-action" type="button" onClick={()=>setSelected(Number(row.ID))}>Abrir ficha <ArrowUpRight size={14}/></button></td>}{endpoint==='contractos'&&<td><button className="cis-edit-action" type="button" onClick={()=>setSelectedContract(String(row.numero??''))}>Abrir contrato <ArrowUpRight size={14}/></button></td>}</tr>)}</tbody></table>{data.data.length===0&&<div className="cis-table-none">Não foram encontrados registos para esta pesquisa.</div>}</div><div className="cis-table-footer"><span>{data.total} registos · Página {data.current_page} de {data.last_page}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(p=>Math.max(1,p-1))}><ChevronLeft size={17}/> Anterior</button><button type="button" disabled={page>=data.last_page} onClick={()=>setPage(p=>p+1)}>Seguinte <ChevronRight size={17}/></button></div></div></>}
 </section>;
}
