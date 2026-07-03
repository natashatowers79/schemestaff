// Scheme Staff — shared front-end behaviour.
//
// Form submissions post to a Google Apps Script endpoint that writes them to
// a Google Sheet and saves uploaded documents to Drive (see
// google-apps-script/Code.gs for the receiving code and setup steps).
// While SUBMIT_URL below is empty, submitting a form validates it and shows
// a preview confirmation only — nothing is stored or sent.

const SUBMIT_URL = ''; // paste the Apps Script web app URL (ends in /exec) between the quotes

const FORM_TYPES = {
  'register-employee': 'Candidates',
  'register-employer': 'Employers',
  'post-job': 'Job postings',
  'contact': 'Contact messages',
};

const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // Apps Script payload headroom

document.addEventListener('DOMContentLoaded', () => {

  document.querySelectorAll('form').forEach(form => {
    form.setAttribute('novalidate', '');

    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!validate(form)) return;

      if (!SUBMIT_URL) {
        showSuccess(form, true);
        return;
      }

      const button = form.querySelector('.btn-submit');
      const buttonText = button.textContent;
      button.disabled = true;
      button.textContent = 'Sending…';
      clearAlert(form);

      try {
        const payload = await buildPayload(form);
        const response = await fetch(SUBMIT_URL, {
          method: 'POST',
          // text/plain avoids a CORS preflight, which Apps Script can't answer
          headers: { 'Content-Type': 'text/plain;charset=utf-8' },
          body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.error || 'Submission failed');
        showSuccess(form, false);
      } catch (err) {
        showAlert(form, err.message === 'too-large'
          ? 'Your uploaded files are too large in total (15MB max). Please use smaller files and try again.'
          : 'Something went wrong sending your form. Please check your connection and try again — nothing has been lost.');
        button.disabled = false;
        button.textContent = buttonText;
      }
    });

    // Clear a field's error state as soon as it's corrected
    form.addEventListener('input', event => {
      const group = event.target.closest('.form-group, .form-terms');
      if (group) group.classList.remove('has-error');
    });
  });

});

/* ── Validation ── */

