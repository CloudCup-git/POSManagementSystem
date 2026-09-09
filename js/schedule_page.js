// ── Schedule Page ────────────────────────────────────────────────
// JS for HR/Schedule_Page.php. Every function guards for missing
// elements so this same file works whether the visitor is looking at
// the read-only employee view or the HR builder view.

// Shift type -> default color, kept in sync with $SHIFT_LABELS in the
// PHP file (also passed down as window.SCHED_LABEL_COLORS).
var SCHED_PRESETS = (typeof SCHED_LABEL_COLORS !== 'undefined') ? SCHED_LABEL_COLORS : {
  'Opening Shift': '#2f6690',
  'Closing Shift': '#d97706',
  'Day Off': '#6b6156'
};

// Shift type -> default start/end time, based on the cafe's actual
// operating hours (8:00 AM open / 10:00 PM close). Full-time defaults
// overlap 2:00–4:00 PM for handover and lunch coverage. Part-time
// defaults are shorter (4hr) blocks, since part-time staff typically
// aren't covering a full open-to-mid or mid-to-close span. All of these
// are still fully editable in the form.
var SCHED_TIME_PRESETS = {
  'Opening Shift': {
    'full-time': { start: '08:00', end: '16:00' },
    'part-time': { start: '08:00', end: '12:00' }
  },
  'Closing Shift': {
    'full-time': { start: '14:00', end: '22:00' },
    'part-time': { start: '18:00', end: '22:00' }
  }
};

// Looks up the employment type for whichever employee is currently
// selected in the modal's Employee dropdown. Falls back to 'full-time'
// if the map isn't available or the id isn't in it.
function schedGetSelectedEmployeeType() {
  var empField = document.getElementById('f_employee_id');
  var types = (typeof SCHED_EMPLOYEE_TYPES !== 'undefined') ? SCHED_EMPLOYEE_TYPES : {};
  if (!empField || !empField.value) return 'full-time';
  return types[empField.value] === 'part-time' ? 'part-time' : 'full-time';
}

// Updates the Full-time/Part-time badge next to the Employee label to
// match whichever employee is currently selected.
function schedUpdateEmpTypeBadge() {
  var badge = document.getElementById('empTypeBadge');
  if (!badge) return;
  var type = schedGetSelectedEmployeeType();
  badge.textContent = type === 'part-time' ? 'Part-time' : 'Full-time';
  badge.classList.remove('full-time', 'part-time');
  badge.classList.add(type);
}

// Shift-length rule (mirrors sched_duration_violation() in
// Schedule_Page.php): part-time shifts must be 4–6 hours, full-time
// shifts must be 8–9 hours. Returns '' if fine, or a message if blocked.
function schedDurationViolation(start, end, employeeType) {
  employeeType = employeeType || schedGetSelectedEmployeeType();

  var startMin = parseInt(start.split(':')[0], 10) * 60 + parseInt(start.split(':')[1], 10);
  var endMin = parseInt(end.split(':')[0], 10) * 60 + parseInt(end.split(':')[1], 10);
  var hours = (endMin - startMin) / 60;

  var minH, maxH;
  if (employeeType === 'part-time') {
    minH = (typeof SCHED_PART_TIME_MIN_HOURS !== 'undefined') ? SCHED_PART_TIME_MIN_HOURS : 4;
    maxH = (typeof SCHED_PART_TIME_MAX_HOURS !== 'undefined') ? SCHED_PART_TIME_MAX_HOURS : 6;
    if (hours >= minH && hours <= maxH) return '';
    return 'A part-time shift must be between ' + minH + ' and ' + maxH + ' hours long. This shift is ' + (Math.round(hours * 10) / 10) + ' hours.';
  }

  minH = (typeof SCHED_FULL_TIME_MIN_HOURS !== 'undefined') ? SCHED_FULL_TIME_MIN_HOURS : 8;
  maxH = (typeof SCHED_FULL_TIME_MAX_HOURS !== 'undefined') ? SCHED_FULL_TIME_MAX_HOURS : 9;
  if (hours >= minH && hours <= maxH) return '';
  return 'A full-time shift must be between ' + minH + ' and ' + maxH + ' hours long. This shift is ' + (Math.round(hours * 10) / 10) + ' hours.';
}

// Day Off isn't a worked shift, so it has no meaningful time range — this
// hides the Start/End Time row and drops their `required` attribute
// whenever "Day Off" is the selected shift type (and restores both when
// switching to a real shift type).
function schedUpdateTimeFieldsVisibility(labelValue) {
  var row = document.getElementById('timeFieldsRow');
  var hint = document.getElementById('dayOffHint');
  var startField = document.getElementById('f_start');
  var endField = document.getElementById('f_end');
  var isDayOff = labelValue === 'Day Off';
  if (row) row.style.display = isDayOff ? 'none' : '';
  if (hint) hint.style.display = isDayOff ? '' : 'none';
  if (startField) {
    startField.required = !isDayOff;
    if (isDayOff) startField.classList.remove('input-error');
  }
  if (endField) {
    endField.required = !isDayOff;
    if (isDayOff) endField.classList.remove('input-error');
  }
}

