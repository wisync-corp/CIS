import {useRef,useState,type FormEvent} from 'react';
import {Calculator,ShieldCheck,Info,AlertCircle,RefreshCw,Landmark,ArrowRight,Layers,Percent,BookOpen} from 'lucide-react';

import LateChargeFields,{initialLateCharge,lateChargeRule,type LateChargeDetails} from './LateChargeFields';
import {useCreditCharges} from './encargos';

const API=import.meta.env.VITE_API_URL||'/api';
type Mode='prestacao'|'juros'|'multa';
type Term='1 Semana'|'2 Semanas'|'3 Semanas'|'Mês';
type Quote={
 valor_base:string;taxa_tabela:string;taxa_aplicada:string;taxa_manual:boolean;
 prazo:Term;periodo_referencia:number;dias_propostos:number;tipo_calculo:Mode;
 acrescimo_calculado:string;total_estimado:string;faixa:string[];
 mora_detalhe?:LateChargeDetails;saldo_prestacao?:string;dias_atraso?:number;vencimento?:string;data_referencia?:string;
 arredondamento:string;aviso:string;consulta_apenas:boolean;
};
const formatMoney=(value:string)=>{
 const number=Number(value);
 return Number.isFinite(number)?new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}).format(number)+' MT':'—';
};
const calculations:{id:Mode;title:string;description:string}[]=[
 {id:'prestacao',title:'Prestação com juros',description:'Base + juros correspondentes à taxa.'},
 {id:'juros',title:'Apenas juros',description:'Calcula os juros, sem adicionar capital.'},
 {id:'multa',title:'Mora por atraso',description:'Estime o encargo pelo tratamento e pela data de referência.'},
];
const terms:Term[]=['1 Semana','2 Semanas','3 Semanas','Mês'];

