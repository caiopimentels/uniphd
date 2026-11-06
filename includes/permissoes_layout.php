<?php
// Como as permissões aparecem na tela de Perfis, no estilo do Bling: abas
// por área e, em cada linha, a caixa principal (ver/usar), "Inclusão e
// edição", "Exclusão" e as permissões extras logo abaixo. Cada caixa marca
// uma ou mais permissões (chaves da tabela permissoes). Permissão nova que
// não estiver aqui aparece sozinha na aba "Outras".
//
// Linha: 'titulo', 'ver' (chave ou null = só rótulo), 'incluir' e
// 'excluir' (listas de chaves) e 'extras' (chave => texto).

function estruturaPermissoes(): array
{
    return [
        'Acadêmico' => [
            ['titulo' => 'Alunos', 'ver' => 'ver_alunos', 'incluir' => ['cadastrar_aluno', 'editar_aluno'], 'excluir' => ['excluir_aluno'],
                'extras' => ['ativar_aluno' => 'Ativar e desativar alunos', 'avaliar_documentos' => 'Aprovar, recusar ou dispensar documentos dos alunos',
                    'ver_cpf_completo' => 'Ver o CPF completo', 'usar_postits' => 'Criar post-its no aluno e avisar usuários e perfis']],
            ['titulo' => 'Cursos', 'ver' => 'ver_cursos', 'dica' => 'Ver cursos, turmas, professores e calendário de aulas',
                'incluir' => ['criar_curso', 'editar_curso'], 'excluir' => ['excluir_curso']],
            ['titulo' => 'Turmas', 'ver' => null, 'incluir' => ['criar_turma', 'editar_turma'], 'excluir' => ['excluir_turma'],
                'extras' => ['concluir_turma' => 'Concluir e reabrir turmas', 'ver_receita_turma' => 'Ver a receita mensal prevista na tela da turma',
                    'ver_despesas_turma' => 'Ver as despesas da turma na tela da turma']],
            ['titulo' => 'Professores', 'ver' => null, 'incluir' => ['gerenciar_professores', 'editar_professor'], 'excluir' => ['excluir_professor']],
            ['titulo' => 'Materiais e videoaulas', 'ver' => 'gerenciar_materiais', 'dica' => 'Usuário professor: só nas turmas dele'],
            ['titulo' => 'Matrículas', 'ver' => null, 'incluir' => ['gerenciar_matriculas'], 'excluir' => ['cancelar_matricula'],
                'dica_incluir' => 'Matricular, reservar vaga, confirmar e concluir', 'dica_excluir' => 'Cancelar, excluir e tirar aluno da turma',
                'extras' => ['trancar_matricula' => 'Trancar e destrancar matrícula', 'liberar_certificado' => 'Liberar e bloquear certificados e declarações']],
        ],
        'Financeiro' => [
            ['titulo' => 'Financeiro dos alunos', 'ver' => 'ver_inadimplencia', 'dica' => 'Aba Financeiro do aluno, Inadimplência, boleto e Pix',
                'incluir' => ['lancar_receber', 'editar_financeiro_aluno'], 'excluir' => ['excluir_receber'],
                'extras' => ['baixar_receber' => 'Dar baixa em contas a receber (inclusive várias de uma vez)',
                    'estornar_receber' => 'Estornar baixa manual (a parcela volta a ficar em aberto)',
                    'gerenciar_cupons' => 'Cadastrar cupons de desconto',
                    'configurar_avisos_cobranca' => 'Configurar os avisos automáticos de cobrança (e-mail e WhatsApp)']],
            ['titulo' => 'Financeiro da instituição', 'ver' => 'ver_financeiro', 'dica' => 'Contas a receber e a pagar, recebimentos, resultado por turma',
                'incluir' => ['acesso_financeiro'], 'excluir' => ['excluir_pagar'], 'dica_incluir' => 'Lançar contas a pagar',
                'extras' => ['pagar_conta' => 'Dar baixa (pagar) contas a pagar', 'ver_dre' => 'Ver o DRE e o Fluxo de caixa']],
            ['titulo' => 'Cadastros do financeiro', 'ver' => null,
                'extras' => ['gerenciar_categorias' => 'Categorias financeiras', 'ver_fornecedores' => 'Fornecedores', 'ver_formas_pagamento' => 'Formas de pagamento']],
        ],
        'Atendimento' => [
            ['titulo' => 'Protocolos', 'ver' => 'ver_protocolos', 'dica' => 'Ver e responder os protocolos dos alunos',
                'extras' => ['abrir_protocolo' => 'Abrir protocolo em nome do aluno', 'configurar_protocolos' => 'Configurar temas, assuntos e status']],
            ['titulo' => 'WhatsApp', 'ver' => 'atender_whatsapp', 'dica' => 'Atender as conversas',
                'extras' => ['configurar_whatsapp' => 'Configurar o WhatsApp e a IA de atendimento']],
            ['titulo' => 'E-mail', 'ver' => 'usar_email', 'dica' => 'Usar a própria caixa de e-mail no sistema',
                'extras' => ['disparar_email' => 'Disparar e-mails em massa para alunos']],
            ['titulo' => 'Avisos no portal', 'ver' => 'gerenciar_avisos', 'dica' => 'Enviar avisos aos alunos e ver quem leu'],
        ],
        'Portal e sistema' => [
            ['titulo' => 'Painel inicial', 'ver' => 'ver_dashboard', 'dica' => 'Página inicial com o resumo'],
            ['titulo' => 'Site do portal do aluno', 'ver' => 'gerenciar_portal', 'dica' => 'Editar as páginas do portal',
                'extras' => ['editar_documentos' => 'Editar os modelos de certificados, declarações e contratos']],
            ['titulo' => 'Usuários do sistema', 'ver' => null, 'incluir' => ['cadastrar_usuario', 'editar_usuario'],
                'dica_incluir' => 'Só dos perfis liberados abaixo',
                'extras' => ['desativar_usuario' => 'Ativar e desativar usuários']],
        ],
    ];
}
