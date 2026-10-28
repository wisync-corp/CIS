-- FICÇÃO: dados fabricados para testar a consulta do CIS. Nunca usar como dados reais.
CREATE TABLE Clientes (
 `ID` INT PRIMARY KEY AUTO_INCREMENT,
 `Numeração` INT, `Nome Completo` VARCHAR(155), `Nº de BI` VARCHAR(50),
 `Nacionalidade` VARCHAR(100), `Contacto` INT, `Bairro` VARCHAR(155),
 `Cidade` VARCHAR(155), `Nº da Casa` VARCHAR(50), `Nº de Quarterão` VARCHAR(50),
 `Email` VARCHAR(155), `Conduta` VARCHAR(100), `Provincia` VARCHAR(155),
 `Data de Emissão` DATE, `Estado Civil` VARCHAR(70), `Rua` VARCHAR(155),
 `Naturalidade` VARCHAR(100), `usuario` VARCHAR(155)
);
INSERT INTO Clientes (`Numeração`,`Nome Completo`,`Bairro`,`Cidade`,`Provincia`,`usuario`)
 VALUES (1,'Cliente Fictício Alfa','Bairro de Teste A','Maputo','Maputo','teste'),
        (2,'Cliente Fictício Beta','Bairro de Teste B','Matola','Maputo','teste');
CREATE TABLE Contractos (
 `Ordem` INT PRIMARY KEY AUTO_INCREMENT, `Nr do Contracto` VARCHAR(155),
 `Nº da Parcela` INT, `Nº de Prestações` INT, `Nº de Cliente` INT,
 `Quantia Creditada` FLOAT, `Capital` INT,`Amortização` INT,
 `Valor de Juros` FLOAT, `Valor de Multa` FLOAT, `Valor a Pagar` FLOAT,
 `Valor Pago` FLOAT, `Data de Cobrança` DATE,
 `Estado` VARCHAR(100), `Estado do Contrato` VARCHAR(100), `usuario` VARCHAR(155)
);
INSERT INTO Contractos (`Nr do Contracto`,`Nº da Parcela`,`Nº de Prestações`,`Nº de Cliente`,`Quantia Creditada`,`Capital`,`Amortização`,`Valor de Juros`,`Valor de Multa`,`Valor a Pagar`,`Valor Pago`,`Data de Cobrança`,`Estado`,`Estado do Contrato`,`usuario`)
 VALUES ('TESTE-001',1,2,1,1000,1000,500,50,0,550,0,'2026-11-01','Pendente','Activo','teste');
CREATE TABLE Extracto (
 `Nr` INT PRIMARY KEY AUTO_INCREMENT,`Contracto` VARCHAR(155),`Parcela` INT,
 `Cliente` VARCHAR(155),`Tipo de Movimento` VARCHAR(100),
 `Descrição` VARCHAR(255),`Data da Operação` DATE,`Valor` FLOAT,
 `usuario` VARCHAR(155)
);
INSERT INTO Extracto (`Contracto`,`Parcela`,`Cliente`,`Tipo de Movimento`,`Descrição`,`Data da Operação`,`Valor`,`usuario`)
 VALUES ('TESTE-001',1,'Cliente Fictício Alfa','Teste','Movimento fictício','2026-10-28',0,'teste');
CREATE USER 'cis_test_reader'@'%' IDENTIFIED BY 'cis_test_only_password';
GRANT SELECT ON cis_test_replica.* TO 'cis_test_reader'@'%';
