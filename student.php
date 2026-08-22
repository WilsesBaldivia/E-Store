<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = require_student();
$view = (string) ($_GET['view'] ?? 'home');
$view = in_array($view, ['home', 'shop', 'cart', 'checkout', 'history', 'voucher'], true) ? $view : 'home';
$errors = [];
$_SESSION['cart'] = is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf()) {
        $errors[] = 'Your form session expired. Please try again.';
    } elseif ($action === 'logout') {
        logout_user();
        redirect('index.php');
    } elseif ($action === 'add_cart') {
        $variantId = filter_input(INPUT_POST, 'variant_id', FILTER_VALIDATE_INT);
        $quantity = max(1, min(10, (int) ($_POST['quantity'] ?? 1)));
        $check = db()->prepare(
            'SELECT pv.id, (i.stock_quantity - i.reserved_quantity) AS available
             FROM product_variants pv JOIN products p ON p.id = pv.product_id
             JOIN inventory i ON i.variant_id = pv.id
             WHERE pv.id = :id AND pv.is_active = 1 AND p.is_active = 1'
        );
        $check->execute(['id' => $variantId]);
        $variant = $check->fetch();
        if (!$variant || (int) $variant['available'] < $quantity) {
            $errors[] = 'That item is unavailable or does not have enough stock.';
        } else {
            $_SESSION['cart'][(int) $variantId] = min(10, ((int) ($_SESSION['cart'][(int) $variantId] ?? 0)) + $quantity);
            set_flash('success', 'Item added to your reservation cart.');
            redirect('student.php?view=shop');
        }
    } elseif ($action === 'remove_cart') {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        unset($_SESSION['cart'][$variantId]);
        redirect('student.php?view=cart');
    } elseif ($action === 'update_cart') {
        foreach ((array) ($_POST['quantity'] ?? []) as $variantId => $quantity) {
            $quantity = max(0, min(10, (int) $quantity));
            if ($quantity === 0) {
                unset($_SESSION['cart'][(int) $variantId]);
            } else {
                $_SESSION['cart'][(int) $variantId] = $quantity;
            }
        }
        redirect('student.php?view=cart');
    } elseif ($action === 'reserve') {
        $view = 'checkout';
        $claimDate = (string) ($_POST['preferred_claim_date'] ?? '');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $validDate = DateTime::createFromFormat('Y-m-d', $claimDate);
        if (!$_SESSION['cart']) {
            $errors[] = 'Your cart is empty.';
        }
        if (!$validDate || $validDate->format('Y-m-d') !== $claimDate || $claimDate < date('Y-m-d')) {
            $errors[] = 'Select a valid claiming date that is not in the past.';
        }
        if (strlen($notes) > 500) {
            $errors[] = 'Order notes may contain up to 500 characters.';
        }

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $items = [];
                $total = 0.0;
                $itemQuery = $pdo->prepare(
                    'SELECT pv.id, pv.size, pv.color, p.name, p.base_price, pv.price_override,
                            i.stock_quantity, i.reserved_quantity
                     FROM product_variants pv JOIN products p ON p.id = pv.product_id
                     JOIN inventory i ON i.variant_id = pv.id
                     WHERE pv.id = :id AND pv.is_active = 1 AND p.is_active = 1 FOR UPDATE'
                );
                foreach ($_SESSION['cart'] as $variantId => $quantity) {
                    $itemQuery->execute(['id' => $variantId]);
                    $item = $itemQuery->fetch();
                    if (!$item || ((int) $item['stock_quantity'] - (int) $item['reserved_quantity']) < $quantity) {
                        throw new RuntimeException('An item in your cart no longer has enough stock.');
                    }
                    $price = $item['price_override'] !== null ? (float) $item['price_override'] : (float) $item['base_price'];
                    $item['quantity'] = (int) $quantity;
                    $item['price'] = $price;
                    $items[] = $item;
                    $total += $price * $quantity;
                }

                $code = 'CSC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $insertReservation = $pdo->prepare(
                    "INSERT INTO reservations (reservation_code, user_id, status, total_amount, preferred_claim_date, notes)
                     VALUES (:code, :user_id, 'pending', :total, :claim_date, :notes)"
                );
                $insertReservation->execute(['code' => $code, 'user_id' => $user['id'], 'total' => $total, 'claim_date' => $claimDate, 'notes' => $notes ?: null]);
                $reservationId = (int) $pdo->lastInsertId();
                $insertItem = $pdo->prepare(
                    'INSERT INTO reservation_items (reservation_id, variant_id, product_name, variant_name, unit_price, quantity, subtotal)
                     VALUES (:reservation_id, :variant_id, :product_name, :variant_name, :unit_price, :quantity, :subtotal)'
                );
                $reserveStock = $pdo->prepare('UPDATE inventory SET reserved_quantity = reserved_quantity + :quantity WHERE variant_id = :variant_id');
                $movement = $pdo->prepare(
                    "INSERT INTO inventory_movements (variant_id, reservation_id, movement_type, quantity_change, quantity_after, notes)
                     VALUES (:variant_id, :reservation_id, 'reserve', 0, :quantity_after, :notes)"
                );
                foreach ($items as $item) {
                    $variantName = trim(implode(' / ', array_filter([$item['size'], $item['color']])));
                    $subtotal = $item['price'] * $item['quantity'];
                    $insertItem->execute(['reservation_id' => $reservationId, 'variant_id' => $item['id'], 'product_name' => $item['name'], 'variant_name' => $variantName ?: null, 'unit_price' => $item['price'], 'quantity' => $item['quantity'], 'subtotal' => $subtotal]);
                    $reserveStock->execute(['quantity' => $item['quantity'], 'variant_id' => $item['id']]);
                    $movement->execute(['variant_id' => $item['id'], 'reservation_id' => $reservationId, 'quantity_after' => $item['stock_quantity'], 'notes' => 'Reserved ' . $item['quantity'] . ' unit(s)']);
                }
                $pdo->commit();
                $_SESSION['cart'] = [];
                redirect('student.php?view=voucher&code=' . urlencode($code));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The reservation could not be completed.';
            }
        }
    }
}

