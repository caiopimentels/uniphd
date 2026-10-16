<?php
// Ações em massa dos cadastros simples (alunos, cursos, professores): ativar,
// desativar e excluir os itens marcados na lista. Cada endpoint massa.php
// passa a configuração da sua tabela.

/**
 * $cfg: tabela, modulo (auditoria), rotulo ('aluno'...), lista (URL da lista),
 *       perm_ativar, perm_excluir (chaves de permissão),
 *       impedimento (fn (PDO, int $id): ?string — motivo para não excluir).
 * Redireciona para a lista com o resumo do que foi feito.
 */
function executarAcaoMassaCadastro(PDO $pdo, array $cfg): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($cfg['lista']);
    }
    $acao = input('acao');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    $voltar = (string) ($_POST['voltar'] ?? '');
    if (!str_starts_with($voltar, $cfg['lista'])) {
        $voltar = $cfg['lista'];
    }
    if (!$ids || !in_array($acao, ['ativar', 'desativar', 'excluir'], true)) {
        flash('danger', 'Marque ao menos um item e escolha a ação.');
        redirect($voltar);
    }
    $permissao = $acao === 'excluir' ? $cfg['perm_excluir'] : $cfg['perm_ativar'];
    if (!usuarioTemPermissao($permissao)) {
        flash('danger', 'Seu perfil não pode fazer esta ação.');
        redirect($voltar);
    }

    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, nome FROM {$cfg['tabela']} WHERE id IN ({$marcas})");
    $stmt->execute($ids);
    $registros = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $feitos = [];
    $pulados = [];
    foreach ($registros as $id => $nome) {
        if ($acao === 'excluir') {
            $motivo = $cfg['impedimento']($pdo, (int) $id);
            if ($motivo) {
                $pulados[] = $nome . ' (' . $motivo . ')';
                continue;
            }
            $pdo->prepare("DELETE FROM {$cfg['tabela']} WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = ? WHERE id = ?")->execute([$acao === 'ativar' ? 1 : 0, $id]);
        }
        $feitos[] = $nome;
    }

    $verbo = ['ativar' => 'ativado(s)', 'desativar' => 'desativado(s)', 'excluir' => 'excluído(s)'][$acao];
    if ($feitos) {
        registrarAtividade($cfg['modulo'], $acao === 'excluir' ? 'exclusao' : 'edicao',
            mb_substr(ucfirst($cfg['rotulo']) . ' ' . $verbo . ' em massa: ' . implode(', ', $feitos), 0, 255));
        flash('success', count($feitos) . ' ' . $cfg['rotulo'] . '(s) ' . $verbo . '.');
    }
    if ($pulados) {
        flash('warning', 'Não excluídos — desative em vez disso: ' . implode('; ', $pulados) . '.');
    }
    redirect($voltar);
}
