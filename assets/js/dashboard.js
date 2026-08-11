/**
 * dashboard.js — Lógica do dashboard (gráficos, atualização)
 */
(function() {
    'use strict';

    // ── Dados dos gráficos (injetados via <script> no Twig) ──
    const radarUf      = window.radarUf      || [];
    const resultado    = window.radarResultado || [];
    const mensais      = window.radarMensais   || [];
    const coberturaUf  = window.radarCobertura || [];
    const postoAtiv    = window.postoAtividade || [];
    const solicDiarias = window.solicDiarias   || [];

    // ── Helpers de tema ──────────────────────────────────────
    const dark = () => document.documentElement.getAttribute('data-bs-theme') === 'dark'
                     || window.matchMedia('(prefers-color-scheme:dark)').matches;
    const gc = () => dark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    const tc = () => dark() ? '#adb5bd' : '#6c757d';
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.size = 11;
    }

    // ── Função segura para criar gráficos ──────────────────
    function safeChart(id, config) {
        const el = document.getElementById(id);
        if (el && typeof Chart !== 'undefined') {
            try {
                new Chart(el, config);
            } catch(e) {
                console.warn('Chart ' + id, e);
            }
        }
    }

    // ── Configurações dos gráficos ──────────────────────────
    const chartConfigs = {
        'chartRadarUf': {
            type: 'bar',
            data: {
                labels: radarUf.map(r => r.uf),
                datasets: [
                    { label: 'Aprovados',  data: radarUf.map(r => +r.aprovados),  backgroundColor: '#20c997', stack: 's', borderRadius: 3 },
                    { label: 'Reprovados', data: radarUf.map(r => +r.reprovados), backgroundColor: '#dc3545', stack: 's', borderRadius: 3 },
                    { label: 'Outros',     data: radarUf.map(r => +r.total - (+r.aprovados||0) - (+r.reprovados||0)), backgroundColor: '#6c757d', stack: 's', borderRadius: 3 },
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { color: tc() } } },
                scales: {
                    x: { stacked: true, grid: { color: gc() }, ticks: { color: tc() } },
                    y: { stacked: true, beginAtZero: true, grid: { color: gc() }, ticks: { color: tc(), precision: 0 } }
                }
            }
        },
        'chartRadarResultado': {
            type: 'doughnut',
            data: {
                labels: resultado.map(r => r.resultado),
                datasets: [{
                    data: resultado.map(r => +r.total),
                    backgroundColor: resultado.map(r => ({ 'APROVADO': '#20c997', 'REPROVADO': '#dc3545', 'SEM INFO': '#6c757d' }[r.resultado] || '#adb5bd')),
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: { legend: { position: 'bottom', labels: { color: tc() } } }
            }
        },
        'chartVerifMensais': {
            type: 'line',
            data: {
                labels: mensais.map(r => r.mes),
                datasets: [{ label: 'Verificações', data: mensais.map(r => +r.total), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.1)', fill: true, tension: .4, pointRadius: 4 }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gc() }, ticks: { color: tc() } },
                    y: { beginAtZero: true, grid: { color: gc() }, ticks: { color: tc(), precision: 0 } }
                }
            }
        },
        'chartCoberturaUf': {
            type: 'bar',
            data: {
                labels: coberturaUf.map(r => r.uf),
                datasets: [
                    { label: 'Com Waze', data: coberturaUf.map(r => +r.com_waze), backgroundColor: 'rgba(32,201,151,.8)', borderRadius: 3 },
                    { label: 'Sem Waze', data: coberturaUf.map(r => +r.sem_waze), backgroundColor: 'rgba(220,53,69,.7)', borderRadius: 3 }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { color: tc() } } },
                scales: {
                    x: { stacked: true, beginAtZero: true, grid: { color: gc() }, ticks: { color: tc(), precision: 0 } },
                    y: { stacked: true, grid: { color: gc() }, ticks: { color: tc() } }
                }
            }
        },
        'chartPostoAtividade': {
            type: 'line',
            data: {
                labels: postoAtiv.map(r => r.dia),
                datasets: [{ label: 'Cadastros', data: postoAtiv.map(r => +r.cadastros), borderColor: '#fd7e14', backgroundColor: 'rgba(253,126,20,.1)', fill: true, tension: .4, pointRadius: 3 }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gc() }, ticks: { color: tc() } },
                    y: { beginAtZero: true, grid: { color: gc() }, ticks: { color: tc(), precision: 0 } }
                }
            }
        },
        'chartSolicDiarias': {
            type: 'line',
            data: {
                labels: solicDiarias.map(r => r.dia),
                datasets: [{ label: 'Solicitações', data: solicDiarias.map(r => +r.total), borderColor: '#0dcaf0', backgroundColor: 'rgba(13,202,240,.12)', fill: true, tension: .4, pointRadius: 3 }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gc() }, ticks: { color: tc() } },
                    y: { beginAtZero: true, grid: { color: gc() }, ticks: { color: tc(), precision: 0 } }
                }
            }
        }
    };

    // ── Inicialização dos gráficos quando visíveis ──────────
    function initChart(canvas) {
        const id = canvas.id;
        if (id && chartConfigs[id]) {
            safeChart(id, chartConfigs[id]);
            canvas.dataset.initialized = 'true';
        }
    }

    const chartContainers = document.querySelectorAll('.chart-container');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const canvas = entry.target.querySelector('canvas');
                    if (canvas && !canvas.dataset.initialized) {
                        initChart(canvas);
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '100px' });
        chartContainers.forEach(el => observer.observe(el));
    } else {
        chartContainers.forEach(container => {
            const canvas = container.querySelector('canvas');
            if (canvas) initChart(canvas);
        });
    }

    // ── Função de animação count-up (local) ──────────────────
    function formatNumber(n) {
        return Math.round(n).toLocaleString('pt-BR');
    }

    function animateCountUp(el) {
        const raw = el.dataset.target;
        if (!raw) return;
        const target = parseFloat(raw);
        if (isNaN(target) || target === 0) {
            el.textContent = formatNumber(target);
            return;
        }
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) {
            el.textContent = formatNumber(target);
            return;
        }
        const duration = Math.min(1200, Math.max(600, target / 50));
        let startTime = null;
        function step(ts) {
            if (!startTime) startTime = ts;
            const elapsed = ts - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 4);
            el.textContent = formatNumber(eased * target);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = formatNumber(target);
            }
        }
        requestAnimationFrame(step);
    }

    // ── Botão "Atualizar" ────────────────────────────────────
    const refreshBtn = document.getElementById('refresh-dashboard');
    if (refreshBtn) {
        let originalHtml = refreshBtn.innerHTML;

        refreshBtn.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Atualizando...';

            fetch('/dashboard/refresh')
                .then(res => {
                    if (!res.ok) throw new Error('Erro na requisição');
                    return res.json();
                })
                .then(data => {
                    // Atualiza os .count-up com novos valores
                    document.querySelectorAll('.count-up').forEach(el => {
                        const key = el.dataset.key || el.id;
                        if (data[key] !== undefined) {
                            const newValue = parseFloat(data[key]);
                            if (!isNaN(newValue)) {
                                el.dataset.target = newValue;
                                // Aplica a animação imediatamente
                                animateCountUp(el);
                            }
                        }
                    });
                    // Feedback visual de sucesso
                    this.innerHTML = '<i class="bi bi-check-circle-fill"></i> Atualizado!';
                    setTimeout(() => {
                        this.innerHTML = originalHtml;
                        this.disabled = false;
                    }, 1500);
                })
                .catch(err => {
                    console.warn('Erro ao atualizar:', err);
                    this.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Erro';
                    setTimeout(() => {
                        this.innerHTML = originalHtml;
                        this.disabled = false;
                    }, 2000);
                });
        });
    }

})();