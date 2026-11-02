(() => {
  'use strict';
  const form = document.querySelector('[data-campaign-form]');
  if (!form) return;
  const name = form.querySelector('[name="name"]');
  const radius = form.querySelector('[name="target_radius_km"]');
  const media = form.querySelector('[name="media_type"]');
  const render = () => {
    document.querySelector('[data-preview-name]').textContent = name.value.trim() || 'Your next promotion';
    document.querySelector('[data-preview-radius]').textContent = radius.value || '—';
    document.querySelector('[data-preview-media]').textContent = media.selectedOptions[0]?.textContent || 'Choose a format';
  };
  form.addEventListener('input', render);
  render();
})();
