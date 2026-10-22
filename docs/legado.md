# Engenharia inversa do legado

Fonte recebida: sistema VBA e exportações MySQL das tabelas Clientes, Contractos, Extracto e Usuarios.

## Tabelas confirmadas por CREATE TABLE
- Clientes: PK ID, campos de identificação e contacto.
- Contractos: PK Ordem; Nr do Contracto, Nº da Parcela, Nº de Prestações, Quantia Creditada, Capital, Amortização, Valor de Juros, Valor de Multa, Valor a Pagar, Valor Pago, datas, Estado e usuario.
- Extracto: PK Nr; Contracto, Parcela, Cliente, Tipo de Movimento, Descrição, Data da Operação, Valor, usuario.
- Usuarios: PK Id_cli; Username, Password, T_Usuario, Status, Email.

## Questões técnicas a validar automaticamente
- Cardinalidade de linhas por contrato e seus vínculos com prestações/renovações.
- Distribuição real de pagamentos totais/parciais nos diferentes procedimentos VBA.
- Existência de juros fixos, variáveis e respectivos algoritmos.
- Preenchimento de Capital e Amortização em prestações criadas por pagamentos parciais.
- Reconciliação de registos de Extracto com Contractos.

Não carregar exportações com dados pessoais neste repositório público.
