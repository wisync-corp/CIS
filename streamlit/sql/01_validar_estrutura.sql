-- CIS — pré-validação da base legada (somente leitura).
-- Executar no esquema MySQL de TESTES, não em produção.
-- Nenhum comando INSERT/UPDATE/DELETE/ALTER.
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('Contractos','Clientes','Extracto','Usuarios')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Resultado esperado: pelo menos as quatro tabelas acima.
-- Conferir campos financeiros isoladamente; não inferir tipo contabilístico da descrição do extracto.
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME='Contractos' AND COLUMN_NAME IN
       ('Nr do Contracto','Quantia Creditada','Valor a Pagar','Valor Pago',
        'Capital','Amortização','Valor de Juros','Valor de Multa','Data de Inicio',
        'Data de Cobrança','Estado'))
      OR
      (TABLE_NAME='Clientes' AND COLUMN_NAME IN ('ID','Nome Completo','Estado Civil'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
