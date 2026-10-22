# Diagnóstico de contabilização do legado — NÃO concluir totais pelos rótulos

Fonte: DDL e linhas exemplificativas dos exports `Contractos.sql` e `Extracto.sql`, fornecidos para engenharia inversa. **Não publicar dumps**, nem dados pessoais, nem reproduzir credenciais.

## Evidência concreta e anonimizada

A tabela `Contractos` tem colunas independentes:
`Capital` (INT), `Amortização` (INT), `Valor de Juros` (FLOAT), `Valor de Multa` (FLOAT), `Valor a Pagar` (FLOAT), `Valor Pago` (FLOAT).

Numa prestação ilustrativa efectivamente observada no dump (sem identificador do cliente):
- `Capital = 10.000`
- `Amortização = 10.000`
- `Valor de Juros = 2.500`
- `Valor a Pagar = 12.500`
- `Valor Pago = 12.500`
- `Valor de Multa = 0`

A tabela `Extracto` tem registo de entrada associado à prestação cuja **descrição é `Receita de Juros` e o valor é 12.500**, isto é, aparenta representar o **pagamento total**, e não apenas os 2.500 de juros.

### Implicação

**Nunca contabilizar todo o movimento rotulado `Receita de Juros` como rendimento de juros no dashboard.** Tal procedimento iria sobreavaliar as receitas e confundir recuperação de capital com rendimento. É necessário reconciliar `Contractos` + `Extracto` por contrato, parcela e eventos, tendo em conta parciais, renovações, aumentos de crédito e possíveis duplicações.

Exemplo de teste esperado:
- Total recebido = 12.500
- Amortização recebida = 10.000
- Juros recebidos = 2.500
- Multa recebida = 0

**Atenção:** os valores acima são uma *referência de teste de uma linha* e não permitem generalizar uma regra para todas as operações.

## Regras técnicas

1. Nunca mapear directamente a descrição do Extracto para rubricas contabilísticas sem reconciliação.
2. O dashboard deve mostrar um estado *indisponível* em vez de um indicador financeiro inventado.
3. A associação entre registos deve ser validada antes da contabilização.
4. Desembolsos e aumentos de crédito são saídas de caixa mas aumentam capital financiado; não são despesas operacionais por defeito.
5. A diferença entre o capital inicial e o capital recuperado não é uma medida de lucro.
6. Verificar integralmente os procedimentos VBA responsáveis pelos movimentos antes de implementar lançamentos de escrita.
7. Criar testes comparativos por tipo de operação com valores exatos e montantes em centavos.
