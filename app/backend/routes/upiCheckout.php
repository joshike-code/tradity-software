<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/SanitizationService.php';

use Core\SanitizationService;

$ref = isset($_GET['ref']) ? SanitizationService::sanitizeParam($_GET['ref']) : '';
if (empty($ref)) {
    http_response_code(400);
    echo "<h3>Invalid transaction reference</h3>";
    exit;
}

$conn = Database::getConnection();

// Check if polling request
if (isset($_GET['poll'])) {
    header("Content-Type: application/json");
    $stmt = $conn->prepare("SELECT status FROM payments WHERE tx_ref = ? LIMIT 1");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['status' => $res['status'] ?? 'not_found']);
    exit;
}

$stmt = $conn->prepare("SELECT id, user_id, account, amount, status, date FROM payments WHERE tx_ref = ? LIMIT 1");
$stmt->bind_param("s", $ref);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    http_response_code(404);
    echo "<h3>Payment record not found for reference: " . htmlspecialchars($ref) . "</h3>";
    exit;
}

$keys = require(__DIR__ . '/../config/keys.php');
$platformName = $keys['platform']['name'] ?? 'Traditi';

$merchantUpi = !empty($keys['razorpay']['merchant_upi']) ? trim($keys['razorpay']['merchant_upi']) : '';

