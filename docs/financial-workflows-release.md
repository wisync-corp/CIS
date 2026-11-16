# CIS — Entrega conjunta de abertura, prestações semanais e cobrança

**Data:** 16-11-2026. **Fontes:** Contractos.frm (VBA), Cobrança.frm e Parcelas.frm (extraídos do ficheiro Sistema Microcredito.zip fornecido pelo utilizador), além das instruções financeiras expressas nesta conversa.

## 1. Funcionalidades integradas

### Abertura e desembolso
- Pesquisa cliente, número sugerido, saldo do Extracto, tabela de 15/30 dias, prazo e modalidade.
- Fórmulas financeiras em MT inteiros com arredondamento para cima e conciliação da amortização final.
- Transacção única: INSERT das prestações em Contractos + INSERT do desembolso como Saídas no Extracto, sob bloqueio de exclusão. É obrigatório confirmar disponibilização efectiva do dinheiro.
- Aprovação por administrador; apenas novo contrato, sem alterar os históricos.
- A consulta `GET /api/cis/contractos/estado-operacional` indica se a configuração do VPS está efectivamente pronta. Não prova a execução de desembolso real.

### Semana e calendário
- Prazo de 1, 2, 3 semanas = 7, 14, 21 dias, respectivamente.
- Prazo de 1, 2, 3 meses = calendário real, não 30/60/90 dias artificiais.
- Modalidade Semanal = vencimentos de 7 em 7 dias; a última parcela ajusta-se à data final (pode ter intervalo menor).
- Modalidade Mensal = vencimentos por mês e última na data final.
- Uma Parcela = vencimento único no fim do contrato.
- Juros FIXOS: taxa da tabela aplicada uma vez ao capital total, distribuída pelas parcelas; juros e valores monetários sempre inteiros e reconciliados.
- Juros VARIÁVEIS: parcela única com juro inicial zero; na cobrança, cálculo baseado nos dias efectivos desde a abertura até ao pagamento, conforme Cobrança.frm.
- Juros COMPOSTOS com modalidade Semanal permanecem bloqueados: ainda falta aprovação da conversão da taxa de referência de 15/30 dias. **Não inferir a taxa semanal sem decisão da Capta**.
- A opção semanal tem bloqueio separado `CIS_WEEKLY_CONTRACTS_APPROVED`. Os cálculos das fórmulas adicionais dependem ainda de `CIS_EXTENDED_CALCULATIONS_APPROVED`.

### Cobrança
- A análise do **Cobrança.frm** confirmou: pagamento integral vs parcial, estado Pago ou Pago em Atraso, entrada em Extracto por `UpdateTable6`, juros variáveis conforme `Capital*(% de Juro/100)*dias` e multa por decisão do operador.
- O **Parcelas.frm** mostra que, na condição de multa, a taxa é aplicada aos juros usados como referência. O CIS calcula multa opcional `ceil(juros*(% de Multa)/100)` somente se o pagamento estiver atrasado.
- Cobra-se apenas a **totalidade** de uma prestação de contrato criado com a nova coluna de estratégia; não reescrever contratos históricos sem reconciliação.
- Confirmar recebimento real do valor integral antes do UPDATE de uma prestação e INSERT de uma entrada no Extracto na mesma transacção.
- A liquidação da última prestação pendente marca todas as linhas do contrato como Finalizado.
- **Fora do alcance**: cobrança parcial, renovação, compensações, multas retroactivas e correcções a contratos históricos. O VBA tem lógica muito ramificada e algumas actualizações não atómicas: não copiá-las sem rever dados e confirmar o significado de cada movimento.

## 2. Cuidados com o esquema original
- O SQL fornecido mostra `Contractos.Capital` e `Amortização` como INT, mas `Valor a Pagar`, `Valor de Juros` e `Extracto.Valor` como FLOAT.
- Novos valores são inteiros; operações acima de 16.777.216 MT na representação FLOAT são recusadas para não perder exactidão do inteiro.
- O campo `Estratégia de Cálculo` é nullable; novas linhas gravam sempre a estratégia, linhas históricas permanecem inalteradas.
- **O sistema não edita, arredonda ou reescreve os valores financeiros históricos.**

## 3. Activação conjunta

No VPS (e **não** neste chat), a partir da pasta `/opt/cis`:

```bash
cd /opt/cis
git pull origin main
bash deploy/activate-financial-workflows.sh
```

O comando:
1. Confirma estrutura MySQL InnoDB, colunas e leitura privada.
2. Cria backup privado completo.
3. Executa os testes sem gravação do motor financeiro, compatibilidade e prazos/juros/multa.
4. Verifica sintaxe PHP e compila o React.
5. Pede **uma confirmação** `ACTIVAR-FLUXOS` após o operador ter verificado fundos disponíveis, inexistência de outros escritores de dados e resultados dos testes.
6. Acrescenta a coluna opcional de estratégia, se faltar; cria ou utiliza o utilizador de escrita restrita, concede permissões exactas para cobranças e abre as quatro flags financeiras.
7. Reconstrói o frontend e o backend.
8. Verifica rotas, configuração activa, ligação financeira e coluna necessária.

A activação **não cria contratos falsos nem lança movimentos financeiros**. O primeiro desembolso e a primeira cobrança reais devem ser supervisionados pelo operador e conferidos no `Contractos` e `Extracto`. Os testes de código e sinalizadores confirmam prontidão técnica, não equivalência com uma transacção real já efectuada.

## 4. Pendências que continuam expressamente bloqueadas
- Juros compostos com prestação semanal: política de taxa equivalente.
- Pagamentos parciais e renovações: impacto sobre saldo, capital remanescente, multas, histórico e Extracto exige decisão expressa.
- Operações do VBA antigo e do CIS concorrentes na mesma base: desactivar outros processos escritores antes da activação.
