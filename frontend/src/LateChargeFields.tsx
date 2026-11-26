const methods=[
 ['fixo_unico','Valor fixo único'],['fixo_periodico','Valor fixo periódico'],
 ['percentual_unico','Percentagem única'],['percentual_periodico','Percentagem periódica simples'],
 ['escaloes','Escalões de atraso'],['misto','Modelo misto'],['sem_mora','Sem regra de mora aplicável'],
] as const;
type Tier={dias:string;valor:string};
export const initialLateCharge={
 tipo:'',valor:'',taxa:'',base:'saldo',periodicidade:'diaria',dias_periodo:'',carencia:'0',
 contar_desde_vencimento:false,periodos_iniciados:false,tecto:'',escaloes:[{dias:'1',valor:''}] as Tier[],
 acumular:false,componente:'fixo',valor_recorrente:'',decisao:'integral',valor_reduzido:'',motivo:'',
};
type Draft=typeof initialLateCharge;
export type LateChargeDetails={dias_sujeitos:number;periodos:number;base_centimos:number;calculado_centimos:number;cobrado_centimos:number;tipo:string};
const periodic=(type:string)=>['fixo_periodico','percentual_periodico','misto'].includes(type);
const percentage=(type:string,d:Draft)=>type.startsWith('percentual')||(type==='misto'&&d.componente==='percentagem');
const numeric=(v:string,digits:number)=>new RegExp('^\\d+(?:[.,]\\d{1,'+digits+'})?$').test(v.trim());
const decimal=(v:string)=>v.trim().replace(',','.');
export function lateChargeRule(d:Draft,legacy:boolean){
 const tipo=legacy?'legado':d.tipo;
 if(!tipo)return null;
 const fixed=tipo.startsWith('fixo')||tipo==='misto', rec=periodic(tipo), pct=percentage(tipo,d);
 const fixedExtra=tipo==='misto'&&d.componente==='fixo';
 if((fixed&&!numeric(d.valor,2))||(pct&&!numeric(d.taxa,4))||(fixedExtra&&!numeric(d.valor_recorrente,2))
    ||(rec&&d.periodicidade==='personalizada'&&!/^\d+$/.test(d.dias_periodo))
    ||(tipo==='escaloes'&&d.escaloes.some(t=>!/^\d+$/.test(t.dias)||!numeric(t.valor,2)))
    ||(!legacy&&tipo!=='sem_mora'&&(!/^\d+$/.test(d.carencia)||(d.tecto!==''&&!numeric(d.tecto,2))))
    ||(tipo!=='sem_mora'&&d.decisao==='reduzir'&&!numeric(d.valor_reduzido,2))
    ||(tipo!=='sem_mora'&&d.decisao!=='integral'&&!d.motivo.trim()))return null;
 return {tipo,...(!legacy&&tipo!=='sem_mora'?{carencia:Number(d.carencia),contar_desde_vencimento:d.contar_desde_vencimento,...(d.tecto!==''?{tecto:decimal(d.tecto)}:{})}:{}),
  ...(fixed?{valor:decimal(d.valor)}:{}),...(pct?{taxa:decimal(d.taxa),base:d.base}:{}),
  ...(rec?{periodicidade:d.periodicidade,periodos_iniciados:d.periodos_iniciados,...(d.periodicidade==='personalizada'?{dias_periodo:Number(d.dias_periodo)}:{})}:{}),
  ...(tipo==='escaloes'?{acumular:d.acumular,escaloes:d.escaloes.map(t=>({dias:Number(t.dias),valor:decimal(t.valor)}))}:{}),
  ...(tipo==='misto'?{componente:d.componente,...(fixedExtra?{valor_recorrente:decimal(d.valor_recorrente)}:{})}:{}),
  decisao:tipo==='sem_mora'?'integral':d.decisao,...(tipo!=='sem_mora'&&d.decisao!=='integral'?{motivo:d.motivo.trim(),...(d.decisao==='reduzir'?{valor_reduzido:decimal(d.valor_reduzido)}:{})}:{}),
 };
}
const amount=(cents:number)=>new Intl.NumberFormat('pt-MZ',{minimumFractionDigits:2,maximumFractionDigits:2}).format(cents/100)+' MT';
export default function LateChargeFields({draft:d,onChange,legacy,canAdjust,details}:{draft:Draft;onChange:(d:Draft)=>void;legacy:boolean;canAdjust:boolean;details?:LateChargeDetails|null}){
 const update=<K extends keyof Draft,>(name:K,value:Draft[K])=>onChange({...d,[name]:value});
 const input=(k:'valor'|'taxa'|'valor_recorrente'|'carencia'|'tecto'|'dias_periodo'|'valor_reduzido',label:string,mode:'decimal'|'numeric'='decimal')=>
  <label>{label}<input inputMode={mode} value={d[k]} onChange={e=>update(k,e.target.value)}/></label>;
 const check=(k:'periodos_iniciados'|'contar_desde_vencimento'|'acumular',label:string)=>
  <label className="cis-collections-check"><input type="checkbox" checked={d[k]} onChange={e=>update(k,e.target.checked)}/>{label}</label>;
 const tier=(i:number,k:keyof Tier,v:string)=>update('escaloes',d.escaloes.map((t,j)=>i===j?{...t,[k]:v}:t));
 return <fieldset className="cis-collections-technical">
  <legend>Mora da prestação</legend>
  {legacy?<p>Aplica-se a regra de mora já registada na prestação.</p>:<label>Tratamento de mora
   <select value={d.tipo} onChange={e=>onChange({...d,tipo:e.target.value,decisao:'integral'})}><option value="">Seleccione o tratamento aplicável</option>{methods.map(([key,label])=><option key={key} value={key}>{label}</option>)}</select>
  </label>}
  {!legacy&&d.tipo&&d.tipo!=='sem_mora'&&<>
   <div className="cis-collections-fields">
    {(d.tipo.startsWith('fixo')||d.tipo==='misto')&&input('valor',d.tipo==='misto'?'Encargo inicial (MT)':'Valor fixo (MT)')}
    {d.tipo==='misto'&&<label>Componente periódica<select value={d.componente} onChange={e=>update('componente',e.target.value)}><option value="fixo">Valor fixo</option><option value="percentagem">Percentagem</option></select></label>}
    {d.tipo==='misto'&&d.componente==='fixo'&&input('valor_recorrente','Valor por período (MT)')}
    {percentage(d.tipo,d)&&<>{input('taxa','Taxa (%)')}<label>Base de cálculo<select value={d.base} onChange={e=>update('base',e.target.value)}><option value="saldo">Saldo em dívida da prestação</option><option value="capital">Capital em dívida</option></select></label></>}
    {periodic(d.tipo)&&<><label>Periodicidade<select value={d.periodicidade} onChange={e=>update('periodicidade',e.target.value)}><option value="diaria">Diária</option><option value="semanal">Semanal</option><option value="quinzenal">Quinzenal (15 dias)</option><option value="mensal">Mensal (mês de calendário)</option><option value="personalizada">A cada N dias</option></select></label>
     {d.periodicidade==='personalizada'&&input('dias_periodo','Dias por período','numeric')}</>}
    {input('carencia','Carência (dias)','numeric')}{input('tecto','Tecto em MT (opcional)')}
   </div>
   {check('contar_desde_vencimento','Ultrapassada a carência, contar desde o vencimento')}
   {periodic(d.tipo)&&check('periodos_iniciados','Cobrar também períodos iniciados')}
   {d.tipo==='escaloes'&&<>
    {check('acumular','Somar os escalões atingidos')}
    {d.escaloes.map((t,i)=><div className="cis-collections-fields" key={i}>
     <label>Escalão {i+1} · a partir de dias<input inputMode="numeric" value={t.dias} onChange={e=>tier(i,'dias',e.target.value)}/></label>
     <label>Valor (MT)<input inputMode="decimal" value={t.valor} onChange={e=>tier(i,'valor',e.target.value)}/></label>
     {d.escaloes.length>1&&<button type="button" className="cis-edit-action" onClick={()=>update('escaloes',d.escaloes.filter((_,j)=>i!==j))}>Remover escalão {i+1}</button>}
    </div>)}
    {d.escaloes.length<30&&<button type="button" className="cis-edit-action" onClick={()=>update('escaloes',[...d.escaloes,{dias:'',valor:''}])}>Adicionar escalão</button>}
   </>}
  </>}
  {canAdjust&&(legacy||d.tipo&&d.tipo!=='sem_mora')&&<div className="cis-collections-fields"><label>Cobrança da mora
   <select value={d.decisao} onChange={e=>update('decisao',e.target.value)}><option value="integral">Cobrar integralmente</option><option value="reduzir">Reduzir</option><option value="dispensar">Dispensar</option></select></label>
   {d.decisao==='reduzir'&&input('valor_reduzido','Mora a cobrar (MT)')}
   {d.decisao!=='integral'&&<label>Motivo<input value={d.motivo} maxLength={200} onChange={e=>update('motivo',e.target.value)}/></label>}
  </div>}
  {details&&<p role="status">{details.dias_sujeitos} dia(s) sujeitos a mora · {details.periodos} período(s) · Base {amount(details.base_centimos)} · Mora calculada {amount(details.calculado_centimos)} · <strong>A cobrar {amount(details.cobrado_centimos)}</strong></p>}
 </fieldset>;
}
