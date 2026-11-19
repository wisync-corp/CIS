import {useEffect,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {Search,Download,Printer,FileText,X,AlertCircle,ChevronLeft,ChevronRight,ArrowUpRight,ArrowDownRight,Wallet,CalendarDays,ShieldCheck} from 'lucide-react';
import logoPrincipal from './assets/brand/cis-principal-claro.svg';

const API=import.meta.env.VITE_API_URL||'/api';
type Mode='geral'|'clientes';
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

const money=new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2});
const mt=(v:string|number|null|undefined)=>v===null||v===undefined||v===''?'—':money.format(Number(v));
const dt=(v:string|null|undefined)=>{if(!v)return '—';const [y,m,d]=v.slice(0,10).split('-');return d&&m&&y?`${d}/${m}/${y}`:v;};
const csvCell=(v:unknown)=>{const s=v===null||v===undefined?'':String(v);return /[";\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;};

export default function ExtractoDesk({token,role}:{token:string;role:string}){
 const isAdmin=role==='administrador';
 const [mode,setMode]=useState<Mode>(isAdmin?'geral':'clientes');
 const [inicio,setInicio]=useState('');const [fim,setFim]=useState('');
 const [cliente,setCliente]=useState('');const [clienteInput,setClienteInput]=useState('');
 const [suggestions,setSuggestions]=useState<{nome:string;id:number|null;contratos:number}[]>([]);
 const [contratos,setContratos]=useState<string[]>([]);const [contrato,setContrato]=useState('');
 const [tipo,setTipo]=useState('todos');const [estado,setEstado]=useState('todos');
 const [contaId,setContaId]=useState('');const [accounts,setAccounts]=useState<Account[]>([]);
 const [page,setPage]=useState(1);
 const [geral,setGeral]=useState<GeralResp|null>(null);const [clientes,setClientes]=useState<ClientesResp|null>(null);
 const [loading,setLoading]=useState(false);const [error,setError]=useState('');
 const [exporting,setExporting]=useState(false);
 const [evidence,setEvidence]=useState<Evidence|null>(null);const [evidenceBusy,setEvidenceBusy]=useState(false);
 const headers={Authorization:'Bearer '+token,Accept:'application/json'};
 const suggestTimer=useRef<number|undefined>(undefined);

 const params=(extra:Record<string,string>={})=>{const p=new URLSearchParams();
  if(inicio)p.set('inicio',inicio);if(fim)p.set('fim',fim);if(cliente)p.set('cliente',cliente);if(contrato)p.set('contrato',contrato);
  if(mode==='geral'){if(tipo!=='todos')p.set('tipo',tipo);if(contaId)p.set('conta_id',contaId);}else if(estado!=='todos')p.set('estado',estado);
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
 },[mode,inicio,fim,cliente,contrato,tipo,estado,contaId,page,token]);

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
 function clearAll(){setInicio('');setFim('');setCliente('');setClienteInput('');setContrato('');setTipo('todos');setEstado('todos');setContaId('');setPage(1);}
 function switchMode(m:Mode){setMode(m);setPage(1);setError('');}

 async function exportCsv(){
  setExporting(true);setError('');
  try{
   const all:(GeralRow|ClienteRow)[]=[];let p=1,total=0;
   do{const j=await getJson<GeralResp|ClientesResp>('/cis/extractos/'+mode+'?'+params({page:String(p),por_pagina:'200'}));all.push(...j.linhas);total=j.total_linhas;p++;}while(all.length<total&&p<=100);
   const head=mode==='geral'?['Cod. Op','Data','Descrição','Tipo','Valor (MT)','Contrato','Cliente','Parcela','Conta']
    :['Cod. Op','Contrato','Cliente','Parcela','Vencimento','Dias','F. Pagamento','Capital (MT)','Juros (MT)','Multa (MT)','Quantia (MT)','Data de pagamento','Estado'];
   const lines=all.map(r=>mode==='geral'?(()=>{const g=r as GeralRow;return [g.codigo,dt(g.data),g.descricao,g.tipo==='entrada'?'Entrada':'Saída',g.valor.replace('.',','),g.contrato,g.cliente,g.parcela,g.conta];})()
    :(()=>{const c=r as ClienteRow;return [c.codigo,c.contrato,c.cliente,c.parcela,dt(c.vencimento),c.dias,c.forma_pagamento,c.capital.replace('.',','),c.juros.replace('.',','),c.multa.replace('.',','),c.valor.replace('.',','),dt(c.data_pagamento),c.estado];})());
   const csv='﻿'+[head,...lines].map(l=>l.map(csvCell).join(';')).join('\r\n');
   const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
   a.download=`extracto-${mode}${cliente?'-'+cliente.replace(/[^\w-]+/g,'_'):''}${contrato?'-'+contrato:''}-${new Date().toISOString().slice(0,10)}.csv`;
   document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),2000);
  }catch(e){setError(e instanceof Error?e.message:'Falha na exportação.');}finally{setExporting(false);}
 }

 async function openEvidence(){
  setEvidenceBusy(true);setError('');
  try{const p=new URLSearchParams();if(cliente)p.set('cliente',cliente);if(contrato)p.set('contrato',contrato);if(inicio)p.set('inicio',inicio);if(fim)p.set('fim',fim);
   setEvidence(await getJson<Evidence>('/cis/extractos/evidencia?'+p));}
  catch(e){setError(e instanceof Error?e.message:'Não foi possível gerar o extracto do cliente.');}finally{setEvidenceBusy(false);}
 }
 function printEvidence(){
  const prev=document.title;document.title=`Extracto ${evidence?.cliente.nome??''} ${evidence?.emitido_em.slice(0,10)??''}`.trim();
  document.body.classList.add('cis-printing-evidence');
  const done=()=>{document.body.classList.remove('cis-printing-evidence');document.title=prev;window.removeEventListener('afterprint',done);};
  window.addEventListener('afterprint',done);window.print();setTimeout(done,1500);
 }

 const data=mode==='geral'?geral:clientes;
 const lastPage=data?Math.max(1,Math.ceil(data.total_linhas/data.por_pagina)):1;
 const g=geral?.totais,c=clientes?.totais;
 const canEvidence=!!(cliente||contrato);

 return <>
  <section className="cis-panel cis-list-panel cis-extracto">
   <div className="cis-panel-head"><div><h2>Extracto de movimentos</h2><p>{mode==='geral'?'Entradas e saídas registadas na Caixa (Extracto).':'Prestações dos clientes: em aberto, vencidas e pagas.'}</p></div>
    <div className="cis-extracto-modes" role="tablist" aria-label="Tipo de extracto">
     {isAdmin&&<button type="button" role="tab" aria-selected={mode==='geral'} className={mode==='geral'?'selected':''} onClick={()=>switchMode('geral')}>Geral</button>}
     <button type="button" role="tab" aria-selected={mode==='clientes'} className={mode==='clientes'?'selected':''} onClick={()=>switchMode('clientes')}>Clientes</button>
    </div></div>

   <div className="cis-extracto-filters">
    <label><CalendarDays size={15}/> {mode==='geral'?'Operação de':'Cobrança de'}<input type="date" value={inicio} max={fim||undefined} onChange={e=>{setInicio(e.target.value);setPage(1);}}/></label>
    <label>até<input type="date" value={fim} min={inicio||undefined} onChange={e=>{setFim(e.target.value);setPage(1);}}/></label>
    <div className="cis-extracto-client"><label><Search size={15}/> Cliente<input value={clienteInput} onChange={e=>typeClient(e.target.value)} placeholder="Todos" autoComplete="off" aria-autocomplete="list"/></label>
     {suggestions.length>0&&<ul role="listbox">{suggestions.map(s=><li key={s.nome}><button type="button" onClick={()=>pickClient(s.nome)}><b>{s.nome}</b><small>{s.id?`ID ${s.id} · `:''}{s.contratos} contrato(s)</small></button></li>)}</ul>}</div>
    <label>Contrato<select value={contrato} onChange={e=>{setContrato(e.target.value);setPage(1);}} disabled={!cliente||!contratos.length}><option value="">Todos</option>{contratos.map(n=><option key={n} value={n}>{n}</option>)}</select></label>
    {mode==='geral'?<>
     <label>Tipo<select value={tipo} onChange={e=>{setTipo(e.target.value);setPage(1);}}><option value="todos">Todos</option><option value="entradas">Entradas</option><option value="saidas">Saídas</option></select></label>
     {accounts.length>0&&<label>Conta<select value={contaId} onChange={e=>{setContaId(e.target.value);setPage(1);}}><option value="">Todas</option>{accounts.map(a=><option key={a.id} value={String(a.id)}>{a.code} · {a.name}</option>)}</select></label>}
    </>:<label>Estado<select value={estado} onChange={e=>{setEstado(e.target.value);setPage(1);}}><option value="todos">Todos</option><option value="aberta">Abertas</option><option value="vencida">Vencidas</option><option value="paga">Pagas</option></select></label>}
    <button type="button" className="cis-extracto-clear" onClick={clearAll}>Limpar</button>
   </div>

   {mode==='geral'&&g&&<div className="cis-extracto-totals">
    <div><span><ArrowUpRight size={15}/> Entradas</span><strong className="pos">{mt(g.entradas)} MT</strong></div>
    <div><span><ArrowDownRight size={15}/> Saídas</span><strong className="neg">{mt(g.saidas)} MT</strong></div>
    <div><span><Wallet size={15}/> Total disponível</span><strong>{mt(g.disponivel)} MT</strong></div>
   </div>}
   {mode==='clientes'&&c&&<div className="cis-extracto-totals">
    <div><span>Total em aberto · {c.n_aberto}</span><strong>{mt(c.aberto)} MT</strong><small>Juros {mt(c.juros_aberto)}</small></div>
    <div><span>Total vencido · {c.n_vencido}</span><strong className="neg">{mt(c.vencido)} MT</strong><small>Juros e multa {mt(c.juros_vencido)}</small></div>
    <div><span>Total pago · {c.n_pago}</span><strong className="pos">{mt(c.pago)} MT</strong><small>Juros e multa {mt(c.juros_pago)}</small></div>
   </div>}

   <div className="cis-extracto-actions">
    <button type="button" onClick={()=>void exportCsv()} disabled={exporting||!data||!data.total_linhas}><Download size={15}/>{exporting?'A exportar…':'Exportar CSV (Excel)'}</button>
    <button type="button" className="primary" onClick={()=>void openEvidence()} disabled={!canEvidence||evidenceBusy} title={canEvidence?'Gerar extracto para entregar ao cliente':'Escolha um cliente para gerar o extracto'}><FileText size={15}/>{evidenceBusy?'A preparar…':'Extracto do cliente (PDF)'}</button>
    {!canEvidence&&<small>Escolha um cliente para gerar o extracto de evidência.</small>}
   </div>

   {loading&&<div className="cis-loading" role="status">A consultar o extracto…</div>}
   {error&&<div className="cis-table-error" role="alert"><AlertCircle size={19}/>{error}</div>}
   {!loading&&!error&&data&&(data.total_linhas===0?<div className="cis-extracto-empty">Sem registos para os filtros escolhidos.</div>:<>
    <div className="cis-table-scroll"><table>
     {mode==='geral'?<><thead><tr><th>Cod. Op</th><th>Data</th><th>Descrição</th><th>Conta</th><th className="num">Quantia (MT)</th></tr></thead>
      <tbody>{geral!.linhas.map(r=><tr key={r.nr}><td>{r.codigo}</td><td>{dt(r.data)}</td><td>{r.descricao}</td><td>{r.conta??'—'}</td><td className={'num '+(r.tipo==='entrada'?'pos':'neg')}>{r.tipo==='entrada'?'+':'−'} {mt(r.valor)}</td></tr>)}</tbody></>
     :<><thead><tr><th>Cod. Op</th><th>Contrato</th><th>Cliente</th><th>P.</th><th>Cobrança</th><th className="num">Dias</th><th>F. Pagto</th><th className="num">Capital</th><th className="num">Juros</th><th className="num">Quantia</th><th>Estado</th></tr></thead>
      <tbody>{clientes!.linhas.map(r=><tr key={r.ordem}><td>{r.codigo}</td><td>{r.contrato}</td><td>{r.cliente}</td><td>{r.parcela}</td><td>{dt(r.vencimento)}</td><td className={'num '+(r.dias<0&&r.situacao!=='paga'?'neg':'')}>{r.dias}</td><td>{r.forma_pagamento??'—'}</td><td className="num">{mt(r.capital)}</td><td className="num">{mt(r.juros)}</td><td className="num">{mt(r.valor)}</td><td><span className={'cis-extracto-state '+r.situacao}>{r.estado}</span></td></tr>)}</tbody></>}
    </table></div>
    <div className="cis-table-footer"><span>{data.total_linhas.toLocaleString('pt-MZ')} registos · Página {page} de {lastPage}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(p=>Math.max(1,p-1))}><ChevronLeft size={17}/> Anterior</button><button type="button" disabled={page>=lastPage} onClick={()=>setPage(p=>p+1)}>Seguinte <ChevronRight size={17}/></button></div></div>
   </>)}
  </section>

  {evidence&&createPortal(<div className="cis-evidence-overlay" role="dialog" aria-modal="true" aria-label="Extracto do cliente">
   <div className="cis-evidence-bar"><b>Pré-visualização do extracto do cliente</b><span>Use «Imprimir / Guardar PDF» e escolha «Guardar como PDF» para enviar ao cliente.</span>
    <button type="button" className="primary" onClick={printEvidence}><Printer size={15}/> Imprimir / Guardar PDF</button>
    <button type="button" onClick={()=>setEvidence(null)} aria-label="Fechar"><X size={16}/></button></div>
   <article className="cis-evidence">
    <header><img src={logoPrincipal} alt="CIS" width={170} height={50}/><div><h1>{evidence.titulo}</h1><p>Emitido em {dt(evidence.emitido_em.slice(0,10))} às {evidence.emitido_em.slice(11)}{evidence.emitido_por?` por ${evidence.emitido_por}`:''}</p>
     <p>Período: {evidence.periodo.inicio||evidence.periodo.fim?`${dt(evidence.periodo.inicio)} a ${dt(evidence.periodo.fim)}`:'todo o histórico'}</p></div></header>
    <section className="cis-evidence-client"><h2>Cliente</h2><dl>
     <div><dt>Nome</dt><dd>{evidence.cliente.nome}</dd></div>
     {evidence.cliente.id&&<div><dt>N.º de cliente</dt><dd>{evidence.cliente.numeracao??evidence.cliente.id}</dd></div>}
     {evidence.cliente.bi&&<div><dt>N.º de BI</dt><dd>{evidence.cliente.bi}</dd></div>}
     {evidence.cliente.contacto&&<div><dt>Contacto</dt><dd>{evidence.cliente.contacto}</dd></div>}
     {evidence.cliente.morada&&<div><dt>Morada</dt><dd>{evidence.cliente.morada}</dd></div>}
    </dl></section>
    <section className="cis-evidence-summary">
     <div><span>Em aberto</span><strong>{mt(evidence.totais.aberto)} MT</strong></div>
     <div><span>Vencido</span><strong>{mt(evidence.totais.vencido)} MT</strong></div>
     <div><span>Pago</span><strong>{mt(evidence.totais.pago)} MT</strong></div>
     <div><span>Total em dívida</span><strong>{mt(Number(evidence.totais.aberto)+Number(evidence.totais.vencido))} MT</strong></div>
    </section>
    <section><h2>Contratos</h2><table><thead><tr><th>Contrato</th><th>Início</th><th className="num">Capital</th><th className="num">Prestações</th><th className="num">Pago</th><th className="num">Em dívida</th><th>Situação</th></tr></thead>
     <tbody>{evidence.contratos.map(k=><tr key={k.contrato}><td>{k.contrato}</td><td>{dt(k.inicio)}</td><td className="num">{mt(k.capital)}</td><td className="num">{k.prestacoes}</td><td className="num">{mt(k.pago)}</td><td className="num">{mt(k.em_divida)}</td><td>{k.situacao}</td></tr>)}</tbody></table></section>
    <section><h2>Prestações</h2><table><thead><tr><th>Contrato</th><th>P.</th><th>Vencimento</th><th className="num">Capital</th><th className="num">Juros</th><th className="num">Multa</th><th className="num">Valor</th><th>Pago em</th><th>Estado</th></tr></thead>
     <tbody>{evidence.prestacoes.map(r=><tr key={r.ordem}><td>{r.contrato}</td><td>{r.parcela}</td><td>{dt(r.vencimento)}</td><td className="num">{mt(r.capital)}</td><td className="num">{mt(r.juros)}</td><td className="num">{mt(r.multa)}</td><td className="num">{mt(r.valor)}</td><td>{dt(r.data_pagamento)}</td><td>{r.estado}</td></tr>)}</tbody></table></section>
    <section><h2>Movimentos registados</h2>{evidence.movimentos.length?<table><thead><tr><th>Data</th><th>Referência</th><th>Descrição</th><th>Contrato</th><th>Tipo</th><th className="num">Valor (MT)</th></tr></thead>
     <tbody>{evidence.movimentos.map(m=><tr key={m.codigo}><td>{dt(m.data)}</td><td>{m.codigo}</td><td>{m.descricao}</td><td>{m.contrato}{m.parcela?' · P'+m.parcela:''}</td><td>{m.tipo}</td><td className="num">{mt(m.valor)}</td></tr>)}</tbody></table>:<p>Sem movimentos registados no período.</p>}</section>
    <footer><ShieldCheck size={14}/><span>Código de verificação <b>{evidence.codigo_verificacao}</b> · documento gerado pelo CIS a partir dos registos do sistema. Valores em Meticais (MT).</span></footer>
   </article>
  </div>,document.body)}
 </>;
}
