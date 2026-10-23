/* Script della pagina del corso, copiato da public/employee/academy.php (materiali aperti da window.CORA_ACADEMY). */
(function () {
    var total = window.CORA_ACADEMY.total;
    var opened = {};
    window.CORA_ACADEMY.opened.forEach(function (oid) { opened[oid] = true; });

    // Segno subito il materiale come aperto: la registrazione vera la fa il server
    // quando il file viene richiesto (anteprima, download o redirect del link).
    function markOpened(row) {
        var id = row.dataset.material;
        opened[id] = true;
        var ic = row.querySelector('.ic');
        ic.classList.add('done');
        ic.textContent = '✓';
        var count = document.querySelectorAll('.ea-mat').length;
        var done = Object.keys(opened).filter(function (k) { return document.querySelector('.ea-mat[data-material="' + k + '"]'); }).length;
        var btn = document.getElementById('eaCompleteBtn');
        if (btn && done >= count) {
            btn.disabled = false;
            var hint = document.getElementById('eaCompleteHint');
            if (hint) hint.style.display = 'none';
        }
    }

    document.querySelectorAll('.ea-open').forEach(function (a) {
        a.addEventListener('click', function () { markOpened(a.closest('.ea-mat')); });
    });

    var viewer = document.getElementById('eaViewer');
    var body = document.getElementById('eaViewerBody');
    document.querySelectorAll('.ea-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var src = b.dataset.src, view = b.dataset.view, html = '';
            if (view === 'pdf') html = '<iframe src="' + src + '" title="Documento"></iframe>';
            if (view === 'video') html = '<video src="' + src + '" controls controlsList="nodownload" playsinline></video>';
            if (view === 'audio') html = '<audio src="' + src + '" controls></audio>';
            if (view === 'image') html = '<img src="' + src + '" alt="">';
            body.innerHTML = html;
            document.getElementById('eaViewerTitle').textContent = b.dataset.title;
            document.getElementById('eaViewerNewTab').href = src;
            viewer.classList.add('open');
            viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            markOpened(b.closest('.ea-mat'));
        });
    });
    var close = document.getElementById('eaViewerClose');
    if (close) close.addEventListener('click', function () { body.innerHTML = ''; viewer.classList.remove('open'); });
})();
