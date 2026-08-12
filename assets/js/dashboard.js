// dashboard.js — lógica de gráficos e refresh do dashboard ToolboxWaze
// Ajustado para evitar vibração: responsive: false e instâncias destruídas antes de recriar.

document.addEventListener('DOMContentLoaded', function () {
    console.log('[Dashboard] Inicializando...');

    if (typeof Chart === 'undefined') {
        console.error('[Dashboard] Chart.js não carregado.');
        document.querySelectorAll('.chart-container canvas').forEach(canvas => {
            const parent = canvas.parentElement;
            if (parent) {
                parent.innerHTML = '<div class="text-muted small">Gráfico indisponível (Chart.js não carregado).</div>';
            }
        });
        return;
    }

    const chartInstances = {};

    function createChart(canvasId, config, fallbackMessage = 'Sem dados para exibir.') {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.warn(`[Dashboard] Canvas #${canvasId} não encontrado.`);
            return null;
        }

        if (chartInstances[canvasId]) {
            try {
                chartInstances[canvasId].destroy();
                console.log(`[Dashboard] ${canvasId}: instância anterior destruída.`);
            } catch (e) {
                console.warn(`[Dashboard] ${canvasId}: erro ao destruir instância anterior:`, e);
            }
        }

        if (!config || !config.data || !config.data.datasets || config.data.datasets.length === 0) {
            const parent = canvas.parentElement;
            if (parent) {
                parent.innerHTML = `<div class="text-muted small">${fallbackMessage}</div>`;
            }
            console.log(`[Dashboard] ${canvasId}: sem dados válidos.`);
            return null;
        }

        try {
            if (!config.options) config.options = {};
            // Desliga responsividade para evitar loop de resize
            config.options.responsive = false;
            config.options.maintainAspectRatio = false;

            if (!config.options.plugins) config.options.plugins = {};
            if (!config.options.plugins.tooltip) config.options.plugins.tooltip = {};
            config.options.plugins.tooltip.callbacks = {
                label: function (context) {
                    let label = context.dataset.label || '';
                    let value = context.parsed.y !== undefined ? context.parsed.y : context.parsed.r;
                    if (value === undefined || value === null || isNaN(value)) {
                        value = 0;
                    }
                    if (label) {
                        return label + ': ' + value;
                    }
                    return value;
                }
            };

            const chart = new Chart(canvas, config);
            chartInstances[canvasId] = chart;
            console.log(`[Dashboard] ${canvasId} renderizado.`);
            return chart;
        } catch (e) {
            console.error(`[Dashboard] Erro ao criar gráfico ${canvasId}:`, e);
            const parent = canvas.parentElement;
            if (parent) {
                parent.innerHTML = `<div class="text-muted small">${fallbackMessage}</div>`;
            }
            return null;
        }
    }

    // ==============================================================
    // 1. Radares por Estado (chartRadarUf)
    // ==============================================================

    const radarUfData = Array.isArray(window.radarUf) ? window.radarUf : [];
    console.log('[Dashboard] radarUf:', radarUfData.length, 'registros.');

    if (radarUfData.length > 0) {
        const labels = radarUfData.map(item => String(item.sigla_uf || item.uf || ''));
        const totais = radarUfData.map(item => Number(item.total) || 0);

        createChart('chartRadarUf', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Radares',
                    data: totais,
                    backgroundColor: 'rgba(13, 110, 253, 0.7)',
                    borderColor: '#0d6efd',
                    borderWidth: 1
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        }, 'Nenhum dado de radar por estado.');
    } else {
        const container = document.getElementById('chartRadarUf')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Nenhum dado de radar por estado.</div>';
    }

    // ==============================================================
    // 2. Resultado Geral (chartRadarResultado)
    // ==============================================================

    const resultadoData = Array.isArray(window.radarResultado) ? window.radarResultado : [];
    console.log('[Dashboard] radarResultado:', resultadoData.length, 'registros.');

    if (resultadoData.length > 0) {
        const labels = resultadoData.map(item => String(item.resultado || 'Desconhecido'));
        const values = resultadoData.map(item => Number(item.total) || 0);
        const backgroundColors = ['#198754', '#dc3545', '#ffc107', '#0d6efd', '#6c757d'];

        createChart('chartRadarResultado', {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: backgroundColors.slice(0, labels.length),
                    borderWidth: 2
                }]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '60%'
            }
        }, 'Sem dados de resultado.');
    } else {
        const container = document.getElementById('chartRadarResultado')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Sem dados de resultado.</div>';
    }

    // ==============================================================
    // 3. Verificações Mensais (chartVerifMensais)
    // ==============================================================

    const mensaisData = Array.isArray(window.radarMensais) ? window.radarMensais : [];
    console.log('[Dashboard] radarMensais:', mensaisData.length, 'registros.');

    if (mensaisData.length > 0) {
        const labels = mensaisData.map(item => String(item.mes || ''));
        const valores = mensaisData.map(item => Number(item.total) || 0);

        createChart('chartVerifMensais', {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Verificações',
                    data: valores,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#0d6efd'
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        }, 'Nenhuma verificação mensal.');
    } else {
        const container = document.getElementById('chartVerifMensais')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Nenhuma verificação mensal.</div>';
    }

    // ==============================================================
    // 4. Cobertura Waze por Estado (chartCoberturaUf)
    // ==============================================================

    const coberturaData = Array.isArray(window.radarCobertura) ? window.radarCobertura : [];
    console.log('[Dashboard] radarCobertura:', coberturaData.length, 'registros.');

    if (coberturaData.length > 0) {
        const labels = coberturaData.map(item => String(item.sigla_uf || item.uf || ''));
        const pct = coberturaData.map(item => Number(item.pct) || 0);

        createChart('chartCoberturaUf', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cobertura (%)',
                    data: pct,
                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                    borderColor: '#dc3545',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    y: { grid: { display: false } }
                }
            }
        }, 'Dados de cobertura indisponíveis.');
    } else {
        const container = document.getElementById('chartCoberturaUf')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Dados de cobertura indisponíveis.</div>';
    }

    // ==============================================================
    // 5. Atividade dos Postos (chartPostoAtividade)
    // ==============================================================

    const postoAtividade = Array.isArray(window.postoAtividade) ? window.postoAtividade : [];
    console.log('[Dashboard] postoAtividade:', postoAtividade.length, 'registros.');

    if (postoAtividade.length > 0) {
        const labels = postoAtividade.map(item => String(item.dia || item.data || ''));
        const valores = postoAtividade.map(item => Number(item.total) || 0);

        createChart('chartPostoAtividade', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Atividade',
                    data: valores,
                    backgroundColor: 'rgba(255, 193, 7, 0.7)',
                    borderColor: '#ffc107',
                    borderWidth: 1
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        }, 'Dados de atividade não disponíveis.');
    } else {
        const container = document.getElementById('chartPostoAtividade')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Dados de atividade não disponíveis.</div>';
    }

    // ==============================================================
    // 6. Solicitações Diárias (chartSolicDiarias)
    // ==============================================================

    const solicDiarias = Array.isArray(window.solicDiarias) ? window.solicDiarias : [];
    console.log('[Dashboard] solicDiarias:', solicDiarias.length, 'registros.');

    if (solicDiarias.length > 0) {
        const labels = solicDiarias.map(item => String(item.dia || ''));
        const valores = solicDiarias.map(item => Number(item.total) || 0);

        createChart('chartSolicDiarias', {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Solicitações',
                    data: valores,
                    borderColor: '#0dcaf0',
                    backgroundColor: 'rgba(13, 202, 240, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#0dcaf0'
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        }, 'Nenhuma solicitação diária.');
    } else {
        const container = document.getElementById('chartSolicDiarias')?.parentElement;
        if (container) container.innerHTML = '<div class="text-muted small">Nenhuma solicitação diária.</div>';
    }

    // ==============================================================
    // Botão Refresh (atualiza KPIs)
    // ==============================================================

    const refreshBtn = document.getElementById('refresh-dashboard');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = ' Atualizando...';

            fetch('/dashboard/refresh', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => {
                    if (!response.ok) throw new Error('Erro na requisição');
                    return response.json();
                })
                .then(data => {
                    document.querySelectorAll('[data-key]').forEach(el => {
                        const key = el.getAttribute('data-key');
                        if (!key) return;
                        if (key in data) {
                            if (el.classList.contains('count-up')) {
                                animateCountUp(el, data[key]);
                            } else {
                                el.textContent = data[key];
                            }
                        }
                    });

                    if (data.solic_hoje !== undefined) {
                        const hojeEl = document
                            .querySelector('[data-key="solic_total"]')
                            ?.closest('.card-dashboard')
                            ?.querySelector('.small.text-muted');
                        if (hojeEl) hojeEl.textContent = data.solic_hoje + ' hoje';
                    }

                    btn.innerHTML = ' Atualizar';
                    btn.disabled = false;
                })
                .catch(error => {
                    console.error('[Dashboard] Erro ao atualizar:', error);
                    btn.innerHTML = ' Erro';
                    setTimeout(() => {
                        btn.innerHTML = ' Atualizar';
                        btn.disabled = false;
                    }, 3000);
                });
        });
    }

    function animateCountUp(el, target) {
        const currentVal = parseInt(el.textContent) || 0;
        const targetVal = parseInt(target) || 0;

        if (currentVal === targetVal) {
            el.textContent = targetVal;
            return;
        }

        const duration = 500;
        const startTime = performance.now();
        const startVal = currentVal;

        function update(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.round(startVal + (targetVal - startVal) * eased);
            el.textContent = value;

            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                el.textContent = targetVal;
            }
        }

        requestAnimationFrame(update);
    }

    console.log('[Dashboard] Inicialização concluída.');
});