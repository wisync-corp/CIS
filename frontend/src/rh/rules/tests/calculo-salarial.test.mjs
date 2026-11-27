import test from 'node:test';
import assert from 'node:assert/strict';
import {calcularValoresFolha,diasUteisMes} from '../calculo-salarial.js';

test('mensal com falta, acréscimos, descontos, INSS e IRPS por dependentes',()=>{
 const r=calcularValoresFolha({RegimePagamento:'Mensal','Valor Unitário':24000,'Dias Trabalhados':30,'Faltas Injustificadas':1,
  'Horas Extras':100,'Subsídios':150,'Bónus':100,'Outros Acréscimos':50,'Outros Descontos':100,Canalizar:'Sim',TotalDependentes:4},
  {arredondamento:{Casas_INSS:2,Casas_IRPS:2},escaloesIRPS:[{'Limite Inferior':0,'Coeficiente adicional':0,'4 ou mais':0},{'Limite Inferior':20000,'Coeficiente adicional':0.1,'4 ou mais':200}]});
 assert.deepEqual(r,{'Vencimento':24000,'Total de Acréscimos':400,'Total de Descontos':800,'Vencimento Base':23600,
  'INSS Colaborador':708,'INSS Empresa':944,'Total INSS':1652,'IRPS':560,'Salario Liquido':22232});
});

test('jornada mensal parcial mantém o arredondamento intermédio da tarifa horária',()=>{
 const r=calcularValoresFolha({RegimePagamento:'Mensal','Valor Unitário':1000,'Dias Trabalhados':17,Departamento:'ISPC'});
 assert.equal(r.Vencimento,567.12);
});

test('hora e diário calculam sobre as unidades indicadas',()=>{
 const input={'Valor Unitário':10,'Dias Trabalhados':20,'Horas Trabalhadas':3.5,Departamento:'ISPC'};
 assert.equal(calcularValoresFolha({...input,RegimePagamento:'Hora'}).Vencimento,35);
 assert.equal(calcularValoresFolha({...input,RegimePagamento:'Diário'}).Vencimento,200);
});

test('não calcula retenção sem a tabela configurada',()=>{
 assert.throws(()=>calcularValoresFolha({RegimePagamento:'Mensal'}),/escalões de IRPS/);
});

test('calendário mensal exclui fins de semana e feriados em dia útil',()=>{
 assert.equal(diasUteisMes(2026,2,[{Mes:2,Dia:2},{Mes:2,Dia:7},{Mes:3,Dia:2}]),19);
});
