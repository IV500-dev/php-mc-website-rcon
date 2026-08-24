<?php
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $prod_id = isset($_POST['prod_id']) ? intval($_POST['prod_id']) : 0;
    $qty = isset($_POST['qty']) ? intval($_POST['qty']) : 0;

    if ($prod_id > 0) {
        if ($qty > 0) {
            $_SESSION['cart'][$prod_id] = $qty;
        } else {
            unset($_SESSION['cart'][$prod_id]);
        }
    }

    $total_price = 0;
    if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
        $ids = array_keys($_SESSION['cart']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as $p) {
            $total_price += $p['price'] * $_SESSION['cart'][$p['id']];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'total_price' => number_format($total_price)
    ]);
    exit;
}