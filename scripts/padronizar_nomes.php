<?php

// Padroniza os nomes já cadastrados dos alunos (nome, nome da mãe e do pai)
// e os endereços (rua, complemento, bairro, cidade; UF maiúscula): só a
// primeira letra de cada palavra maiúscula — o mesmo que o sistema faz ao
// salvar. Rodar uma vez no servidor:
//
//   sudo -u www-data php scripts/padronizar_nomes.php          → só mostra o que mudaria
//   sudo -u www-data php scripts/padronizar_nomes.php --gravar → grava

require __DIR__ . '/../includes/bootstrap_cli.php';

$pdo = getDB();
$gravar = in_array('--gravar', $argv, true);
$atualizar = $pdo->prepare('UPDATE alunos SET nome = ?, nome_mae = ?, nome_pai = ? WHERE id = ?');
$alterados = 0;

foreach ($pdo->query('SELECT id, nome, nome_mae, nome_pai FROM alunos ORDER BY id')->fetchAll() as $a) {
    $novo = [
        'nome' => formatarNomePessoa((string) $a['nome']),
        'nome_mae' => $a['nome_mae'] === null ? null : formatarNomePessoa($a['nome_mae']),
        'nome_pai' => $a['nome_pai'] === null ? null : formatarNomePessoa($a['nome_pai']),
    ];
    if ($novo['nome'] === $a['nome'] && $novo['nome_mae'] === $a['nome_mae'] && $novo['nome_pai'] === $a['nome_pai']) {
        continue;
    }
    $alterados++;
    echo '#' . $a['id'] . ': ' . $a['nome'] . ' → ' . $novo['nome'] . "\n";
    if ($gravar) {
        $atualizar->execute([$novo['nome'], $novo['nome_mae'], $novo['nome_pai'], $a['id']]);
    }
}

$atualizarEndereco = $pdo->prepare('UPDATE aluno_enderecos SET logradouro = ?, complemento = ?, bairro = ?, cidade = ?, uf = ? WHERE id = ?');
$enderecos = 0;
$formatar = fn ($v) => $v === null ? null : formatarNomePessoa($v);
foreach ($pdo->query('SELECT id, aluno_id, logradouro, complemento, bairro, cidade, uf FROM aluno_enderecos ORDER BY id')->fetchAll() as $e) {
    $novo = [$formatar($e['logradouro']), $formatar($e['complemento']), $formatar($e['bairro']), $formatar($e['cidade']),
        $e['uf'] === null ? null : mb_strtoupper(trim($e['uf']), 'UTF-8')];
    if ($novo === [$e['logradouro'], $e['complemento'], $e['bairro'], $e['cidade'], $e['uf']]) {
        continue;
    }
    $enderecos++;
    echo 'Endereço do aluno #' . $e['aluno_id'] . ': ' . $e['logradouro'] . ', ' . $e['cidade'] . ' → ' . $novo[0] . ', ' . $novo[3] . "\n";
    if ($gravar) {
        $atualizarEndereco->execute([...$novo, $e['id']]);
    }
}

if ($gravar && ($alterados || $enderecos)) {
    registrarAtividadeSistema('alunos', 'edicao', 'Cadastros padronizados (primeira letra maiúscula): ' . $alterados . ' nome(s) e ' . $enderecos . ' endereço(s).');
}
echo $alterados . ' nome(s) e ' . $enderecos . ' endereço(s) ' . ($gravar ? 'padronizado(s).' : 'seriam alterados. Rode com --gravar para gravar.') . "\n";
