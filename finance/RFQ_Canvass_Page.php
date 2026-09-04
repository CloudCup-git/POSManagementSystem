<?php
// Consolidated into Procurement_Hub.php (Purchase Orders tab, RFQ/Canvass sub-tab) — see docs/procurement/STATUS.md.
$qs = $_GET ? '&' . http_build_query($_GET) : '';
header('Location: Procurement_Hub.php?tab=purchase_orders' . $qs);
exit;
