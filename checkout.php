<?php
require_once 'includes/config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT c.id as cart_id, p.* FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$cartItems = $stmt->fetchAll();

if (count($cartItems) === 0) {
    header('Location: ' . SITE_URL . '/cart.php');
    exit;
}

$subtotal = 0;
foreach ($cartItems as $item) {
    $price = ($item['discount_price'] && $item['discount_price'] < $item['price']) ? $item['discount_price'] : $item['price'];
    $subtotal += $price;
}

$taxEnabled = getSetting('tax_enabled', '1') === '1';
$taxPercent = floatval(getSetting('tax_percentage', '0'));
$tax = $taxEnabled ? ($subtotal * $taxPercent / 100) : 0;
$total = $subtotal + $tax;

$razorpayKeyId = getSetting('razorpay_key_id', '');
$razorpayKeySecret = getSetting('razorpay_key_secret', '');

$error = '';
$success = '';

// Handle Razorpay payment success callback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['razorpay_payment_id'])) {
    $razorpayPaymentId = $_POST['razorpay_payment_id'];
    $orderId = intval($_POST['order_id'] ?? 0);
    
    // Verify payment with Razorpay API
    $paymentId = $_POST['razorpay_payment_id'];
    $razorpayOrderId = $_POST['razorpay_order_id'];
    $razorpaySignature = $_POST['razorpay_signature'];
    
    $generatedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $paymentId, $razorpayKeySecret);
    
    if ($generatedSignature === $razorpaySignature) {
        // Payment verified - complete the order
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE orders SET payment_status = 'completed', status = 'completed', transaction_id = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$razorpayPaymentId, $orderId, $_SESSION['user_id']]);
            
            // Increment product downloads
            $stmt = $pdo->prepare("SELECT product_id FROM order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($productIds as $pid) {
                $pdo->prepare("UPDATE products SET downloads = downloads + 1 WHERE id = ?")->execute([$pid]);
            }
            
            // Clear cart
            $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$_SESSION['user_id']]);
            
            $pdo->commit();
            
            $stmt = $pdo->prepare("SELECT order_number FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $orderNumber = $stmt->fetchColumn();
            
            header('Location: ' . SITE_URL . '/orders.php?order=' . $orderNumber);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Order completion failed. Please contact support.';
        }
    } else {
        $error = 'Payment verification failed. Please try again.';
    }
}

// Create Razorpay order when page loads (for frontend)
$razorpayOrderId = '';
if ($razorpayKeyId && $razorpayKeySecret) {
    $amountInPaise = intval($total * 100); // Razorpay uses paise
    
    // Create order in Razorpay
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $razorpayKeyId . ':' . $razorpayKeySecret);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'amount' => $amountInPaise,
        'currency' => 'INR',
        'receipt' => 'order_' . time()
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $orderData = json_decode($response, true);
    if (isset($orderData['id'])) {
        $razorpayOrderId = $orderData['id'];
    }
}

// Create pending order in database for tracking
$orderNumber = 'LL-' . strtoupper(bin2hex(random_bytes(6)));
$maxDownloads = intval(getSetting('max_downloads_per_purchase', '3'));
$expiryDays = intval(getSetting('download_expiry_days', '7'));

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_number, subtotal, tax, total, payment_method, payment_status, status) VALUES (?, ?, ?, ?, ?, 'razorpay', 'pending', 'pending')");
    $stmt->execute([$_SESSION['user_id'], $orderNumber, $subtotal, $tax, $total]);
    $orderId = $pdo->lastInsertId();
    
    foreach ($cartItems as $item) {
        $price = ($item['discount_price'] && $item['discount_price'] < $item['price']) ? $item['discount_price'] : $item['price'];
        $downloadToken = bin2hex(random_bytes(16));
        $downloadExpiry = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
        
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_title, price, download_token, download_expiry, max_downloads) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$orderId, $item['id'], $item['title'], $price, $downloadToken, $downloadExpiry, $maxDownloads]);
    }
    
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    $error = 'Failed to create order. Please try again.';
}

$pageTitle = 'Checkout';
include 'includes/header.php';
?>

