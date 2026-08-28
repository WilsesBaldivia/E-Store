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
        $selectedLevel = (string) ($_POST['academic_level'] ?? '');
        $quantity = max(1, min(10, (int) ($_POST['quantity'] ?? 1)));
        $check = db()->prepare(
            'SELECT pv.id, p.academic_level, (i.stock_quantity - i.reserved_quantity) AS available
             FROM product_variants pv JOIN products p ON p.id = pv.product_id
             JOIN inventory i ON i.variant_id = pv.id
             WHERE pv.id = :id AND pv.is_active = 1 AND p.is_active = 1'
        );
        $check->execute(['id' => $variantId]);
        $variant = $check->fetch();
        if (!in_array($selectedLevel, ['elementary', 'jhs', 'shs', 'college'], true)) {
            $errors[] = 'Please select an academic level.';
        } elseif (!$variant || !in_array($variant['academic_level'], ['all', $selectedLevel], true) || (int) $variant['available'] < $quantity) {
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
        $removeVariantId = filter_input(INPUT_POST, 'remove_variant_id', FILTER_VALIDATE_INT);
        if ($removeVariantId) {
            unset($_SESSION['cart'][(int) $removeVariantId]);
            set_flash('success', 'Item removed from your cart.');
        } else {
            foreach ((array) ($_POST['quantity'] ?? []) as $variantId => $quantity) {
                $quantity = max(0, min(10, (int) $quantity));
                if ($quantity === 0) {
                    unset($_SESSION['cart'][(int) $variantId]);
                } else {
                    $_SESSION['cart'][(int) $variantId] = $quantity;
                }
            }
            set_flash('success', 'Cart quantities updated.');
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
$levelLabels = ['elementary' => 'Elementary', 'jhs' => 'Junior High School', 'shs' => 'Senior High School', 'college' => 'College'];
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
$cartTotal = array_sum(array_map(static function (array $row): float {
    return $row['price'] * $row['quantity'];
}, $cartRows));

$productRows = db()->query(
    "SELECT p.id AS product_id, p.sku, pv.id AS variant_id, pv.size, pv.color, p.name, p.description,
            p.academic_level, p.base_price, pv.price_override, p.image_path, c.name AS category, c.slug AS category_slug,
            GREATEST(i.stock_quantity - i.reserved_quantity, 0) AS available
     FROM products p JOIN categories c ON c.id = p.category_id
     JOIN product_variants pv ON pv.product_id = p.id
     JOIN inventory i ON i.variant_id = pv.id
     WHERE p.is_active = 1 AND pv.is_active = 1
     ORDER BY FIELD(c.slug, 'uniforms', 'books'), FIELD(p.academic_level, 'elementary', 'jhs', 'shs', 'college'), p.name,
              FIELD(pv.size, 'Small', 'Medium', 'Large', 'XL', 'Standard'), pv.size"
)->fetchAll();

$catalogGroups = [
    'uniform' => [
        'name' => 'School Uniform',
        'category' => 'Uniforms',
        'description' => 'Choose your academic level and uniform size.',
        'icon' => 'bx-t-shirt',
        'item_label' => 'Uniform size',
        'options' => [],
        'image_path' => null,
    ],
    'pe' => [
        'name' => 'P.E. Uniform',
        'category' => 'Physical Education',
        'description' => 'Choose your academic level and P.E. uniform size.',
        'icon' => 'bx-run',
        'item_label' => 'P.E. size',
        'options' => [],
        'image_path' => null,
    ],
    'typeb' => [
        'name' => 'Type B Uniform',
        'category' => 'College Uniform',
        'description' => 'Official Type B uniform exclusively for College students.',
        'icon' => 'bx-t-shirt',
        'item_label' => 'Type B size',
        'options' => [],
        'image_path' => null,
    ],
    'books' => [
        'name' => 'School Books',
        'category' => 'Learning Materials',
        'description' => 'Choose your academic level, then select a book.',
        'icon' => 'bx-book-open',
        'item_label' => 'Book title',
        'options' => [],
        'image_path' => null,
    ],
];
$catalogImageFiles = [
    'UNI-ELEM-REG' => 'uniform-elementary',
    'UNI-JHS-REG' => 'uniform-jhs-clean',
    'UNI-SHS-REG' => 'uniform-shs-clean',
    'UNI-COL-REG' => 'uniform-college-department-01',
    'UNI-COL-02' => 'uniform-college-department-02-clean',
    'UNI-COL-03' => 'uniform-college-department-03-clean',
    'UNI-COL-04' => 'uniform-college-department-04',
    'UNI-COL-05' => 'uniform-college-department-05',
    'TYPEB-COL-02' => 'typeb-department-02-clean',
    'TYPEB-COL-03' => 'typeb-department-03-clean',
    'TYPEB-COL-06' => 'typeb-department-06-clean',
    'TYPEB-COL-08' => 'typeb-department-08-clean',
    'TYPEB-COL-09' => 'typeb-department-09-clean',
    'PE-ELEM-SET' => 'pe-elementary',
    'PE-JHS-SET' => 'pe-jhs',
    'PE-SHS-SET' => 'pe-shs',
    'PE-COL-SET' => 'pe-college',
];

foreach ($productRows as $row) {
    if ($row['category_slug'] === 'books') {
        $groupKey = 'books';
    } elseif ($row['category_slug'] === 'uniforms') {
        if (str_starts_with($row['sku'], 'TYPEB-')) {
            $groupKey = 'typeb';
        } else {
            $groupKey = str_starts_with($row['sku'], 'PE-') || stripos($row['name'], 'P.E.') !== false ? 'pe' : 'uniform';
        }
    } else {
        continue;
    }
    $imagePath = $row['image_path'];
    $automaticImageBase = $catalogImageFiles[$row['sku']] ?? null;
    if (!$imagePath && $automaticImageBase) {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $automaticImage = $automaticImageBase . '.' . $extension;
            if (is_file(__DIR__ . '/assets/images/products/' . $automaticImage)) {
                $imagePath = 'assets/images/products/' . $automaticImage;
                break;
            }
        }
    }
    if (!$catalogGroups[$groupKey]['image_path'] && $imagePath) {
        $catalogGroups[$groupKey]['image_path'] = $imagePath;
    }
    $catalogGroups[$groupKey]['options'][] = [
        'id' => (int) $row['variant_id'],
        'product_name' => $row['name'],
        'academic_level' => $row['academic_level'],
        'size' => $row['size'] ?: 'Standard',
        'color' => $row['color'],
        'price' => $row['price_override'] !== null ? (float) $row['price_override'] : (float) $row['base_price'],
        'available' => (int) $row['available'],
        'image_path' => $imagePath,
    ];
}
$catalogGroups = array_filter($catalogGroups, static fn (array $group): bool => (bool) $group['options']);

$reservationQuery = db()->prepare(
    "SELECT r.*, GROUP_CONCAT(CONCAT(ri.product_name, ' x ', ri.quantity) ORDER BY ri.id SEPARATOR ', ') AS items
     FROM reservations r LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
     WHERE r.user_id = :user_id GROUP BY r.id ORDER BY r.created_at DESC"
);
$reservationQuery->execute(['user_id' => $user['id']]);
$reservations = $reservationQuery->fetchAll();
$openCount = count(array_filter($reservations, static function (array $reservation): bool {
    return in_array($reservation['status'], ['pending', 'processing', 'ready'], true);
}));

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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Portal | CSCQC E-Store</title><link rel="stylesheet" href="assets/front.css?v=<?= (int) filemtime(__DIR__ . '/assets/front.css') ?>"></head>
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
      <section class="product-grid catalog-groups">
        <?php if (!$catalogGroups): ?><div class="panel empty-state">No products have been added by the administrator yet.</div><?php endif; ?>
        <?php foreach ($catalogGroups as $groupKey => $group):
            $availableOptions = array_filter($group['options'], static fn (array $option): bool => $option['available'] > 0);
            $startingPrice = min(array_column($group['options'], 'price'));
            $selectableLevels = ['elementary' => 'Elementary', 'jhs' => 'Junior High School', 'shs' => 'Senior High School', 'college' => 'College'];
            $optionLevels = array_unique(array_column($group['options'], 'academic_level'));
            if (!in_array('all', $optionLevels, true)) {
                $selectableLevels = array_intersect_key($selectableLevels, array_flip($optionLevels));
            }
            $singleLevel = count($selectableLevels) === 1 ? array_key_first($selectableLevels) : null;
            $departmentChoices = [];
            if ($groupKey === 'typeb') {
                foreach ($group['options'] as $option) {
                    $departmentChoices[$option['product_name']] = preg_replace('/^College Type B\s*-\s*/i', '', $option['product_name']);
                }
            } elseif ($groupKey === 'uniform') {
                foreach ($group['options'] as $option) {
                    if ($option['academic_level'] === 'college') {
                        $departmentChoices[$option['product_name']] = preg_replace('/^College School Uniform\s*-\s*/i', '', $option['product_name']);
                    }
                }
            }
            $departmentLevel = $groupKey === 'uniform' ? 'college' : '';
            $initialImagePath = $groupKey === 'typeb' ? null : $group['image_path'];
            $levelBadge = implode(' · ', array_map(static fn (string $level): string => strtoupper(str_replace(['Junior High School', 'Senior High School'], ['JHS', 'SHS'], $level)), $selectableLevels));
        ?>
          <article class="product-card catalog-group-card">
            <div class="product-image <?= $groupKey === 'books' ? 'book-green' : '' ?>" data-catalog-image>
              <img data-product-photo src="<?= h($initialImagePath) ?>" alt="<?= h($group['name']) ?>" <?= $initialImagePath ? '' : 'hidden' ?>>
              <i data-product-icon class="bx <?= h($group['icon']) ?>" <?= $initialImagePath ? 'hidden' : '' ?>></i>
              <small><?= h($levelBadge) ?></small>
            </div>
            <div class="product-info">
              <span class="category"><?= h(strtoupper($group['category'])) ?></span>
              <h3><?= h($group['name']) ?></h3>
              <p><?= h($group['description']) ?></p>
              <form method="post" class="product-form" data-catalog-form autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_cart">
                <?php if ($singleLevel): ?>
                  <input type="hidden" name="academic_level" value="<?= h($singleLevel) ?>" data-level-select>
                  <label>Academic level</label>
                  <div class="fixed-catalog-choice"><i class="bx bx-graduation"></i> <?= h($selectableLevels[$singleLevel]) ?> only</div>
                <?php else: ?>
                  <label for="level-<?= h($groupKey) ?>">Academic level</label>
                  <select id="level-<?= h($groupKey) ?>" name="academic_level" data-level-select required>
                    <option value="">Choose academic level</option>
                    <?php foreach ($selectableLevels as $levelValue => $levelName): ?><option value="<?= h($levelValue) ?>"><?= h($levelName) ?></option><?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <?php if ($departmentChoices): ?>
                  <div class="catalog-field" data-department-field <?= $departmentLevel ? 'hidden' : '' ?>>
                    <label for="department-<?= h($groupKey) ?>">Department</label>
                    <select id="department-<?= h($groupKey) ?>" data-department-select data-department-level="<?= h($departmentLevel) ?>" <?= $departmentLevel ? 'disabled' : '' ?> required>
                      <option value="">Choose department</option>
                      <?php foreach ($departmentChoices as $departmentValue => $departmentLabel): ?><option value="<?= h($departmentValue) ?>"><?= h($departmentLabel) ?></option><?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <label for="variant-<?= h($groupKey) ?>"><?= h($group['item_label']) ?></label>
                <select id="variant-<?= h($groupKey) ?>" name="variant_id" data-item-select required>
                  <option value="">Choose a size or item</option>
                  <?php foreach ($group['options'] as $option):
                      $optionLabel = $groupKey === 'books' ? $option['product_name'] : $option['size'];
                      $fallbackLevel = $option['academic_level'] === 'all' ? 'All levels' : ($levelLabels[$option['academic_level']] ?? ucfirst($option['academic_level']));
                  ?>
                    <option value="<?= $option['id'] ?>" data-level="<?= h($option['academic_level']) ?>" data-department="<?= h($option['product_name']) ?>" data-price="<?= number_format($option['price'], 2, '.', '') ?>" data-available="<?= $option['available'] ?>" data-image="<?= h($option['image_path']) ?>" <?= $option['available'] < 1 ? 'disabled' : '' ?>>
                      <?= h($fallbackLevel) ?> — <?= h($optionLabel) ?><?= $option['color'] ? ' / ' . h($option['color']) : '' ?> — <?= $option['available'] ?> available
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="product-bottom">
                  <strong data-product-price>From &#8369;<?= number_format($startingPrice, 2) ?></strong>
                  <button class="add-cart" disabled><?= $availableOptions ? 'Select options' : 'Out of stock' ?></button>
                </div>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php elseif ($view === 'cart'): ?>
      <section class="page-heading"><span class="overline">RESERVATION CART</span><h2>Review your selected items</h2></section><section class="panel section-card"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_cart"><div class="cart-list"><?php if (!$cartRows): ?><p class="empty-state">Your cart is empty. <a href="student.php?view=shop">Browse products</a>.</p><?php endif; ?><?php foreach ($cartRows as $row): ?><article><div><strong><?= h($row['name']) ?></strong><small><?= h($row['size'] ?: 'Standard') ?></small></div><input aria-label="Quantity for <?= h($row['name']) ?>" type="number" min="0" max="10" name="quantity[<?= (int) $row['id'] ?>]" value="<?= (int) $row['quantity'] ?>"><b>&#8369;<?= number_format($row['price'] * $row['quantity'], 2) ?></b><button type="submit" name="remove_variant_id" value="<?= (int) $row['id'] ?>" class="remove-cart-button" formnovalidate data-remove-item data-item-name="<?= h($row['name'] . ' — ' . ($row['size'] ?: 'Standard')) ?>"><i class="bx bx-trash"></i> Remove</button></article><?php endforeach; ?></div><?php if ($cartRows): ?><div class="cart-total"><strong>Total: &#8369;<?= number_format($cartTotal, 2) ?></strong><div><button class="secondary-button">Update cart</button><a class="primary-button" href="student.php?view=checkout">Proceed to checkout</a></div></div><?php endif; ?></form></section>
      <div class="cart-confirm-modal" data-remove-modal hidden>
        <section class="cart-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="removeTitle" aria-describedby="removeMessage">
          <div class="cart-confirm-icon"><i class="bx bx-trash"></i></div>
          <span class="overline">REMOVE CART ITEM</span>
          <h2 id="removeTitle">Remove this item?</h2>
          <p id="removeMessage">This item will be removed from your reservation cart.</p>
          <strong data-remove-name></strong>
          <div class="cart-confirm-actions"><button type="button" class="secondary-button" data-remove-cancel>Keep item</button><button type="button" class="confirm-remove-button" data-remove-confirm><i class="bx bx-trash"></i> Remove item</button></div>
        </section>
      </div>
    <?php elseif ($view === 'checkout'): ?>
      <section class="page-heading"><span class="overline">CHECKOUT</span><h2>Complete your reservation</h2><p>Payment will be collected when the order is claimed on campus.</p></section><div class="checkout-layout"><form method="post" class="panel checkout-form"><?= csrf_field() ?><input type="hidden" name="action" value="reserve"><label>Student name</label><input value="<?= h($fullName) ?>" readonly><label>Student ID</label><input value="<?= h($user['student_id']) ?>" readonly><label for="claimDate">Preferred claiming date</label><input id="claimDate" type="date" name="preferred_claim_date" min="<?= date('Y-m-d') ?>" required><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="500"></textarea><button class="checkout-button" <?= !$cartRows ? 'disabled' : '' ?>>Place reservation</button></form><aside class="panel summary-card"><h2>Order summary</h2><?php foreach ($cartRows as $row): ?><p><?= h($row['name']) ?> x <?= (int) $row['quantity'] ?> <b>&#8369;<?= number_format($row['price'] * $row['quantity'], 2) ?></b></p><?php endforeach; ?><hr><strong>Total: &#8369;<?= number_format($cartTotal, 2) ?></strong></aside></div>
    <?php elseif ($view === 'history'): ?>
      <section class="page-heading"><span class="overline">ORDER RECORDS</span><h2>Reservation history</h2></section><section class="panel section-card"><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr></thead><tbody><?php if (!$reservations): ?><tr><td colspan="5" class="empty-state">No reservations yet.</td></tr><?php endif; ?><?php foreach ($reservations as $reservation): ?><tr><td><a href="student.php?view=voucher&amp;code=<?= urlencode($reservation['reservation_code']) ?>"><strong><?= h($reservation['reservation_code']) ?></strong></a></td><td><?= h(date('M d, Y', strtotime($reservation['created_at']))) ?></td><td><?= h($reservation['items'] ?: 'No item details') ?></td><td>&#8369;<?= number_format((float) $reservation['total_amount'], 2) ?></td><td><span class="status <?= h($reservation['status']) ?>"><?= h(ucwords($reservation['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php else: ?>
      <?php if (!$voucher): ?><section class="panel section-card empty-state">Voucher not found.</section><?php else: ?><section class="voucher panel"><div class="voucher-head"><span class="mini-seal">C</span><div><span class="overline">DIGITAL ORDER RESERVATION VOUCHER</span><h2>CSCQC E-Store</h2></div><button type="button" class="secondary-button no-print" onclick="window.print()">Print voucher</button></div><div class="voucher-code"><?= h($voucher['reservation_code']) ?></div><dl><div><dt>Student</dt><dd><?= h($fullName) ?></dd></div><div><dt>Student ID</dt><dd><?= h($user['student_id']) ?></dd></div><div><dt>Claiming date</dt><dd><?= h(date('M d, Y', strtotime($voucher['preferred_claim_date']))) ?></dd></div><div><dt>Status</dt><dd><?= h(ucwords($voucher['status'])) ?></dd></div></dl><h3>Reserved items</h3><ul><?php foreach (explode('||', (string) $voucher['items']) as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul><div class="voucher-total">Total payable on campus: <strong>&#8369;<?= number_format((float) $voucher['total_amount'], 2) ?></strong></div><p>Present this voucher and your CSCQC student ID when claiming your order.</p></section><?php endif; ?>
    <?php endif; ?>

  </main>
</div><script src="assets/front.js?v=<?= (int) filemtime(__DIR__ . '/assets/front.js') ?>"></script></body></html>
