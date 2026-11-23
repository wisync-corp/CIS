/**
 * Glossário CIS — linguagem de actuação (termos de microcrédito em português de Moçambique).
 * Só muda o que o utilizador vê: os nomes das colunas e os valores gravados na base mantêm-se.
 */

/** Rótulo de cada coluna da base (Contractos/Extracto) mostrado ao utilizador. */
export const COLUNA:Record<string,string>={
 'Nr do Contracto':'N.º do contrato','Contracto':'Contrato','Nº de Cliente':'N.º do mutuário','Nome do Cliente':'Mutuário',
 'Quantia Creditada':'Capital desembolsado','Capital':'Capital','Amortização':'Amortização de capital',
 'Valor de Juros':'Juros','Valor de Multa':'Juros de mora','Valor a Pagar':'Valor da prestação','Valor Pago':'Valor pago',
 '% de Juro':'Taxa de juro (% por período)','% de Multa':'Taxa de juros de mora (%)','Tipo de Juro':'Tipo de juro',
 'Data de Inicio':'Data de início','Data de Cobrança':'Data de vencimento','Data Final':'Vencimento final','Data de Pagamento':'Data de pagamento',
 'M. Pagamento':'Periodicidade','Prazo/Duração':'Prazo','Forma de Pagamento':'Forma de pagamento','Nº da Parcela':'N.º da prestação',
 'Nº de Prestações':'N.º de prestações','Parcela':'Prestação','Estado':'Estado da prestação','Estado do Contrato':'Estado do contrato',
 'Situação de Pagamento':'Situação do pagamento','Estratégia de Cálculo':'Sistema de amortização','Tipo de Movimento':'Tipo de movimento',
};
export const coluna=(k:string)=>COLUNA[k]??k;

/** Estado da prestação (valor gravado → termo). */
export const ESTADO_PRESTACAO:Record<string,string>={
 'Pendente':'Em curso','Pagamento Atrasado':'Em atraso','Pago':'Paga','Pago em Atraso':'Paga com atraso','Renovado':'Renovada',
};
export const estadoPrestacao=(v:unknown)=>{const s=String(v??'').trim();return ESTADO_PRESTACAO[s]??(s||'—');};

/** Situação do contrato (valor calculado → termo). */
export const SITUACAO_CONTRATO:Record<string,string>={
 'Pendente':'Em curso','Pagamento Atrasado':'Em atraso','Pagamento atrasado':'Em atraso','Finalizado':'Liquidado',
};
export const situacaoContrato=(v:unknown)=>{const s=String(v??'').trim();return SITUACAO_CONTRATO[s]??(s||'—');};

