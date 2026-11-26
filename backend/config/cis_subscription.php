<?php
/**
 * Subscrição do CIS (licença do software, paga pela instituição à WiSync).
 * Estes pagamentos NÃO são reembolsos de mutuários e nunca tocam na Caixa nem no Extracto.
 * Preços em MT por mês. «referencia» = preço normal depois do lançamento (mostrado riscado).
 */
return [
    'planos' => [
        'arranque' => ['nome' => 'Arranque', 'preco' => 1500, 'referencia' => 1500, 'utilizadores' => 2, 'extra' => 350,
            'descricao' => 'Operadores muito pequenos: uma agência, a equipa mínima.'],
        'essencial' => ['nome' => 'Essencial', 'preco' => 1900, 'referencia' => 2500, 'utilizadores' => 3, 'extra' => 350,
            'descricao' => 'Administrador, gestor de carteira e caixa/cobrador.'],
        'profissional' => ['nome' => 'Profissional', 'preco' => 3500, 'referencia' => 4500, 'utilizadores' => 10, 'extra' => 300,
            'descricao' => 'Várias agências, carteiras por gestor e equipa de cobrança.'],
        'institucional' => ['nome' => 'Institucional', 'preco' => 6900, 'referencia' => 8500, 'utilizadores' => 20, 'extra' => 250,
            'descricao' => 'Rede de agências e equipas grandes.'],
    ],
    // Módulos opcionais: só ficam à venda quando existirem no CIS.
    'modulos' => [
        'tesouraria' => ['nome' => 'Tesouraria', 'preco' => 900, 'disponivel' => false,
            'descricao' => 'Planeamento, controlo de despesas e recebimentos e reconciliação bancária.'],
        'rh' => ['nome' => 'Recursos Humanos', 'preco' => 700, 'disponivel' => false,
            'descricao' => 'Colaboradores, contratos, adendas e lançamentos de RH.'],
    ],
    'meses_gratis_anual' => 2,          // pagamento anual: paga 10 meses, usa 12
    'dias_teste' => 14,
    'dias_aviso' => 7,                  // aviso antes do vencimento
    'dias_carencia' => 7,               // depois do vencimento, antes de bloquear novos utilizadores
    'plano_inicial' => 'essencial',
    'taxa_implementacao' => ['min' => 5000, 'max' => 7500],

    // Pagamento: «manual» (transferência para o número da WiSync + confirmação pela WiSync na VPS),
    // «api» (M-Pesa Open API da Vodacom / USSD Push e-Mola da Movitel) ou «simulacao» (só testes).
    'simulacao' => env('CIS_PAGAMENTOS_SIMULACAO', false),
    'mpesa' => [
        'numero_manual' => env('CIS_MPESA_NUMERO', ''),       // número/conta M-Pesa da WiSync para pagamento manual
        'api_key' => env('MPESA_API_KEY', ''),
        'public_key' => env('MPESA_PUBLIC_KEY', ''),
        'service_provider_code' => env('MPESA_SERVICE_PROVIDER_CODE', ''),
        'host' => env('MPESA_HOST', 'api.sandbox.vm.co.mz'),  // produção: api.vm.co.mz
        'origin' => env('MPESA_ORIGIN', '*'),
    ],
    'emola' => [
        'numero_manual' => env('CIS_EMOLA_NUMERO', ''),
        'wsdl' => env('EMOLA_WSDL', ''),                      // ex.: https://IP:PORTA/BCCSGateway/BCCSGateway?wsdl
        'username' => env('EMOLA_USERNAME', ''),
        'password' => env('EMOLA_PASSWORD', ''),
        'key' => env('EMOLA_KEY', ''),
        'partner_code' => env('EMOLA_PARTNER_CODE', ''),
        'callback_secret' => env('EMOLA_CALLBACK_SECRET', ''),
    ],
];
