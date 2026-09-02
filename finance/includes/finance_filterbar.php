      <!-- ===== filter bar ===== -->
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
          <input type="date" name="from" value="<?= $fromStr ?>">
          <span class="muted">to</span>
          <input type="date" name="to" value="<?= $toStr ?>">
          <button type="submit" name="range" value="custom" class="apply-btn">Apply</button>
        </div>
      </form>
      <div class="range-label" style="margin:-14px 0 20px 4px;">
        Showing <?= $from->format('M d, Y') ?> – <?= $to->format('M d, Y') ?> · <?= $orderCnt ?> completed order<?= $orderCnt === 1 ? '' : 's' ?>
      </div>
