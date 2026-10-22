# Invariantes financeiras do CIS

## Vocabulário
- **Capital**: saldo/principal da operação, distinguindo capital inicialmente creditado do capital por amortizar.
- **Amortização**: redução do capital atribuível a uma operação.
- **Juros**: encargos de remuneração do crédito.
- **Multa**: encargo adicional tratado de forma independente, com processo de dispensa autorizado.
- **Prestação**: obrigação exigível na data contratual, composta por elementos distintos.

## Critérios de implementação
1. Cada recebimento deve registar um valor total e uma distribuição explícita: amortização, juros e multa (e outros componentes apenas se comprovados).
2. Soma dos valores distribuídos = valor recebido, com arredondamento monetário controlado; nunca usar FLOAT em cálculos novos.
3. Redução do capital depende da amortização atribuída, e não do valor total recebido.
4. Não apagar nem substituir o histórico de pagamentos parciais; usar movimentos imutáveis e auditoria.
5. Não definir prioridade de imputação financeira sem evidência do VBA/contrato e validação.
6. Não efectuar escrita em produção até existirem testes comparativos VBA ↔ Web.
7. Multas: cobrador pode solicitar dispensa/alteração, autorizada por gestor ou administrador.

## Estados
Distinguir situação da prestação, situação do contrato e liquidação de encargos; normalizar apenas na camada da aplicação sem reescrever dados históricos arbitrariamente.
