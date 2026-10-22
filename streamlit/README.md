# CIS — Dashboard em Streamlit

Implementação baseada no `Dashboard.py` fornecido pela Capta. Mantém três secções: **Desempenho do Portfólio de Microcrédito**, **Análise de Clientes** e **Desempenho Financeiro e Crescimento**. Ano, mês, prazo, modalidade e cliente são filtros. A interface é apenas de leitura.

## Segurança
- Autentica via **Laravel CIS API** `POST /api/auth/login` e confirma a sessão em `GET /api/auth/me`.
- Apenas perfis **administrador** e **gestor** têm acesso global ao dashboard. Os outros perfis ficam bloqueados.
- A ligação SQL ocorre apenas no **servidor Streamlit**, com credenciais vindas de variáveis de ambiente, conta MySQL com **SELECT exclusivamente** e, durante desenvolvimento, base **copiada/anonimizada**.
- Não reutiliza a ligação hardcoded nem o ficheiro CSS absoluto incluídos em `Dashboard.py`. **Alterar imediatamente a senha exposta no ficheiro original**, antes de qualquer implantação.
- Não colocar o dashboard publicamente acessível sem HTTPS, protecção de acesso, restrições de rede e avaliação de segurança. Tokens residem na sessão do Streamlit, sem ser gravados no navegador pelo código da aplicação.
- Não importar/copiar os dados reais ou dumps SQL para o GitHub.

## Executar
```bash
cd streamlit
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
# exportar CIS_API_URL e LEGACY_DB_* no servidor, sem colocar senhas no código
streamlit run app.py
```

É necessário arrancar previamente o backend Laravel CIS e criar a primeira conta administrativa com `php artisan cis:bootstrap-admin email@empresa.test`. A API e o serviço MySQL de testes devem estar acessíveis do servidor Streamlit.

## Definição dos números
As linhas da tabela `Contractos` representam prestações, não necessariamente empréstimos. O volume de empréstimos e a quantia creditada são apurados **uma vez por Nr do Contracto** para não duplicar capital. `Valor de Juros` representa **juros previstos/contratuais**, não necessariamente juros recebidos. `Valor Pago` representa **recebimentos registados**, não lucro. `Amortização`, `Juros` e `Multa` só devem alimentar saldos de cobrança reais após reconciliação com o VBA.

O dashboard *não* apresenta lucro, juros recebidos ou dívida de capital calculada por suposições; nesses casos, mostra uma nota de validação pendente.
