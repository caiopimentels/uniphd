<?php

// Colunas de cada lista (tela e impressão). O usuário escolhe quais aparecem
// e em que ordem (botão de colunas acima da tabela); a impressão sai igual.
// Formato de cada coluna: ver includes/listagem.php.

function telasComColunas(): array
{
    return ['alunos' => 'Alunos', 'turmas' => 'Turmas', 'cursos' => 'Cursos', 'contas_receber' => 'Contas a receber', 'contas_pagar' => 'Contas a pagar'];
}

function definicaoColunasTela(PDO $pdo, string $tela): array
{
    return match ($tela) {
        'alunos' => colunasTelaAlunos(),
        'turmas' => colunasTelaTurmas(),
        'cursos' => colunasTelaCursos(),
        'contas_receber' => colunasTelaReceber($pdo),
        'contas_pagar' => colunasTelaPagar(),
        default => [],
    };
}

function seloAtivo(bool $ativo): string
{
    return $ativo ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>';
}

function colunasTelaAlunos(): array
{
    $colunas = [
        'ra' => ['rotulo' => 'RA', 'ordem' => 'ra', 'classe' => 'text-nowrap small text-muted',
            'html' => fn ($a) => e($a['ra'] ?: '-'), 'texto' => fn ($a) => $a['ra'] ?: '-'],
        'nome' => ['rotulo' => 'Nome', 'ordem' => 'nome',
            'html' => fn ($a) => e($a['nome']), 'texto' => fn ($a) => $a['nome']],
        'cpf' => ['rotulo' => 'CPF', 'ordem' => 'cpf', 'classe' => 'text-nowrap',
            'html' => fn ($a) => e(cpfFormatado($a['cpf']) ?: '-'), 'texto' => fn ($a) => cpfFormatado($a['cpf']) ?: '-'],
        'telefone' => ['rotulo' => 'Telefone', 'ordem' => 'telefone', 'classe' => 'text-nowrap',
            'html' => fn ($a) => e(telefoneFormatado($a['telefone']) ?: '-'), 'texto' => fn ($a) => telefoneFormatado($a['telefone']) ?: '-'],
        'email' => ['rotulo' => 'E-mail', 'ordem' => 'email', 'padrao' => false,
            'html' => fn ($a) => e($a['email'] ?: '-'), 'texto' => fn ($a) => $a['email'] ?: '-'],
        'nascimento' => ['rotulo' => 'Nascimento', 'ordem' => 'nascimento', 'classe' => 'text-nowrap', 'padrao' => false,
            'html' => fn ($a) => $a['data_nascimento'] ? formatDate($a['data_nascimento']) : '-', 'texto' => fn ($a) => $a['data_nascimento'] ? formatDate($a['data_nascimento']) : '-'],
        'matriculas' => ['rotulo' => 'Matrículas ativas', 'ordem' => 'matriculas', 'classe' => 'text-center',
            'html' => fn ($a) => (string) (int) $a['matriculas_ativas'], 'texto' => fn ($a) => (string) (int) $a['matriculas_ativas']],
        'financeiro' => ['rotulo' => 'Financeiro', 'ordem' => 'financeiro',
            'html' => fn ($a) => (float) $a['valor_vencido'] > 0
                ? '<span class="badge text-bg-danger">Em atraso · ' . formatMoney($a['valor_vencido']) . '</span>'
                : '<span class="badge text-bg-success">Em dia</span>',
            'texto' => fn ($a) => (float) $a['valor_vencido'] > 0 ? 'Em atraso · ' . formatMoney($a['valor_vencido']) : 'Em dia'],
        'situacao' => ['rotulo' => 'Situação', 'ordem' => 'situacao',
            'html' => fn ($a) => seloAtivo((bool) $a['ativo']), 'texto' => fn ($a) => $a['ativo'] ? 'Ativo' : 'Inativo'],
    ];
    if (!usuarioPodeVerInadimplencia()) {
        unset($colunas['financeiro']);
    }
    return $colunas;
}