function validate(form) {
  const errors = [];

  // A field is required when its label carries the orange * (.required span)
  form.querySelectorAll('.form-group').forEach(group => {
    group.classList.remove('has-error');
    const label = group.querySelector('label');
    const field = group.querySelector('input, select, textarea');
    if (!label || !field || !label.querySelector('.required')) return;
    if (field.type === 'radio') {
      if (!group.querySelector('input[type="radio"]:checked')) {
        group.classList.add('has-error');
        errors.push(group);
      }
      return;
    }
    const empty = field.type === 'file' ? field.files.length === 0 : field.value.trim() === '';
    if (empty) {
      group.classList.add('has-error');
      errors.push(group);
    }
  });

  const terms = form.querySelector('.form-terms input[type="checkbox"]');
  if (terms) {
    terms.closest('.form-terms').classList.remove('has-error');
    if (!terms.checked) {
      terms.closest('.form-terms').classList.add('has-error');
      errors.push(terms.closest('.form-terms'));
    }
  }

  if (errors.length > 0) {
    errors[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    return false;
  }
  return true;
}

/* ── Serialisation ── */

function formType() {
  const page = location.pathname.split('/').pop().replace('.html', '');
  return FORM_TYPES[page] || 'Other';
}

function cleanLabel(el) {
  return el.textContent
    .replace(/\*/g, '')
    .replace(/\((optional|if applicable|if once-off)\)/gi, '')
    .replace(/\s+/g, ' ')
    .trim();
}

// Column name for a field, prefixed with its certificate/compliance block
// so repeated labels like "Upload certificate" stay distinct
function fieldKey(group) {
  const label = group.querySelector('label');
  let key = label ? cleanLabel(label) : 'Field';
  const cert = group.closest('.cert-block');
  if (cert) key = cleanLabel(cert.querySelector('.cert-block-title')) + ' — ' + key;
  const comp = group.closest('.compliance-block');
  if (comp) key = comp.querySelector('.compliance-block-header h3').textContent.trim() + ' — ' + key;
  return key;
}

function readableName(name) {
  const words = name.replace(/_/g, ' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
}

// Human-readable choice for card-style radios (plan/status/radio cards)
function radioText(input) {
  const card = input.closest('label');
  const nameEl = card && card.querySelector('.status-name, .plan-name, .radio-label');
  return nameEl ? nameEl.textContent.trim() : input.value;
}

async function buildPayload(form) {
  const fields = {};
  const fileJobs = [];

  form.querySelectorAll('.form-group').forEach(group => {
    const key = fieldKey(group);

    const checkboxGroup = group.querySelector('.checkbox-group');
    if (checkboxGroup) {
      fields[key] = [...checkboxGroup.querySelectorAll('input:checked')]
        .map(c => c.closest('.checkbox-item').textContent.trim())
        .join('; ');
      return;
    }

    const rateRow = group.querySelector('.rate-row');
    if (rateRow) {
      const amount = rateRow.querySelector('input').value.trim();
      const per = rateRow.querySelector('select').value;
      fields[key] = [amount, per.toLowerCase()].filter(Boolean).join(' ');
      return;
    }

    const fileInput = group.querySelector('input[type="file"]');
    if (fileInput) {
      [...fileInput.files].forEach(file => fileJobs.push({ field: key, file }));
      return;
    }

    const field = group.querySelector('input, select, textarea');
    if (!field) return;
    if (field.type === 'password') return; // never transmit passwords
    if (field.type === 'radio' || field.type === 'checkbox') return; // handled below
    fields[key] = field.value.trim();
  });

  // Radio groups (subscription plans, availability status, radio cards)
  const radioNames = new Set(
    [...form.querySelectorAll('input[type="radio"][name]')].map(r => r.name)
  );
  radioNames.forEach(name => {
    const checked = form.querySelector(`input[name="${name}"]:checked`);
    fields[readableName(name)] = checked ? radioText(checked) : '';
  });

  // Named toggle checkboxes (e.g. PPRA required, credit report required)
  form.querySelectorAll('.toggle-item input[type="checkbox"][name]').forEach(toggle => {
    fields[readableName(toggle.name)] = toggle.checked ? 'Yes' : 'No';
  });

  const totalBytes = fileJobs.reduce((sum, job) => sum + job.file.size, 0);
  if (totalBytes > MAX_UPLOAD_BYTES) throw new Error('too-large');

  const files = await Promise.all(fileJobs.map(readFileAsBase64));
  return { formType: formType(), fields, files };
}

function readFileAsBase64(job) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve({
      field: job.field,
      filename: job.file.name,
      mimeType: job.file.type || 'application/octet-stream',
      base64: reader.result.split(',')[1],
    });
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(job.file);
  });
}

/* ── Result panels ── */

function showSuccess(form, previewOnly) {
  const success = document.createElement('div');
  success.className = 'form-success';
  success.innerHTML = previewOnly
    ? `
      <h2>All done — form complete ✓</h2>
      <p>Thanks! Every required field checks out.</p>
      <p class="form-success-note">Preview site: this submission hasn't been stored or sent anywhere yet.</p>
      <a href="index.html" class="btn btn-outline">Back to home</a>`
    : `
      <h2>Thank you — we've received it ✓</h2>
      <p>Your submission has been sent to the Scheme Staff team. We'll be in touch.</p>
      <a href="index.html" class="btn btn-outline">Back to home</a>`;
  form.replaceWith(success);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showAlert(form, message) {
  clearAlert(form);
  const alert = document.createElement('div');
  alert.className = 'form-alert';
  alert.textContent = message;
  form.querySelector('.form-footer').prepend(alert);
  alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function clearAlert(form) {
  const existing = form.querySelector('.form-alert');
  if (existing) existing.remove();
}
