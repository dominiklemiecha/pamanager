/* Firma sul canvas, copiata da public/employee/contract-sign.php. */
    (function(){
        const canvas = document.getElementById('sigCanvas');
        const ctx = canvas.getContext('2d');
        const dpi = window.devicePixelRatio || 1;
        function fit() {
            const r = canvas.getBoundingClientRect();
            canvas.width = r.width * dpi;
            canvas.height = r.height * dpi;
            ctx.scale(dpi, dpi);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#0f172a';
        }
        fit();
        window.addEventListener('resize', fit);
        let drawing = false, hasDrawn = false, last = null;
        function pos(e) {
            const r = canvas.getBoundingClientRect();
            const t = e.touches && e.touches[0];
            const x = (t ? t.clientX : e.clientX) - r.left;
            const y = (t ? t.clientY : e.clientY) - r.top;
            return {x, y};
        }
        function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            const p = pos(e);
            ctx.beginPath();
            ctx.moveTo(last.x, last.y);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            last = p;
            hasDrawn = true;
            document.getElementById('submitSig').disabled = false;
        }
        function end() { drawing = false; last = null; }
        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        canvas.addEventListener('mouseup', end);
        canvas.addEventListener('mouseleave', end);
        canvas.addEventListener('touchstart', start, {passive:false});
        canvas.addEventListener('touchmove', move, {passive:false});
        canvas.addEventListener('touchend', end);
        document.getElementById('clearSig').addEventListener('click', () => {
            ctx.setTransform(1,0,0,1,0,0);
            ctx.clearRect(0,0,canvas.width,canvas.height);
            ctx.scale(dpi, dpi);
            ctx.lineWidth = 2.2; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.strokeStyle='#0f172a';
            hasDrawn = false;
            document.getElementById('submitSig').disabled = true;
        });
        document.getElementById('signForm').addEventListener('submit', (e) => {
            if (!hasDrawn) { e.preventDefault(); alert('Disegna la firma prima di confermare.'); return; }
            document.getElementById('signatureData').value = canvas.toDataURL('image/png');
        });
    })();
    