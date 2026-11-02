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
  const fields = [name, radius, media, headline, message, destination, location];
  const initial = fields.map(field => field.value);
  const saveState = document.querySelector('[data-save-state]');
  const editable = Boolean(saveState);
  let submitting = false;
  const isDirty = () => editable && (form.hasAttribute('data-unsaved-response')
    || fields.some((field, index) => field.value !== initial[index]));
  const render = () => {
    if (saveState) {
      const label = isDirty() ? 'Unsaved changes — save your draft before leaving.' : 'No unsaved changes.';
      if (saveState.textContent !== label) saveState.textContent = label;
    }
    document.querySelector('[data-preview-name]').textContent = name.value.trim() || 'Your next promotion';
    document.querySelector('[data-preview-radius]').textContent = radius.value || '—';
    document.querySelector('[data-preview-media]').textContent = media.selectedOptions[0]?.textContent || 'Choose a format';
    document.querySelector('[data-preview-headline]').textContent = headline.value.trim() || 'Your headline goes here';
    document.querySelector('[data-preview-message]').textContent = message.value.trim() || 'Your promotion message will appear here.';
    document.querySelector('[data-preview-destination]').textContent = destination.value.trim() || 'Your destination website';
    document.querySelector('[data-preview-location]').textContent = location.selectedOptions[0]?.textContent || 'Choose later';
  };
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  window.addEventListener('beforeunload', event => {
    if (!submitting && isDirty()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  // Native validation runs before submit. Prevent accidental repeated clicks only
  // once a valid submission starts; persisted pageshow restores the form on Back.
  form.addEventListener('submit', event => {
    if (submitting) { event.preventDefault(); return; }
    submitting = true;
    const button = form.querySelector('[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Saving draft…'; }
    if (saveState) saveState.textContent = 'Saving draft…';
  });
  window.addEventListener('pageshow', () => {
    submitting = false;
    const button = form.querySelector('[type="submit"]');
    if (button) { button.disabled = false; button.textContent = 'Save draft'; }
    render();
  });
  render();
})();
