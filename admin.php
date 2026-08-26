<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
$staff = require_staff();
$view = (string) ($_GET['view'] ?? 'dashboard');
$view = in_array($view, ['dashboard','reservations','inventory','products','users','reports'], true) ? $view : 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { set_flash('error', 'Form session expired.'); redirect('admin.php?view=' . urlencode($view)); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'logout') { logout_user(); redirect('admin-login.php'); }
    try {
        if ($action === 'user_status' && is_admin()) {
            $update = db()->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'student'");
            $update->execute(['status' => in_array($_POST['status'] ?? '', ['active','suspended'], true) ? $_POST['status'] : 'active', 'id' => (int) $_POST['id']]);
        } elseif ($action === 'stock') {
            $update = db()->prepare('UPDATE inventory SET stock_quantity = GREATEST(reserved_quantity, :stock) WHERE variant_id = :id');
            $update->execute(['stock' => max(0, (int) $_POST['stock']), 'id' => (int) $_POST['id']]);
        } elseif ($action === 'reservation_status') {
            $status = (string) ($_POST['status'] ?? 'pending');
            if (in_array($status, ['pending','processing','ready','claimed','cancelled'], true)) {
                $update = db()->prepare('UPDATE reservations SET status = :status, claimed_at = IF(:status2 = \'claimed\', NOW(), claimed_at) WHERE id = :id');
                $update->execute(['status' => $status, 'status2' => $status, 'id' => (int) $_POST['id']]);
            }
        } elseif ($action === 'category' && is_admin()) {
            $name = trim((string) $_POST['name']); $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $insert = db()->prepare('INSERT INTO categories (name,slug) VALUES (:name,:slug) ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=1');
            $insert->execute(['name' => $name, 'slug' => trim($slug, '-')]);
        } elseif ($action === 'product' && is_admin()) {
            $pdo = db(); $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO products (category_id,sku,name,description,academic_level,base_price) VALUES (:category,:sku,:name,:description,:level,:price)');
            $insert->execute(['category'=>(int)$_POST['category_id'],'sku'=>strtoupper(trim((string)$_POST['sku'])),'name'=>trim((string)$_POST['name']),'description'=>trim((string)$_POST['description']),'level'=>$_POST['academic_level'],'price'=>(float)$_POST['price']]);
            $productId=(int)$pdo->lastInsertId();
            $variant=$pdo->prepare('INSERT INTO product_variants (product_id,variant_sku,size) VALUES (:product,:sku,:size)');
            $variant->execute(['product'=>$productId,'sku'=>strtoupper(trim((string)$_POST['sku'])).'-'.strtoupper(trim((string)($_POST['size'] ?: 'STD'))),'size'=>trim((string)$_POST['size']) ?: null]);
            $inventory=$pdo->prepare('INSERT INTO inventory (variant_id,stock_quantity,reorder_level) VALUES (:variant,:stock,5)');
            $inventory->execute(['variant'=>(int)$pdo->lastInsertId(),'stock'=>max(0,(int)$_POST['stock'])]); $pdo->commit();
        }
        set_flash('success', 'Changes saved successfully.');
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        set_flash('error', 'The change could not be saved. Check the submitted values.');
    }
    redirect('admin.php?view=' . urlencode($view));
}
$flash=get_flash();
$metrics=[
 'pending'=>(int)db()->query("SELECT COUNT(*) FROM reservations WHERE status IN ('pending','processing')")->fetchColumn(),
 'low'=>(int)db()->query('SELECT COUNT(*) FROM inventory WHERE stock_quantity-reserved_quantity <= reorder_level')->fetchColumn(),
 'students'=>(int)db()->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
 'products'=>(int)db()->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn(),
];
$reservations=db()->query("SELECT r.*, CONCAT(u.first_name,' ',u.last_name) student_name,u.student_id FROM reservations r JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC LIMIT 100")->fetchAll();
$inventory=db()->query("SELECT pv.id variant_id,p.name,p.sku,pv.size,p.base_price,i.stock_quantity,i.reserved_quantity,i.reorder_level,c.name category FROM inventory i JOIN product_variants pv ON pv.id=i.variant_id JOIN products p ON p.id=pv.product_id JOIN categories c ON c.id=p.category_id ORDER BY p.name,pv.size")->fetchAll();
$users=db()->query("SELECT u.*,(SELECT COUNT(*) FROM reservations r WHERE r.user_id=u.id) orders FROM users u WHERE u.role='student' ORDER BY u.created_at DESC")->fetchAll();
$categories=db()->query('SELECT * FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
$reportFrom = (string) ($_GET['from'] ?? date('Y-m-01'));
$reportTo = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportFrom) || !strtotime($reportFrom)) $reportFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportTo) || !strtotime($reportTo)) $reportTo = date('Y-m-d');
if ($reportFrom > $reportTo) [$reportFrom, $reportTo] = [$reportTo, $reportFrom];
$reportQuery = db()->prepare(
    "SELECT COUNT(*) total_reservations,
            COALESCE(SUM(status = 'claimed'), 0) claimed_count,
            COALESCE(SUM(status IN ('pending','processing','ready')), 0) open_count,
            COALESCE(SUM(CASE WHEN status = 'claimed' THEN total_amount ELSE 0 END), 0) claimed_revenue,
            COALESCE(SUM(CASE WHEN status IN ('pending','processing','ready') THEN total_amount ELSE 0 END), 0) open_value
     FROM reservations WHERE DATE(COALESCE(claimed_at, created_at)) BETWEEN :date_from AND :date_to"
);
$reportQuery->execute(['date_from' => $reportFrom, 'date_to' => $reportTo]);
$report = $reportQuery->fetch();
$dailyQuery = db()->prepare(
    "SELECT DATE(COALESCE(claimed_at, created_at)) report_date, COUNT(*) claimed_orders, SUM(total_amount) revenue
     FROM reservations WHERE status = 'claimed' AND DATE(COALESCE(claimed_at, created_at)) BETWEEN :date_from AND :date_to
     GROUP BY DATE(COALESCE(claimed_at, created_at)) ORDER BY report_date DESC"
);
$dailyQuery->execute(['date_from' => $reportFrom, 'date_to' => $reportTo]);
$dailyRevenue = $dailyQuery->fetchAll();
$claimedQuery = db()->prepare(
    "SELECT r.reservation_code, r.total_amount, COALESCE(r.claimed_at, r.created_at) claimed_date,
            CONCAT(u.first_name, ' ', u.last_name) student_name
     FROM reservations r JOIN users u ON u.id = r.user_id
     WHERE r.status = 'claimed' AND DATE(COALESCE(r.claimed_at, r.created_at)) BETWEEN :date_from AND :date_to
     ORDER BY claimed_date DESC LIMIT 20"
);
$claimedQuery->execute(['date_from' => $reportFrom, 'date_to' => $reportTo]);
$claimedReservations = $claimedQuery->fetchAll();
$todayReservations = (int) db()->query("SELECT COUNT(*) FROM reservations WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$readyReservations = (int) db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'ready'")->fetchColumn();
$dashboardRecent = array_slice($reservations, 0, 6);
$dashboardLowStock = array_values(array_filter($inventory, static function (array $item): bool {
    return ((int) $item['stock_quantity'] - (int) $item['reserved_quantity']) <= (int) $item['reorder_level'];
}));
$dashboardLowStock = array_slice($dashboardLowStock, 0, 5);
$monthlyQuery = db()->query(
    "SELECT DATE_FORMAT(COALESCE(claimed_at, created_at), '%Y-%m') month_key, SUM(total_amount) revenue
     FROM reservations WHERE status = 'claimed' AND COALESCE(claimed_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(COALESCE(claimed_at, created_at), '%Y-%m')"
);
$monthlyMap = [];
foreach ($monthlyQuery->fetchAll() as $monthRow) $monthlyMap[$monthRow['month_key']] = (float) $monthRow['revenue'];
$monthlyRevenue = [];
$monthStart = new DateTimeImmutable('first day of this month');
for ($offset = 5; $offset >= 0; $offset--) {
    $month = $monthStart->modify("-{$offset} months");
    $monthlyRevenue[] = ['label' => $month->format('M'), 'amount' => $monthlyMap[$month->format('Y-m')] ?? 0.0];
}
$monthlyMaximum = max(1.0, ...array_column($monthlyRevenue, 'amount'));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Management | CSCQC E-Store</title><link rel="stylesheet" href="assets/admin.css?v=20260823-4"></head><body><button class="admin-menu" data-menu><i class="bx bx-menu"></i></button><div class="admin-shell"><aside class="admin-sidebar" data-sidebar><a class="admin-brand" href="admin.php"><span class="admin-mark">C</span><span>CSCQC ADMIN<small>E-STORE MANAGEMENT</small></span></a><nav class="admin-nav"><a href="admin.php">Dashboard</a><a href="admin.php?view=reservations">Reservations</a><a href="admin.php?view=inventory">Inventory</a><a href="admin.php?view=products">Products & Categories</a><a href="admin.php?view=users">User Management</a><a href="admin.php?view=reports">Reports</a></nav><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="admin-logout">Log out</button></form></aside><main class="admin-main"><header class="admin-topbar"><div><span>MANAGEMENT / <?= h(strtoupper($view)) ?></span><h1><?= h(ucwords(str_replace('_',' ',$view))) ?></h1></div><strong><?= h($staff['first_name'].' '.$staff['last_name']) ?></strong></header><?php if($flash):?><div class="admin-alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif;?>
<?php if($view==='dashboard'):?>
<section class="dashboard-welcome"><div><span class="system-pill"><i class="bx bx-check-circle"></i> System operational</span><h2>Good day, <?=h($staff['first_name'])?>.</h2><p>Here is the latest activity across the CSCQC E-Store for <?=h(date('l, F j, Y'))?>.</p></div><div class="dashboard-welcome-actions"><a class="welcome-secondary" href="admin.php?view=reports"><i class="bx bx-line-chart"></i> View reports</a><a class="welcome-primary" href="admin.php?view=reservations"><i class="bx bx-receipt"></i> Process reservations</a></div></section>
<section class="dashboard-metrics"><article class="dashboard-metric revenue"><div class="dashboard-metric-top"><span><i class="bx bx-wallet"></i></span><small>Revenue this month</small></div><strong>&#8369;<?=number_format((float)$report['claimed_revenue'],2)?></strong><p>From claimed reservations</p></article><article class="dashboard-metric"><div class="dashboard-metric-top"><span><i class="bx bx-time-five"></i></span><small>Open reservations</small></div><strong><?=(int)$report['open_count']?></strong><p><?=$todayReservations?> received today</p></article><article class="dashboard-metric"><div class="dashboard-metric-top"><span><i class="bx bx-package"></i></span><small>Ready to claim</small></div><strong><?=$readyReservations?></strong><p>Awaiting student pickup</p></article><article class="dashboard-metric alert"><div class="dashboard-metric-top"><span><i class="bx bx-error-circle"></i></span><small>Low stock variants</small></div><strong><?=$metrics['low']?></strong><p>Require inventory attention</p></article></section>
<section class="dashboard-quick"><a href="admin.php?view=reservations"><span><i class="bx bx-receipt"></i></span><div><strong>Reservations</strong><small>Review and update orders</small></div><i class="bx bx-chevron-right"></i></a><a href="admin.php?view=inventory"><span><i class="bx bx-package"></i></span><div><strong>Inventory</strong><small>Manage current stock</small></div><i class="bx bx-chevron-right"></i></a><a href="admin.php?view=users"><span><i class="bx bx-user"></i></span><div><strong>User accounts</strong><small>Manage student access</small></div><i class="bx bx-chevron-right"></i></a></section>
<div class="dashboard-content"><section class="admin-panel dashboard-recent"><div class="dashboard-section-head"><div><span class="eyebrow">RECENT ACTIVITY</span><h2>Latest reservations</h2></div><a href="admin.php?view=reservations">View all</a></div><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Student</th><th>Total</th><th>Status</th><th>Logged</th></tr></thead><tbody><?php if(!$dashboardRecent):?><tr><td colspan="5" class="dashboard-empty">No reservations have been submitted yet.</td></tr><?php endif;?><?php foreach($dashboardRecent as $recent):?><tr><td><strong><?=h($recent['reservation_code'])?></strong></td><td><strong><?=h($recent['student_name'])?></strong><small><?=h($recent['student_id'])?></small></td><td>&#8369;<?=number_format((float)$recent['total_amount'],2)?></td><td><span class="badge <?=h($recent['status'])?>"><?=h(ucwords($recent['status']))?></span></td><td><?=h(date('M d, g:i A',strtotime($recent['created_at'])))?></td></tr><?php endforeach;?></tbody></table></div></section><aside class="dashboard-side"><section class="admin-panel revenue-panel"><div class="dashboard-section-head"><div><span class="eyebrow">PERFORMANCE</span><h2>Six-month revenue</h2></div><a href="admin.php?view=reports">Details</a></div><div class="revenue-chart"><?php foreach($monthlyRevenue as $month):?><div class="revenue-bar"><span class="bar-value">&#8369;<?=number_format($month['amount'],0)?></span><div><i style="height:<?=max(5,($month['amount']/$monthlyMaximum)*100)?>%"></i></div><small><?=h($month['label'])?></small></div><?php endforeach;?></div></section><section class="admin-panel stock-panel"><div class="dashboard-section-head"><div><span class="eyebrow">INVENTORY ALERTS</span><h2>Low stock</h2></div><a href="admin.php?view=inventory">Manage</a></div><div class="dashboard-stock-list"><?php if(!$dashboardLowStock):?><p class="dashboard-empty">All inventory levels are healthy.</p><?php endif;?><?php foreach($dashboardLowStock as $stock): $available=(int)$stock['stock_quantity']-(int)$stock['reserved_quantity'];?><article><span><i class="bx bx-package"></i></span><div><strong><?=h($stock['name'])?></strong><small><?=h($stock['size']?:'Standard')?> · <?=max(0,$available)?> available</small></div><b><?=max(0,$available)?></b></article><?php endforeach;?></div></section></aside></div>
<?php elseif($view==='reports'):?>
<section class="admin-panel report-controls no-print"><div><span class="eyebrow">SALES MONITORING</span><h2>Revenue report</h2><p>Revenue includes only reservations marked as claimed. It is not profit because product costs are not recorded.</p></div><form method="get" class="report-filter"><input type="hidden" name="view" value="reports"><label>From<input type="date" name="from" value="<?=h($reportFrom)?>" required></label><label>To<input type="date" name="to" value="<?=h($reportTo)?>" required></label><button>Generate report</button><button type="button" class="print-button" onclick="window.print()">Print</button></form></section>
<section class="metric-grid report-metrics"><article><small>Claimed revenue</small><strong>&#8369;<?=number_format((float)$report['claimed_revenue'],2)?></strong><p>Completed reservations</p></article><article><small>Claimed orders</small><strong><?=(int)$report['claimed_count']?></strong><p>Successfully completed</p></article><article><small>Open reservations</small><strong><?=(int)$report['open_count']?></strong><p>Pending, processing or ready</p></article><article><small>Open order value</small><strong>&#8369;<?=number_format((float)$report['open_value'],2)?></strong><p>Not counted as revenue</p></article></section>
<div class="report-columns"><section class="admin-panel"><h2>Revenue by day</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Claimed orders</th><th>Revenue</th></tr></thead><tbody><?php if(!$dailyRevenue):?><tr><td colspan="3" class="empty-report">No claimed orders in this period.</td></tr><?php endif;?><?php foreach($dailyRevenue as $day):?><tr><td><?=h(date('M d, Y',strtotime($day['report_date'])))?></td><td><?=(int)$day['claimed_orders']?></td><td><strong>&#8369;<?=number_format((float)$day['revenue'],2)?></strong></td></tr><?php endforeach;?></tbody></table></div></section><section class="admin-panel"><h2>Recent claimed transactions</h2><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Student</th><th>Claimed</th><th>Amount</th></tr></thead><tbody><?php if(!$claimedReservations):?><tr><td colspan="4" class="empty-report">No transactions found.</td></tr><?php endif;?><?php foreach($claimedReservations as $claimed):?><tr><td><strong><?=h($claimed['reservation_code'])?></strong></td><td><?=h($claimed['student_name'])?></td><td><?=h(date('M d, Y',strtotime($claimed['claimed_date'])))?></td><td>&#8369;<?=number_format((float)$claimed['total_amount'],2)?></td></tr><?php endforeach;?></tbody></table></div></section></div>
<?php elseif($view==='reservations'):?><section class="admin-panel"><h2>Student reservations</h2><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Student</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($reservations as $row):?><tr><td><?=h($row['reservation_code'])?></td><td><strong><?=h($row['student_name'])?></strong><small><?=h($row['student_id'])?></small></td><td><?=h(date('M d, Y',strtotime($row['created_at'])))?></td><td>&#8369;<?=number_format((float)$row['total_amount'],2)?></td><td><span class="badge <?=h($row['status'])?>"><?=h(ucwords($row['status']))?></span></td><td><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="reservation_status"><input type="hidden" name="id" value="<?=$row['id']?>"><select name="status"><?php foreach(['pending','processing','ready','claimed','cancelled'] as $status):?><option <?=$row['status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select><button>Save</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($view==='inventory'):?><section class="admin-panel"><h2>Inventory</h2><div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th>Size</th><th>Reserved</th><th>Stock</th><th>Save</th></tr></thead><tbody><?php foreach($inventory as $row):?><tr><td><?=h($row['name'])?></td><td><?=h($row['sku'])?></td><td><?=h($row['size']?:'Standard')?></td><td><?=$row['reserved_quantity']?></td><td><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?=$row['variant_id']?>"><input type="number" name="stock" min="<?=$row['reserved_quantity']?>" value="<?=$row['stock_quantity']?>"></td><td><button>Update</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($view==='users'):?><section class="admin-panel user-directory"><div class="user-directory-head"><div><span class="eyebrow">ACCOUNT DIRECTORY</span><h2>Users</h2><p>Manage student access and review registered customer accounts.</p></div><label class="user-search"><i class="bx bx-search"></i><input type="search" placeholder="Search users" data-user-search></label></div><div class="table-wrap"><table class="user-table"><thead><tr><th>Full name</th><th>Status</th><th>Email</th><th>Academic level</th><th>Reservations</th><th>Access</th></tr></thead><tbody><?php foreach($users as $row): $fullName=$row['first_name'].' '.$row['last_name']; $active=$row['status']==='active';?><tr data-user-row data-search="<?=h(strtolower($fullName.' '.$row['email'].' '.$row['student_id']))?>"><td><div class="directory-user"><span class="directory-avatar"><?=h(strtoupper(substr($row['first_name'],0,1).substr($row['last_name'],0,1)))?></span><div><strong><?=h($fullName)?></strong><small><?=h($row['student_id'])?></small></div></div></td><td><span class="account-status <?=$active?'active':'inactive'?>"><?=$active?'Active':'Not active'?></span></td><td><?=h($row['email'])?></td><td><?=h(strtoupper($row['academic_level']))?></td><td><?=(int)$row['orders']?></td><td><?php if(is_admin()):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?=$row['id']?>"><input type="hidden" name="status" value="<?=$active?'suspended':'active'?>"><button class="account-action <?=$active?'deactivate':'activate'?>"><?=$active?'Deactivate':'Activate'?></button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table><p class="empty-report" data-user-empty hidden>No users match your search.</p></div></section>
<?php else:?><div class="admin-columns"><section class="admin-panel"><h2>Add category</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="category"><label>Category name</label><input name="name" required><button class="primary-button">Save category</button></form></section><section class="admin-panel"><h2>Add product and first variant</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="product"><label>Category</label><select name="category_id"><?php foreach($categories as $cat):?><option value="<?=$cat['id']?>"><?=h($cat['name'])?></option><?php endforeach;?></select><label>SKU</label><input name="sku" required><label>Name</label><input name="name" required><label>Description</label><textarea name="description"></textarea><label>Academic level</label><select name="academic_level"><option value="all">All</option><option value="elementary">Elementary</option><option value="jhs">JHS</option><option value="shs">SHS</option><option value="college">College</option></select><label>Price</label><input name="price" type="number" min="0" step="0.01" required><label>Size/variant</label><input name="size" placeholder="Standard, Small, 32"><label>Initial stock</label><input name="stock" type="number" min="0" value="0"><button class="primary-button">Add product</button></form></section></div><?php endif;?><footer class="admin-footer">CSCQC E-Store · Administration &copy; <?=date('Y')?></footer></main></div><script src="assets/admin.js"></script></body></html>
