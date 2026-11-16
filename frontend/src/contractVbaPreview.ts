/**
 * Pré-visualização em meticais inteiros (regra CIS 16-11-2026).
 * As estratégias TOTAL, SIMPLE e DECLINING são regras financeiras novas,
 * não são transcrições do Contractos.frm. A API recalcula tudo na gravação.
 */
export type InterestType='Fixo'|'Variável'|'Composto';
export type PaymentMethod='Mensal'|'Semanal'|'Uma Parcela';
export type Strategy=
 |'Amortização fixa com juro decrescente'
 |'Amortização fixa com juro fixo'
 |'Prestação fixa pelo total'
 |'Prestação fixa simples'
 |'Tabela Price'
 |'Juro variável na cobrança';
export type PreviewLine={
 parcela:number;vencimento:string;capital:number;amortizacao:number;
 juros:number;valorPagar:number;multa:number;valorPago:number;estado:'Pendente';
};
export type ContractPreview={linhas:PreviewLine[];totalJuros:number;totalPagar:number;saldoFinal:number};

function roundUp(value:number):number{
 if(!Number.isFinite(value)||value<0||value>1e9)throw new Error('Resultado financeiro inválido.');
 return Math.ceil(value-1e-9);
}
export function addWeeks(iso:string,weeks:number):string{
 const [y,m,d]=iso.split('-').map(Number);
 const next=new Date(Date.UTC(y,m-1,d+7*weeks));
 return next.toISOString().slice(0,10);
}
export function addMonthsVba(iso:string,months:number):string{
 if(!/^\d{4}-\d{2}-\d{2}$/.test(iso))throw new Error('Data inválida.');
 const [year,month,day]=iso.split('-').map(Number);
 const first=new Date(Date.UTC(year,month-1+months,1));
 const last=new Date(Date.UTC(first.getUTCFullYear(),first.getUTCMonth()+1,0)).getUTCDate();
 first.setUTCDate(Math.min(day,last));
 return first.toISOString().slice(0,10);
}
export function previewContractVba(input:{
 montante:number;taxa:number;tipo:InterestType;modalidade:PaymentMethod;
 prazoMeses:number;dataInicio:string;estrategia:Strategy;prazo?:string;periodoDias?:number;
}):ContractPreview{
 const {montante,taxa,tipo,prazoMeses,dataInicio,estrategia}=input;
 if(!Number.isSafeInteger(montante)||montante<=0||montante>16777216||
  !Number.isFinite(taxa)||taxa<0||taxa>100||
  !Number.isInteger(prazoMeses)||prazoMeses<0||prazoMeses>3)
  throw new Error('Montante inteiro, taxa ou prazo inválidos.');
 const compatible:Record<InterestType,Strategy[]>={
  Fixo:['Amortização fixa com juro fixo','Prestação fixa pelo total','Prestação fixa simples'],
  Composto:['Amortização fixa com juro decrescente','Tabela Price'],
  Variável:['Juro variável na cobrança'],
 };
 if(!compatible[tipo]?.includes(estrategia))throw new Error('Estratégia incompatível com o tipo de juro.');
 if(tipo==='Variável'&&input.modalidade!=='Uma Parcela'||
    tipo==='Composto'&&input.modalidade!=='Mensal')
   throw new Error('Modalidade incompatível com o tipo de juro.');
 const prazo=input.prazo??(prazoMeses+' '+(prazoMeses===1?'Mês':'Meses'));
 const isWeeklyTerm=/^[123] Semanas?$/.test(prazo);
 const weeks=isWeeklyTerm?Number(prazo[0]):0;
 const isoFinal=isWeeklyTerm?addWeeks(input.dataInicio,weeks):addMonthsVba(input.dataInicio,prazoMeses);
 const startMs=Date.parse(input.dataInicio+'T00:00:00Z');
 const finalMs=Date.parse(isoFinal+'T00:00:00Z');
 const days=(finalMs-startMs)/86400000;
 if(!Number.isInteger(days)||days<=0)throw new Error('Prazo inválido.');
 if(tipo==='Composto'&&input.modalidade==='Semanal')throw new Error('Juros compostos semanais exigem política da taxa de referência.');
 const k=tipo==='Variável'||input.modalidade==='Uma Parcela'?1:
  input.modalidade==='Semanal'?Math.ceil(days/7):prazoMeses;
 if(k<1||montante<k)throw new Error('Montante insuficiente para o número de prestações.');
 const r=taxa/100;
 const totalInterest=['Amortização fixa com juro fixo','Prestação fixa pelo total'].includes(estrategia)
  ?roundUp(montante*r):0;
 const totalDue=estrategia==='Prestação fixa pelo total'?montante+totalInterest:0;
 const perPay=totalDue?roundUp(totalDue/k):0;
 const perPrincipal=roundUp(montante/k);
 const perInterest=roundUp(totalInterest/k);
 const price=estrategia==='Tabela Price'?roundUp(r===0?montante/k:
  (montante*r*Math.pow(1+r,k))/(Math.pow(1+r,k)-1)):0;
 let remaining=montante,accruedInterest=0,totalPaid=0;
 const linhas:PreviewLine[]=[];
 for(let i=1;i<=k;i++){
  const last=i===k;
  const capital=remaining;
  let amortizacao=last?remaining:Math.min(remaining,perPrincipal);
  let juros=0,valorPagar=0;
  switch(estrategia){
   case 'Juro variável na cobrança':
    valorPagar=amortizacao;break;
   case 'Amortização fixa com juro fixo':
    juros=last?totalInterest-accruedInterest:Math.min(perInterest,totalInterest-accruedInterest);
    valorPagar=amortizacao+juros;break;
   case 'Prestação fixa pelo total':
    valorPagar=last?totalDue-totalPaid:Math.min(perPay,totalDue-totalPaid);
    juros=valorPagar-amortizacao;break;
   case 'Prestação fixa simples':
    juros=roundUp(montante*r);valorPagar=amortizacao+juros;break;
   case 'Amortização fixa com juro decrescente':
    juros=roundUp(remaining*r);valorPagar=amortizacao+juros;break;
   case 'Tabela Price':
    juros=roundUp(remaining*r);
    valorPagar=last?remaining+juros:price;
    amortizacao=last?remaining:valorPagar-juros;break;
  }
  if(amortizacao<=0||amortizacao>remaining||juros<0||valorPagar!==amortizacao+juros)
   throw new Error('A estratégia produziu valores inválidos. Não é possível continuar.');
  remaining-=amortizacao;
  accruedInterest+=juros;
  totalPaid+=valorPagar;
  const vencimento=last?isoFinal:input.modalidade==='Semanal'?addWeeks(dataInicio,i):addMonthsVba(dataInicio,i);
  linhas.push({parcela:i,vencimento,capital,amortizacao,juros,
   valorPagar,multa:0,valorPago:0,estado:'Pendente'});
 }
 if(remaining!==0||linhas.reduce((a,b)=>a+b.amortizacao,0)!==montante
    ||totalPaid!==montante+accruedInterest)
  throw new Error('Falha na reconciliação de capital e juros.');
 return {linhas,totalJuros:accruedInterest,totalPagar:totalPaid,saldoFinal:remaining};
}
