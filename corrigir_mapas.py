#!/usr/bin/env python3
from pathlib import Path
import subprocess
import sys

root = Path.cwd()
changes = {
    'templates/escola_inep/show.html.twig': [
        ('     id="escola-data"', '     id="escola-data"\n     data-maptiler-key="{{ maptiler_key|default(\'\')|e(\'html_attr\') }}"'),
        ("  setTimeout(function () {\n    var map = L.map('escola-map'", "  var mapTilerKey = (el.dataset.maptilerKey || '').trim();\n  var mapDiv = document.getElementById('escola-map');\n  if (!Number.isFinite(lat2) || !Number.isFinite(lon2) || Math.abs(lat2) > 90 || Math.abs(lon2) > 180) {\n    mapDiv.textContent = 'Não foi possível identificar coordenadas válidas.';\n    return;\n  }\n  if (!mapTilerKey || typeof window.L === 'undefined') {\n    mapDiv.textContent = !mapTilerKey\n      ? 'Mapa indisponível: configure MAPTILER_KEY no ambiente da aplicação.'\n      : 'Não foi possível carregar a biblioteca de mapas. Atualize a página.';\n    mapDiv.classList.add('p-3', 'text-warning');\n    return;\n  }\n\n  setTimeout(function () {\n    if (!mapDiv.isConnected || mapDiv.dataset.mapInitialized === '1') return;\n    var map = L.map('escola-map'"),
        ("    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {\n      attribution: '&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors',\n      maxZoom: 19\n    }).addTo(map);", "    mapDiv.dataset.mapInitialized = '1';\n    L.tileLayer('https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=' + encodeURIComponent(mapTilerKey), {\n      attribution: '&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors &copy; <a href=\"https://www.maptiler.com/copyright/\" target=\"_blank\" rel=\"noopener\">MapTiler</a>',\n      maxZoom: 19,\n      crossOrigin: true\n    }).on('tileerror', function () {\n      console.error('Falha nos tiles MapTiler: verifique MAPTILER_KEY, quota e restrições de domínio.');\n    }).addTo(map);")
    ],
    'templates/posto/show.html.twig': [
        ('            <div id="posto-map"', '            <div id="posto-map"\n                 data-maptiler-key="{{ maptiler_key|default(\'\')|e(\'html_attr\') }}"'),
        ("        function buildMap() {\n            var L = window.L;\n            mapDiv.innerHTML = '';", "        function buildMap() {\n            if (!mapDiv.isConnected || mapDiv.dataset.mapInitialized === '1') return;\n            var L = window.L;\n            var mapTilerKey = (mapDiv.dataset.maptilerKey || '').trim();\n            mapDiv.innerHTML = '';\n            if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180 || !mapTilerKey || !L) {\n                var warning = document.createElement('div');\n                warning.className = 'alert alert-warning rounded-0 border-0 m-0 h-100 d-flex align-items-center small';\n                warning.setAttribute('role', 'alert');\n                warning.textContent = !mapTilerKey\n                    ? 'Mapa indisponível: configure MAPTILER_KEY no ambiente da aplicação.'\n                    : (!L ? 'Não foi possível carregar a biblioteca de mapas. Atualize a página.' : 'Não foi possível identificar coordenadas válidas.');\n                mapDiv.appendChild(warning);\n                return;\n            }"),
        ("            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {\n                attribution: '© <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\">OpenStreetMap</a>',\n                maxZoom: 19\n            }).addTo(map);", "            mapDiv.dataset.mapInitialized = '1';\n            L.tileLayer('https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=' + encodeURIComponent(mapTilerKey), {\n                attribution: '&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors &copy; <a href=\"https://www.maptiler.com/copyright/\" target=\"_blank\" rel=\"noopener\">MapTiler</a>',\n                maxZoom: 19,\n                crossOrigin: true\n            }).on('tileerror', function () {\n                console.error('Falha nos tiles MapTiler: verifique MAPTILER_KEY, quota e restrições de domínio.');\n            }).addTo(map);")
    ]
}
prepared = {}
for name, replacements in changes.items():
    path = root / name
    original = path.read_text(encoding='utf-8')
    updated = original
    for before, after in replacements:
        count = updated.count(before)
        if count != 1:
            sys.exit(f'Nenhum arquivo alterado: trecho esperado em {name} apareceu {count} vez(es). Verifique a versão do repositório.')
        updated = updated.replace(before, after, 1)
    prepared[path] = updated
for path, updated in prepared.items():
    path.write_text(updated, encoding='utf-8')
subprocess.run(['git', 'diff', '--check'], check=True)
print('Correção aplicada aos dois templates e seus scripts JavaScript inline. Revise git diff e execute o lint Twig antes de criar o commit.')
