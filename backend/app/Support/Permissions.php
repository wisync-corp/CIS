<?php

namespace App\Support;

/**
 * Matriz de permissões do CIS por função.
 *
 * Uma única fonte para o servidor (controladores) e para a interface (menus e botões,
 * via /auth/me). O administrador tem tudo («*»). Acrescentar uma função ou permissão
 * aqui reflecte-se em todo o sistema.
 */
final class Permissions
{
    /** Permissões conhecidas (rótulo para o ecrã de utilizadores). */
    public const PERMISSIONS = [
        'ver'              => 'Consultar mutuários, contratos, visão geral e simulador',
        'mutuarios.editar' => 'Editar a ficha dos mutuários',
        'contratos.abrir'  => 'Preparar e abrir contratos (desembolso)',
        'cobrancas'        => 'Registar pagamentos e operações de cobrança; recibos',
        'cobrancas.data'   => 'Registar pagamentos com data passada',
        'caixa'            => 'Caixa: contas, movimentos, transferências, conferência e fecho',
        'caixa.estornar'   => 'Estornar movimentos da Caixa',
        'extractos'        => 'Extractos dos mutuários',
        'extractos.geral'  => 'Extracto geral da Caixa',
        'mensagens'        => 'Centro de mensagens (WhatsApp/SMS)',
        'relatorios'       => 'Relatórios regulatórios e de gestão',
        'definicoes'       => 'Definições da empresa e parametrização',
        'utilizadores'     => 'Gerir utilizadores e funções',
    ];

    /** Funções: rótulo, descrição e permissões. */
    public const ROLES = [
        'administrador' => ['label' => 'Administrador', 'descricao' => 'Acesso total, incluindo utilizadores e definições.', 'perms' => ['*']],
        'gestor' => ['label' => 'Gestor de crédito', 'descricao' => 'Carteira de crédito: mutuários, contratos, cobranças, extractos, mensagens e relatórios.',
            'perms' => ['ver', 'mutuarios.editar', 'contratos.abrir', 'cobrancas', 'cobrancas.data', 'extractos', 'extractos.geral', 'mensagens', 'relatorios']],
        'financeiro' => ['label' => 'Gestor financeiro', 'descricao' => 'Caixa e tesouraria, recebimentos, extractos e relatórios.',
            'perms' => ['ver', 'cobrancas', 'cobrancas.data', 'caixa', 'extractos', 'extractos.geral', 'relatorios']],
        'cobrador' => ['label' => 'Cobrador / gestor de carteira', 'descricao' => 'Recebe pagamentos com a data de hoje, emite recibos e contacta os mutuários.',
            'perms' => ['ver', 'cobrancas', 'extractos', 'mensagens']],
        'agente' => ['label' => 'Agente / promotor de crédito', 'descricao' => 'Cadastra e actualiza mutuários e faz simulações.',
            'perms' => ['ver', 'mutuarios.editar']],
    ];

    public static function roleKeys(): array
    {
        return array_keys(self::ROLES);
    }

    /** Lista efectiva de permissões de um utilizador (vazia se inactivo ou função desconhecida). */
    public static function of(?object $user): array
    {
        if (!$user || !($user->active ?? false)) return [];
        // Palavra-passe temporária por trocar: só pode trocar a palavra-passe (sem acesso aos dados).
        if ($user->must_change_password ?? false) return [];
        $perms = self::ROLES[$user->role ?? '']['perms'] ?? [];
        return in_array('*', $perms, true) ? array_keys(self::PERMISSIONS) : $perms;
    }

    public static function can(?object $user, string ...$any): bool
    {
        $mine = self::of($user);
        foreach ($any as $p) if (in_array($p, $mine, true)) return true;
        return false;
    }

    /** 403 com mensagem clara quando o utilizador não tem nenhuma das permissões indicadas. */
    public static function require(?object $user, string|array $perms, ?string $message = null): void
    {
        $perms = (array) $perms;
        if (self::can($user, ...$perms)) return;
        $what = self::PERMISSIONS[$perms[0]] ?? $perms[0];
        abort(403, $message ?? 'Sem permissão para: '.mb_strtolower($what).'. Peça ao administrador.');
    }
}
