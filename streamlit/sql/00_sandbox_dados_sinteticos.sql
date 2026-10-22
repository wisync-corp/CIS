-- CIS: base FICTÍCIA para testes locais. NUNCA executar na base de produção.
-- Criar apenas em MySQL isolado. A conta do dashboard usa exclusivamente SELECT.
CREATE DATABASE IF NOT EXISTS cis_dashboard_sandbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cis_dashboard_sandbox;

CREATE TABLE IF NOT EXISTS Clientes (
    ID INT PRIMARY KEY,
    `Nome Completo` VARCHAR(150),
    `Estado Civil` VARCHAR(40)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS Contractos (
    `Ordem` INT PRIMARY KEY,
    `Nr do Contracto` VARCHAR(80),
    `Nº da Parcela` INT,
    `Nº de Prestações` INT,
    `Nº de Cliente` INT,
    `Nome do Cliente` VARCHAR(150),
    `Data de Inicio` DATE,
    `Data de Cobrança` DATE,
    `Prazo/Duração` VARCHAR(80),
    `M. Pagamento` VARCHAR(60),
    `Intervalo` VARCHAR(80),
    `Quantia Creditada` DECIMAL(16,2),
    `Capital` DECIMAL(16,2),
    `Amortização` DECIMAL(16,2),
    `Valor de Juros` DECIMAL(16,2),
    `Valor de Multa` DECIMAL(16,2),
    `Valor a Pagar` DECIMAL(16,2),
    `Valor Pago` DECIMAL(16,2),
    `Estado` VARCHAR(60),
    `Estado do Contrato` VARCHAR(60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dados sintéticos. IDs e nomes não são de clientes reais.
INSERT INTO Clientes (ID,`Nome Completo`,`Estado Civil`) VALUES
(900001,'CLIENTE TESTE A','Solteiro'),
(900002,'CLIENTE TESTE B','Casado')
ON DUPLICATE KEY UPDATE `Nome Completo`=VALUES(`Nome Completo`),`Estado Civil`=VALUES(`Estado Civil`);

INSERT INTO Contractos
(`Ordem`,`Nr do Contracto`,`Nº da Parcela`,`Nº de Prestações`,`Nº de Cliente`,`Nome do Cliente`,
`Data de Inicio`,`Data de Cobrança`,`Prazo/Duração`,`M. Pagamento`,`Intervalo`,
`Quantia Creditada`,`Capital`,`Amortização`,`Valor de Juros`,`Valor de Multa`,
`Valor a Pagar`,`Valor Pago`,`Estado`,`Estado do Contrato`) VALUES
(900001,'CIS-TESTE-001',1,2,900001,'CLIENTE TESTE A','2026-09-01','2026-10-01','2 meses','Mensal','5000 - 10000',10000,10000,5000,750,0,5750,5750,'Pago','Em Progresso'),
(900002,'CIS-TESTE-001',2,2,900001,'CLIENTE TESTE A','2026-09-01','2026-11-01','2 meses','Mensal','5000 - 10000',10000,5000,5000,750,0,5750,0,'Pendente','Em Progresso'),
(900003,'CIS-TESTE-002',1,1,900002,'CLIENTE TESTE B','2026-10-01','2026-11-01','1 mês','Mensal','10000 - 20000',15000,15000,15000,2250,0,17250,0,'Pendente','Em Progresso')
ON DUPLICATE KEY UPDATE `Estado`=VALUES(`Estado`),`Valor Pago`=VALUES(`Valor Pago`);

-- Validação rápida:
SELECT COUNT(*) AS prestacoes_teste,
       COUNT(DISTINCT `Nr do Contracto`) AS contratos_teste
FROM Contractos;
-- Esperado: 3 prestações e 2 contratos (se a sandbox só tiver estes registos).

-- IMPORTANTE: criar um utilizador MySQL separado com privilégio SELECT.
-- Não incluir a password real desse utilizador neste ficheiro.
