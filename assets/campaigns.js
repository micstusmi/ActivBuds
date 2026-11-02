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
  let saved = fields.map(field => field.value);
  const saveState = document.querySelector('[data-save-state]');
  const editable = Boolean(saveState);
  const retry = document.querySelector('[data-autosave-retry]');
  const errors = document.querySelector('[data-autosave-errors]');
  const button = form.querySelector('[type="submit"]');
  let rejected = form.hasAttribute('data-unsaved-response');
  let timer, saving = false, stopped = false;
  const snapshot = () => fields.map(field => field.value);
  const isDirty = () => editable && (rejected || snapshot().some((value, index) => value !== saved[index]));
  const status = text => { if (saveState && saveState.textContent !== text) saveState.textContent = text; };
  const render = () => {
    document.querySelector('[data-preview-name]').textContent = name.value.trim() || 'Your next promotion';
    document.querySelector('[data-preview-radius]').textContent = radius.value || '—';
    document.querySelector('[data-preview-media]').textContent = media.selectedOptions[0]?.textContent || 'Choose a format';
    document.querySelector('[data-preview-headline]').textContent = headline.value.trim() || 'Your headline goes here';
    document.querySelector('[data-preview-message]').textContent = message.value.trim() || 'Your promotion message will appear here.';
    document.querySelector('[data-preview-destination]').textContent = destination.value.trim() || 'Your destination website';
    document.querySelector('[data-preview-location]').textContent = location.selectedOptions[0]?.textContent || 'Choose later';
  };
  const schedule = () => {
    render(); clearTimeout(timer);
    if (!editable || stopped || saving) return;
    if (!isDirty()) { status('Saved'); return; }
    status('Unsaved changes');
    timer = setTimeout(save, 900);
  };
  const save = async () => {
    clearTimeout(timer);
    if (saving || stopped || !isDirty()) return;
    if (!form.checkValidity()) { status('Complete the required fields to save automatically.'); return; }
    const sent = snapshot();
    const body = new FormData(form); body.set('autosave', '1');
    const creating = !form.querySelector('[name="revision"]');
    saving = true; retry.hidden = true; errors.textContent = ''; status('Saving…');
    let confirmedFailure = false;
    try {
      const response = await fetch(form.action, {method:'POST', body, credentials:'same-origin', headers:{Accept:'application/json'}});
      if (response.redirected) throw new Error('Sign in again, then reopen your draft.');
      const result = await response.json();
      if (!response.ok) {
        confirmedFailure = true;
        if (response.status === 409 || response.status === 401 || response.status === 403) stopped = true;
        const details = Object.values(result.fields || {}).join(' ');
        throw new Error(details || result.error || 'Saving failed.');
      }
      if (!Number.isInteger(result.id) || !/^[a-f0-9]{64}$/.test(result.revision)) throw new Error('Save confirmation was not recognised.');
      const url = new URL('campaign_edit.php?id=' + result.id, window.location.href);
      form.action = url.href; history.replaceState(null, '', url.href);
      let revision = form.querySelector('[name="revision"]');
      if (!revision) { revision = document.createElement('input'); revision.type='hidden'; revision.name='revision'; form.append(revision); }
      revision.value = result.revision;
      saved = sent; rejected = false; status('Saved');
    } catch (error) {
      // A first create may have reached the server even when its reply was lost.
      // Reconcile in the dashboard rather than offering a blind create retry.
      stopped = stopped || (creating && !confirmedFailure);
      status('Couldn’t save');
      errors.textContent = error.message + (stopped ? ' Keep this page open and copy your edits before reopening the draft from the dashboard.' : ' Your edits remain on this page.');
      retry.hidden = stopped;
    } finally {
      saving = false;
      if (isDirty() && !stopped && retry.hidden) schedule();
    }
  };
  if (editable && window.fetch && window.FormData) {
    button.hidden = true;
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    form.addEventListener('submit', event => { event.preventDefault(); save(); });
    retry.addEventListener('click', () => { stopped = false; save(); });
    window.addEventListener('beforeunload', event => {
      if (saving || isDirty()) { event.preventDefault(); event.returnValue = ''; }
    });
    status(rejected ? 'Unsaved changes — edit a field to retry.' : (form.querySelector('[name="revision"]') ? 'Saved' : 'Name your campaign to start autosaving.'));
  } else {
    form.addEventListener('input', render);
  }
  render();
})();
