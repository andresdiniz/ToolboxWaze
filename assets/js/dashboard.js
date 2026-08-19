// dashboard.js — lógica de gráficos e refresh do dashboard ToolboxWaze
// Ajustado para evitar vibração: responsive: false e instâncias destruídas antes de recriar.
//
// FIX (contraste dark mode): Chart.js não sabia nada sobre o tema do
// app. Sem configurar cor de eixo/legenda/grid, ele usa o padrão
// pensado pra fundo branco (~#666 pro texto, cinza claro pro grid),
// que fica quase ilegível sobre os cards escuros (#182224/#1e2b2d).
// Agora lemos o atributo [data-theme] da <html> e aplicamos uma
// paleta compatível via Chart.defaults antes de montar os gráficos.
// Também escutamos o evento "tw:themechange" (disparado em app.js
// quando o usuário clica no botão de tema) pra reconstruir os
// gráficos na hora, sem precisar recarregar a página.

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

    // ==================================================================
    // Tema dos gráficos
    // ==================================================================

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function getChartTheme() {
        const dark = isDarkTheme();
        return {
            dark,
            text:          dark ? '#c7d8d9' : '#495057',
            textMuted:     dark ? '#94b5b8' : '#6c757d',
            grid:          dark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)',
            tooltipBg:     dark ? '#1e2b2d' : '#ffffff',
            tooltipBorder: dark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.08)',
        };
    }

    // Aplica a paleta em Chart.defaults — vale pra todo gráfico criado
    // depois desse ponto, sem precisar repetir cor em cada config.
    function applyChartTheme() {
        const theme = getChartTheme();

        Chart.defaults.color = theme.text;
        Chart.defaults.borderColor = theme.grid;

        Chart.defaults.plugins.legend.labels.color = theme.text;

        Chart.defaults.plugins.tooltip.backgroundColor = theme.tooltipBg;
        Chart.defaults.plugins.tooltip.titleColor = theme.text;
        Chart.defaults.plugins.tooltip.bodyColor = theme.text;
        Chart.defaults.plugins.tooltip.borderColor = theme.tooltipBorder;
        Chart.defaults.plugins.tooltip.borderWidth = 1;

        Chart.defaults.scale.grid.color = theme.grid;
        Chart.defaults.scale.ticks.color = theme.textMuted;

        console.log(`[Dashboard] Tema dos gráficos aplicado: ${theme.dark ? 'dark' : 'light'}.`);
        return theme;
    }

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
            delete chartInstances[canvasId];
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

    // Precisamos de um <canvas> "fresco" pra recriar um gráfico depois
    // que o fallback de "sem dados" substituiu o canvas por um <div>.
    // Isso só importa numa troca de tema em runtime; no load inicial
    // os canvases do template já existem.
    function ensureCanvas(canvasId) {
        const existing = document.getElementById(canvasId);
        if (existing && existing.tagName === 'CANVAS') return existing;

        const container = existing ? existing.parentElement : null;
        if (!container) return null;

        const canvas = document.createElement('canvas');
        canvas.id = canvasId;
        container.innerHTML = '';
        container.appendChild(canvas);
        return canvas;
    }

    // ==================================================================
    // Construção de todos os gráficos do dashboard
    // ==================================================================
    // Isolado numa função pra poder ser chamado de novo quando o tema
    // muda (evento tw:themechange), sem duplicar a lógica de leitura
    // dos dados (que continuam nos mesmos window.* já embutidos pelo
    // Twig — não recarregamos do servidor, só redesenhamos).
    function buildCharts() {

        // ==============================================================
        // 1. Radares por Estado (chartRadarUf)
        // ==============================================================

        const radarUfData = Array.isArray(window.radarUf) ? window.radarUf : [];
        console.log('[Dashboard] radarUf:', radarUfData.length, 'registros.');

        if (radarUfData.length > 0) {
            const labels = radarUfData.map(item => String(item.sigla_uf || item.uf || ''));
            const totais = radarUfData.map(item => Number(item.total) || 0);

            ensureCanvas('chartRadarUf');
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

            // Cor fixa por SIGNIFICADO do resultado, não por posição no array.
            // Antes a cor vinha de backgroundColors[index], então a ordem que o
            // banco devolvia as linhas decidia a cor — e por acaso "REPARADO"
            // caía no vermelho e "REPROVADO" no amarelo. Agora cada status tem
            // cor fixa, não importa a ordem do GROUP BY.
            const resultadoColorMap = {
                'APROVADO': '#198754',
                'REPROVADO': '#dc3545',
                'REPARADO': '#ffc107',
                'PENDENTE': '#0d6efd',
            };
            const fallbackColor = '#6c757d';
            const backgroundColors = labels.map(l => resultadoColorMap[l.toUpperCase()] || fallbackColor);

            ensureCanvas('chartRadarResultado');
            createChart('chartRadarResultado', {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: backgroundColors,
                        borderWidth: 2,
                        borderColor: isDarkTheme() ? '#182224' : '#ffffff'
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

            ensureCanvas('chartVerifMensais');
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

            ensureCanvas('chartCoberturaUf');
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

            ensureCanvas('chartPostoAtividade');
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

            ensureCanvas('chartSolicDiarias');
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
    }

    // ==================================================================
    // Inicialização
    // ==================================================================

    applyChartTheme();
    buildCharts();

    // Troca de tema em runtime (clique no botão sol/lua) — reaplica a
    // paleta e reconstrói os gráficos, sem precisar de F5.
    document.addEventListener('tw:themechange', function () {
        applyChartTheme();
        buildCharts();
    });

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