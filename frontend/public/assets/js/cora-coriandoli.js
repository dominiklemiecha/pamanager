/* Coriandoli della schermata dopo la firma, copiati da public/employee/contract-sign.php. */
    // Coriandoli DIY (no dipendenze esterne)
    (function(){
        const canvas = document.getElementById('confetti-canvas');
        const ctx = canvas.getContext('2d');
        const dpi = window.devicePixelRatio || 1;
        function fit() {
            canvas.width = innerWidth * dpi;
            canvas.height = innerHeight * dpi;
            canvas.style.width = innerWidth + 'px';
            canvas.style.height = innerHeight + 'px';
            ctx.setTransform(dpi, 0, 0, dpi, 0, 0);
        }
        fit();
        window.addEventListener('resize', fit);

        const colors = ['#ffffff','#81c8ff','#4fa1ff','#fde047','#f472b6','#34d399','#fbbf24'];
        const particles = [];
        function spawn(burstCount, originY) {
            for (let i = 0; i < burstCount; i++) {
                particles.push({
                    x: Math.random() * innerWidth,
                    y: originY ?? -20 - Math.random() * 80,
                    vx: (Math.random() - 0.5) * 4,
                    vy: 2 + Math.random() * 4,
                    g: 0.12 + Math.random() * 0.08,
                    size: 6 + Math.random() * 8,
                    rot: Math.random() * Math.PI,
                    vr: (Math.random() - 0.5) * 0.25,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    shape: Math.random() < 0.5 ? 'rect' : 'circle',
                });
            }
        }
        // Burst iniziali
        spawn(180);
        setTimeout(() => spawn(120), 350);
        setTimeout(() => spawn(120), 800);
        // Sparate dai bordi inferiori
        setTimeout(() => {
            for (let k = 0; k < 80; k++) {
                particles.push({
                    x: Math.random() < 0.5 ? -10 : innerWidth + 10,
                    y: innerHeight - 50,
                    vx: (Math.random() < 0.5 ? 1 : -1) * (6 + Math.random() * 4),
                    vy: -8 - Math.random() * 6,
                    g: 0.18,
                    size: 6 + Math.random() * 8,
                    rot: Math.random() * Math.PI,
                    vr: (Math.random() - 0.5) * 0.3,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    shape: Math.random() < 0.5 ? 'rect' : 'circle',
                });
            }
        }, 300);

        function draw() {
            ctx.clearRect(0, 0, innerWidth, innerHeight);
            for (let i = particles.length - 1; i >= 0; i--) {
                const p = particles[i];
                p.vy += p.g;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vr;
                p.vx *= 0.995;
                if (p.y > innerHeight + 30) { particles.splice(i, 1); continue; }
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                if (p.shape === 'rect') {
                    ctx.fillRect(-p.size/2, -p.size/3, p.size, p.size * 0.6);
                } else {
                    ctx.beginPath();
                    ctx.arc(0, 0, p.size/2, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.restore();
            }
            requestAnimationFrame(draw);
        }
        draw();
    })();
    