function colunasTelaTurmas(): array
{
    $vagasLivres = fn ($t) => max(0, (int) $t['vagas'] - (int) $t['alunos_ativos'] - (int) $t['reservas']);
    return [
        'turma' => ['rotulo' => 'Turma', 'ordem' => 'turma',
            'html' => fn ($t) => e($t['nome']) . ($t['horario'] ? '<div class="small text-muted">' . e($t['horario']) . '</div>' : ''),
            'texto' => fn ($t) => $t['nome'] . ($t['horario'] ? ' (' . $t['horario'] . ')' : '')],
        'curso' => ['rotulo' => 'Curso', 'ordem' => 'curso',
            'html' => fn ($t) => e($t['curso_nome']), 'texto' => fn ($t) => $t['curso_nome']],
        'professor' => ['rotulo' => 'Professor', 'ordem' => 'professor',
            'html' => fn ($t) => e($t['professor_nome'] ?: '-'), 'texto' => fn ($t) => $t['professor_nome'] ?: '-'],
        'periodo' => ['rotulo' => 'Período', 'ordem' => 'periodo',
            'html' => fn ($t) => e(periodoTurma($t)), 'texto' => fn ($t) => periodoTurma($t)],
        'mensalidade' => ['rotulo' => 'Mensalidade', 'ordem' => 'mensalidade', 'classe' => 'text-end text-nowrap',
            'html' => fn ($t) => formatMoney($t['valor_mensalidade']), 'texto' => fn ($t) => formatMoney($t['valor_mensalidade'])],
        'valor_total' => ['rotulo' => 'Valor total', 'ordem' => 'valor_total', 'classe' => 'text-end text-nowrap',
            'html' => fn ($t) => (float) $t['valor_total'] > 0 ? formatMoney($t['valor_total']) : '-',
            'texto' => fn ($t) => (float) $t['valor_total'] > 0 ? formatMoney($t['valor_total']) : '-'],
        'alunos' => ['rotulo' => 'Alunos', 'ordem' => 'alunos', 'classe' => 'text-center',
            'html' => fn ($t) => (int) $t['alunos_ativos'] . '/' . (int) $t['vagas']
                . ($t['reservas'] ? '<div class="small text-muted" title="Vagas reservadas pela secretaria"><i class="bi bi-bookmark"></i> +' . (int) $t['reservas'] . ' reserv.</div>' : '')
                . ((int) $t['alunos_ativos'] + (int) $t['reservas'] >= (int) $t['vagas'] ? ' <span class="badge text-bg-warning">Lotada</span>' : ''),
            'texto' => fn ($t) => (int) $t['alunos_ativos'] . '/' . (int) $t['vagas'] . ($t['reservas'] ? ' (+' . (int) $t['reservas'] . ' reserv.)' : '')],
        'vagas_livres' => ['rotulo' => 'Vagas livres', 'ordem' => 'vagas_livres', 'classe' => 'text-center', 'padrao' => false,
            'html' => fn ($t) => (string) $vagasLivres($t), 'texto' => fn ($t) => (string) $vagasLivres($t)],
        'situacao' => ['rotulo' => 'Situação', 'ordem' => 'situacao',
            'html' => fn ($t) => badgeSituacaoTurma($t['situacao_nome'], $t['situacao_cor']), 'texto' => fn ($t) => $t['situacao_nome']],
    ];
}

