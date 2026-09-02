<?php
/**
 * CloudCup — Finance: Annual Tax computation
 * -------------------------------------------------------------
 * The tax the shop pays: Percentage Tax under NIRC Sec. 116 (as amended
 * by the CREATE Act, reverted to 3% effective July 1, 2023) — 3% of
 * gross sales/receipts, with NO deduction or threshold subtracted from
 * the base. (The ₱3,000,000 figure in the law is an *eligibility*
 * ceiling — below it you're non-VAT/OPT, above it you're VAT-registered
 * — it is not subtracted from gross sales.) Percentage Tax is legally
 * filed and remitted quarterly (BIR Form 2551Q); this page rolls the
 * four quarters up into a calendar-year total purely for owner/finance
 * planning visibility, independent of the date-range filter the other
 * reports use.
 *
 * This is a planning estimate for owner/finance visibility, NOT a BIR
 * filing. Confirm the applicable percentage-tax rate and any
 * VAT-registration requirement with an accountant before remitting.
 *
 * Expects $pdo and $today (from finance_data.php, already required by
 * whichever page includes this file).
 */

const PERCENTAGE_TAX_RATE = 0.03; // 3%, NIRC Sec. 116 as amended by CREATE (RA 11534)

$taxYear = isset($_GET['year']) ? max(2000, (int) $_GET['year']) : (int) $today->format('Y');

$stmt = $pdo->prepare("
    SELECT MONTH(ordered_at) AS m, COALESCE(SUM(total_amount),0) AS total
    FROM orders
    WHERE status = 'completed' AND YEAR(ordered_at) = :yr
    GROUP BY m
");
$stmt->execute([':yr' => $taxYear]);
$monthlyRaw = $stmt->fetchAll();

$byMonth = array_fill(1, 12, 0.0);
foreach ($monthlyRaw as $m) {
    $byMonth[(int) $m['m']] = (float) $m['total'];
}

$monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$monthlyRevenue = [];
foreach ($byMonth as $mNum => $total) {
    $monthlyRevenue[] = ['label' => $monthNames[$mNum - 1], 'total' => $total];
}

$annualGross = array_sum($byMonth);
$taxDue      = round($annualGross * PERCENTAGE_TAX_RATE, 2); // 3% of gross sales, no deduction

/* Year picker options — every year that has completed-order data, plus
   the currently selected year even if it has none yet. */
$taxYearOptions = $pdo->query("
    SELECT DISTINCT YEAR(ordered_at) AS yr FROM orders WHERE status = 'completed' ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((string) $taxYear, array_map('strval', $taxYearOptions), true)) {
    $taxYearOptions[] = $taxYear;
    rsort($taxYearOptions);
}
