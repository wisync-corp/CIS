import CompanyBrand,{PoweredBy} from './CompanyBrand';
import {useCompanyProfile} from './company';
import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Search,Download,Printer,FileText,X,AlertCircle,Info,MessageSquare,Phone,ChevronLeft,ChevronRight,ArrowUpRight,ArrowDownRight,Wallet,CalendarDays,ShieldCheck} from 'lucide-react';
import type {ExtractoPreset} from './Overview';
import MessageCenter from './MessageCenter';
import {situacaoContrato} from './glossario';

const API=import.meta.env.VITE_API_URL||'/api';
type Mode='geral'|'clientes';
type Field={key:string;label:string;format?:'date'|'money'|'direction';internal?:boolean};
const fields:Record<Mode,Field[]>={
 geral:[
  {key:'codigo',label:'Referência da operação'},{key:'data',label:'Data do movimento',format:'date'},
  {key:'descricao',label:'Descrição do movimento'},{key:'conta',label:'Conta financeira'},
  {key:'tipo',label:'Natureza do movimento',format:'direction'},{key:'valor',label:'Montante (MT)',format:'money'},
  {key:'contrato',label:'Número do contrato'},{key:'cliente',label:'Mutuário'},
  {key:'parcela',label:'Número da prestação'},{key:'usuario',label:'Operador do registo',internal:true},
 ],
 clientes:[
  {key:'codigo',label:'Referência do registo'},{key:'contrato',label:'Número do contrato'},
  {key:'cliente',label:'Mutuário',internal:true},{key:'parcela',label:'Número da prestação'},
  {key:'inicio',label:'Início da prestação',format:'date'},{key:'vencimento',label:'Data de vencimento',format:'date'},
  {key:'dias',label:'Dias até ao vencimento'},{key:'forma_pagamento',label:'Modalidade de pagamento'},
  {key:'capital',label:'Capital concedido (MT)',format:'money'},{key:'juros',label:'Juros contratuais (MT)',format:'money'},
  {key:'multa',label:'Juros de mora (MT)',format:'money'},{key:'valor',label:'Valor em dívida ou pago (MT)',format:'money'},
  {key:'valor_previsto',label:'Montante previsto (MT)',format:'money'},{key:'valor_recebido',label:'Montante recebido (MT)',format:'money'},
  {key:'data_pagamento',label:'Data do pagamento',format:'date'},{key:'estado',label:'Situação da prestação'},
 ],
};
const defaults:Record<Mode,string[]>={
 geral:['codigo','data','descricao','conta','valor'],
 clientes:['codigo','contrato','cliente','parcela','vencimento','dias','forma_pagamento','capital','juros','valor','estado'],
};
type GeralRow={nr:number;codigo:string;descricao:string;data:string;tipo:'entrada'|'saida';valor:string;contrato:string|null;cliente:string|null;parcela:string|null;conta:string|null;usuario:string|null};
type ClienteRow={ordem:number;codigo:string;contrato:string;cliente:string;parcela:number|string;inicio:string;vencimento:string;dias:number;forma_pagamento:string|null;capital:string;juros:string;multa:string;valor:string;data_pagamento:string|null;situacao:'aberta'|'vencida'|'paga'|'outra';estado:string};
type GeralResp={linhas:GeralRow[];pagina:number;por_pagina:number;total_linhas:number;totais:{entradas:string;saidas:string;disponivel:string}};
type ClientesTot={aberto:string;vencido:string;pago:string;juros_aberto:string;juros_vencido:string;juros_pago:string;n_aberto:number;n_vencido:number;n_pago:number};
type ClientesResp={linhas:ClienteRow[];pagina:number;por_pagina:number;total_linhas:number;totais:ClientesTot};
type Evidence={titulo:string;emitido_em:string;emitido_por:string|null;periodo:{inicio:string|null;fim:string|null};
 cliente:{nome:string;id:number|null;numeracao?:string;bi?:string;contacto?:string;morada?:string};
 contratos:{contrato:string;capital:string;inicio:string;prestacoes:number;em_divida:string;pago:string;situacao:string}[];
 prestacoes:Omit<ClienteRow,'cliente'|'situacao'>[];
 movimentos:{codigo:string;data:string;descricao:string;contrato:string;parcela:string|null;tipo:string;valor:string}[];
 totais:ClientesTot;codigo_verificacao:string};