<div class="main-content">
    <div class="container" style="padding-top:20px;padding-bottom:40px;max-width:900px;">
        <h3 style="font-weight:800;margin-bottom:24px;"><i class="bi bi-credit-card me-2"></i>Checkout</h3>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <!-- Payment Method -->
                <div class="checkout-card mb-3">
                    <h5 style="font-weight:700;margin-bottom:12px;">Payment Method</h5>
                    <div class="payment-methods">
                        <label class="payment-method selected">
                            <i class="bi bi-credit-card-2-front"></i>
                            <span>Razorpay (Card / UPI / Net Banking)</span>
                        </label>
                    </div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">
                        <i class="bi bi-shield-check me-1"></i> Your payment is secure and encrypted by Razorpay.
                    </p>
                </div>

                <!-- Order Items -->
                <div class="checkout-card">
                    <h5 style="font-weight:700;margin-bottom:12px;">Order Items</h5>
                    <?php foreach ($cartItems as $item):
                        $price = ($item['discount_price'] && $item['discount_price'] < $item['price']) ? $item['discount_price'] : $item['price'];
                    ?>
                    <div class="order-product">
                        <div class="order-product-img">
                            <?php if ($item['thumbnail']): ?>
                                <img src="<?= SITE_URL ?>/assets/img/products/<?= $item['thumbnail'] ?>" alt="">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/50x50/6c5ce7/ffffff?text=P" alt="">
                            <?php endif; ?>
                        </div>
                        <div class="order-product-info">
                            <div class="order-product-title"><?= htmlspecialchars($item['title']) ?></div>
                        </div>
                        <div class="order-product-price"><?= formatPrice($price) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="cart-summary">
                    <h5>Order Summary</h5>
                    <div class="cart-summary-row">
                        <span>Subtotal (<?= count($cartItems) ?> items)</span>
                        <span><?= formatPrice($subtotal) ?></span>
                    </div>
                    <?php if ($taxEnabled): ?>
                    <div class="cart-summary-row">
                        <span><?= getSetting('tax_type', 'Tax') ?> (<?= $taxPercent ?>%)</span>
                        <span><?= formatPrice($tax) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="cart-summary-row total">
                        <span>Total</span>
                        <span><?= formatPrice($total) ?></span>
                    </div>
                    
                    <?php if ($razorpayOrderId): ?>
                    <form id="razorpay-form" method="POST">
                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                        <input type="hidden" name="razorpay_order_id" value="<?= $razorpayOrderId ?>">
                        <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
                        <input type="hidden" name="razorpay_signature" id="razorpay_signature">
                        <button type="button" id="pay-btn" class="btn btn-primary w-100 mt-3"><i class="bi bi-lock-fill me-2"></i>Pay <?= formatPrice($total) ?></button>
                    </form>
                    <?php else: ?>
                    <button class="btn btn-primary w-100 mt-3" disabled><i class="bi bi-exclamation-triangle me-2"></i>Payment Gateway Not Configured</button>
                    <?php endif; ?>
                    
                    <a href="<?= SITE_URL ?>/cart.php" class="btn btn-outline-primary w-100 mt-2"><i class="bi bi-arrow-left me-1"></i>Back to Cart</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($razorpayOrderId): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
var options = {
    "key": "<?= $razorpayKeyId ?>",
    "amount": "<?= intval($total * 100) ?>",
    "currency": "INR",
    "name": "<?= getSetting('site_name', 'Local Link') ?>",
    "description": "Digital Products Purchase",
    "order_id": "<?= $razorpayOrderId ?>",
    "handler": function (response) {
        document.getElementById('razorpay_payment_id').value = response.razorpay_payment_id;
        document.getElementById('razorpay_signature').value = response.razorpay_signature;
        document.getElementById('razorpay-form').submit();
    },
    "prefill": {
        "name": "<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>",
        "email": "<?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>"
    },
    "theme": {
        "color": "#6c5ce7"
    }
};
var rzp = new Razorpay(options);
document.getElementById('pay-btn').onclick = function(e) {
    e.preventDefault();
    rzp.open();
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
