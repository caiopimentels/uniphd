<style>
/* E-mail (menu E-mail): pastas à esquerda, lista/leitura no meio, parecido com o Gmail. */
.email-app { display: grid; grid-template-columns: 230px 1fr; gap: 16px; align-items: start; }
@media (max-width: 900px) { .email-app { grid-template-columns: 1fr; } .email-lateral { position: static !important; } }
.email-lateral { position: sticky; top: calc(var(--topnav-h, 58px) + 12px); }
.email-escrever { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-radius: 16px; font-weight: 600; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.15); }
.email-pastas a { display: flex; align-items: center; gap: 12px; padding: 7px 14px; border-radius: 0 18px 18px 0; color: inherit; text-decoration: none; font-size: 14px; }
.email-pastas a:hover { background: var(--accent-soft); }
.email-pastas a.ativa { background: var(--accent-soft); font-weight: 700; color: var(--accent-dark, var(--accent)); }
.email-pastas a .qtd { margin-left: auto; font-size: 12px; font-weight: 700; }
.email-pastas .sep { border-top: 1px solid var(--bs-border-color); margin: 8px 14px; }
.email-principal { min-width: 0; }
.email-busca { margin-bottom: 10px; }
.email-busca .form-control, .email-busca .input-group-text { background: var(--bs-tertiary-bg); border-color: transparent; }
.email-lista-card { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 12px; overflow: hidden; }
.email-barra { display: flex; align-items: center; gap: 4px; padding: 6px 12px; border-bottom: 1px solid var(--bs-border-color); min-height: 46px; }
.email-linha { display: flex; align-items: center; gap: 8px; padding: 0 12px; border-bottom: 1px solid var(--bs-border-color-translucent); background: var(--bs-tertiary-bg); }
.email-linha.nao-lida { background: var(--bs-body-bg); }
.email-linha.nao-lida .email-quem, .email-linha.nao-lida .email-assunto, .email-linha.nao-lida .email-data { font-weight: 700; }
.email-linha:hover { box-shadow: inset 1px 0 0 #dadce0, inset -1px 0 0 #dadce0, 0 1px 2px rgba(60,64,67,.3); position: relative; z-index: 1; }
.email-linha .estrela { border: 0; background: none; color: #9aa0a6; padding: 2px; }
.email-linha .estrela.ligada { color: #f4b400; }
.email-link { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; padding: 10px 0; color: inherit; text-decoration: none; font-size: 14px; }
.email-quem { width: 200px; flex: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.email-assunto { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.email-data { flex: none; font-size: 12px; color: var(--bs-secondary-color); width: 62px; text-align: right; }
/* Ações da linha (arquivar, excluir, lida): no lugar da data, ao passar o mouse. */
.email-acoes-linha { display: none; flex: none; gap: 2px; }
.email-acoes-linha button { border: 0; background: none; color: var(--bs-secondary-color); padding: 4px 7px; border-radius: 50%; }
.email-acoes-linha button:hover { background: rgba(60,64,67,.12); color: var(--bs-body-color); }
.email-linha:hover .email-acoes-linha { display: inline-flex; }
.email-linha:hover .email-data { display: none; }
@media (hover: none) { .email-acoes-linha { display: inline-flex; } .email-linha .email-data { display: none; } }
/* Ações da barra: sempre à vista, apagadas até marcar um e-mail. */
.email-acoes-sel { display: inline-flex; align-items: center; }
.email-acoes-sel.sem-selecao { opacity: .4; pointer-events: none; }
@media (max-width: 700px) { .email-quem { width: 110px; } }
.email-leitura { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: 12px; padding: 18px 22px; }
.email-leitura h4 { font-weight: 500; margin-bottom: 14px; }
.email-remetente { display: flex; gap: 12px; align-items: flex-start; }
.email-avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; flex: none; }
.email-corpo-frame { width: 100%; border: 0; min-height: 120px; margin-top: 14px; }
.email-anexos { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--bs-border-color); }
.email-anexo { display: flex; align-items: center; gap: 8px; border: 1px solid var(--bs-border-color); border-radius: 8px; padding: 8px 12px; color: inherit; text-decoration: none; max-width: 260px; }
.email-anexo:hover { background: var(--bs-tertiary-bg); }
.email-anexo .nome { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 13px; }
.email-anexo img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; }
</style>
