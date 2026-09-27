/**
 * Gmail-style 6-digit OTP boxes
 */
(function () {
  function wire(root) {
    const inputs = Array.from(root.querySelectorAll('.otp-digit'));
    if (!inputs.length) return;

    inputs.forEach((input, idx) => {
      input.addEventListener('input', (e) => {
        const v = (e.target.value || '').replace(/\D/g, '');
        e.target.value = v.slice(-1);
        if (e.target.value && idx < inputs.length - 1) {
          inputs[idx + 1].focus();
        }
      });

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && idx > 0) {
          inputs[idx - 1].focus();
        }
        if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
        if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
      });

      input.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text') || '';
        const digits = text.replace(/\D/g, '').slice(0, inputs.length).split('');
        digits.forEach((d, i) => {
          if (inputs[i]) inputs[i].value = d;
        });
        const next = Math.min(digits.length, inputs.length - 1);
        inputs[next].focus();
      });
    });

    inputs[0].focus();
  }

  document.querySelectorAll('[data-otp]').forEach(wire);
})();
