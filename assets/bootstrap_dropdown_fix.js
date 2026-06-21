/**
 * Fix: Bootstrap Dropdowns + Hotwired Turbo Drive
 *
 * Problema 1: Após navegação Turbo, Bootstrap perde as instâncias de Dropdown.
 * Problema 2: Quando já está na página admin, dispose() destruía a instância
 *             antes do clique completar, impedindo a abertura do dropdown.
 *
 * Solução: usar sempre o bootstrap do CDN (window.bootstrap) como fonte da
 * verdade, e só criar instâncias novas para elementos que ainda não têm uma.
 */

(function () {
    'use strict';

    function getBootstrap() {
        // Usa sempre o bootstrap do CDN carregado no <head> — fonte única da verdade
        return window.bootstrap || (typeof bootstrap !== 'undefined' ? bootstrap : null);
    }

    function initDropdowns() {
        var bs = getBootstrap();
        if (!bs || !bs.Dropdown) return;

        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            // Só cria instância se ainda não existir — não destroi as que funcionam
            if (!bs.Dropdown.getInstance(el)) {
                new bs.Dropdown(el);
            }
        });
    }

    function closeAllDropdowns() {
        var bs = getBootstrap();
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            if (bs && bs.Dropdown) {
                var instance = bs.Dropdown.getInstance(el);
                if (instance) instance.hide();
            }
            el.setAttribute('aria-expanded', 'false');
            el.classList.remove('show');
        });
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            menu.classList.remove('show');
        });
    }

    // Inicializa no carregamento normal da página
    document.addEventListener('DOMContentLoaded', initDropdowns);

    // Reinicializa após cada navegação Turbo (página nova no cache)
    document.addEventListener('turbo:render', initDropdowns);
    document.addEventListener('turbo:load', initDropdowns);

    // Fecha dropdowns ao iniciar navegação Turbo
    document.addEventListener('turbo:click', closeAllDropdowns);
    document.addEventListener('turbo:before-visit', closeAllDropdowns);
}());