// Opens the Add/Edit Shift modal. Call with no args to add a new shift
// (using whatever employee is currently selected), or with a shift
// object (from the grid block / table row onclick) to edit one.
function openShiftModal(shift) {
  var modal = document.getElementById('shiftModal');
  if (!modal) return;

  var title   = document.getElementById('shiftModalTitle');
  var idField = document.getElementById('f_schedule_id');
  var empField = document.getElementById('f_employee_id');
  var dayBoxes = document.querySelectorAll('.f_day_checkbox');
  var dayHint = document.getElementById('dayPickerHint');
  var startField = document.getElementById('f_start');
  var endField = document.getElementById('f_end');
  var labelField = document.getElementById('f_label');
  var colorField = document.getElementById('f_color');

  if (shift && shift.schedule_id) {
    title.textContent = 'Edit Shift';
    idField.value = shift.schedule_id;
    empField.value = shift.employee_id;
    // Editing always applies to the one row it came from, so only that
    // day is checked and every other day is locked out.
    dayBoxes.forEach(function (box) {
      var checked = box.value === shift.day_of_week;
      box.checked = checked;
      box.disabled = !checked;
      box.closest('.sched-day-chip').classList.toggle('disabled', !checked);
    });
    if (dayHint) dayHint.textContent = 'Editing a single shift — delete and re-add it to move it to a different day.';
    startField.value = shift.start_time;
    endField.value = shift.end_time;
    labelField.value = shift.label;
    colorField.value = shift.color;
  } else {
    title.textContent = 'Add Shift';
    idField.value = '';
    if (empField && typeof SCHED_DEFAULT_EMPLOYEE !== 'undefined') empField.value = SCHED_DEFAULT_EMPLOYEE;
    dayBoxes.forEach(function (box) {
      box.checked = false;
      box.disabled = false;
      box.closest('.sched-day-chip').classList.remove('disabled');
    });
    if (dayHint) dayHint.textContent = 'Pick one or more days — the same shift is added to each.';
    var defaultPreset = SCHED_TIME_PRESETS['Opening Shift'][schedGetSelectedEmployeeType()];
    startField.value = defaultPreset.start;
    endField.value = defaultPreset.end;
    labelField.value = '';
    colorField.value = '#2f6690';
  }

  schedUpdateTimeFieldsVisibility(labelField.value);
  schedUpdateEmpTypeBadge();
  highlightSelectedColorDot(colorField.value);
  updateDayChipStyles();
  modal.classList.add('open');
}

function updateDayChipStyles() {
  document.querySelectorAll('.sched-day-chip').forEach(function (chip) {
    var box = chip.querySelector('.f_day_checkbox');
    chip.classList.toggle('checked', !!(box && box.checked));
  });
}

function highlightSelectedColorDot(color) {
  document.querySelectorAll('.sched-color-dot').forEach(function (dot) {
    dot.classList.toggle('selected', dot.dataset.color.toLowerCase() === (color || '').toLowerCase());
  });
}

