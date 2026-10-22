-- CIS — consultas de diagnóstico em base MySQL de TESTES.
-- Não contém dados de pessoas nem escreve na base.
SELECT COUNT(*) AS total_prestacoes,
       COUNT(DISTINCT `Nr do Contracto`) AS total_contratos,
       COUNT(DISTINCT `Nº de Cliente`) AS total_clientes_com_contrato
FROM Contractos;

SELECT `Estado`, COUNT(*) AS quantidade
FROM Contractos
GROUP BY `Estado`
ORDER BY quantidade DESC;

-- Controlo importante: diferença entre contagem de prestações e empréstimos.
-- Quantia creditada NUNCA é somada sem deduplicação do contrato.
SELECT COUNT(*) AS total_clientes_cadastrados FROM Clientes;

-- Nunca classificar todos os movimentos com a descrição «Receita de Juros»
-- como juros realmente recebidos sem reconciliação por componente.
