/**
 * CloudCup — Mini Calculator (popup)
 * Self-contained: builds its own modal markup, wires up the trigger
 * button (#openCalcBtn), and handles all key logic. No dependencies.
 */
(function () {
  const state = { current: '0', previous: null, operator: null, overwrite: true };

  function fmt(n) {
    if (!isFinite(n)) return 'Error';
    // trim floating point noise, keep up to 8 decimal places
    const rounded = Math.round((n + Number.EPSILON) * 1e8) / 1e8;
    return rounded.toString();
  }

  function updateDisplay() {
    const disp = document.getElementById('calcDisplay');
    const sub = document.getElementById('calcSubDisplay');
    if (!disp) return;
    disp.textContent = state.current;
    sub.textContent = state.operator && state.previous !== null
      ? `${fmt(state.previous)} ${state.operator}`
      : '';
  }

  function inputDigit(d) {
    if (state.overwrite) {
      state.current = d === '.' ? '0.' : d;
      state.overwrite = false;
    } else {
      if (d === '.' && state.current.includes('.')) return;
      if (state.current === '0' && d !== '.') state.current = d;
      else state.current += d;
    }
    updateDisplay();
  }

  function applyOperator(op) {
    const cur = parseFloat(state.current);
    if (state.operator && !state.overwrite) {
      compute();
    } else {
      state.previous = cur;
    }
    state.operator = op;
    state.overwrite = true;
    updateDisplay();
  }

  function compute() {
    if (state.operator === null || state.previous === null) return;
    const a = state.previous, b = parseFloat(state.current);
    let result = b;
    switch (state.operator) {
      case '+': result = a + b; break;
      case '−': result = a - b; break;
      case '×': result = a * b; break;
      case '÷': result = b === 0 ? NaN : a / b; break;
    }
    state.current = fmt(result);
    state.previous = null;
    state.operator = null;
    state.overwrite = true;
    updateDisplay();
  }

  function clearAll() {
    state.current = '0'; state.previous = null; state.operator = null; state.overwrite = true;
    updateDisplay();
  }

  function backspace() {
    if (state.overwrite) return;
    state.current = state.current.length > 1 ? state.current.slice(0, -1) : '0';
    if (state.current === '') state.current = '0';
    updateDisplay();
  }

  function toggleSign() {
    if (state.current === '0') return;
    state.current = state.current.startsWith('-') ? state.current.slice(1) : '-' + state.current;
    updateDisplay();
  }

  function percent() {
    state.current = fmt(parseFloat(state.current) / 100);
    updateDisplay();
  }

  function openCalc() {
    document.getElementById('calcOverlay').classList.add('open');
    updateDisplay();
  }
  function closeCalc() {
    document.getElementById('calcOverlay').classList.remove('open');
  }

  document.addEventListener('DOMContentLoaded', function () {
    const openBtn = document.getElementById('openCalcBtn');
    const overlay = document.getElementById('calcOverlay');
    if (!openBtn || !overlay) return;

    openBtn.addEventListener('click', openCalc);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeCalc(); });
    document.getElementById('calcCloseBtn').addEventListener('click', closeCalc);

    document.querySelectorAll('.calc-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const digit = btn.dataset.digit;
        const op = btn.dataset.op;
        const action = btn.dataset.action;
        if (digit !== undefined) inputDigit(digit);
        else if (op) applyOperator(op);
        else if (action === 'equals') compute();
        else if (action === 'clear') clearAll();
        else if (action === 'back') backspace();
        else if (action === 'sign') toggleSign();
        else if (action === 'percent') percent();
      });
    });

    document.addEventListener('keydown', (e) => {
      if (!overlay.classList.contains('open')) return;
      if (e.key === 'Escape') { closeCalc(); return; }
      if (/^[0-9]$/.test(e.key)) inputDigit(e.key);
      else if (e.key === '.') inputDigit('.');
      else if (e.key === '+') applyOperator('+');
      else if (e.key === '-') applyOperator('−');
      else if (e.key === '*') applyOperator('×');
      else if (e.key === '/') { e.preventDefault(); applyOperator('÷'); }
      else if (e.key === 'Enter' || e.key === '=') compute();
      else if (e.key === 'Backspace') backspace();
    });

    updateDisplay();
  });
})();