if (empty($merchantUpi)) {
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>UPI Deposit Unavailable - <?= htmlspecialchars($platformName) ?></title>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
      <style>
        body { margin: 0; background: #0e1217; color: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; box-sizing: border-box; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 16px; width: 100%; max-width: 360px; padding: 28px 24px; text-align: center; box-shadow: 0 12px 32px rgba(0,0,0,0.4); }
        .icon { font-size: 42px; color: #f59e0b; margin-bottom: 16px; }
        .title { font-size: 18px; font-weight: 700; margin: 0 0 10px; color: #f1f5f9; }
        .desc { font-size: 13px; color: #94a3b8; line-height: 1.5; margin: 0; }
      </style>
    </head>
    <body>
      <div class="card">
        <div class="icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h2 class="title">UPI Payments Unavailable</h2>
        <p class="desc">Merchant UPI configuration is missing. Please contact platform support or choose an alternative deposit method.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$amount = floatval($payment['amount']);
$inrAmount = round($amount * 85.0, 2);
$merchantEncoded = urlencode($platformName);
$upiIntent = "upi://pay?pa=" . urlencode($merchantUpi) . "&pn=" . $merchantEncoded . "&tr=" . urlencode($ref) . "&am=" . $inrAmount . "&cu=INR&tn=" . urlencode("Deposit Account " . $payment['account']);
$qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=" . urlencode($upiIntent);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UPI Deposit - <?= htmlspecialchars($platformName) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    body { background: #0e1217; color: #f0f4f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
    .card { background: #181f2a; border: 1px solid #283344; border-radius: 14px; max-width: 440px; width: 100%; padding: 28px 24px; box-shadow: 0 12px 32px rgba(0,0,0,0.4); text-align: center; }
    .header { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 20px; }
    .header img { height: 32px; object-fit: contain; }
    .title { font-size: 20px; font-weight: 700; color: #fff; margin-bottom: 4px; }
    .subtitle { font-size: 13px; color: #8e9cae; margin-bottom: 20px; }
    .amount-box { background: rgba(0, 180, 136, 0.08); border: 1px solid rgba(0, 180, 136, 0.3); border-radius: 10px; padding: 14px; margin-bottom: 20px; }
    .amount-usd { font-size: 24px; font-weight: 800; color: #00d09c; }
    .amount-inr { font-size: 14px; color: #8e9cae; margin-top: 3px; font-weight: 500; }
    .qr-container { background: #fff; padding: 14px; border-radius: 12px; display: inline-block; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); }
    .qr-container img { width: 210px; height: 210px; display: block; }
    .info-list { text-align: left; background: #202938; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; }
    .info-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #8e9cae; }
    .info-val { font-weight: 600; color: #fff; display: flex; align-items: center; gap: 6px; }
    .copy-btn { background: none; border: none; color: #00a3ff; cursor: pointer; font-size: 12px; padding: 2px 6px; border-radius: 4px; }
    .copy-btn:hover { background: rgba(0, 163, 255, 0.15); }
    .btn { display: block; width: 100%; padding: 13px; border-radius: 8px; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; text-align: center; border: none; transition: 0.2s; }
    .btn-upi { background: #006CBF; color: #fff; margin-bottom: 10px; }
    .btn-upi:hover { background: #005a9e; }
    .btn-paid { background: #00d09c; color: #0e1217; font-weight: 700; }
    .btn-paid:hover { background: #00b889; }
    .apps { display: flex; justify-content: center; align-items: center; gap: 14px; margin-top: 18px; font-size: 11px; color: #8e9cae; }
    .apps span { background: #202938; padding: 5px 10px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <div style="font-size: 26px; font-weight: 800; color: #006CBF; letter-spacing: 1px;"><i class="fa-solid fa-bolt" style="color: #00d09c;"></i> UPI</div>
    </div>
    <h2 class="title">Scan to Pay via UPI</h2>
    <p class="subtitle">Use Google Pay, PhonePe, Paytm, BHIM, or any UPI App</p>

    <div class="amount-box">
      <div class="amount-usd">$<?= number_format($amount, 2) ?> USD</div>
      <div class="amount-inr">≈ ₹<?= number_format($inrAmount, 2) ?> INR (Rate: 1 USD = ₹85.00)</div>
    </div>

    <div class="qr-container">
      <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="UPI QR Code">
    </div>

    <div class="info-list">
      <div class="info-row">
        <span class="info-label">Merchant UPI ID:</span>
        <span class="info-val">
          <code id="upi-val"><?= htmlspecialchars($merchantUpi) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('upi-val')"><i class="fa-regular fa-copy"></i></button>
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">Reference ID:</span>
        <span class="info-val">
          <code id="ref-val"><?= htmlspecialchars($ref) ?></code>
          <button type="button" class="copy-btn" onclick="copyText('ref-val')"><i class="fa-regular fa-copy"></i></button>
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">Trading Account:</span>
        <span class="info-val"><?= htmlspecialchars($payment['account']) ?></span>
      </div>
    </div>

    <a href="<?= htmlspecialchars($upiIntent) ?>" class="btn btn-upi"><i class="fa-solid fa-mobile-screen-button"></i> Open UPI App</a>
    <button type="button" class="btn btn-paid" id="btn-paid-done" onclick="markPaid()"><i class="fa-solid fa-circle-check"></i> I Have Paid</button>

    <div class="apps">
      <span>GPay</span>
      <span>PhonePe</span>
      <span>Paytm</span>
      <span>BHIM</span>
      <span>Cred</span>
    </div>
  </div>

  <script>
    function copyText(id) {
      const text = document.getElementById(id).innerText;
      navigator.clipboard.writeText(text);
      alert('Copied: ' + text);
    }

    function markPaid() {
      const btn = document.getElementById('btn-paid-done');
      btn.innerText = 'Payment Submitted!';
      btn.disabled = true;
      btn.style.opacity = '0.7';

      // Notify parent window (Tradity frontend cashier)
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ status: 'success' }, '*');
      }
    }

    // Auto-poll status
    const pollInterval = setInterval(() => {
      fetch('?ref=<?= urlencode($ref) ?>&poll=1')
        .then(res => res.json())
        .then(data => {
          if (data && data.status === 'success') {
            clearInterval(pollInterval);
            if (window.parent && window.parent !== window) {
              window.parent.postMessage({ status: 'success' }, '*');
            }
          }
        })
        .catch(() => {});
    }, 4000);
  </script>
</body>
</html>