/** Glossário mostrado em Definições › Glossário. */
export const GLOSSARIO:{termo:string;significado:string}[]=[
 {termo:'Mutuário',significado:'Pessoa ou empresa que recebe o crédito.'},
 {termo:'Utilizador',significado:'Pessoa da equipa com conta no CIS (email e palavra-passe pessoal).'},
 {termo:'Função',significado:'Papel do utilizador no CIS (ex.: Gestor de crédito); define o que pode ver e fazer. Ver o separador «Funções».'},
 {termo:'Permissão',significado:'Acção autorizada a uma função (ex.: registar pagamentos, ver a Caixa).'},
 {termo:'Agência',significado:'Balcão ou filial da instituição. Quem está numa agência só vê os contratos e as contas dessa agência.'},
 {termo:'Agência principal (Sede)',significado:'Agência onde ficam os contratos ainda não atribuídos a outra agência.'},
 {termo:'Palavra-passe temporária',significado:'Palavra-passe dada pelo administrador a uma conta nova ou reposta; é trocada no primeiro acesso.'},
 {termo:'Capital',significado:'Valor emprestado, sem juros.'},
 {termo:'Desembolso',significado:'Entrega do dinheiro ao mutuário.'},
 {termo:'Capital desembolsado',significado:'Montante entregue no desembolso.'},
 {termo:'Taxa de juro (por período)',significado:'Percentagem cobrada pelo uso do capital; indica-se sempre o período (15 ou 30 dias).'},
 {termo:'Prestação',significado:'Pagamento periódico do crédito.'},
 {termo:'Valor da prestação',significado:'Capital + juros a pagar numa prestação.'},
 {termo:'Periodicidade',significado:'Frequência das prestações.'},
 {termo:'Plano de pagamentos',significado:'Tabela das prestações com datas, capital e juros.'},
 {termo:'Data de vencimento',significado:'Data em que a prestação deve ser paga.'},
 {termo:'Vencimento final',significado:'Data da última prestação.'},
 {termo:'Período de carência / tolerância',significado:'Dias sem pagamento ou sem penalização.'},
 {termo:'Juros de mora',significado:'Penalização por atraso, cobrada sobre valores vencidos e não pagos.'},
 {termo:'Valor vencido',significado:'Montante que já devia ter sido pago.'},
 {termo:'Saldo em dívida',significado:'Tudo o que falta pagar.'},
 {termo:'Prestação em atraso',significado:'Prestação vencida e não paga.'},
 {termo:'Em curso / Em atraso / Liquidado',significado:'Situação do contrato: dentro do prazo, com prestações em atraso, ou sem nada por pagar.'},
 {termo:'Paga / Paga com atraso / Renovada',significado:'Estado da prestação depois do pagamento.'},
 {termo:'Renovação',significado:'Pagamento dos juros com nova prestação no mesmo contrato.'},
 {termo:'Liquidação da prestação',significado:'Pagamento completo de uma prestação (capital, juros e juros de mora).'},
 {termo:'Folha de cobrança',significado:'Lista do que cobrar no período, com contactos.'},
 {termo:'Prorrogação',significado:'Alargar o prazo depois do vencimento.'},
 {termo:'Reestruturação',significado:'Novo plano para um mutuário com dificuldades.'},
 {termo:'Liquidação antecipada',significado:'Pagar tudo antes do prazo.'},
 {termo:'Carteira em risco a 30 dias (PAR 30)',significado:'Capital em dívida com atraso > 30 dias ÷ carteira bruta.'},
 {termo:'Antiguidade dos atrasos',significado:'Carteira em atraso por escalões de dias.'},
 {termo:'Taxa de reembolso',significado:'Valor cobrado ÷ valor vencido no período.'},
 {termo:'Gestor de crédito',significado:'Responsável pela carteira de mutuários.'},
 {termo:'Extracto de conta',significado:'Documento com prestações, pagamentos e saldo do mutuário.'},
 {termo:'Data de fecho',significado:'Data até à qual não se podem alterar lançamentos da Caixa.'},
 {termo:'Sistema de amortização',significado:'Forma de calcular as prestações (taxa flat, sistema francês, SAC…).'},
];

// Funções dos utilizadores (igual à matriz do servidor: App\Support\Permissions).
export const FUNCOES:{id:string;nome:string;definicao:string;pode:string[];naoPode:string[]}[]=[
 {id:'administrador',nome:'Administrador',definicao:'Responsável pelo sistema e pela empresa no CIS. Tem acesso total.',
  pode:['Tudo o que as outras funções fazem','Criar e gerir utilizadores, funções e agências','Alterar as definições da empresa e a parametrização (preços, tipos de movimento, modelos)','Estornar movimentos da Caixa'],naoPode:[]},
 {id:'gestor',nome:'Gestor de crédito',definicao:'Gere a carteira de crédito: analisa, concede, acompanha e cobra.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Cadastrar e editar mutuários','Preparar e abrir contratos (desembolso), também com data passada se a conta tinha saldo nesse dia','Registar pagamentos e operações de cobrança, também com data passada; emitir recibos','Extractos dos mutuários e extracto geral','Centro de mensagens (WhatsApp/SMS)','Relatórios'],
  naoPode:['Caixa (contas, movimentos, fecho)','Definições e utilizadores']},
 {id:'financeiro',nome:'Gestor financeiro',definicao:'Responsável pela Caixa e tesouraria e pelo controlo dos recebimentos.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Caixa: contas, movimentos, transferências, conferência e fecho diário','Registar pagamentos (também com data passada) e emitir recibos','Extractos dos mutuários e extracto geral','Relatórios'],
  naoPode:['Abrir contratos','Editar mutuários','Mensagens','Estornos, definições e utilizadores']},
 {id:'cobrador',nome:'Cobrador / gestor de carteira',definicao:'Acompanha os mutuários e recebe os pagamentos no dia a dia.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Registar pagamentos com a data de hoje e emitir recibos','Extractos dos mutuários','Enviar mensagens (lembretes, recibos, extractos)'],
  naoPode:['Pagamentos com data passada','Abrir contratos ou editar mutuários','Caixa, relatórios, definições e utilizadores']},
 {id:'agente',nome:'Agente / promotor de crédito',definicao:'Angaria e cadastra mutuários e faz simulações no terreno.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Cadastrar e editar mutuários'],
  naoPode:['Registar pagamentos','Abrir contratos','Caixa, extractos, mensagens, relatórios, definições e utilizadores']},
];
