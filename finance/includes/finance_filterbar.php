      <!-- ===== filter bar ===== -->
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
      <form class="filter-bar" method="get">
        <div class="preset-group">
          <?php
            $presets = [
              'today'      => 'Today',
              'this_week'  => 'This Week',
              'this_month' => 'This Month',
              'this_year'  => 'This Year',
            ];
            foreach ($presets as $key => $label):
          ?>
            <button type="submit" name="range" value="<?= $key ?>"
              class="preset-btn <?= $range === $key ? 'active' : '' ?>"><?= $label ?></button>
          <?php endforeach; ?>
        </div>

        <div class="custom-range">
          <span class="cc-date-wrap">
            <svg class="cc-date-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <input type="text" class="cc-date-input" id="fin-date-from" name="from" value="<?= $fromStr ?>" autocomplete="off">
          </span>
          <span class="muted">to</span>
          <span class="cc-date-wrap">
            <svg class="cc-date-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <input type="text" class="cc-date-input" id="fin-date-to" name="to" value="<?= $toStr ?>" autocomplete="off">
          </span>
          <button type="submit" name="range" value="custom" class="apply-btn">Apply</button>
        </div>
      </form>
      <div class="range-label" style="margin:-14px 0 20px 4px;">
        Showing <?= $from->format('M d, Y') ?> – <?= $to->format('M d, Y') ?> · <?= $orderCnt ?> completed order<?= $orderCnt === 1 ? '' : 's' ?>
      </div>

      <style>
        /* Themed to match the Finance portal's gold/brown palette (same
           colors used in the SweetAlert2 logout dialogs) instead of the
           browser's plain native date-picker popover. */
        .cc-date-wrap{ position: relative; display: inline-flex; align-items: center; }
        .cc-date-icon{ position: absolute; left: 9px; color: #9c9184; pointer-events: none; }
        .cc-date-input{
          font-size: 12.5px; padding: 6px 10px 6px 28px; border-radius: 8px;
          border: 1px solid #e9e3d8; background: #fff; color: #161009;
          width: 108px; cursor: pointer;
        }
        .cc-date-input:focus{ outline: none; border-color: #b8703f; box-shadow: 0 0 0 3px rgba(184,112,63,0.15); }
        .flatpickr-calendar{ box-shadow: 0 20px 45px rgba(0,0,0,0.22); border-radius: 14px; overflow: hidden; }
        .flatpickr-months{ background: #161009; }
        .flatpickr-month{ color: #fff; fill: #fff; }
        .flatpickr-current-month .flatpickr-monthDropdown-months{ background: #161009; color: #fff; }
        .flatpickr-prev-month, .flatpickr-next-month{ color: #fff !important; fill: #fff !important; }
        .flatpickr-prev-month:hover svg, .flatpickr-next-month:hover svg{ fill: #b8703f; }
        span.flatpickr-weekday{ background: #161009; color: rgba(255,255,255,0.6); }
        .flatpickr-day.selected, .flatpickr-day.selected:hover{ background: #b8703f; border-color: #b8703f; }
        .flatpickr-day.today{ border-color: #b8703f; }
        .flatpickr-day.today:hover{ background: #b8703f; border-color: #b8703f; color: #fff; }
        .flatpickr-day:hover{ background: #f5ece3; }
        .flatpickr-day.inRange{
          background: #f5ece3; border-color: #f5ece3; box-shadow: -5px 0 0 #f5ece3, 5px 0 0 #f5ece3;
        }
      </style>
      <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
      <script>
        (function () {
          if (typeof flatpickr === 'undefined') return;
          var fromEl = document.getElementById('fin-date-from');
          var toEl   = document.getElementById('fin-date-to');
          if (!fromEl || !toEl) return;

          var fromFp = flatpickr(fromEl, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'M j, Y',
            altInputClass: 'cc-date-input',
            onChange: function (selectedDates) {
              if (selectedDates[0]) toFp.set('minDate', selectedDates[0]);
            }
          });
          var toFp = flatpickr(toEl, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'M j, Y',
            altInputClass: 'cc-date-input',
            onChange: function (selectedDates) {
              if (selectedDates[0]) fromFp.set('maxDate', selectedDates[0]);
            }
          });
        })();
      </script>