$flash = get_flash();
$levelLabels = ['college' => 'College', 'shs' => 'Senior High School', 'jhs' => 'Junior High School'];
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);

$cartRows = [];
if ($_SESSION['cart']) {
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $cartQuery = db()->prepare(
        "SELECT pv.id, pv.size, pv.color, p.name, p.base_price, pv.price_override,
                (i.stock_quantity - i.reserved_quantity) AS available
         FROM product_variants pv JOIN products p ON p.id = pv.product_id
         JOIN inventory i ON i.variant_id = pv.id WHERE pv.id IN ($placeholders)"
    );
    $cartQuery->execute($ids);
    foreach ($cartQuery->fetchAll() as $row) {
        $row['quantity'] = (int) $_SESSION['cart'][(int) $row['id']];
        $row['price'] = $row['price_override'] !== null ? (float) $row['price_override'] : (float) $row['base_price'];
        $cartRows[] = $row;
    }
}
$cartCount = array_sum(array_map('intval', $_SESSION['cart']));
$cartTotal = array_sum(array_map(fn(array $row): float => $row['price'] * $row['quantity'], $cartRows));

$products = db()->query(
    'SELECT pv.id AS variant_id, pv.size, pv.color, p.name, p.description, p.academic_level,
            p.base_price, pv.price_override, c.name AS category,
            GREATEST(i.stock_quantity - i.reserved_quantity, 0) AS available
     FROM products p JOIN categories c ON c.id = p.category_id
     JOIN product_variants pv ON pv.product_id = p.id
     JOIN inventory i ON i.variant_id = pv.id
     WHERE p.is_active = 1 AND pv.is_active = 1 ORDER BY c.name DESC, p.name, pv.size'
)->fetchAll();

$reservationQuery = db()->prepare(
    "SELECT r.*, GROUP_CONCAT(CONCAT(ri.product_name, ' x ', ri.quantity) ORDER BY ri.id SEPARATOR ', ') AS items
     FROM reservations r LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
     WHERE r.user_id = :user_id GROUP BY r.id ORDER BY r.created_at DESC"
);
$reservationQuery->execute(['user_id' => $user['id']]);
$reservations = $reservationQuery->fetchAll();
$openCount = count(array_filter($reservations, fn(array $r): bool => in_array($r['status'], ['pending', 'processing', 'ready'], true)));

$announcementsQuery = db()->prepare(
    "SELECT title, content, announcement_type, published_at FROM announcements
     WHERE is_active = 1 AND (academic_level = 'all' OR academic_level = :level)
       AND published_at <= NOW() AND (expires_at IS NULL OR expires_at >= NOW())
     ORDER BY published_at DESC LIMIT 4"
);
$announcementsQuery->execute(['level' => $user['academic_level']]);
$announcements = $announcementsQuery->fetchAll();

