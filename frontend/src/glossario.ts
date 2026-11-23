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
 'Pendente':'Em curso','Pagamento Atrasado':'Em atraso','Pago':'Paga','Pago em Atraso':'Paga com atraso','Renovado':'Renovada','Abatido':'Abatida',
};
export const estadoPrestacao=(v:unknown)=>{const s=String(v??'').trim();return ESTADO_PRESTACAO[s]??(s||'—');};

/** Situação do contrato (valor calculado → termo). */
export const SITUACAO_CONTRATO:Record<string,string>={
 'Pendente':'Em curso','Pagamento Atrasado':'Em atraso','Pagamento atrasado':'Em atraso','Finalizado':'Liquidado','Abatido':'Abatido',
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
 {termo:'Contacto de cobrança',significado:'Registo de cada chamada, mensagem ou visita ao mutuário, com o resultado e notas. Fica na ficha do contrato (separador Contactos).'},
 {termo:'Promessa de pagamento',significado:'Compromisso do mutuário de pagar um valor até uma data. Fica cumprida quando os pagamentos do contrato até essa data chegam ao valor; falhada quando a data passa sem isso.'},
 {termo:'Agenda de cobrança',significado:'Lista do dia com as promessas para hoje, promessas falhadas, contactos agendados, atrasos sem contacto e vencimentos de hoje (folha de cobrança).'},
 {termo:'Lembretes automáticos',significado:'Mensagens calculadas todos os dias pelo CIS (promessa para hoje, dias de atraso definidos, vencimento hoje ou em breve). São enviadas pela fila do WhatsApp Web, sem repetir o mesmo contrato no mesmo dia.'},
 {termo:'Proposta de crédito',significado:'Pedido de crédito com a análise do mutuário (rendimento, despesas, histórico e score). Só depois de aprovada se pode abrir o contrato e desembolsar.'},
 {termo:'Proposta não levantada',significado:'Proposta aprovada em que o mutuário não veio buscar o dinheiro (desistiu ou já não precisa). Fecha a proposta sem contrato e liberta a aprovação.'},
 {termo:'Aviso ao mutuário',significado:'Mensagem de WhatsApp enviada quando a proposta é aprovada, recusada ou não levantada. Os avisos por enviar aparecem nas Propostas e no Centro de Mensagens (Avisos de propostas).'},
 {termo:'Limites da agência',significado:'Montante mínimo/máximo e taxa mínima/máxima que cada agência aceita nas propostas (Definições › Agências). Uma proposta fora dos limites não pode ser submetida nem aprovada.'},
 {termo:'Limite de aprovação',significado:'Montante até ao qual o gestor de crédito pode aprovar propostas (Definições › Credit scoring). Acima disso decide o administrador. Ninguém aprova a própria proposta, excepto o administrador.'},
 {termo:'Taxa de esforço',significado:'Parte do rendimento mensal do mutuário comprometida com prestações: (encargo mensal do crédito + outras prestações) ÷ rendimento mensal.'},
 {termo:'Reforço de crédito',significado:'Aumento do capital de uma prestação em curso (juro fixo): o valor novo sai da Caixa como «Aumento de Crédito» e os juros são recalculados; o vencimento mantém-se. Conta como dinheiro emprestado nos relatórios.'},
 {termo:'Abate de crédito',significado:'Retirar da carteira activa o saldo em dívida de um contrato considerado incobrável, sem movimento de Caixa. Exige 90 dias de atraso; antes disso, só como abate antecipado justificado. Pode ser anulado se ainda não houver recuperações.'},
 {termo:'Recuperação de crédito abatido',significado:'Valor recebido depois do abate; entra na Caixa e reduz o saldo abatido por recuperar.'},
 {termo:'Carteira',significado:'Conjunto de contratos ou mutuários pelos quais uma pessoa responde. O gestor de carteira e o agente só veem a sua carteira.'},
 {termo:'Gestor de carteira (responsável)',significado:'Utilizador com a função Cobrador atribuído a um contrato; acompanha e cobra esse contrato. Atribui-se na ficha do contrato ou em massa em Definições › Carteiras.'},
 {termo:'Agente do mutuário',significado:'Utilizador com a função Agente que cadastrou o mutuário (ou a quem foi atribuído). Conta para o desempenho do agente.'},
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
  pode:['Tudo o que as outras funções fazem','Ver o desempenho de toda a equipa (gestores de crédito, de carteira e agentes)','Reforçar, abater (e anular o abate) e recuperar créditos','Aprovar propostas de qualquer montante (incluindo as próprias)','Criar e gerir utilizadores, funções, agências e carteiras','Alterar as definições da empresa e a parametrização (preços, tipos de movimento, modelos)','Estornar movimentos da Caixa'],naoPode:[]},
 {id:'gestor',nome:'Gestor de crédito',definicao:'Gere a carteira de crédito: analisa, concede, acompanha e cobra.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Cadastrar e editar mutuários','Criar propostas e aprovar as dos outros até ao limite de aprovação','Abrir contratos a partir de propostas aprovadas (desembolso), também com data passada se a conta tinha saldo nesse dia','Registar pagamentos e operações de cobrança, também com data passada; emitir recibos','Extractos dos mutuários e extracto geral','Centro de mensagens (WhatsApp/SMS)','Relatórios'],
  naoPode:['Aprovar as próprias propostas ou acima do limite','Caixa (contas, movimentos, fecho)','Desempenho da equipa','Definições e utilizadores']},
 {id:'financeiro',nome:'Gestor financeiro',definicao:'Responsável pela Caixa e tesouraria e pelo controlo dos recebimentos. Função opcional: numa empresa pequena o administrador assume esta parte.',
  pode:['Consultar mutuários, contratos, visão geral e simulador','Caixa: contas, movimentos, transferências, conferência e fecho diário','Registar pagamentos (também com data passada) e emitir recibos','Extractos dos mutuários e extracto geral','Relatórios'],
  naoPode:['Abrir contratos','Editar mutuários','Mensagens','Estornos, definições e utilizadores']},
 {id:'cobrador',nome:'Cobrador / gestor de carteira',definicao:'Acompanha os mutuários da sua carteira e recebe os pagamentos no dia a dia.',
  pode:['Ver só a sua carteira: os contratos de que é responsável e os mutuários desses contratos','Registar pagamentos com a data de hoje e emitir recibos','Extractos dos mutuários','Enviar mensagens (lembretes, recibos, extractos)'],
  naoPode:['Ver contratos de outros gestores de carteira','Pagamentos com data passada','Abrir contratos ou editar mutuários','Caixa, relatórios, definições e utilizadores']},
 {id:'agente',nome:'Agente / promotor de crédito',definicao:'Angaria e cadastra mutuários e faz simulações no terreno.',
  pode:['Cadastrar e editar mutuários','Ver só os mutuários que cadastrou (ou lhe foram atribuídos) e os contratos deles','Criar e submeter propostas de crédito para os seus mutuários','Simulador'],
  naoPode:['Ver mutuários de outros agentes','Aprovar propostas','Registar pagamentos','Abrir contratos','Caixa, extractos, mensagens, relatórios, definições e utilizadores']},
];
