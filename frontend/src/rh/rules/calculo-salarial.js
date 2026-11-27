import {configValues,findScale} from './rh-engine.js';
// Regras observadas nas colunas calculadas de tbl_lançamento.
// A origem dos parâmetros e a autorização para processar pertencem ao serviço RH.
const number=(value)=>{
 if(value===null||value===undefined||value==='')return 0;
 const result=Number(value);
 if(!Number.isFinite(result))throw new TypeError('Valor numérico inválido na folha.');
 return result;
};
const round=(value,places=0)=>{
 if(!Number.isInteger(places)||places<0||places>8)throw new RangeError('Precisão de arredondamento inválida.');
 const factor=10**places;
 const absolute=Math.abs(value)*factor;
 return Math.sign(value)*Math.floor(absolute+0.5+Number.EPSILON*absolute)/factor;
};

export function diasUteisMes(ano,mes,feriados){
 if(!Number.isInteger(ano)||ano<1900||!Number.isInteger(mes)||mes<1||mes>12)throw new RangeError('Período inválido.');
 const end=new Date(Date.UTC(ano,mes,0)).getUTCDate();
 const weekday=dia=>{const day=new Date(Date.UTC(ano,mes-1,dia)).getUTCDay();return day>0&&day<6;};
 let result=0;
 for(let dia=1;dia<=end;dia++)if(weekday(dia))result++;
 // A folha desconta uma ocorrência por linha de feriado, inclusive duplicados.
 for(const feriado of feriados){
  const dia=number(feriado.Dia);
  if(number(feriado.Mes)===mes&&dia>=1&&dia<=end&&weekday(dia))result--;
 }
 return result;
}

const parametro=(values,key,fallback)=>{
 const value=values[key]??fallback;
 if(value===undefined||value===null||value==='')throw new Error('Falta o parâmetro '+key+'.');
 return value;
};
const normalize=value=>String(value??'').trim().toUpperCase();

export function calcularValoresFolha(linha,{arredondamento={},escaloesIRPS=[],configuracao=null}={}){
 const ctx=configuracao?.contexto??{};
 const at=configuracao?.dataReferencia;
 if(configuracao&&!at)throw new Error('Indique a data de referência da folha.');
 const values=configuracao?configValues(configuracao.parametros,ctx,at):{};
 const get=(key,legacy)=>parametro(values,key,configuracao?undefined:legacy);
 const baseDias=number(get('BASE_DIAS_MES',30));
 const baseHoras=number(get('BASE_HORAS_DIA',8));
 if(baseDias<=0||baseHoras<=0)throw new RangeError('As bases de dias e horas devem ser superiores a zero.');
 const regime=String(linha.RegimePagamento??'').trim().toUpperCase();
 const tarifa=number(linha['Valor Unitário']);
 const dias=number(linha['Dias Trabalhados']);
 const horas=number(linha['Horas Trabalhadas']);
 const bruto=round(regime==='MENSAL'?(dias<baseDias?round(tarifa/baseDias/baseHoras,2)*baseHoras*dias:tarifa):tarifa*(regime==='HORA'?horas:dias),2);
 const acrescimos=['Horas Extras','Subsídios','Bónus','Outros Acréscimos'].reduce((sum,key)=>sum+number(linha[key]),0);
 const descontos=round(bruto/baseDias/baseHoras,2)*baseHoras*number(linha['Faltas Injustificadas']);
 const base=bruto-descontos+acrescimos;
 const canaliza=normalize(linha.Canalizar)===normalize(get('INSS_CONDICAO_CANALIZAR','Sim'));
 const precision=(key,legacy)=>number(configuracao?parametro(values,key,values.ARRED_CASAS_DEFAULT):legacy);
 const colaborador=canaliza?round(base*number(get('INSS_TAXA_COLAB',0.03)),precision('INSS_COLAB_ARRED_CASAS',arredondamento.Casas_INSS)):0;
 const empresa=canaliza?round(base*number(get('INSS_TAXA_EMP',0.04)),number(get('INSS_EMP_ARRED_CASAS',0))):0;
 let irps=0;
 if(normalize(linha.Departamento)!==normalize(get('IRPS_ISENCAO_DEPARTAMENTO','ISPC'))){
  const dependentes=number(linha.TotalDependentes);
  const maxDependentes=configuracao?number(get('IRPS_DEPENDENTES_MAX',4)):4;
  if(configuracao){
   if(!configuracao.tabelaIRPS)throw new Error('Indique a tabela de IRPS em vigor.');
   const scale=findScale(configuracao.escaloes,configuracao.tabelaIRPS,base,ctx,at);
   if(!scale)throw new Error('Não existe escalão de IRPS aplicável à base calculada.');
   if(!Number.isInteger(dependentes)||dependentes<0)throw new RangeError('Número de dependentes inválido.');
   const effective=Math.min(dependentes,maxDependentes);
   const coluna=effective>=4?'Dependentes_4_Mais':'Dependentes_'+effective;
   irps=round((base-number(scale.Limite_Inferior))*number(scale.Coeficiente_Adicional)+number(scale[coluna]),precision('IRPS_ARRED_CASAS',0));
  }else{
   if(!escaloesIRPS.length)throw new Error('Configure os escalões de IRPS antes de calcular.');
   const ordered=[...escaloesIRPS].sort((a,b)=>number(a['Limite Inferior'])-number(b['Limite Inferior']));
   const scale=ordered.findLast(row=>number(row['Limite Inferior'])<=base);
   if(scale&&Number.isInteger(dependentes)&&dependentes>=0){
    const coluna=dependentes>=4?'4 ou mais':String(dependentes);
    irps=round((base-number(scale['Limite Inferior']))*number(scale['Coeficiente adicional'])+number(scale[coluna]),number(arredondamento.Casas_IRPS));
   }
  }
 }
 return {'Vencimento':bruto,'Total de Acréscimos':acrescimos,'Total de Descontos':descontos,'Vencimento Base':base,
  'INSS Colaborador':colaborador,'INSS Empresa':empresa,'Total INSS':colaborador+empresa,
  'IRPS':irps,'Salario Liquido':base-colaborador-irps-number(linha['Outros Descontos'])};
}
