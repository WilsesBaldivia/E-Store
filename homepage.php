<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';

$sessionUser = require_student();

try {
    $userStatement = db()->prepare(
        'SELECT id, student_id, first_name, last_name, email, academic_level, role, status
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $userStatement->execute(['id' => $sessionUser['id']]);
    $user = $userStatement->fetch();

    if (!$user || $user['role'] !== 'student' || $user['status'] !== 'active') {
        logout_user();
        redirect('login.php');
    }

    $_SESSION['user'] = $user;

    $metricStatement = db()->prepare(
        "SELECT
            COUNT(*) AS total_reservations,
            COALESCE(SUM(status IN ('pending','processing','ready')), 0) AS open_reservations,
            COALESCE(SUM(status = 'claimed'), 0) AS claimed_reservations
         FROM reservations
         WHERE user_id = :user_id"
    );
    $metricStatement->execute(['user_id' => $user['id']]);
    $metrics = $metricStatement->fetch();

    $reservationStatement = db()->prepare(
        "SELECT r.reservation_code, r.created_at, r.total_amount, r.status,
                GROUP_CONCAT(CONCAT(ri.product_name, ' x ', ri.quantity)
                             ORDER BY ri.id SEPARATOR ', ') AS items
         FROM reservations r
         LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
         WHERE r.user_id = :user_id
         GROUP BY r.id, r.reservation_code, r.created_at, r.total_amount, r.status
         ORDER BY r.created_at DESC
         LIMIT 5"
    );
    $reservationStatement->execute(['user_id' => $user['id']]);
    $reservations = $reservationStatement->fetchAll();
} catch (RuntimeException $exception) {
    error_log('CSCQC student home error: ' . $exception->getMessage());
    set_flash('error', 'The student portal is temporarily unavailable.');
    redirect('login.php');
}

$levelLabels = [
    'college' => 'College',
    'shs' => 'Senior High School',
    'jhs' => 'Junior High School',
];
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$levelLabel = $levelLabels[$user['academic_level']] ?? 'Student';

$statsHtml = '<section class="stats-grid">'
    . '<article><span class="stat-icon green"><i class="bx bx-package"></i></span><div><small>Total reservations</small><strong>' . str_pad((string) ((int) $metrics['total_reservations']), 2, '0', STR_PAD_LEFT) . '</strong><p>All reservation records</p></div></article>'
    . '<article><span class="stat-icon gold"><i class="bx bx-time-five"></i></span><div><small>Open reservations</small><strong>' . str_pad((string) ((int) $metrics['open_reservations']), 2, '0', STR_PAD_LEFT) . '</strong><p>Pending or ready to claim</p></div></article>'
    . '<article><span class="stat-icon blue"><i class="bx bx-check-circle"></i></span><div><small>Claimed orders</small><strong>' . str_pad((string) ((int) $metrics['claimed_reservations']), 2, '0', STR_PAD_LEFT) . '</strong><p>Successfully completed</p></div></article>'
    . '</section>';

$rows = '';
foreach ($reservations as $reservation) {
    $status = (string) $reservation['status'];
    $badgeClass = $status === 'claimed' ? 'claimed' : 'pending';
    $statusLabel = ucwords(str_replace('_', ' ', $status));
    $rows .= '<tr><td><strong>' . h($reservation['reservation_code']) . '</strong></td>'
        . '<td>' . h(date('M d, Y', strtotime((string) $reservation['created_at']))) . '</td>'
        . '<td>' . h($reservation['items'] ?: 'No item details') . '</td>'
        . '<td>&#8369;' . number_format((float) $reservation['total_amount'], 2) . '</td>'
        . '<td><span class="status ' . $badgeClass . '">' . h($statusLabel) . '</span></td></tr>';
}

if ($rows === '') {
    $rows = '<tr><td colspan="5" class="empty-table">No reservations yet. Browse uniforms or books to get started.</td></tr>';
}

$ordersHtml = '<section class="panel orders-panel" id="history">'
    . '<div class="panel-heading"><div><span class="overline">RECENT ACTIVITY</span><h2>Reservation history</h2></div></div>'
    . '<div class="table-wrap"><table><thead><tr><th>Reservation ID</th><th>Date logged</th><th>Items</th><th>Total</th><th>Status</th></tr></thead><tbody>'
    . $rows
    . '</tbody></table></div></section>';

$template = file_get_contents(__DIR__ . '/homepage.html');
if ($template === false) {
    http_response_code(500);
    exit('The student home template could not be loaded.');
}

$template = str_replace(
    [
        'Good day, Shaina!',
        'Shaina Faye Rivera',
        '>SR<',
        'Student ID: 2026-0413',
        'rivera.shaina@cscqc.edu.ph',
        'College Department',
        'href="homepage.html"',
        'href="index.html"><span><i class="bx bx-log-out"></i></span> Log out',
    ],
    [
        'Good day, ' . h($user['first_name']) . '!',
        h($fullName),
        '>' . h($initials) . '<',
        'Student ID: ' . h($user['student_id']),
        h($user['email']),
        h($levelLabel . ' Department'),
        'href="homepage.php"',
        'href="logout.php"><span><i class="bx bx-log-out"></i></span> Log out',
    ],
    $template
);

$template = preg_replace('/Student [^<]* Grade 11/', 'Student &middot; ' . h($levelLabel), $template, 1);
$template = preg_replace('/Grade 11 [^<]* St\. Matthew/', h($levelLabel), $template, 1);
$template = preg_replace('/<section class="stats-grid">.*?<\/section>/s', $statsHtml, $template, 1);
$template = preg_replace('/<section class="panel orders-panel" id="history">.*?<\/section>/s', $ordersHtml, $template, 1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $template;