$voucher = null;
if ($view === 'voucher' && isset($_GET['code'])) {
    $voucherQuery = db()->prepare(
        "SELECT r.*, GROUP_CONCAT(CONCAT(ri.product_name, IF(ri.variant_name IS NULL, '', CONCAT(' - ', ri.variant_name)), ' x ', ri.quantity) ORDER BY ri.id SEPARATOR '||') AS items
         FROM reservations r LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
         WHERE r.user_id = :user_id AND r.reservation_code = :code GROUP BY r.id"
    );
    $voucherQuery->execute(['user_id' => $user['id'], 'code' => (string) $_GET['code']]);
    $voucher = $voucherQuery->fetch();
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Portal | CSCQC E-Store</title><link rel="stylesheet" href="assets/front.css"></head>
<body class="portal-page">
<button class="menu-toggle" type="button" data-menu><i class="bx bx-menu"></i></button>
<div class="app-shell">
  <aside class="sidebar" data-sidebar><a class="side-brand" href="student.php"><span class="mini-seal">C</span><span>CSCQC<small>E-STORE</small></span></a>
    <nav class="side-nav"><a class="<?= $view === 'home' ? 'active' : '' ?>" href="student.php"><span><i class="bx bx-home-alt"></i></span> Home</a><p>STORE</p><a class="<?= $view === 'shop' ? 'active' : '' ?>" href="student.php?view=shop"><span><i class="bx bx-store"></i></span> Products</a><a class="<?= in_array($view, ['cart','checkout'], true) ? 'active' : '' ?>" href="student.php?view=cart"><span><i class="bx bx-cart"></i></span> Cart <b class="cart-badge"><?= $cartCount ?></b></a><p>ACCOUNT</p><a class="<?= $view === 'history' ? 'active' : '' ?>" href="student.php?view=history"><span><i class="bx bx-receipt"></i></span> Reservations</a></nav>
    <form method="post" class="sidebar-logout"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="logout-link" type="submit"><span><i class="bx bx-log-out"></i></span> Log out</button></form>
  </aside>
  <main class="app-main">
    <header class="topbar"><div><span class="breadcrumb">STUDENT PORTAL / <?= h(strtoupper($view)) ?></span><h1><?= h($fullName) ?></h1></div><div class="student-chip"><span><?= h(strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1))) ?></span><div><strong><?= h($fullName) ?></strong><small><?= h($levelLabels[$user['academic_level']] ?? 'Student') ?></small></div></div></header>
    <?php if ($flash): ?><div class="portal-alert success"><?= h($flash['message']) ?></div><?php endif; ?><?php if ($errors): ?><div class="portal-alert error"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>

    <?php if ($view === 'home'): ?>
      <section class="page-heading"><span class="overline">WELCOME BACK</span><h2>Your student dashboard</h2><p>Review store updates and monitor your reservations.</p></section>
      <section class="stats-grid"><article><small>Total reservations</small><strong><?= count($reservations) ?></strong></article><article><small>Open reservations</small><strong><?= $openCount ?></strong></article><article><small>Cart items</small><strong><?= $cartCount ?></strong></article></section>
      <section class="panel section-card"><div class="panel-heading"><div><span class="overline">ANNOUNCEMENTS</span><h2>Important store updates</h2></div></div><?php if (!$announcements): ?><p class="empty-state">No active announcements for your academic level.</p><?php else: ?><div class="announcement-list"><?php foreach ($announcements as $notice): ?><article><i class="bx bx-bell"></i><div><strong><?= h($notice['title']) ?></strong><p><?= h($notice['content']) ?></p></div></article><?php endforeach; ?></div><?php endif; ?></section>
    <?php elseif ($view === 'shop'): ?>
      <section class="page-heading"><span class="overline">CSCQC CATALOG</span><h2>Uniforms and learning materials</h2><p>Availability and prices are retrieved from the inventory database.</p></section>
      <section class="product-grid"><?php if (!$products): ?><div class="panel empty-state">No products have been added by the administrator yet.</div><?php endif; ?><?php foreach ($products as $product): $price = $product['price_override'] ?? $product['base_price']; ?><article class="product-card"><div class="product-image <?= strtolower($product['category']) === 'books' ? 'book-green' : '' ?>"><i class="bx <?= strtolower($product['category']) === 'books' ? 'bx-book-open' : 'bx-t-shirt' ?>"></i><small><?= h(strtoupper($product['academic_level'])) ?></small></div><div class="product-info"><span class="category"><?= h(strtoupper($product['category'])) ?></span><h3><?= h($product['name']) ?></h3><p><?= h($product['description']) ?></p><p><b><?= h($product['size'] ?: 'Standard') ?></b> · <?= (int) $product['available'] ?> available</p><form method="post" class="product-bottom"><?= csrf_field() ?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="variant_id" value="<?= (int) $product['variant_id'] ?>"><strong>&#8369;<?= number_format((float) $price, 2) ?></strong><button class="add-cart" <?= (int) $product['available'] < 1 ? 'disabled' : '' ?>><?= (int) $product['available'] < 1 ? 'Out of stock' : 'Add to cart' ?></button></form></div></article><?php endforeach; ?></section>
    <?php elseif ($view === 'cart'): ?>
      <section class="page-heading"><span class="overline">RESERVATION CART</span><h2>Review your selected items</h2></section><section class="panel section-card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_cart"><div class="cart-list"><?php if (!$cartRows): ?><p class="empty-state">Your cart is empty. <a href="student.php?view=shop">Browse products</a>.</p><?php endif; ?><?php foreach ($cartRows as $row): ?><article><div><strong><?= h($row['name']) ?></strong><small><?= h($row['size'] ?: 'Standard') ?></small></div><input type="number" min="0" max="10" name="quantity[<?= (int) $row['id'] ?>]" value="<?= (int) $row['quantity'] ?>"><b>&#8369;<?= number_format($row['price'] * $row['quantity'], 2) ?></b></article><?php endforeach; ?></div><?php if ($cartRows): ?><div class="cart-total"><strong>Total: &#8369;<?= number_format($cartTotal, 2) ?></strong><div><button class="secondary-button">Update cart</button><a class="primary-button" href="student.php?view=checkout">Proceed to checkout</a></div></div><?php endif; ?></form></section>
    <?php elseif ($view === 'checkout'): ?>
      <section class="page-heading"><span class="overline">CHECKOUT</span><h2>Complete your reservation</h2><p>Payment will be collected when the order is claimed on campus.</p></section><div class="checkout-layout"><form method="post" class="panel checkout-form"><?= csrf_field() ?><input type="hidden" name="action" value="reserve"><label>Student name</label><input value="<?= h($fullName) ?>" readonly><label>Student ID</label><input value="<?= h($user['student_id']) ?>" readonly><label for="claimDate">Preferred claiming date</label><input id="claimDate" type="date" name="preferred_claim_date" min="<?= date('Y-m-d') ?>" required><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="500"></textarea><button class="checkout-button" <?= !$cartRows ? 'disabled' : '' ?>>Place reservation</button></form><aside class="panel summary-card"><h2>Order summary</h2><?php foreach ($cartRows as $row): ?><p><?= h($row['name']) ?> x <?= (int) $row['quantity'] ?> <b>&#8369;<?= number_format($row['price'] * $row['quantity'], 2) ?></b></p><?php endforeach; ?><hr><strong>Total: &#8369;<?= number_format($cartTotal, 2) ?></strong></aside></div>
    <?php elseif ($view === 'history'): ?>
      <section class="page-heading"><span class="overline">ORDER RECORDS</span><h2>Reservation history</h2></section><section class="panel section-card"><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead><tbody><?php if (!$reservations): ?><tr><td colspan="5" class="empty-state">No reservations yet.</td></tr><?php endif; ?><?php foreach ($reservations as $reservation): ?><tr><td><a href="student.php?view=voucher&amp;code=<?= urlencode($reservation['reservation_code']) ?>"><strong><?= h($reservation['reservation_code']) ?></strong></a></td><td><?= h(date('M d, Y', strtotime($reservation['created_at']))) ?></td><td><?= h($reservation['items'] ?: 'No item details') ?></td><td>&#8369;<?= number_format((float) $reservation['total_amount'], 2) ?></td><td><span class="status <?= h($reservation['status']) ?>"><?= h(ucwords($reservation['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php else: ?>
      <?php if (!$voucher): ?><section class="panel section-card empty-state">Voucher not found.</section><?php else: ?><section class="voucher panel"><div class="voucher-head"><span class="mini-seal">C</span><div><span class="overline">DIGITAL ORDER RESERVATION VOUCHER</span><h2>CSCQC E-Store</h2></div><button type="button" class="secondary-button no-print" onclick="window.print()">Print voucher</button></div><div class="voucher-code"><?= h($voucher['reservation_code']) ?></div><dl><div><dt>Student</dt><dd><?= h($fullName) ?></dd></div><div><dt>Student ID</dt><dd><?= h($user['student_id']) ?></dd></div><div><dt>Claiming date</dt><dd><?= h(date('M d, Y', strtotime($voucher['preferred_claim_date']))) ?></dd></div><div><dt>Status</dt><dd><?= h(ucwords($voucher['status'])) ?></dd></div></dl><h3>Reserved items</h3><ul><?php foreach (explode('||', (string) $voucher['items']) as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul><div class="voucher-total">Total payable on campus: <strong>&#8369;<?= number_format((float) $voucher['total_amount'], 2) ?></strong></div><p>Present this voucher and your CSCQC student ID when claiming your order.</p></section><?php endif; ?>
    <?php endif; ?>
    <footer class="app-footer">CSCQC E-Store · Capstone System <span>&copy; <?= date('Y') ?></span></footer>
  </main>
</div><script src="assets/front.js"></script></body></html>
