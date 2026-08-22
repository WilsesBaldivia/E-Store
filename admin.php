<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
$staff = require_staff();
$view = (string) ($_GET['view'] ?? 'dashboard');
$view = in_array($view, ['dashboard','reservations','inventory','products','users'], true) ? $view : 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { set_flash('error', 'Form session expired.'); redirect('admin.php?view=' . urlencode($view)); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'logout') { logout_user(); redirect('admin-login.php'); }
    try {
        if ($action === 'user_status' && is_admin()) {
            $update = db()->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'student'");
            $update->execute(['status' => in_array($_POST['status'] ?? '', ['active','suspended'], true) ? $_POST['status'] : 'pending', 'id' => (int) $_POST['id']]);
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
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Management | CSCQC E-Store</title><link rel="stylesheet" href="assets/admin.css"></head><body><button class="admin-menu" data-menu><i class="bx bx-menu"></i></button><div class="admin-shell"><aside class="admin-sidebar" data-sidebar><a class="admin-brand" href="admin.php"><span class="admin-mark">C</span><span>CSCQC ADMIN<small>E-STORE MANAGEMENT</small></span></a><nav class="admin-nav"><a href="admin.php">Dashboard</a><a href="admin.php?view=reservations">Reservations</a><a href="admin.php?view=inventory">Inventory</a><a href="admin.php?view=products">Products & Categories</a><a href="admin.php?view=users">User Management</a><a href="student.php">Student Store</a></nav><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="admin-logout">Log out</button></form></aside><main class="admin-main"><header class="admin-topbar"><div><span>MANAGEMENT / <?= h(strtoupper($view)) ?></span><h1><?= h(ucwords(str_replace('_',' ',$view))) ?></h1></div><strong><?= h($staff['first_name'].' '.$staff['last_name']) ?></strong></header><?php if($flash):?><div class="admin-alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif;?>
<?php if($view==='dashboard'):?><section class="metric-grid"><article><small>Pending reservations</small><strong><?= $metrics['pending'] ?></strong></article><article><small>Low stock variants</small><strong><?= $metrics['low'] ?></strong></article><article><small>Students</small><strong><?= $metrics['students'] ?></strong></article><article><small>Active products</small><strong><?= $metrics['products'] ?></strong></article></section><section class="admin-panel"><h2>System overview</h2><p>The dashboard values are retrieved directly from MySQL. Use the navigation to process reservations, update inventory, maintain products, and verify students.</p></section>
<?php elseif($view==='reservations'):?><section class="admin-panel"><h2>Student reservations</h2><div class="table-wrap"><table><thead><tr><th>Voucher</th><th>Student</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($reservations as $row):?><tr><td><?=h($row['reservation_code'])?></td><td><strong><?=h($row['student_name'])?></strong><small><?=h($row['student_id'])?></small></td><td><?=h(date('M d, Y',strtotime($row['created_at'])))?></td><td>&#8369;<?=number_format((float)$row['total_amount'],2)?></td><td><span class="badge <?=h($row['status'])?>"><?=h(ucwords($row['status']))?></span></td><td><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="reservation_status"><input type="hidden" name="id" value="<?=$row['id']?>"><select name="status"><?php foreach(['pending','processing','ready','claimed','cancelled'] as $status):?><option <?=$row['status']===$status?'selected':''?>><?=$status?></option><?php endforeach;?></select><button>Save</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($view==='inventory'):?><section class="admin-panel"><h2>Inventory</h2><div class="table-wrap"><table><thead><tr><th>Product</th><th>SKU</th><th>Size</th><th>Reserved</th><th>Stock</th><th>Save</th></tr></thead><tbody><?php foreach($inventory as $row):?><tr><td><?=h($row['name'])?></td><td><?=h($row['sku'])?></td><td><?=h($row['size']?:'Standard')?></td><td><?=$row['reserved_quantity']?></td><td><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="stock"><input type="hidden" name="id" value="<?=$row['variant_id']?>"><input type="number" name="stock" min="<?=$row['reserved_quantity']?>" value="<?=$row['stock_quantity']?>"></td><td><button>Update</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($view==='users'):?><section class="admin-panel"><h2>Student verification</h2><div class="table-wrap"><table><thead><tr><th>Student</th><th>ID</th><th>Level</th><th>Orders</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($users as $row):?><tr><td><strong><?=h($row['first_name'].' '.$row['last_name'])?></strong><small><?=h($row['email'])?></small></td><td><?=h($row['student_id'])?></td><td><?=h(strtoupper($row['academic_level']))?></td><td><?=$row['orders']?></td><td><span class="badge <?=h($row['status'])?>"><?=h(ucwords($row['status']))?></span></td><td><?php if(is_admin()):?><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="user_status"><input type="hidden" name="id" value="<?=$row['id']?>"><select name="status"><option value="pending">Pending</option><option value="active" <?=$row['status']==='active'?'selected':''?>>Active</option><option value="suspended" <?=$row['status']==='suspended'?'selected':''?>>Suspended</option></select><button>Save</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php else:?><div class="admin-columns"><section class="admin-panel"><h2>Add category</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="category"><label>Category name</label><input name="name" required><button class="primary-button">Save category</button></form></section><section class="admin-panel"><h2>Add product and first variant</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="product"><label>Category</label><select name="category_id"><?php foreach($categories as $cat):?><option value="<?=$cat['id']?>"><?=h($cat['name'])?></option><?php endforeach;?></select><label>SKU</label><input name="sku" required><label>Name</label><input name="name" required><label>Description</label><textarea name="description"></textarea><label>Academic level</label><select name="academic_level"><option value="all">All</option><option value="college">College</option><option value="shs">SHS</option><option value="jhs">JHS</option></select><label>Price</label><input name="price" type="number" min="0" step="0.01" required><label>Size/variant</label><input name="size" placeholder="Standard, Small, 32"><label>Initial stock</label><input name="stock" type="number" min="0" value="0"><button class="primary-button">Add product</button></form></section></div><?php endif;?><footer class="admin-footer">CSCQC E-Store · Administration &copy; <?=date('Y')?></footer></main></div><script src="assets/admin.js"></script></body></html>
