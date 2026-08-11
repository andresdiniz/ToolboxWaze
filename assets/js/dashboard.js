// dashboard.js – com alturas controladas pelo CSS do container

document.addEventListener('DOMContentLoaded', function () {
    console.log('[Dashboard] Inicializando...');

    if (typeof Chart === 'undefined') {
        console.error('[Dashboard] Chart.js não carregado.');
        document.querySelectorAll('.chart-container canvas').forEach(canvas => {
            canvas.parentElement.innerHTML = `
                <div class="alert alert-warning text-center py-3">
                    <i class="bi bi-exclamation-triangle"></i> Gráfico indisponível: Chart.js não carregou.
                </div>
            `;
        });
        return;
    }

    // Configuração global – apenas fonte e tamanho
    Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
    Chart.defaults.font.size = 11;

    const chartInstances = {};

    function destroyChart(canvasId) {
        if (chartInstances[canvasId]) {
            chartInstances[canvasId].destroy();
            delete chartInstances[canvasId];
        }
    }

    function createChart(canvasId, config, fallbackMessage = 'Sem dados para exibir.') {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.warn(`[Dashboard] Canvas #${canvasId} não encontrado.`);
            return null;
        }

        destroyChart(canvasId);

        // Verifica dados válidos
        const hasValidData = config.data && config.data.datasets && config.data.datasets.some(ds => {
            return Array.isArray(ds.data) && ds.data.some(v => v !== undefined && v !== null && !isNaN(v));
        });

        if (!hasValidData) {
            const container = canvas.parentElement;
            if (container) {
                container.innerHTML = `<p class="text-muted text-center py-3">${fallbackMessage}</p>`;
            }
            console.log(`[Dashboard] ${canvasId}: sem dados válidos.`);
            return null;
        }

        try {
            // Garantir que as opções de responsividade estejam corretas
            if (!config.options) config.options = {};
            config.options.responsive = true;
            config.options.maintainAspectRatio = false;

            // Tooltips personalizados
            if (!config.options.plugins) config.options.plugins = {};
            if (!config.options.plugins.tooltip) config.options.plugins.tooltip = {};
            config.options.plugins.tooltip.callbacks = {
                label: function(context) {
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
            const container = canvas.parentElement;
            if (container) {
                container.innerHTML = `
                    <div class="alert alert-danger text-center py-3">
                        <i class="bi bi-bug"></i> Erro ao renderizar gráfico.
                    </div>
                `;
            }
            return null;
        }
    }

    // --------------------------------------------------------------
    // 1. Radares por Estado
    // --------------------------------------------------------------
    const radarUfData = window.radarUf || [];
    console.log(`[Dashboard] radarUfData: ${radarUfData.length} registros.`);

    if (radarUfData.length > 0) {
        const labels = radarUfData.map(item => String(item.sigla_uf || item.uf || '').trim());
        const aprovados = radarUfData.map(item => Number(item.aprovados) || 0);
        const reprovados = radarUfData.map(item => Number(item.reprovados) || 0);
        const outros = radarUfData.map(item => {
            const total = Number(item.total) || 0;
            return total - (Number(item.aprovados) || 0) - (Number(item.reprovados) || 0);
        });

        createChart('chartRadarUf', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Aprovados',
                        data: aprovados,
                        backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        borderColor: '#198754',
                        borderWidth: 1,
                    },
                    {
                        label: 'Reprovados',
                        data: reprovados,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 1,
                    },
                    {
                        label: 'Outros',
                        data: outros,
                        backgroundColor: 'rgba(108, 117, 125, 0.7)',
                        borderColor: '#6c757d',
                        borderWidth: 1,
                    },
                ],
            },
            options: {
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true },
                },
            },
        }, 'Nenhum dado de radar por estado.');
    } else {
        const container = document.getElementById('chartRadarUf')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Nenhum dado de radar por estado.</p>';
    }

    // --------------------------------------------------------------
    // 2. Resultado Geral
    // --------------------------------------------------------------
    const resultadoData = window.radarResultado || [];
    console.log(`[Dashboard] radarResultado: ${resultadoData.length} registros.`);
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
                    borderWidth: 2,
                }],
            },
            options: {
                plugins: { legend: { position: 'bottom' } },
                cutout: '60%',
            },
        }, 'Sem dados de resultado.');
    } else {
        const container = document.getElementById('chartRadarResultado')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Sem dados de resultado.</p>';
    }

    // --------------------------------------------------------------
    // 3. Verificações Mensais
    // --------------------------------------------------------------
    const mensaisData = window.radarMensais || [];
    console.log(`[Dashboard] radarMensais: ${mensaisData.length} registros.`);
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
                    pointBackgroundColor: '#0d6efd',
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true },
                },
            },
        }, 'Nenhuma verificação mensal.');
    } else {
        const container = document.getElementById('chartVerifMensais')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Nenhuma verificação mensal.</p>';
    }

    // --------------------------------------------------------------
    // 4. Cobertura Waze
    // --------------------------------------------------------------
    const coberturaData = window.radarCobertura || [];
    console.log(`[Dashboard] radarCobertura: ${coberturaData.length} registros.`);
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
                    borderWidth: 1,
                }],
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, max: 100 },
                    y: { grid: { display: false } },
                },
            },
        }, 'Dados de cobertura indisponíveis.');
    } else {
        const container = document.getElementById('chartCoberturaUf')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Dados de cobertura indisponíveis.</p>';
    }

    // --------------------------------------------------------------
    // 5. Posto Atividade
    // --------------------------------------------------------------
    const postoAtividade = window.postoAtividade || [];
    console.log(`[Dashboard] postoAtividade: ${postoAtividade.length} registros.`);
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
                    borderWidth: 1,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true },
                },
            },
        }, 'Dados de atividade não disponíveis.');
    } else {
        const container = document.getElementById('chartPostoAtividade')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Dados de atividade não disponíveis.</p>';
    }

    // --------------------------------------------------------------
    // 6. Solicitações Diárias
    // --------------------------------------------------------------
    const solicDiarias = window.solicDiarias || [];
    console.log(`[Dashboard] solicDiarias: ${solicDiarias.length} registros.`);
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
                    pointBackgroundColor: '#0dcaf0',
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true },
                },
            },
        }, 'Nenhuma solicitação diária.');
    } else {
        const container = document.getElementById('chartSolicDiarias')?.parentElement;
        if (container) container.innerHTML = '<p class="text-muted text-center py-3">Nenhuma solicitação diária.</p>';
    }

    // ==============================================================
    // Botão Refresh (atualiza KPIs)
    // ==============================================================
    const refreshBtn = document.getElementById('refresh-dashboard');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Atualizando...';

            fetch('/dashboard/refresh', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(response => {
                    if (!response.ok) throw new Error('Erro na requisição');
                    return response.json();
                })
                .then(data => {
                    document.querySelectorAll('[data-key]').forEach(el => {
                        const key = el.getAttribute('data-key');
                        if (key in data) {
                            if (el.classList.contains('count-up')) {
                                animateCountUp(el, data[key]);
                            } else {
                                el.textContent = data[key];
                            }
                        }
                    });
                    if (data.solic_hoje !== undefined) {
                        const hojeEl = document.querySelector('[data-key="solic_total"]')
                            ?.closest('.card-dashboard')
                            ?.querySelector('.small.text-muted');
                        if (hojeEl) hojeEl.textContent = data.solic_hoje + ' hoje';
                    }
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Atualizar';
                    btn.disabled = false;
                })
                .catch(error => {
                    console.error('[Dashboard] Erro ao atualizar:', error);
                    btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Erro';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Atualizar';
                        btn.disabled = false;
                    }, 3000);
                });
        });
    }

    function animateCountUp(el, target) {
        const current = parseInt(el.textContent) || 0;
        if (current === target) return;
        const duration = 500;
        const startTime = performance.now();
        const startVal = current;

        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.round(startVal + (target - startVal) * eased);
            el.textContent = value;
            if (progress < 1) {
                requestAnimationFrame(update);
            } else {
                el.textContent = target;
            }
        }
        requestAnimationFrame(update);
    }

    console.log('[Dashboard] Inicialização concluída.');
});