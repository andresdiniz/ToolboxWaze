/**
 * Fix: Bootstrap Dropdowns + Hotwired Turbo Drive
 *
 * O Turbo interceptá cliques antes do Bootstrap processar o dropdown toggle.
 * Este módulo força a reinicialização dos dropdowns após cada navegação Turbo
 * e garante que os dropdowns do navbar funcionem corretamente.
 */

function initDropdowns() {
    // Fecha todos os dropdowns abertos antes de reinicializar
    document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
        menu.classList.remove('show');
    });
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        el.setAttribute('aria-expanded', 'false');
        el.classList.remove('show');
    });

    // Reinicializa instâncias Bootstrap Dropdown
    if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            // Descarta instância anterior para evitar listeners duplicados
            var existing = bootstrap.Dropdown.getInstance(el);
            if (existing) existing.dispose();
            new bootstrap.Dropdown(el);
        });
    }
}

// Inicializa no carregamento normal
document.addEventListener('DOMContentLoaded', initDropdowns);

// Reinicializa após cada navegação Turbo
document.addEventListener('turbo:render', initDropdowns);
document.addEventListener('turbo:load', initDropdowns);

// Fecha dropdown ao clicar num item (Turbo navega sem reload)
document.addEventListener('turbo:click', function () {
    document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
        menu.classList.remove('show');
    });
    document.querySelectorAll('[data-bs-toggle="dropdown"].show').forEach(function (el) {
        el.classList.remove('show');
        el.setAttribute('aria-expanded', 'false');
    });
});
