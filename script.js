// Scheme Staff — shared front-end behaviour.
// The forms have no backend yet: submitting validates the fields and shows a
// preview confirmation, but nothing is stored or sent anywhere.

document.addEventListener('DOMContentLoaded', () => {

  document.querySelectorAll('form').forEach(form => {
    form.setAttribute('novalidate', '');

    form.addEventListener('submit', event => {
      event.preventDefault();

      const errors = [];

      // A field is required when its label carries the orange * (.required span)
      form.querySelectorAll('.form-group').forEach(group => {
        group.classList.remove('has-error');
        const label = group.querySelector('label');
        const field = group.querySelector('input, select, textarea');
        if (!label || !field || !label.querySelector('.required')) return;

        let empty;
        if (field.type === 'file') {
          empty = field.files.length === 0;
        } else {
          empty = field.value.trim() === '';
        }
        if (empty) {
          group.classList.add('has-error');
          errors.push(group);
        }
      });

      // Terms & conditions checkbox
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
        return;
      }

      const success = document.createElement('div');
      success.className = 'form-success';
      success.innerHTML = `
        <h2>All done — form complete ✓</h2>
        <p>Thanks! Every required field checks out.</p>
        <p class="form-success-note">Preview site: this submission hasn't been stored or sent anywhere yet.</p>
        <a href="index.html" class="btn btn-outline">Back to home</a>
      `;
      form.replaceWith(success);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Clear a field's error state as soon as it's corrected
    form.addEventListener('input', event => {
      const group = event.target.closest('.form-group, .form-terms');
      if (group) group.classList.remove('has-error');
    });
  });

});
