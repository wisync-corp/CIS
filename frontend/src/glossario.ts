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
export const GLOSSARIO:{termo:string;significado:string;antes?:string}[]=[
 {termo:'Mutuário',significado:'Cliente que recebe o crédito.',antes:'Cliente (no contrato)'},
 {termo:'Capital',significado:'Valor emprestado, sem juros.'},
 {termo:'Desembolso',significado:'Entrega do dinheiro ao mutuário.'},
 {termo:'Capital desembolsado',significado:'Montante entregue no desembolso.',antes:'Quantia creditada'},
 {termo:'Taxa de juro (por período)',significado:'Percentagem cobrada pelo uso do capital; indica-se sempre o período (15 ou 30 dias).',antes:'% de juro'},
 {termo:'Prestação',significado:'Pagamento periódico do crédito.',antes:'Parcela'},
 {termo:'Valor da prestação',significado:'Capital + juros a pagar numa prestação.',antes:'Valor a pagar'},
 {termo:'Periodicidade',significado:'Frequência das prestações.',antes:'Modalidade de pagamento'},
 {termo:'Plano de pagamentos',significado:'Tabela das prestações com datas, capital e juros.',antes:'Plano de prestações'},
 {termo:'Data de vencimento',significado:'Data em que a prestação deve ser paga.',antes:'Data de cobrança'},
 {termo:'Vencimento final',significado:'Data da última prestação.',antes:'Data final'},
 {termo:'Período de carência / tolerância',significado:'Dias sem pagamento ou sem penalização.'},
 {termo:'Juros de mora',significado:'Penalização por atraso, cobrada sobre valores vencidos e não pagos.',antes:'Multa'},
 {termo:'Valor vencido',significado:'Montante que já devia ter sido pago.'},
 {termo:'Saldo em dívida',significado:'Tudo o que falta pagar.',antes:'Em dívida'},
 {termo:'Prestação em atraso',significado:'Prestação vencida e não paga.',antes:'Vencida / Pagamento atrasado'},
 {termo:'Em curso / Em atraso / Liquidado',significado:'Situação do contrato: dentro do prazo, com prestações em atraso, ou sem nada por pagar.',antes:'Pendente / Pagamento atrasado / Finalizado'},
 {termo:'Paga / Paga com atraso / Renovada',significado:'Estado da prestação depois do pagamento.',antes:'Pago / Pago em atraso / Renovado'},
 {termo:'Renovação',significado:'Pagamento dos juros com nova prestação no mesmo contrato.',antes:'Renovar empréstimo'},
 {termo:'Liquidação da prestação',significado:'Pagamento completo de uma prestação (capital, juros e juros de mora).',antes:'Pagamento integral'},
 {termo:'Folha de cobrança',significado:'Lista do que cobrar no período, com contactos.',antes:'Agenda de cobranças'},
 {termo:'Prorrogação',significado:'Alargar o prazo depois do vencimento.'},
 {termo:'Reestruturação',significado:'Novo plano para um mutuário com dificuldades.'},
 {termo:'Liquidação antecipada',significado:'Pagar tudo antes do prazo.'},
 {termo:'Carteira em risco a 30 dias (PAR 30)',significado:'Capital em dívida com atraso > 30 dias ÷ carteira bruta.',antes:'Carteira em risco > 30 dias'},
 {termo:'Antiguidade dos atrasos',significado:'Carteira em atraso por escalões de dias.'},
 {termo:'Taxa de reembolso',significado:'Valor cobrado ÷ valor vencido no período.'},
 {termo:'Gestor de crédito',significado:'Responsável pela carteira de mutuários.',antes:'Operador'},
 {termo:'Extracto de conta',significado:'Documento com prestações, pagamentos e saldo do mutuário.',antes:'Extracto do cliente'},
 {termo:'Data de fecho',significado:'Data até à qual não se podem alterar lançamentos da Caixa.',antes:'Fecho de Caixa'},
 {termo:'Sistema de amortização',significado:'Forma de calcular as prestações (taxa flat, sistema francês, SAC…).',antes:'Estratégia de cálculo'},
];