function colunasTelaCursos(): array
{
    return [
        'curso' => ['rotulo' => 'Curso', 'ordem' => 'curso',
            'html' => fn ($c) => e($c['nome']), 'texto' => fn ($c) => $c['nome']],
        'carga' => ['rotulo' => 'Carga horária', 'ordem' => 'carga', 'classe' => 'text-end',
            'html' => fn ($c) => $c['carga_horaria'] ? (int) $c['carga_horaria'] . ' h' : '-', 'texto' => fn ($c) => $c['carga_horaria'] ? (int) $c['carga_horaria'] . ' h' : '-'],
        'abertas' => ['rotulo' => 'Turmas abertas', 'ordem' => 'abertas', 'classe' => 'text-center',
            'html' => fn ($c) => (string) (int) $c['turmas_abertas'], 'texto' => fn ($c) => (string) (int) $c['turmas_abertas']],
        'total' => ['rotulo' => 'Turmas (total)', 'ordem' => 'total', 'classe' => 'text-center',
            'html' => fn ($c) => (string) (int) $c['turmas_total'], 'texto' => fn ($c) => (string) (int) $c['turmas_total']],
        'situacao' => ['rotulo' => 'Situação', 'ordem' => 'situacao',
            'html' => fn ($c) => seloAtivo((bool) $c['ativo']), 'texto' => fn ($c) => $c['ativo'] ? 'Ativo' : 'Inativo'],
    ];
}

function colunasTelaReceber(PDO $pdo): array
{
    $vencida = fn ($c) => $c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d');
    $status = fn ($c) => $vencida($c) ? 'vencido' : $c['status'];
    $forma = fn ($c) => $c['forma_pagamento_id'] ? (string) meiosCobrancaForma($pdo, (int) $c['forma_pagamento_id'])['nome'] : '';
    return [
        'descricao' => ['rotulo' => 'Descrição', 'ordem' => 'descricao',
            'html' => function ($c) use ($pdo, $forma) {
                $html = e($c['descricao']);
                if ($nomeForma = $forma($c)) {
                    $html .= '<div class="small text-muted"><i class="bi bi-wallet2"></i> ' . e($nomeForma) . '</div>';
                }
                if ($selo = seloBancoParcela($pdo, (int) $c['id'])) {
                    $html .= '<div class="mt-1">' . $selo . '</div>';
                }
                return $html;
            },
            'texto' => fn ($c) => $c['descricao']],
        'aluno' => ['rotulo' => 'Aluno/Categoria', 'ordem' => 'aluno',
            'html' => fn ($c) => e($c['aluno_nome'] ?: $c['categoria_nome'] ?: '-'), 'texto' => fn ($c) => $c['aluno_nome'] ?: $c['categoria_nome'] ?: '-'],
        'turma' => ['rotulo' => 'Curso · turma', 'ordem' => 'turma', 'padrao' => false,
            'html' => fn ($c) => e($c['turma_nome'] ? $c['curso_nome'] . ' · ' . $c['turma_nome'] : '-'),
            'texto' => fn ($c) => $c['turma_nome'] ? $c['curso_nome'] . ' · ' . $c['turma_nome'] : '-'],
        'forma' => ['rotulo' => 'Forma de pagamento', 'ordem' => null, 'padrao' => false,
            'html' => fn ($c) => e($forma($c) ?: '-'), 'texto' => fn ($c) => $forma($c) ?: '-'],
        'vencimento' => ['rotulo' => 'Vencimento', 'ordem' => 'vencimento', 'classe' => 'text-nowrap',
            'html' => fn ($c) => '<span class="' . ($vencida($c) ? 'estoque-baixo' : '') . '">' . formatDate($c['data_vencimento']) . '</span>',
            'texto' => fn ($c) => formatDate($c['data_vencimento'])],
        'valor' => ['rotulo' => 'Valor', 'ordem' => 'valor', 'classe' => 'text-end text-nowrap',
            'html' => fn ($c) => formatMoney($c['valor'])
                . ($c['status'] === 'pendente' && $c['desconto_valor'] !== null
                    ? '<div class="small ' . ((float) $c['desconto_valor'] > 0 ? 'text-success' : 'text-muted') . '">'
                        . ((float) $c['desconto_valor'] > 0 ? '− ' . formatMoney($c['desconto_valor']) . ' até ' . formatDate($c['desconto_ate'] ?: $c['data_vencimento']) : 'sem desconto') . '</div>'
                    : ''),
            'texto' => fn ($c) => formatMoney($c['valor'])],
        'pago_em' => ['rotulo' => 'Pago em', 'ordem' => 'pago_em', 'classe' => 'text-nowrap',
            'html' => fn ($c) => $c['status'] === 'pago' && $c['data_recebimento'] ? formatDate($c['data_recebimento']) : '-',
            'texto' => fn ($c) => $c['status'] === 'pago' && $c['data_recebimento'] ? formatDate($c['data_recebimento']) : '-'],
        'valor_pago' => ['rotulo' => 'Valor pago', 'ordem' => 'valor_pago', 'classe' => 'text-end text-nowrap',
            'html' => fn ($c) => $c['status'] === 'pago' ? '<span class="text-success fw-semibold">' . formatMoney($c['valor_recebido'] ?? $c['valor']) . '</span>' : '-',
            'texto' => fn ($c) => $c['status'] === 'pago' ? formatMoney($c['valor_recebido'] ?? $c['valor']) : '-'],
        'status' => ['rotulo' => 'Status', 'ordem' => 'status',
            'html' => fn ($c) => '<span class="badge ' . contaStatusBadgeClass($status($c)) . '">' . contaStatusLabel($status($c)) . '</span>',
            'texto' => fn ($c) => contaStatusLabel($status($c))],
    ];
}

