<?php
// Visualizador de documento do aluno (PDF ou imagem) dentro do sistema,
// sem precisar baixar. Chame verDocumento(id, ehPdf, titulo) — ou, para
// anexo da ficha, verDocumento('anexo=' + id, ehPdf, titulo).
?>
<div class="modal fade" id="modal-documento" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark"></i> <span id="documento-titulo"></span></h5>
                <a href="#" id="documento-baixar" class="btn btn-sm btn-outline-secondary ms-auto me-2"><i class="bi bi-download"></i> Baixar</a>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-body-tertiary text-center" id="documento-corpo" style="height: 80vh;"></div>
        </div>
    </div>
</div>
<script>
function verDocumento(id, ehPdf, titulo) {
    const url = '/documentos/ver.php?' + (String(id).startsWith('anexo=') ? id : 'id=' + encodeURIComponent(id));
    document.getElementById('documento-titulo').textContent = titulo;
    document.getElementById('documento-baixar').href = url + '&baixar=1';
    const corpo = document.getElementById('documento-corpo');
    corpo.innerHTML = '';
    if (ehPdf) {
        const quadro = document.createElement('iframe');
        quadro.src = url;
        quadro.style.cssText = 'width:100%;height:100%;border:0;';
        corpo.appendChild(quadro);
    } else {
        const imagem = document.createElement('img');
        imagem.src = url;
        imagem.alt = titulo;
        imagem.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
        corpo.appendChild(imagem);
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-documento')).show();
}
document.getElementById('modal-documento').addEventListener('hidden.bs.modal', () => { document.getElementById('documento-corpo').innerHTML = ''; });
</script>
