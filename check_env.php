<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include_once 'hotel_helpers.php';

$brevo = getEnvValue('BREVO_API_KEY');
$brevoLegacy = getEnvValue('BREVO');
$sender = getEnvValue('BREVO_SENDER_EMAIL');
$senderSource = $sender ? 'env' : 'default';

$brevoKeyName = $brevo ? 'BREVO_API_KEY' : ($brevoLegacy ? 'BREVO' : null);

echo json_encode([
  'status' => 'success',
  'brevo_present' => $brevo ? true : ($brevoLegacy ? true : false),
  'brevo_key_name' => $brevoKeyName,
  'brevo_key_sample' => ($brevo ?: $brevoLegacy) ? substr($brevo ?: $brevoLegacy, 0, 8) . '...' : null,
  'sender_email' => $sender ?: 'no-reply@hotelmanagementsystem.local',
  'sender_email_source' => $senderSource,
]);

?>
