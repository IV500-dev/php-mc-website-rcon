<?php
require_once 'config.php';
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    if (isset($_SESSION['cart']) && isset($_SESSION['cart'][$remove_id])) {
        unset($_SESSION['cart'][$remove_id]);
    }
    header("Location: checkout.php");
    exit;
}

$cart_items = [];
$total_price = 0;
if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as $p) {
        $quantity = $_SESSION['cart'][$p['id']];
        $p['quantity'] = $quantity;
        $p['subtotal'] = $p['price'] * $quantity;
        $total_price += $p['subtotal'];
        $cart_items[] = $p;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipt']) && !empty($cart_items)) {
    $current_time = time();
    $order_cooldown = 300;

    if (isset($_SESSION['last_order_time']) && ($current_time - $_SESSION['last_order_time']) < $order_cooldown) {
        $remaining = $order_cooldown - ($current_time - $_SESSION['last_order_time']);
        $checkout_error = "Please wait " . $remaining . " seconds before submitting another order.";
    } else {
        $file = $_FILES['receipt'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];
            
            if (in_array($ext, $allowed) && $file['size'] < 3000000) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (in_array($mime, ['image/jpeg', 'image/png'])) {
                    $new_name = 'receipt_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    if (!is_dir('uploads')) {
                        mkdir('uploads', 0755, true);
                    }
                    if (move_uploaded_file($file['tmp_name'], 'uploads/' . $new_name)) {
                        $order_data = [];
                        foreach ($cart_items as $item) {
                            $order_data[] = [
                                'id' => $item['id'],
                                'quantity' => $_SESSION['cart'][$item['id']]
                            ];
                        }
                        $items_serialized = json_encode($order_data);
                        $stmt = $db->prepare("INSERT INTO orders (username, items, total_price, receipt_img) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$_SESSION['user'], $items_serialized, $total_price, 'uploads/' . $new_name]);
                        
                        $_SESSION['last_order_time'] = $current_time;
                        unset($_SESSION['cart']);
                        $checkout_success = "Receipt submitted successfully. Order pending approval.";
                    } else {
                        $checkout_error = "Error saving uploaded receipt.";
                    }
                } else {
                    $checkout_error = "Invalid image file type.";
                }
            } else {
                $checkout_error = "Only JPG/PNG images under 3MB are allowed.";
            }
        } else {
            $checkout_error = "Please select a valid receipt image file.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($config['site_title'] ?? 'Potato Net'); ?> | Checkout</title>
    <link rel="icon" type="image/x-icon" href="assets/fav.ico">
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }
        body { background: url('assets/log.png') repeat; color: #fff; }
        header { background: url('assets/dark_oak_planks.png') repeat; border-bottom: 4px solid #1a1a1a; padding: 20px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 15px; }
        header img { width: 48px; height: 48px; image-rendering: pixelated; }
        header h1 { font-size: 32px; text-shadow: 2px 2px #000; color: #ffaa00; }
        .container { max-width: 800px; margin: 30px auto; padding: 0 20px; }
        .card { background: url('assets/cracked_stone_bricks.png') repeat; border: 3px solid #333; box-shadow: inset 0 0 10px #000; border-radius: 6px; padding: 20px; margin-bottom: 25px; }
        .card h2 { text-shadow: 2px 2px #000; margin-bottom: 15px; color: #ffff55; }
        .btn { background: #55ff55; color: #000; border: none; padding: 10px 15px; cursor: pointer; font-weight: bold; border-radius: 4px; display: inline-block; text-decoration: none; }
        .btn:hover { background: #22aa22; color: #fff; }
        .btn-danger { background: #ff5555; color: #000; }
        .btn-danger:hover { background: #aa2222; color: #fff; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; background: #222; border: 2px solid #555; color: #fff; border-radius: 4px; }
        .cart-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background: rgba(0,0,0,0.6); }
        .cart-table th, .cart-table td { padding: 12px; border: 1px solid #555; text-align: left; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .alert-error { background: #ff5555; color: #000; }
        .alert-success { background: #55ff55; color: #000; }
        .payment-box {
            background: rgba(0,0,0,0.85); 
            border: 2px dashed #ffff55; 
            padding: 20px; 
            border-radius: 6px; 
            margin-bottom: 20px;
            text-align: center;
        }
        .qty-input { width: 60px; padding: 5px; background: #222; border: 1px solid #555; color: #fff; text-align: center; border-radius: 4px; }
    </style>
</head>
<body>

<header>
    <img src="assets/fav.ico" alt="Logo">
    <h1><?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?></h1>
</header>

<div class="container">
    <div class="card">
        <h2>Your Shopping Cart</h2>
        
        <?php if (isset($checkout_error)): ?><div class="alert alert-error"><?php echo $checkout_error; ?></div><?php endif; ?>
        <?php if (isset($checkout_success)): ?>
            <div class="alert alert-success"><?php echo $checkout_success; ?></div>
            <a href="index.php" class="btn">Back to Shop</a>
        <?php else: ?>
            <?php if (empty($cart_items)): ?>
                <p>Your cart is empty.</p>
                <a href="index.php" class="btn" style="margin-top: 15px;">Back to Shop</a>
            <?php else: ?>
                <table class="cart-table">
                    <thead>
                        <tr style="background: #222;">
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart_items as $item): ?>
                            <tr id="row-<?php echo $item['id']; ?>">
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td class="item-price" data-price="<?php echo $item['price']; ?>"><?php echo number_format($item['price']); ?> Tomans</td>
                                <td>
                                    <input type="number" value="<?php echo $item['quantity']; ?>" min="1" class="qty-input" onchange="updateQty(<?php echo $item['id']; ?>, this.value)">
                                </td>
                                <td class="item-subtotal"><?php echo number_format($item['subtotal']); ?> Tomans</td>
                                <td><a href="checkout.php?remove=<?php echo $item['id']; ?>" class="btn btn-danger" style="padding: 5px 10px;">Remove</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; background: rgba(0,0,0,0.4);">
                            <td colspan="3">Total</td>
                            <td colspan="2" style="color: #55ff55;" id="grand-total"><?php echo number_format($total_price); ?> Tomans</td>
                        </tr>
                    </tbody>
                </table>

                <div class="payment-box">
                    <p style="font-weight: bold; color: #ffff55; font-size: 16px; margin-bottom: 8px;">Payment Instructions</p>
                    <p style="line-height: 1.6; color: #eee;">
                        Please transfer the amount of <strong style="color: #55ff55;" id="instruction-total"><?php echo number_format($total_price); ?> Tomans</strong> to the following card number and then upload your receipt image below to confirm your order.
                    </p>
                    <p style="font-size: 20px; font-weight: bold; letter-spacing: 1.5px; margin-top: 15px; color: #ffff55;">
                        Card Number: <span dir="ltr" style="color: #55ff55;"><?php echo htmlspecialchars(get_setting('card_number')); ?></span>
                    </p>
                </div>

                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 5px;">Upload Transaction Receipt Image (JPG, PNG)</label>
                        <input type="file" name="receipt" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn">Submit Order</button>
                    <a href="index.php" class="btn btn-danger" style="float: right;">Cancel</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function updateQty(productId, newQty) {
    if (newQty < 1) {
        newQty = 1;
    }

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "update_cart.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                var row = document.getElementById("row-" + productId);
                var price = parseFloat(row.querySelector(".item-price").getAttribute("data-price"));
                var subtotalCell = row.querySelector(".item-subtotal");
                
                var newSubtotal = price * newQty;
                subtotalCell.innerHTML = newSubtotal.toLocaleString() + " Tomans";
                
                document.getElementById("grand-total").innerHTML = response.total_price + " Tomans";
                document.getElementById("instruction-total").innerHTML = response.total_price + " Tomans";
            }
        }
    };
    
    xhr.send("prod_id=" + productId + "&qty=" + newQty);
}
</script>
</body>
</html>