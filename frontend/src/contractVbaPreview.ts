/**
 * Prévia estritamente local do cálculo em cmb_adicionar_Click, Contractos.frm.
 * NÃO gera contratos reais nem produz autorização para desembolso.
 * Round(x,2) de VBA usa empate para par (banker's rounding).
 * Valores monetários finais exigem testes de paridade com o Excel e homologação.
 */
export type InterestType='Fixo'|'Variável'|'Composto';
export type PaymentMethod='Mensal'|'Uma Parcela';
export type PreviewLine={
 parcela:number;vencimento:string;capital:number;amortizacao:number;
 juros:number;valorPagar:number;multa:number;valorPago:number;estado:'Pendente';
};
export type ContractPreview={linhas:PreviewLine[];totalJuros:number;totalPagar:number;saldoFinal:number};

export function roundVba(value:number):number{
 if(!Number.isFinite(value))throw new Error('Valor não finito.');
 // Evita diferenças de 0,005 causadas por representação binária.
 const scaled=value*100;
 const floor=Math.floor(scaled);
 const fraction=scaled-floor;
 const epsilon=1e-9;
 const rounded=fraction>0.5+epsilon?floor+1:
  fraction<0.5-epsilon?floor:(floor%2===0?floor:floor+1);
 return rounded/100;
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
 prazoMeses:number;dataInicio:string;
}):ContractPreview{
 const {montante,taxa,tipo,prazoMeses,dataInicio}=input;
 if(!Number.isFinite(montante)||montante<=0||montante>100000000||
    !Number.isFinite(taxa)||taxa<0||taxa>100||
    !Number.isInteger(prazoMeses)||prazoMeses<1||prazoMeses>3){
  throw new Error('Montante, taxa ou prazo inválidos.');
 }
 if(!['Fixo','Variável','Composto'].includes(tipo))throw new Error('Tipo de juro desconhecido.');
 const modalidade=tipo==='Variável'?'Uma Parcela':tipo==='Composto'?'Mensal':input.modalidade;
 const k=tipo==='Variável'||modalidade==='Uma Parcela'?1:prazoMeses;
 const taxaPeriodo=taxa/100;
 const totalJurosFixo=tipo==='Fixo'?roundVba(montante*taxaPeriodo):0;
 const prestacaoBase=tipo==='Composto'
  ?roundVba(taxaPeriodo===0?montante/k:
   (montante*taxaPeriodo*Math.pow(1+taxaPeriodo,k))/(Math.pow(1+taxaPeriodo,k)-1))
  :0;
 let saldoCapital=roundVba(montante),jurosDistribuidos=0;
 const linhas:PreviewLine[]=[];
 for(let i=1;i<=k;i++){
  const vencimento=addMonthsVba(dataInicio,k===1?prazoMeses:i);
  const capital=tipo==='Variável'?roundVba(montante):roundVba(saldoCapital);
  let amortizacao=0,juros=0,valorPagar=0;
  if(tipo==='Variável'){
   amortizacao=roundVba(montante);
   // Cobrança calculará os juros em função da data real de pagamento.
   juros=0;valorPagar=roundVba(montante);
  }else if(tipo==='Composto'){
   juros=roundVba(capital*taxaPeriodo);
   if(i===k){amortizacao=roundVba(saldoCapital);valorPagar=roundVba(amortizacao+juros);}
   else{valorPagar=roundVba(prestacaoBase);amortizacao=roundVba(valorPagar-juros);}
   saldoCapital=roundVba(saldoCapital-amortizacao);
  }else{
   amortizacao=i===k?roundVba(saldoCapital):roundVba(montante/k);
   juros=i===k?roundVba(totalJurosFixo-jurosDistribuidos):roundVba(totalJurosFixo/k);
   if(i!==k)jurosDistribuidos=roundVba(jurosDistribuidos+juros);
   valorPagar=roundVba(amortizacao+juros);
   saldoCapital=roundVba(saldoCapital-amortizacao);
  }
  if(Math.abs(saldoCapital)<0.01)saldoCapital=0;
  if(![capital,amortizacao,juros,valorPagar].every(Number.isFinite))
   throw new Error('Resultado numérico inválido.');
  linhas.push({parcela:i,vencimento,capital,amortizacao,juros,valorPagar,
   multa:0,valorPago:0,estado:'Pendente'});
 }
 return {linhas,totalJuros:roundVba(linhas.reduce((sum,line)=>sum+line.juros,0)),
  totalPagar:roundVba(linhas.reduce((sum,line)=>sum+line.valorPagar,0)),
  saldoFinal:tipo==='Variável'?0:saldoCapital};
}