document.addEventListener('DOMContentLoaded', function () {
  // Color dot picker -> fills the hidden color input.
  var colorField = document.getElementById('f_color');
  document.querySelectorAll('.sched-color-dot').forEach(function (dot) {
    dot.addEventListener('click', function () {
      colorField.value = dot.dataset.color;
      highlightSelectedColorDot(dot.dataset.color);
    });
  });

  // Day chips -> toggle their own "checked" look immediately on click.
  document.querySelectorAll('.f_day_checkbox').forEach(function (box) {
    box.addEventListener('change', updateDayChipStyles);
  });

  // Picking a shift type auto-selects its default color and its default
  // start/end time based on the cafe's operating hours AND the selected
  // employee's Full-time/Part-time status (still fully overridable —
  // color via the swatches above, times via the time inputs themselves).
  var labelField = document.getElementById('f_label');
  var startField = document.getElementById('f_start');
  var endField = document.getElementById('f_end');
  var empField = document.getElementById('f_employee_id');
  if (labelField) {
    labelField.addEventListener('change', function () {
      schedUpdateTimeFieldsVisibility(labelField.value);
      var preset = SCHED_PRESETS[labelField.value];
      if (preset) {
        colorField.value = preset;
        highlightSelectedColorDot(preset);
      }
      var timePreset = SCHED_TIME_PRESETS[labelField.value];
      if (timePreset && startField && endField) {
        var byType = timePreset[schedGetSelectedEmployeeType()];
        startField.value = byType.start;
        endField.value = byType.end;
      }
    });
  }

  // Switching employees updates the Full-time/Part-time badge, and — if
  // a shift type is already picked — re-applies that type's default
  // times for the newly-selected employee (e.g. picking a part-time
  // employee shortens an already-chosen Closing Shift's default hours).
  if (empField) {
    empField.addEventListener('change', function () {
      schedUpdateEmpTypeBadge();
      if (labelField && labelField.value && SCHED_TIME_PRESETS[labelField.value] && startField && endField) {
        var byType = SCHED_TIME_PRESETS[labelField.value][schedGetSelectedEmployeeType()];
        startField.value = byType.start;
        endField.value = byType.end;
      }
      if (endField) endField.classList.remove('input-error');
    });
  }

  // Require at least one day, and a valid (non-reversed, non-zero-length)
  // time range, before the Add/Edit Shift form can be submitted.
  var shiftForm = document.getElementById('shiftForm');
  if (shiftForm) {
    shiftForm.addEventListener('submit', function (e) {
      var anyChecked = document.querySelectorAll('.f_day_checkbox:checked').length > 0;
      if (!anyChecked) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Pick at least one day',
          text: 'Select at least one day for this shift before saving.'
        });
        return;
      }

      if (startField && endField && startField.value && endField.value) {
        if (startField.value === endField.value) {
          e.preventDefault();
          Swal.fire({
            icon: 'warning',
            title: 'Same start and end time',
            text: 'Start Time and End Time can\'t be the same — a shift needs to have some length.'
          });
          return;
        }
        if (endField.value < startField.value) {
          e.preventDefault();
          Swal.fire({
            icon: 'warning',
            title: 'End time is before start time',
            text: 'End Time must be later than Start Time. If this shift crosses midnight, split it into two shifts (e.g. end at 11:59 PM and start a new one at 12:00 AM).'
          });
          return;
        }

        var durationError = schedDurationViolation(startField.value, endField.value);
        if (durationError) {
          e.preventDefault();
          Swal.fire({
            icon: 'warning',
            title: 'Shift length not allowed',
            text: durationError
          });
          return;
        }
      }
    });
  }

  // Also flag it the moment the user leaves the End Time field, instead
  // of waiting until they try to save.
  if (endField) {
    endField.addEventListener('change', function () {
      if (!startField || !startField.value || !endField.value) return;
      var invalid = endField.value <= startField.value || !!schedDurationViolation(startField.value, endField.value);
      endField.classList.toggle('input-error', invalid);
    });
  }
  if (startField) {
    startField.addEventListener('change', function () {
      if (endField) endField.classList.remove('input-error');
    });
  }

  // Close any modal-overlay-admin by clicking its backdrop.
  document.querySelectorAll('.modal-overlay-admin').forEach(function (m) {
    m.addEventListener('click', function (e) {
      if (e.target === m) m.classList.remove('open');
    });
  });

  // SweetAlert confirmation before deleting a shift.
  document.querySelectorAll('.delete-shift-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = form.dataset.name || 'this shift';
      Swal.fire({
        title: 'Remove shift?',
        html: 'Delete <b>' + name + '</b> from the schedule?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, remove it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        reverseButtons: true
      }).then(function (result) {
        if (result.isConfirmed) form.submit();
      });
    });
  });

  schedInitMiniCalendar();
});

// ── Mini calendar (decorative month view, see the PHP comment above its
// markup) — renders entirely client-side; Prev/Next just walk the
// displayed month back and forth, they don't load or filter any data. ──
function schedInitMiniCalendar() {
  var titleEl = document.getElementById('miniCalTitle');
  var daysEl = document.getElementById('miniCalDays');
  var prevBtn = document.getElementById('miniCalPrev');
  var nextBtn = document.getElementById('miniCalNext');
  if (!titleEl || !daysEl) return; // not on this page/view

  var today = new Date();
  var shown = new Date(today.getFullYear(), today.getMonth(), 1);
  var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];

  function render() {
    titleEl.textContent = MONTH_NAMES[shown.getMonth()] + ' ' + shown.getFullYear();
    daysEl.innerHTML = '';

    var firstOfMonth = new Date(shown.getFullYear(), shown.getMonth(), 1);
    // Grid starts on Monday — shift Sunday (0) to the end of the row.
    var startOffset = (firstOfMonth.getDay() + 6) % 7;
    var gridStart = new Date(firstOfMonth);
    gridStart.setDate(gridStart.getDate() - startOffset);

    for (var i = 0; i < 42; i++) {
      var cellDate = new Date(gridStart);
      cellDate.setDate(gridStart.getDate() + i);

      var cell = document.createElement('div');
      cell.className = 'mini-cal-day';
      if (cellDate.getMonth() !== shown.getMonth()) cell.classList.add('is-outside');
      if (cellDate.toDateString() === today.toDateString()) cell.classList.add('is-today');
      cell.textContent = cellDate.getDate();
      daysEl.appendChild(cell);

      // Six full rows is one row too many for most months — stop once
      // we've shown the whole month and at least completed its last week.
      if (i >= 34 && cellDate.getMonth() !== shown.getMonth() && (i + 1) % 7 === 0) break;
    }
  }

  if (prevBtn) prevBtn.addEventListener('click', function () {
    shown.setMonth(shown.getMonth() - 1);
    render();
  });
  if (nextBtn) nextBtn.addEventListener('click', function () {
    shown.setMonth(shown.getMonth() + 1);
    render();
  });

  render();
}