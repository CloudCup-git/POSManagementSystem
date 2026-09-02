<?php
/**
 * CloudCup — Finance Logout
 * Finance now shares the main POS session, so logging out here logs
 * the user out of the whole system (consistent with every other
 * module's Logout button) rather than just clearing finance-only keys.
 */
header('Location: ../auth/Logout_Page.php');
exit;
