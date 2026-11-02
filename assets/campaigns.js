(() => {
  'use strict';
  const form = document.querySelector('[data-campaign-form]');
  if (!form) return;
  const name = form.querySelector('[name="name"]');
  const radius = form.querySelector('[name="target_radius_km"]');
  const media = form.querySelector('[name="media_type"]');
  const headline = form.querySelector('[name="headline"]');
  const message = form.querySelector('[name="message"]');
  const destination = form.querySelector('[name="destination_url"]');
  const location = form.querySelector('[name="location_id"]');
  const render = () => {
    document.querySelector('[data-preview-name]').textContent = name.value.trim() || 'Your next promotion';
    document.querySelector('[data-preview-radius]').textContent = radius.value || '—';
    document.querySelector('[data-preview-media]').textContent = media.selectedOptions[0]?.textContent || 'Choose a format';
    document.querySelector('[data-preview-headline]').textContent = headline.value.trim() || 'Your headline goes here';
    document.querySelector('[data-preview-message]').textContent = message.value.trim() || 'Your promotion message will appear here.';
    document.querySelector('[data-preview-destination]').textContent = destination.value.trim() || 'Your destination website';
    document.querySelector('[data-preview-location]').textContent = location.selectedOptions[0]?.textContent || 'Choose later';
  };
  form.addEventListener('input', render);
  render();
})();
