import test from 'node:test';
import assert from 'node:assert/strict';
import {calcularValoresFolha} from '../calculo-salarial.js';

const param=(Codigo_Parametro,Valor,extra={})=>({Codigo_Parametro,Valor,Tipo_Valor:typeof Valor==='number'?'Decimal':'Texto',
 Nivel_Aplicacao:'Global',Estado:'Activo',Vigencia_Inicio:'2026-01-01',Versao:1,...extra});
const parametros=[
 param('BASE_DIAS_MES',20),param('BASE_HORAS_DIA',8),param('INSS_CONDICAO_CANALIZAR','Sim'),
 param('INSS_TAXA_COLAB',.02),param('INSS_TAXA_EMP',.04),param('INSS_EMP_ARRED_CASAS',0),
 param('ARRED_CASAS_DEFAULT',2),param('IRPS_ISENCAO_DEPARTAMENTO','ISPC'),param('IRPS_DEPENDENTES_MAX',4),
 param('INSS_TAXA_COLAB',.05,{Nivel_Aplicacao:'Empresa',Empresa:'Empresa fictícia'}),
 param('INSS_TAXA_COLAB',.9,{Nivel_Aplicacao:'Empresa',Empresa:'Empresa fictícia',Vigencia_Inicio:'2027-01-01'}),
];
const escaloes=[{ID_Tabela_Escaloes:'IRPS-TESTE',Nivel_Aplicacao:'Global',Estado:'Activo',Limite_Inferior:0,
 Limite_Superior:null,Coeficiente_Adicional:.1,Dependentes_0:10,Dependentes_4_Mais:5}];
const contexto={empresa:'Empresa fictícia'};
const configuracao={parametros,escaloes,tabelaIRPS:'IRPS-TESTE',contexto,dataReferencia:'2026-10-20'};
const linha={RegimePagamento:'Mensal','Valor Unitário':1600,'Dias Trabalhados':10,Canalizar:'Sim',TotalDependentes:5};

test('a folha usa a base e as taxas vigentes nas Definições em vez dos valores históricos',()=>{
 const r=calcularValoresFolha(linha,{configuracao});
 assert.equal(r.Vencimento,800);
 assert.equal(r['INSS Colaborador'],40);
 assert.equal(r['INSS Empresa'],32);
 assert.equal(r.IRPS,85);
 assert.equal(r['Salario Liquido'],675);
});
test('falta de parâmetro ou escalão vigente bloqueia o cálculo configurável',()=>{
 assert.throws(()=>calcularValoresFolha(linha,{configuracao:{...configuracao,parametros:parametros.slice(1)}}),/BASE_DIAS_MES/);
 assert.throws(()=>calcularValoresFolha(linha,{configuracao:{...configuracao,escaloes:[{...escaloes[0],Vigencia_Inicio:'2027-01-01'}]}}),/Nenhum escalão/);
});
