<?php
require_once 'includes/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'request') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) {
            $error = 'Please enter your email.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
                $stmt->execute([$token, $expiry, $user['id']]);
                // In production, send email with reset link
                $resetLink = SITE_URL . "/forgot-password.php?token=" . $token;
                $success = "Password reset link has been sent to your email. (Demo: <a href='{$resetLink}' style='color:var(--primary)'>Click here to reset</a>)";
            } else {
                $error = 'No account found with this email.';
            }
        }
    } elseif ($_POST['action'] === 'reset') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!$password || strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
            $stmt->execute([$token]);
            $user = $stmt->fetch();
            if ($user) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                $stmt->execute([$hash, $user['id']]);
                flash('login_success', 'Password reset successfully. Please login.');
                header('Location: ' . SITE_URL . '/login.php');
                exit;
            } else {
                $error = 'Invalid or expired reset token.';
            }
        }
    }
}

$token = $_GET['token'] ?? '';
$pageTitle = 'Forgot Password';
include 'includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <span class="brand-text"><?= getSetting('site_name', 'YBT Digital') ?></span>
        </div>

        <?php if ($token): ?>
            <h2 class="auth-title">Reset Password</h2>
            <p class="auth-subtitle">Enter your new password</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock input-icon"></i>
                        <input type="password" name="password" class="form-control has-icon" placeholder="Min 6 characters" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <input type="password" name="confirm_password" class="form-control has-icon" placeholder="Re-enter password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-2">Reset Password</button>
            </form>
        <?php else: ?>
            <h2 class="auth-title">Forgot Password?</h2>
            <p class="auth-subtitle">Enter your email to get a reset link</p>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            <form method="POST" class="auth-form">
                <input type="hidden" name="action" value="request">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-group">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" name="email" class="form-control has-icon" placeholder="Enter your email" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-2">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <div class="auth-link">
            <a href="<?= SITE_URL ?>/login.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
