<?php
define('EURO_TO_CC', 110);

// SQL kapcsolat – EZT TÖLTSD KI HELYESEN:
$serverName = "localhost";
$connectionOptions = [
    "Database" => "KALEF2",
    "Uid" => "SA",         // ← AZ SQL FELHASZNÁLÓNEVED
    "PWD" => "123456"   // ← AZ SQL JELSZAVAD
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    file_put_contents("paypal_log.txt", "❌ SQL connection failed\n", FILE_APPEND);
    exit;
}

// Kapott adatokat beolvassuk
$raw_post_data = file_get_contents('php://input');
$raw_post_array = explode('&', $raw_post_data);
$myPost = [];
foreach ($raw_post_array as $keyval) {
    $keyval = explode ('=', $keyval);
    if (count($keyval) == 2)
        $myPost[$keyval[0]] = urldecode($keyval[1]);
}

// PayPal felé küldjük az ellenőrző kérést
$req = 'cmd=_notify-validate';
foreach ($myPost as $key => $value) {
    $value = urlencode($value);
    $req .= "&$key=$value";
}

$paypal_url = "https://ipnpb.paypal.com/cgi-bin/webscr";

$ch = curl_init($paypal_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

$res = curl_exec($ch);
curl_close($ch);

file_put_contents("paypal_log.txt", date('[Y-m-d H:i:s] ') . "IPN Received:\n" . print_r($myPost, true) . "\nResponse: $res\n\n", FILE_APPEND);

if (strcmp($res, "VERIFIED") == 0) {
    // Feldolgozás
    $paymentStatus = $myPost['payment_status'] ?? '';
    $characterName = $myPost['custom'] ?? null;
    $amount = $myPost['mc_gross'] ?? 0;

    if ($paymentStatus === 'Completed' && $characterName) {
        $ccAmount = round(floatval($amount) * EURO_TO_CC);

        $updateQuery = "UPDATE dbo.CHARDETAIL SET INVENCASH = INVENCASH + ? WHERE CHARID = ?";
        $params = [$ccAmount, $characterName];
        $result = sqlsrv_query($conn, $updateQuery, $params);

        if ($result) {
            file_put_contents("paypal_log.txt", "✅ CC updated: {$characterName} +{$ccAmount} CC\n", FILE_APPEND);
        } else {
            file_put_contents("paypal_log.txt", "❌ SQL update failed\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("paypal_log.txt", date('[Y-m-d H:i:s] ') . "IPN NOT VERIFIED\n\n", FILE_APPEND);
}
?>