function colunasTelaPagar(): array
{
    $vencida = fn ($c) => $c['status'] === 'pendente' && $c['data_vencimento'] < date('Y-m-d');
    return [
        'descricao' => ['rotulo' => 'Descrição', 'ordem' => 'descricao',
            'html' => fn ($c) => e($c['descricao'])
                . ($c['turma_id'] ? '<div class="small text-muted"><i class="bi bi-people"></i> <a href="/turmas/visualizar.php?id=' . (int) $c['turma_id'] . '">' . e($c['curso_nome'] . ' · ' . $c['turma_nome']) . '</a></div>' : ''),
            'texto' => fn ($c) => $c['descricao'] . ($c['turma_id'] ? ' (' . $c['curso_nome'] . ' · ' . $c['turma_nome'] . ')' : '')],
        'fornecedor' => ['rotulo' => 'Fornecedor/Categoria', 'ordem' => 'fornecedor',
            'html' => fn ($c) => e($c['fornecedor_nome'] ?: $c['categoria_nome'] ?: '-'), 'texto' => fn ($c) => $c['fornecedor_nome'] ?: $c['categoria_nome'] ?: '-'],
        'vencimento' => ['rotulo' => 'Vencimento', 'ordem' => 'vencimento', 'classe' => 'text-nowrap',
            'html' => fn ($c) => '<span class="' . ($vencida($c) ? 'estoque-baixo' : '') . '">' . formatDate($c['data_vencimento']) . '</span>',
            'texto' => fn ($c) => formatDate($c['data_vencimento'])],
        'valor' => ['rotulo' => 'Valor', 'ordem' => 'valor', 'classe' => 'text-end text-nowrap',
            'html' => fn ($c) => formatMoney($c['valor']), 'texto' => fn ($c) => formatMoney($c['valor'])],
        'pago_em' => ['rotulo' => 'Pago em', 'ordem' => 'pago_em', 'classe' => 'text-nowrap', 'padrao' => false,
            'html' => fn ($c) => $c['status'] === 'pago' && $c['data_pagamento'] ? formatDate($c['data_pagamento']) : '-',
            'texto' => fn ($c) => $c['status'] === 'pago' && $c['data_pagamento'] ? formatDate($c['data_pagamento']) : '-'],
        'status' => ['rotulo' => 'Status', 'ordem' => 'status',
            'html' => fn ($c) => '<span class="badge ' . contaStatusBadgeClass($vencida($c) ? 'vencido' : $c['status']) . '">' . contaStatusLabel($vencida($c) ? 'vencido' : $c['status']) . '</span>',
            'texto' => fn ($c) => contaStatusLabel($vencida($c) ? 'vencido' : $c['status'])],
    ];
}