type Account={id:number;code:string;name:string};
type Informacao={contrato:string;data_inicio:string|null;data_final:string|null;quantia_creditada:string;forma_pagamento:string|null;situacao:'Pendente'|'Pagamento atrasado'|'Finalizado';
 prestacoes:{total:number;pagas:number;em_aberto:number;vencidas:number};em_divida:string;pago:string;proximo_vencimento:string|null;dias_atraso:number;
 cliente:{id:number|null;codigo:string|null;nome:string;contacto:string|null}};
const phoneDigits=(c:string|null)=>{const d=(c??'').replace(/\D/g,'');if(!d)return '';if(d.length===9&&/^[82]/.test(d))return '258'+d;return d.replace(/^00/,'');};
type GeralEvidence={titulo:string;emitido_em:string;emitido_por:string|null;
 filtros:{inicio:string|null;fim:string|null;cliente:string|null;contrato:string|null;conta:string|null;tipo:string};
 linhas:{codigo:string;descricao:string;data:string;tipo:'entrada'|'saida';valor:string;contrato:string|null;cliente:string|null;parcela:string|null;conta:string|null}[];
 total_linhas:number;totais:{entradas:string;saidas:string;disponivel:string};codigo_verificacao:string};

const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const mt=(v:string|number|null|undefined)=>v===null||v===undefined||v===''?'—':money.format(Number(v));
const dt=(v:string|null|undefined)=>{if(!v)return '—';const [y,m,d]=v.slice(0,10).split('-');return d&&m&&y?`${d}/${m}/${y}`:v;};
const csvCell=(v:unknown)=>{const s=v===null||v===undefined?'':String(v);return /[";\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;};

function fieldValue(row:object,column:Field,csv=false){
 const value=(row as Record<string,string|number|null|undefined>)[column.key];
 if(value===null||value===undefined||value==='')return csv?'':'—';
 if(column.format==='date')return dt(String(value));
 if(column.format==='money')return csv?String(value).replace('.',','):mt(value);
 if(column.format==='direction')return value==='entrada'?'Entrada':'Saída';
 return value;
}

export default function ExtractoDesk({token,role,preset,onClearPreset,onOpenContracts}:{token:string;role:string;preset?:ExtractoPreset|null;onClearPreset?:()=>void;onOpenContracts:(numero?:string)=>void}){
 const company=useCompanyProfile(token);
 const isAdmin=role==='administrador';
 const [mode,setMode]=useState<Mode>(preset?'clientes':isAdmin?'geral':'clientes');
 const [period,setPeriod]=useState<ExtractoPreset|null>(preset??null);
 const [inicio,setInicio]=useState('');const [fim,setFim]=useState('');
 const [cliente,setCliente]=useState('');const [clienteInput,setClienteInput]=useState('');
 const [suggestions,setSuggestions]=useState<{nome:string;id:number|null;contratos:number}[]>([]);
 const [contratos,setContratos]=useState<string[]>([]);const [contrato,setContrato]=useState('');
 const [tipo,setTipo]=useState('todos');const [estado,setEstado]=useState<string>(preset?.estado??'todos');
 const [contaId,setContaId]=useState('');const [accounts,setAccounts]=useState<Account[]>([]);
 const [page,setPage]=useState(1);
 const [selection,setSelection]=useState(defaults);
 const [directions,setDirections]=useState<Record<Mode,'asc'|'desc'>>({geral:'desc',clientes:'asc'});
 const direction=directions[mode];
 const visible=fields[mode].filter(column=>selection[mode].includes(column.key));
 function pdfFields(m:Mode){
  const list=fields[m].filter(column=>!column.internal&&selection[m].includes(column.key));
  return list.length?list:fields[m].slice(0,1);
 }
 const [geral,setGeral]=useState<GeralResp|null>(null);const [clientes,setClientes]=useState<ClientesResp|null>(null);
 const [loading,setLoading]=useState(false);const [error,setError]=useState('');
 const [exporting,setExporting]=useState(false);
 const [evidence,setEvidence]=useState<Evidence|null>(null);
 const [info,setInfo]=useState<Informacao|null>(null);const [infoBusy,setInfoBusy]=useState('');const [infoError,setInfoError]=useState('');
 const [msgContract,setMsgContract]=useState<string|null>(null);const [geralDoc,setGeralDoc]=useState<GeralEvidence|null>(null);const [evidenceBusy,setEvidenceBusy]=useState(false);
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 const suggestTimer=useRef<number|undefined>(undefined);

 const params=(extra:Record<string,string>={})=>{const p=new URLSearchParams({direccao:direction});
  if(inicio)p.set('inicio',inicio);if(fim)p.set('fim',fim);if(cliente)p.set('cliente',cliente);if(contrato)p.set('contrato',contrato);
  if(mode==='geral'){if(tipo!=='todos')p.set('tipo',tipo);if(contaId)p.set('conta_id',contaId);}else{if(estado!=='todos')p.set('estado',estado);if(period){p.set('mes',period.mes);p.set('ano',period.ano);p.set('base',period.base);}}
  Object.entries(extra).forEach(([k,v])=>p.set(k,v));return p;};
 const datesOk=!(inicio&&fim&&fim<inicio);

 async function getJson<T>(path:string,signal?:AbortSignal):Promise<T>{
  const r=await fetch(API+path,{headers,signal});const j=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error((j as {message?:string}).message||(r.status===429?'Muitos pedidos seguidos; aguarde um minuto.':'Não foi possível consultar o extracto.'));
  return j as T;
 }

 useEffect(()=>{if(!isAdmin)return;const c=new AbortController();
  getJson<{accounts?:Account[]}>('/cis/caixa/contas',c.signal).then(j=>setAccounts(j.accounts??[])).catch(()=>{});return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[token,isAdmin]);

 useEffect(()=>{
  if(!datesOk){setError('A data final tem de ser igual ou posterior à data inicial.');return;}
  const c=new AbortController();setLoading(true);setError('');
  getJson<GeralResp|ClientesResp>('/cis/extractos/'+mode+'?'+params({page:String(page)}),c.signal)
   .then(j=>{if(c.signal.aborted)return;if(mode==='geral')setGeral(j as GeralResp);else setClientes(j as ClientesResp);})
   .catch(e=>{if(!c.signal.aborted)setError(e instanceof Error?e.message:'Erro ao consultar.');})
   .finally(()=>{if(!c.signal.aborted)setLoading(false);});
  return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[mode,inicio,fim,cliente,contrato,tipo,estado,contaId,page,token,period,direction]);

 useEffect(()=>{setContrato('');setContratos([]);if(!cliente)return;const c=new AbortController();
  getJson<{contratos:string[]}>('/cis/extractos/contratos?'+new URLSearchParams({cliente}),c.signal).then(j=>setContratos(j.contratos)).catch(()=>{});
  return()=>c.abort();
 // eslint-disable-next-line react-hooks/exhaustive-deps
 },[cliente,token]);

 function typeClient(v:string){setClienteInput(v);window.clearTimeout(suggestTimer.current);
  if(v.trim().length<2){setSuggestions([]);if(!v.trim()&&cliente){setCliente('');setPage(1);}return;}
  suggestTimer.current=window.setTimeout(()=>{getJson<{clientes:{nome:string;id:number|null;contratos:number}[]}>('/cis/extractos/contratos?'+new URLSearchParams({termo:v.trim()}))
   .then(j=>setSuggestions(j.clientes)).catch(()=>setSuggestions([]));},300);}
 function pickClient(nome:string){setCliente(nome);setClienteInput(nome);setSuggestions([]);setPage(1);}
 function clearPeriod(){setPeriod(null);setEstado('todos');setPage(1);onClearPreset?.();}
 function clearAll(){setPeriod(null);onClearPreset?.();setInicio('');setFim('');setCliente('');setClienteInput('');setContrato('');setTipo('todos');setEstado('todos');setContaId('');setPage(1);}
 function switchMode(m:Mode){setMode(m);setPage(1);setError('');}

 async function exportCsv(){
  setExporting(true);setError('');
  try{
   const all:(GeralRow|ClienteRow)[]=[];let p=1,total=0;
   do{const j=await getJson<GeralResp|ClientesResp>('/cis/extractos/'+mode+'?'+params({page:String(p),por_pagina:'200'}));all.push(...j.linhas);total=j.total_linhas;p++;}while(all.length<total&&p<=100);
   const head=visible.map(column=>column.label);
   const lines=all.map(row=>visible.map(column=>fieldValue(row,column,true)));
   const csv='﻿'+[head,...lines].map(l=>l.map(csvCell).join(';')).join('\r\n');
   const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
   a.download=`extracto-${mode}${cliente?'-'+cliente.replace(/[^\w-]+/g,'_'):''}${contrato?'-'+contrato:''}-${new Date().toISOString().slice(0,10)}.csv`;
   document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),2000);
  }catch(e){setError(e instanceof Error?e.message:'Falha na exportação.');}finally{setExporting(false);}
 }

 async function openInfo(numero:string|null){
  if(!numero)return;setInfoBusy(numero);setInfoError('');
  try{setInfo(await getJson<Informacao>('/cis/extractos/informacao?'+new URLSearchParams({contrato:numero})));}
  catch(err){setInfo(null);setInfoError(err instanceof Error?err.message:'Não foi possível abrir a informação do contrato.');}finally{setInfoBusy('');}
 }
 async function infoEvidence(numero:string){
  setEvidenceBusy(true);setInfoError('');
  try{setEvidence(await getJson<Evidence>('/cis/extractos/evidencia?'+new URLSearchParams({contrato:numero,direccao:directions.clientes})));}
  catch(err){setInfoError(err instanceof Error?err.message:'Não foi possível gerar o extracto do mutuário.');}finally{setEvidenceBusy(false);}
 }
 async function openEvidence(){
  setEvidenceBusy(true);setError('');
  if(mode==='geral'){try{setGeralDoc(await getJson<GeralEvidence>('/cis/extractos/geral-evidencia?'+params()));}
   catch(e){setError(e instanceof Error?e.message:'Não foi possível gerar o extracto geral.');}finally{setEvidenceBusy(false);}return;}
  try{const p=new URLSearchParams({direccao:direction});if(cliente)p.set('cliente',cliente);if(contrato)p.set('contrato',contrato);if(inicio)p.set('inicio',inicio);if(fim)p.set('fim',fim);
   setEvidence(await getJson<Evidence>('/cis/extractos/evidencia?'+p));}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível gerar o extracto do mutuário.');}finally{setEvidenceBusy(false);}
 }
 function printEvidence(){
  const prev=document.title;document.title=geralDoc?`Extracto geral ${geralDoc.emitido_em.slice(0,10)}`:`Extracto ${evidence?.cliente.nome??''} ${evidence?.emitido_em.slice(0,10)??''}`.trim();
  document.body.classList.add('cis-printing-evidence');
  const done=()=>{document.body.classList.remove('cis-printing-evidence');document.title=prev;window.removeEventListener('afterprint',done);};
  window.addEventListener('afterprint',done);window.print();setTimeout(done,1500);
 }

 const data=mode==='geral'?geral:clientes;
 const lastPage=data?Math.max(1,Math.ceil(data.total_linhas/data.por_pagina)):1;
 const g=geral?.totais,c=clientes?.totais;
 const canEvidence=mode==='geral'?!!data&&data.total_linhas>0:!!(cliente||contrato);

 return <>
  <section className="cis-panel cis-list-panel cis-extracto">
   <div className="cis-panel-head"><div><h2>Extracto de movimentos</h2><p>{mode==='geral'?'Entradas e saídas registadas na Caixa (Extracto).':'Prestações dos mutuários: em curso, em atraso e pagas.'}</p></div>
    <div className="cis-extracto-modes" role="tablist" aria-label="Tipo de extracto">
     {isAdmin&&<button type="button" role="tab" aria-selected={mode==='geral'} className={mode==='geral'?'selected':''} onClick={()=>switchMode('geral')}>Geral</button>}
     <button type="button" role="tab" aria-selected={mode==='clientes'} className={mode==='clientes'?'selected':''} onClick={()=>switchMode('clientes')}>Mutuários</button>
    </div></div>

   <div className="cis-extracto-actions"><button type="button" onClick={()=>onOpenContracts()}><FileText size={15}/> Ir para Contratos e prestações</button></div>

   {period&&mode==='clientes'&&<div className="cis-extracto-preset" role="status"><CalendarDays size={15}/><span>Filtro da Visão geral: <b>{period.rotulo}</b>{period.base==='desembolso'?' — contratos desembolsados no período':period.base==='pagamento'?' — por data de pagamento':' — por data de vencimento'}</span><button type="button" onClick={clearPeriod}><X size={14}/> Remover</button></div>}
   <div className="cis-extracto-filters">
    <label><span><CalendarDays size={14}/> {mode==='geral'?'Operação de':period?.base==='pagamento'?'Pagamento de':period?.base==='desembolso'?'Desembolso de':'Vencimento de'}</span><input type="date" value={inicio} max={fim||undefined} onChange={e=>{setInicio(e.target.value);setPage(1);}}/></label>
    <label>até<input type="date" value={fim} min={inicio||undefined} onChange={e=>{setFim(e.target.value);setPage(1);}}/></label>
    <div className="cis-extracto-client"><label><span><Search size={14}/> Mutuário</span><input value={clienteInput} onChange={e=>typeClient(e.target.value)} placeholder="Todos" autoComplete="off" aria-autocomplete="list"/></label>
     {suggestions.length>0&&<ul role="listbox">{suggestions.map(s=><li key={s.nome}><button type="button" onClick={()=>pickClient(s.nome)}><b>{s.nome}</b><small>{s.id?`ID ${s.id} · `:''}{s.contratos} contrato(s)</small></button></li>)}</ul>}</div>
    <label>Contrato<select value={contrato} onChange={e=>{setContrato(e.target.value);setPage(1);}} disabled={!cliente||!contratos.length}><option value="">Todos</option>{contratos.map(n=><option key={n} value={n}>{n}</option>)}</select></label>
    {mode==='geral'?<>
     <label>Tipo<select value={tipo} onChange={e=>{setTipo(e.target.value);setPage(1);}}><option value="todos">Todos</option><option value="entradas">Entradas</option><option value="saidas">Saídas</option></select></label>
     {accounts.length>0&&<label>Conta<select value={contaId} onChange={e=>{setContaId(e.target.value);setPage(1);}}><option value="">Todas</option>{accounts.map(a=><option key={a.id} value={String(a.id)}>{a.code} · {a.name}</option>)}</select></label>}
    </>:<label>Estado<select value={estado} onChange={e=>{setEstado(e.target.value);setPage(1);}}><option value="todos">Todos</option><option value="aberta">Em curso</option><option value="vencida">Em atraso</option><option value="paga">Pagas</option></select></label>}
    <button type="button" className="cis-extracto-clear" onClick={clearAll}>Limpar</button>
   </div>

   {mode==='geral'&&g&&<div className="cis-extracto-totals">
    <div><span><ArrowUpRight size={15}/> Entradas</span><strong className="pos">{mt(g.entradas)} MT</strong></div>
    <div><span><ArrowDownRight size={15}/> Saídas</span><strong className="neg">{mt(g.saidas)} MT</strong></div>
    <div><span><Wallet size={15}/> Total disponível</span><strong>{mt(g.disponivel)} MT</strong></div>
   </div>}
   {mode==='clientes'&&c&&<div className="cis-extracto-totals">
    <div><span>Em curso · {c.n_aberto}</span><strong>{mt(c.aberto)} MT</strong><small>Juros {mt(c.juros_aberto)}</small></div>
    <div><span>Valor vencido · {c.n_vencido}</span><strong className="neg">{mt(c.vencido)} MT</strong><small>Juros e mora {mt(c.juros_vencido)}</small></div>
    <div><span>Total pago · {c.n_pago}</span><strong className="pos">{mt(c.pago)} MT</strong><small>Juros e mora {mt(c.juros_pago)}</small></div>
   </div>}

   <div className="cis-extracto-actions">
    <button type="button" onClick={()=>void exportCsv()} disabled={exporting||!data||!data.total_linhas}><Download size={15}/>{exporting?'A exportar…':'Exportar CSV (Excel)'}</button>
    <button type="button" className="primary" onClick={()=>void openEvidence()} disabled={!canEvidence||evidenceBusy} title={mode==='geral'?'Gerar o extracto geral com os filtros actuais':canEvidence?'Gerar extracto para entregar ao mutuário':'Escolha um mutuário para gerar o extracto'}><FileText size={15}/>{evidenceBusy?'A preparar…':mode==='geral'?'Extracto geral (PDF)':'Extracto de conta (PDF)'}</button>
    {!canEvidence&&mode==='clientes'&&<small>Escolha um mutuário para gerar o extracto de evidência.</small>}
   </div>

   <div className="cis-extracto-actions">
    <label>Ordem {mode==='geral'?'por data do movimento':'por vencimento'} <select value={direction} onChange={event=>{
     const value=event.target.value==='asc'?'asc':'desc';setDirections(old=>({...old,[mode]:value}));setPage(1);
    }}><option value="asc">Crescente</option><option value="desc">Decrescente</option></select></label>
    <details><summary>Escolher colunas</summary>
     <div className="cis-extracto-filters">{fields[mode].map(column=><label key={column.key}>
      <input type="checkbox" checked={selection[mode].includes(column.key)}
       disabled={selection[mode].length===1&&selection[mode].includes(column.key)}
       onChange={event=>{const checked=event.target.checked;setSelection(old=>({...old,[mode]:checked?[...old[mode],column.key]:old[mode].filter(key=>key!==column.key)}));}}/> {column.label}
     </label>)}</div>
     <button type="button" onClick={()=>setSelection(old=>({...old,[mode]:defaults[mode]}))}>Repor colunas iniciais</button>
    </details>
   </div>

   {infoBusy&&<div className="cis-loading" role="status">A abrir a informação do contrato {infoBusy}…</div>}
   {infoError&&!info&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{infoError}</div>}
   {loading&&<div className="cis-loading" role="status">A consultar o extracto…</div>}
   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{error}</div>}
   {!loading&&!error&&data&&(data.total_linhas===0?<div className="cis-extracto-empty">Sem registos para os filtros escolhidos.</div>:<>
    <div className="cis-table-scroll"><table>
     <thead><tr>{visible.map(column=><th key={column.key} className={column.format==='money'?'num':undefined}>{column.label}</th>)}</tr></thead>
     <tbody>{data.linhas.map(row=>{
      const canOpen=Boolean(row.contrato);
      return <tr key={'nr' in row?row.nr:row.ordem} className={canOpen?'cis-extracto-row':''}
       tabIndex={canOpen?0:undefined} title={canOpen?'Ver informação do contrato '+row.contrato:undefined}
       onClick={()=>void openInfo(row.contrato)} onKeyDown={event=>{if(event.key==='Enter')void openInfo(row.contrato);}}>
       {visible.map(column=><td key={column.key} className={column.format==='money'?'num':undefined}>
        {column.key==='valor'&&'tipo' in row?<span className={row.tipo==='entrada'?'pos':'neg'}>{row.tipo==='entrada'?'+':'−'} {fieldValue(row,column)}</span>
         :column.key==='estado'&&'situacao' in row?<span className={'cis-extracto-state '+row.situacao}>{row.estado}</span>:fieldValue(row,column)}
       </td>)}
      </tr>;
     })}</tbody>
    </table></div>
    <div className="cis-table-footer"><span>{data.total_linhas.toLocaleString('pt-MZ')} registos · Página {page} de {lastPage}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(p=>Math.max(1,p-1))}><ChevronLeft size={17}/> Anterior</button><button type="button" disabled={page>=lastPage} onClick={()=>setPage(p=>p+1)}>Seguinte <ChevronRight size={17}/></button></div></div>
   </>)}
  </section>

  {evidence&&createPortal(<div className="cis-evidence-overlay" role="dialog" aria-modal="true" aria-label="Extracto de conta">
   <div className="cis-evidence-bar"><b>Pré-visualização do extracto do mutuário</b><span>Use «Imprimir / Guardar PDF» e escolha «Guardar como PDF» para enviar ao mutuário.</span>
    <button type="button" className="primary" onClick={printEvidence}><Printer size={15}/> Imprimir / Guardar PDF</button>
    <button type="button" onClick={()=>setEvidence(null)} aria-label="Fechar"><X size={16}/></button></div>
   <article className="cis-evidence">
    <header><CompanyBrand company={company}/><div><h1>{evidence.titulo}</h1><p>Emitido em {dt(evidence.emitido_em.slice(0,10))} às {evidence.emitido_em.slice(11)}{evidence.emitido_por?` por ${evidence.emitido_por}`:''}</p>
     <p>Período: {evidence.periodo.inicio||evidence.periodo.fim?`${dt(evidence.periodo.inicio)} a ${dt(evidence.periodo.fim)}`:'todo o histórico'}</p></div></header>
    <section className="cis-evidence-client"><h2>Mutuário</h2><dl>
     <div><dt>Nome</dt><dd>{evidence.cliente.nome}</dd></div>
     {evidence.cliente.id&&<div><dt>N.º de mutuário</dt><dd>{evidence.cliente.numeracao??evidence.cliente.id}</dd></div>}
     {evidence.cliente.bi&&<div><dt>N.º de BI</dt><dd>{evidence.cliente.bi}</dd></div>}
     {evidence.cliente.contacto&&<div><dt>Contacto</dt><dd>{evidence.cliente.contacto}</dd></div>}
     {evidence.cliente.morada&&<div><dt>Morada</dt><dd>{evidence.cliente.morada}</dd></div>}
    </dl></section>
    <section className="cis-evidence-summary">
     <div><span>Em curso</span><strong>{mt(evidence.totais.aberto)} MT</strong></div>
     <div><span>Valor vencido</span><strong>{mt(evidence.totais.vencido)} MT</strong></div>
     <div><span>Pago</span><strong>{mt(evidence.totais.pago)} MT</strong></div>
     <div><span>Saldo em dívida</span><strong>{mt(Number(evidence.totais.aberto)+Number(evidence.totais.vencido))} MT</strong></div>
    </section>
    <section><h2>Contratos</h2><table><thead><tr><th>Contrato</th><th>Início</th><th className="num">Capital</th><th className="num">Prestações</th><th className="num">Pago</th><th className="num">Em dívida</th><th>Situação</th></tr></thead>
     <tbody>{evidence.contratos.map(k=><tr key={k.contrato}><td>{k.contrato}</td><td>{dt(k.inicio)}</td><td className="num">{mt(k.capital)}</td><td className="num">{k.prestacoes}</td><td className="num">{mt(k.pago)}</td><td className="num">{mt(k.em_divida)}</td><td>{k.situacao}</td></tr>)}</tbody></table></section>
    <section><h2>Prestações</h2><table><thead><tr>{pdfFields('clientes').map(column=><th key={column.key}>{column.label}</th>)}</tr></thead>
     <tbody>{evidence.prestacoes.map(row=><tr key={row.ordem}>{pdfFields('clientes').map(column=><td key={column.key} className={column.format==='money'?'num':undefined}>{fieldValue(row,column)}</td>)}</tr>)}</tbody></table></section>
    <section><h2>Movimentos registados</h2>{evidence.movimentos.length?<table><thead><tr><th>Data</th><th>Referência</th><th>Descrição</th><th>Contrato</th><th>Tipo</th><th className="num">Valor (MT)</th></tr></thead>
     <tbody>{evidence.movimentos.map(m=><tr key={m.codigo}><td>{dt(m.data)}</td><td>{m.codigo}</td><td>{m.descricao}</td><td>{m.contrato}{m.parcela?' · P'+m.parcela:''}</td><td>{m.tipo}</td><td className="num">{mt(m.valor)}</td></tr>)}</tbody></table>:<p>Sem movimentos registados no período.</p>}</section>
    <footer><ShieldCheck size={14}/><span>Código de verificação <b>{evidence.codigo_verificacao}</b> · documento gerado pelo CIS a partir dos registos do sistema. Valores em Meticais (MT).</span><PoweredBy/></footer>
   </article>
  </div>,document.body)}

  {info&&createPortal(<div className="cis-info-backdrop" onClick={()=>setInfo(null)}>
   <aside className="cis-info" role="dialog" aria-modal="true" aria-label={'Informação do contrato '+info.contrato} onClick={ev=>ev.stopPropagation()}>
    <header><div><span><Info size={15}/> Informação</span><h3>Contrato {info.contrato}</h3></div><button type="button" onClick={()=>setInfo(null)} aria-label="Fechar"><X size={17}/></button></header>
    <span className={'cis-info-state '+(info.situacao==='Pagamento atrasado'?'late':info.situacao==='Pendente'?'open':'done')}>{situacaoContrato(info.situacao)}</span>
    <section><h4>Mutuário</h4><dl>
     <div><dt>Código</dt><dd>{info.cliente.codigo??'—'}</dd></div>
     <div><dt>Nome</dt><dd>{info.cliente.nome}</dd></div>
     <div><dt>Contacto</dt><dd>{info.cliente.contacto?<a href={'tel:+'+phoneDigits(info.cliente.contacto)}><Phone size={13}/> {info.cliente.contacto}</a>:'—'}</dd></div>
    </dl></section>
    <section><h4>Contrato</h4><dl>
     <div><dt>Data de início</dt><dd>{dt(info.data_inicio)}</dd></div>
     <div><dt>Data final</dt><dd>{dt(info.data_final)}</dd></div>
     <div><dt>Capital desembolsado</dt><dd>{mt(info.quantia_creditada)} MT</dd></div>
     {info.forma_pagamento&&<div><dt>Forma de pagamento</dt><dd>{info.forma_pagamento}</dd></div>}
    </dl></section>
    <section><h4>Prestações</h4><div className="cis-info-grid">
     <div><span>Total</span><b>{info.prestacoes.total}</b></div><div><span>Pagas</span><b>{info.prestacoes.pagas}</b></div>
     <div><span>Em curso</span><b>{info.prestacoes.em_aberto}</b></div><div className={info.prestacoes.vencidas?'late':''}><span>Em atraso</span><b>{info.prestacoes.vencidas}</b></div>
    </div><dl>
     <div><dt>Saldo em dívida</dt><dd className={Number(info.em_divida)>0?'neg':''}>{mt(info.em_divida)} MT</dd></div>
     <div><dt>Pago</dt><dd>{mt(info.pago)} MT</dd></div>
     {info.proximo_vencimento&&<div><dt>{info.prestacoes.vencidas?'Em atraso desde':'Próximo vencimento'}</dt><dd>{dt(info.proximo_vencimento)}{info.dias_atraso?` · ${info.dias_atraso} dia(s) de atraso`:''}</dd></div>}
    </dl></section>
    {infoError&&<div className="cis-table-error" role="alert"><AlertCircle size={17}/>{infoError}</div>}
    <div className="cis-info-actions">
     <button type="button" onClick={()=>onOpenContracts(info.contrato)}><FileText size={15}/> Abrir contrato e prestações</button>
     <button type="button" className="primary" onClick={()=>setMsgContract(info.contrato)}><MessageSquare size={15}/> Mensagem ao mutuário</button>
     <button type="button" onClick={()=>void infoEvidence(info.contrato)} disabled={evidenceBusy}><FileText size={15}/> {evidenceBusy?'A preparar…':'Extracto de conta (PDF)'}</button>
    </div>
   </aside>
  </div>,document.body)}

  {msgContract&&createPortal(<div className="cis-msg-overlay" role="dialog" aria-modal="true" aria-label="Centro de mensagens" onClick={()=>setMsgContract(null)}>
   <div onClick={ev=>ev.stopPropagation()}><MessageCenter token={token} contrato={msgContract} onClose={()=>setMsgContract(null)}/></div>
  </div>,document.body)}

  {geralDoc&&createPortal(<div className="cis-evidence-overlay" role="dialog" aria-modal="true" aria-label="Extracto geral">
   <div className="cis-evidence-bar"><b>Pré-visualização do extracto geral</b><span>{geralDoc.total_linhas} movimento(s) com os filtros actuais. Use «Imprimir / Guardar PDF».</span>
    <button type="button" className="primary" onClick={printEvidence}><Printer size={15}/> Imprimir / Guardar PDF</button>
    <button type="button" onClick={()=>setGeralDoc(null)} aria-label="Fechar"><X size={16}/></button></div>
   <article className="cis-evidence">
    <header><CompanyBrand company={company}/><div><h1>{geralDoc.titulo}</h1><p>Emitido em {dt(geralDoc.emitido_em.slice(0,10))} às {geralDoc.emitido_em.slice(11)}{geralDoc.emitido_por?` por ${geralDoc.emitido_por}`:''}</p>
     <p>Período: {geralDoc.filtros.inicio||geralDoc.filtros.fim?`${dt(geralDoc.filtros.inicio)} a ${dt(geralDoc.filtros.fim)}`:'todo o histórico'}</p></div></header>
    <section className="cis-evidence-client"><h2>Filtros</h2><dl>
     <div><dt>Conta</dt><dd>{geralDoc.filtros.conta??'Todas'}</dd></div>
     <div><dt>Tipo</dt><dd>{geralDoc.filtros.tipo==='entradas'?'Entradas':geralDoc.filtros.tipo==='saidas'?'Saídas':'Entradas e saídas'}</dd></div>
     <div><dt>Mutuário</dt><dd>{geralDoc.filtros.cliente??'Todos'}</dd></div>
     <div><dt>Contrato</dt><dd>{geralDoc.filtros.contrato??'Todos'}</dd></div>
    </dl></section>
    <section className="cis-evidence-summary cis-evidence-summary-3">
     <div><span>Entradas</span><strong>{mt(geralDoc.totais.entradas)} MT</strong></div>
     <div><span>Saídas</span><strong>{mt(geralDoc.totais.saidas)} MT</strong></div>
     <div><span>Total disponível</span><strong>{mt(geralDoc.totais.disponivel)} MT</strong></div>
    </section>
    <section><h2>Movimentos</h2><table><thead><tr>{pdfFields('geral').map(column=><th key={column.key}>{column.label}</th>)}</tr></thead>
     <tbody>{geralDoc.linhas.map(row=><tr key={row.codigo}>{pdfFields('geral').map(column=><td key={column.key} className={column.format==='money'?'num':undefined}>{column.key==='valor'?(row.tipo==='entrada'?'+':'−')+' '+fieldValue(row,column):fieldValue(row,column)}</td>)}</tr>)}</tbody></table></section>
    <footer><ShieldCheck size={14}/><span>Código de verificação <b>{geralDoc.codigo_verificacao}</b> · documento gerado pelo CIS a partir dos registos do Extracto. Valores em Meticais (MT).</span><PoweredBy/></footer>
   </article>
  </div>,document.body)}
 </>;
}