export default function SimulatorDesk({token}:{token:string}){
 const [mode,setMode]=useState<Mode>('prestacao');
 const [amount,setAmount]=useState('');
 const [term,setTerm]=useState<Term>('2 Semanas');
 const [manual,setManual]=useState('');
 const [quote,setQuote]=useState<Quote|null>(null);
 const [error,setError]=useState('');
 const [busy,setBusy]=useState(false);
 const [late,setLate]=useState(initialLateCharge);
 const enc=useCreditCharges(token);
 const [due,setDue]=useState('');
 const [reference,setReference]=useState('');
 const [capital,setCapital]=useState('');
 const revision=useRef(0);
 const isLate=mode==='multa';
 const rule=isLate?lateChargeRule(late,false):null;
 const moneyInput=(value:string)=>value.trim().replace(',','.');
 const capitalRequired=rule?.base==='capital';
 const valid=Number.isFinite(Number(moneyInput(amount)))&&Number(moneyInput(amount))>0&&/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(moneyInput(amount))&&
  (isLate?!!rule&&!!due&&!!reference&&(!capitalRequired||/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(moneyInput(capital))&&Number(moneyInput(capital))<=Number(moneyInput(amount))):(!manual||/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/.test(manual.trim())));
 const clearQuote=()=>{revision.current++;setQuote(null);setError('');};
 async function calculate(event:FormEvent<HTMLFormElement>){
  event.preventDefault();
  if(!valid||busy)return;
  const current=++revision.current;
  setBusy(true);setError('');setQuote(null);
  try{
   const response=await fetch(API+'/cis/simulador/calcular',{
    method:'POST',headers:{Authorization:'Bearer '+token,Accept:'application/json','Content-Type':'application/json'},
    body:JSON.stringify({tipo_calculo:mode,montante:moneyInput(amount),...(isLate?{mora:rule,vencimento:due,data_referencia:reference,...(capitalRequired?{capital:moneyInput(capital)}:{})}:{prazo:term,...(manual.trim()?{taxa_manual:manual.trim()}:{})})})
   });
   const data=await response.json().catch(()=>({})) as Quote&{message?:string;errors?:Record<string,string[]>};
   if(!response.ok)throw new Error(Object.values(data.errors??{}).flat()[0]||data.message||'Não foi possível calcular a simulação.');
   if(current===revision.current)setQuote(data);
  }catch(err){if(current===revision.current)setError(err instanceof Error?err.message:'Não foi possível calcular.');}
  finally{setBusy(false);}
 }
 return <section className="cis-simulator">
  <header className="cis-simulator-hero"><div><span><Calculator size={16}/> CAPTA · FERRAMENTAS</span><h2>Simulador financeiro</h2><p>Faça cálculos independentes de qualquer contrato, mutuário ou prestação.</p></div>
   <span className="cis-simulator-readonly"><ShieldCheck size={16}/> Apenas simulação</span></header>
  <div className="cis-simulator-intro"><Info size={18}/><div><strong>Calculadora genérica</strong><p>Utiliza as faixas de juros do sistema e os tratamentos de mora disponíveis na cobrança. Nenhuma simulação cria um contrato, factura, prestação, cobrança ou lançamento financeiro.</p></div></div>
  <div className="cis-simulator-layout">
   <form className="cis-simulator-form" onSubmit={e=>void calculate(e)}>
    <div className="cis-simulator-panel-title"><span>01</span><div><h3>Parâmetros do cálculo</h3><p>Introduza livremente os valores que pretende analisar.</p></div></div>
    <fieldset className="cis-simulator-modes"><legend>Tipo de simulação</legend><div>{calculations.map(item=>
     <button type="button" key={item.id} aria-pressed={mode===item.id} className={mode===item.id?'selected':''} onClick={()=>{setMode(item.id);clearQuote();}}>
      <span>{item.id==='prestacao'?<Layers size={19}/>:item.id==='juros'?<Percent size={19}/>:<Landmark size={19}/>}</span>
      <strong>{item.title}</strong><small>{item.description}</small>
     </button>
    )}</div></fieldset>
    <div className="cis-simulator-fields">
     <label>{isLate?'Saldo da prestação antes da mora (MT)':'Valor de referência (MT)'} <span>Obrigatório</span><input type="text" inputMode="decimal" required value={amount} placeholder="Ex.: 5000.00" onChange={e=>{setAmount(e.target.value);clearQuote();}}/><small>{isLate?'Saldo actual da prestação, incluindo os juros contratuais em dívida.':'Montante de referência para juros ou prestação.'}</small></label>
     {isLate?<><label>Data de vencimento<input type="date" required value={due} onChange={e=>{setDue(e.target.value);clearQuote();}}/></label>
      <label>Data de referência<input type="date" required value={reference} onChange={e=>{setReference(e.target.value);clearQuote();}}/><small>Use a data de pagamento que pretende apresentar ao cliente.</small></label>
      {capitalRequired&&<label>Capital em dívida (MT)<input inputMode="decimal" required value={capital} onChange={e=>{setCapital(e.target.value);clearQuote();}}/></label>}
     </>:<><label>Prazo <span>Obrigatório</span><select value={term} onChange={e=>{setTerm(e.target.value as Term);clearQuote();}}>{terms.map(t=><option key={t} value={t}>{t}</option>)}</select><small>O período da tabela de juros é determinado pelo prazo.</small></label>
     <label className="full">Taxa manual (%) <span>Opcional</span><input type="text" inputMode="decimal" value={manual} onChange={e=>{setManual(e.target.value);clearQuote();}} placeholder="Usar a taxa automática da tabela"/><small>Quando preenchida, substitui a taxa da faixa apenas nesta simulação.</small></label></>}
    </div>
    {isLate&&<LateChargeFields draft={late} onChange={value=>{setLate(value);clearQuote();}} legacy={false} canAdjust={false} simulation company={enc?.mora??null}/>}
    {error&&<div className="cis-simulator-error" role="alert"><AlertCircle size={18}/>{error}</div>}
    <div className="cis-simulator-actions"><button type="button" onClick={()=>{setAmount('');setManual('');setMode('prestacao');setTerm('2 Semanas');setLate(initialLateCharge);setDue('');setReference('');setCapital('');clearQuote();}}>Limpar</button>
     <button className="cis-simulator-primary" type="submit" disabled={!valid||busy}>{busy?<RefreshCw size={17} className="cis-cash-spin"/>:<Calculator size={17}/>} {busy?'A calcular…':'Calcular simulação'} <ArrowRight size={16}/></button></div>
   </form>
   <aside className="cis-simulator-results" aria-live="polite">
    <div className="cis-simulator-panel-title"><span>02</span><div><h3>Resultado da simulação</h3><p>Estimativa sem efeitos financeiros.</p></div></div>
    {quote?<><div className="cis-simulator-total"><span>Resultado estimado</span><strong>{formatMoney(quote.total_estimado)}</strong><small>{quote.mora_detalhe?'Saldo da prestação + mora':quote.tipo_calculo==='prestacao'?'Base acrescida dos juros':'Valor calculado sobre a base'}</small></div>
     <dl className="cis-simulator-breakdown">
      {quote.mora_detalhe?<><div><dt>Saldo da prestação</dt><dd>{formatMoney(quote.saldo_prestacao??'')}</dd></div>
      <div><dt>Vencimento e data de referência</dt><dd>{quote.vencimento?.split('-').reverse().join('/')} · {quote.data_referencia?.split('-').reverse().join('/')}</dd></div>
      <div><dt>Dias em atraso</dt><dd>{quote.dias_atraso}</dd></div>
      <div><dt>Dias sujeitos a mora</dt><dd>{quote.mora_detalhe.dias_sujeitos}</dd></div>
      <div><dt>Períodos cobrados</dt><dd>{quote.mora_detalhe.periodos}</dd></div>
      <div><dt>Base de cálculo</dt><dd>{formatMoney(quote.valor_base)}</dd></div>
      <div><dt>Mora estimada</dt><dd>{formatMoney(quote.acrescimo_calculado)}</dd></div></>:<>
      <div><dt>Valor de referência</dt><dd>{formatMoney(quote.valor_base)}</dd></div>
      <div><dt>Taxa da tabela</dt><dd>{quote.taxa_tabela}%</dd></div>
      <div><dt>Taxa aplicada</dt><dd>{quote.taxa_aplicada}% {quote.taxa_manual&&<small>Manual</small>}</dd></div>
      <div><dt>Juros / acréscimo</dt><dd>{formatMoney(quote.acrescimo_calculado)}</dd></div>
      <div><dt>Prazo considerado</dt><dd>{quote.dias_propostos} dias</dd></div>
      <div><dt>Período da faixa</dt><dd>{quote.periodo_referencia} dias</dd></div>
      <div><dt>Faixa de referência</dt><dd>{quote.faixa.join(' – ')} MT</dd></div></>}
     </dl>
     <div className="cis-simulator-note"><ShieldCheck size={17}/><p>{quote.aviso} {quote.arredondamento}.</p></div>
    </>:<div className="cis-simulator-placeholder"><div><BookOpen size={32}/></div><strong>Pronto para calcular</strong><p>Preencha os parâmetros e escolha «Calcular simulação». O resultado aparecerá aqui, sem alterar qualquer registo.</p></div>}
   </aside>
  </div>
 </section>;
}