(function () {
  if (window.twSaudeReady) return;
  window.twSaudeReady = true;
  var activeMap = null;
  function init() {
    var el = document.getElementById('saude-data');
    var box = document.getElementById('saude-map');
    if (!el || !box || box.dataset.ready) return;
    var lat = Number(el.dataset.lat), lon = Number(el.dataset.lon);
    if (!el.dataset.lat || !el.dataset.lon || !Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180 || lat === 0 || lon === 0) return;
    if (!window.L) { box.textContent = 'Não foi possível carregar o mapa. Atualize a página.'; return; }
    box.dataset.ready = '1';
    activeMap = L.map(box, {scrollWheelZoom:false}).setView([lat, lon], 17);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom:19,
      attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
    }).addTo(activeMap);
    var popup = document.createElement('div');
    var title = document.createElement('strong'); title.textContent = el.dataset.nome;
    popup.append(title, document.createElement('br'), document.createTextNode(el.dataset.local));
    L.marker([lat, lon]).addTo(activeMap).bindPopup(popup).openPopup();
    setTimeout(function () { if (activeMap) activeMap.invalidateSize(); }, 100);
  }
  document.addEventListener('click', async function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) return;
    var feedback = document.getElementById('saude-feedback');
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      if (feedback) feedback.textContent = 'Copiado.';
    } catch (error) {
      if (feedback) feedback.textContent = 'Não foi possível copiar automaticamente. Selecione o texto para copiar.';
    }
  });
  document.addEventListener('DOMContentLoaded', init);
  document.addEventListener('turbo:load', init);
  document.addEventListener('turbo:before-cache', function () {
    if (activeMap) { activeMap.remove(); activeMap = null; }
    var box = document.getElementById('saude-map');
    if (box) { delete box.dataset.ready; box.innerHTML = ''; }
  });
  if (document.readyState !== 'loading') init();
}());
