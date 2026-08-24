<?php
require_once 'config.php';
require_once 'rcon.php';

check_ip_block();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $user_input = trim($_POST['username']);
    $pass_input = trim($_POST['password']);
    
    $admin_user = get_setting('admin_user');
    $admin_pass = get_setting('admin_pass');
    
    if ($user_input === $admin_user && password_verify($pass_input, $admin_pass)) {
        $_SESSION['admin'] = true;
        clear_failed_attempts();
    } else {
        register_failed_attempt();
        $login_error = "Invalid credentials. Attempt registered.";
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header("Location: admin.php");
    exit;
}

if (!isset($_SESSION['admin'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login | <?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?></title>
    <link rel="icon" type="image/x-icon" href="assets/fav.ico">
    <style>
        body { background: #111; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: #222; border: 2px solid #555; padding: 30px; border-radius: 8px; width: 320px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #aaa; }
        .form-control { width: 100%; padding: 10px; background: #333; border: 1px solid #555; color: #fff; border-radius: 4px; box-sizing: border-box; }
        .btn { width: 100%; background: #55ff55; color: #000; border: none; padding: 10px; font-weight: bold; cursor: pointer; border-radius: 4px; }
        .error { color: #ff5555; font-weight: bold; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2 style="text-align: center; margin-bottom: 20px; color: #ffff55;">Admin Login</h2>
        <?php if (isset($login_error)): ?><div class="error"><?php echo $login_error; ?></div><?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required autocomplete="off">
            </div>
            <button type="submit" name="login" class="btn">Login</button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_admin'])) {
    $new_user = trim($_POST['new_user']);
    $new_pass = trim($_POST['new_pass']);
    if (!empty($new_user)) {
        update_setting('admin_user', $new_user);
    }
    if (!empty($new_pass)) {
        update_setting('admin_pass', password_hash($new_pass, PASSWORD_BCRYPT));
    }
    $admin_success = "Admin credentials updated.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_rcon'])) {
    update_setting('rcon_host', trim($_POST['rcon_host']));
    update_setting('rcon_port', trim($_POST['rcon_port']));
    update_setting('rcon_pass', trim($_POST['rcon_pass']));
    update_setting('card_number', trim($_POST['card_number']));
    $sett_success = "Settings successfully updated.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name = htmlspecialchars($_POST['name']);
    $price = intval($_POST['price']);
    $command = $_POST['command'];
    
    if (isset($_FILES['p_img']) && $_FILES['p_img']['error'] == 0) {
        $file = $_FILES['p_img'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $new_name = 'p_' . time() . '_' . rand(100, 999) . '.' . $ext;
            if (!is_dir('uploads')) {
                mkdir('uploads', 0755, true);
            }
            if (move_uploaded_file($file['tmp_name'], 'uploads/' . $new_name)) {
                $stmt = $db->prepare("INSERT INTO products (name, price, image, command) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $price, 'uploads/' . $new_name, $command]);
                $p_success = "Product added.";
            }
        }
    }
}

if (isset($_GET['delete_product'])) {
    $p_id = intval($_GET['delete_product']);
    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$p_id]);
    header("Location: admin.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reply_ticket'])) {
    $t_id = intval($_POST['ticket_id']);
    $reply = htmlspecialchars($_POST['reply_text']);
    $stmt = $db->prepare("UPDATE tickets SET reply = ?, status = 'closed' WHERE id = ?");
    $stmt->execute([$reply, $t_id]);
    $t_success = "Ticket replied and closed.";
}

if (isset($_GET['approve_order'])) {
    $o_id = intval($_GET['approve_order']);
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
    $stmt->execute([$o_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        $items = json_decode($order['items'], true);
        $player = $order['username'];
        
        $rcon = new MinecraftRcon(get_setting('rcon_host'), get_setting('rcon_port'), get_setting('rcon_pass'));
        $connected = $rcon->connect();
        
        foreach ($items as $item) {
            $prod_id = $item['id'];
            $qty = $item['quantity'];
            
            $stmt_p = $db->prepare("SELECT command FROM products WHERE id = ?");
            $stmt_p->execute([$prod_id]);
            $raw_cmd = $stmt_p->fetchColumn();
            
            if ($raw_cmd) {
                $final_cmd = str_replace('player', $player, $raw_cmd);
                if ($connected) {
                    for ($i = 0; $i < $qty; $i++) {
                        $rcon->sendCommand($final_cmd);
                    }
                }
            }
        }
        if ($connected) {
            $rcon->disconnect();
        }
        
        $stmt_u = $db->prepare("UPDATE orders SET status = 'approved' WHERE id = ?");
        $stmt_u->execute([$o_id]);
        $order_msg = "Order approved and commands executed.";
    }
}

if (isset($_GET['cancel_order'])) {
    $o_id = intval($_GET['cancel_order']);
    $stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$o_id]);
    $order_msg = "Order cancelled.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | <?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?></title>
    <link rel="icon" type="image/x-icon" href="assets/fav.ico">
    <style>
        * {
            box-sizing: border-box;
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }
        body {
            background: #1a1a1a;
            color: #fff;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #555;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .layout {
            display: flex;
            gap: 20px;
        }
        .sidebar {
            width: 220px;
            background: #2a2a2a;
            border-radius: 8px;
            padding: 20px;
            flex-shrink: 0;
        }
        .sidebar h3 {
            margin-bottom: 20px;
            color: #ffff55;
            text-align: center;
            border-bottom: 1px solid #444;
            padding-bottom: 10px;
        }
        .nav-item {
            display: block;
            padding: 12px 15px;
            margin-bottom: 10px;
            background: #333;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s, transform 0.2s;
        }
        .nav-item:hover {
            background: #444;
            transform: translateX(5px);
        }
        .nav-item.active {
            background: #55ff55;
            color: #000;
        }
        .content {
            flex: 1;
        }
        .section {
            display: none;
            animation: fadeIn 0.4s ease;
        }
        .section.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card {
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .card h2 {
            margin-bottom: 15px;
            color: #ffff55;
            border-bottom: 1px solid #444;
            padding-bottom: 5px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #aaa;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            background: #333;
            border: 1px solid #555;
            color: #fff;
            border-radius: 4px;
        }
        .btn {
            background: #55ff55;
            color: #000;
            border: none;
            padding: 8px 12px;
            cursor: pointer;
            font-weight: bold;
            border-radius: 4px;
            display: inline-block;
            text-decoration: none;
        }
        .btn:hover {
            background: #22aa22;
            color: #fff;
        }
        .btn-danger {
            background: #ff5555;
            color: #000;
        }
        .btn-danger:hover {
            background: #aa2222;
            color: #fff;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .table th, .table td {
            padding: 10px;
            border: 1px solid #444;
            text-align: left;
        }
        .table th {
            background: #333;
        }
        .success {
            color: #55ff55;
            font-weight: bold;
            margin-bottom: 15px;
        }
        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px;
            }
            .sidebar h3 {
                width: 100%;
                margin-bottom: 5px;
            }
            .nav-item {
                flex: 1;
                text-align: center;
                margin-bottom: 0;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1><?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?> - Admin Panel</h1>
    <a href="admin.php?logout=1" class="btn btn-danger">Logout</a>
</div>

<div class="layout">
    <div class="sidebar">
        <h3>Navigation</h3>
        <div class="nav-item active" onclick="showSection('settings')">Settings</div>
        <div class="nav-item" onclick="showSection('products')">Products</div>
        <div class="nav-item" onclick="showSection('orders')">Orders</div>
        <div class="nav-item" onclick="showSection('tickets')">Tickets</div>
    </div>

    <div class="content">
        <div id="settings" class="section active">
            <?php if (isset($sett_success)): ?><div class="success"><?php echo $sett_success; ?></div><?php endif; ?>
            <?php if (isset($admin_success)): ?><div class="success"><?php echo $admin_success; ?></div><?php endif; ?>
            <div class="card">
                <h2>System Settings</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>RCON Host</label>
                        <input type="text" name="rcon_host" class="form-control" value="<?php echo htmlspecialchars(get_setting('rcon_host')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>RCON Port</label>
                        <input type="number" name="rcon_port" class="form-control" value="<?php echo htmlspecialchars(get_setting('rcon_port')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>RCON Password</label>
                        <input type="password" name="rcon_pass" class="form-control" value="<?php echo htmlspecialchars(get_setting('rcon_pass')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Card Number (For payments)</label>
                        <input type="text" name="card_number" class="form-control" value="<?php echo htmlspecialchars(get_setting('card_number')); ?>" required>
                    </div>
                    <button type="submit" name="update_rcon" class="btn">Save Settings</button>
                </form>
            </div>
            <div class="card">
                <h2>Change Admin Credentials</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>New Admin Username</label>
                        <input type="text" name="new_user" class="form-control" placeholder="owner">
                    </div>
                    <div class="form-group">
                        <label>New Admin Password</label>
                        <input type="password" name="new_pass" class="form-control" placeholder="Leave empty to keep current">
                    </div>
                    <button type="submit" name="update_admin" class="btn">Update Account</button>
                </form>
            </div>
        </div>

        <div id="products" class="section">
            <?php if (isset($p_success)): ?><div class="success"><?php echo $p_success; ?></div><?php endif; ?>
            <div class="card">
                <h2>Add New Product</h2>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Price (Tomans)</label>
                        <input type="number" name="price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>RCON Command (Use "player" placeholder)</label>
                        <input type="text" name="command" class="form-control" placeholder="give player diamond 64" required>
                    </div>
                    <div class="form-group">
                        <label>Product Image</label>
                        <input type="file" name="p_img" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" name="add_product" class="btn">Add Product</button>
                </form>
            </div>
            <div class="card">
                <h2>Manage Products</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Command</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $products = $db->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($products as $p):
                        ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($p['image']); ?>" width="40" style="image-rendering:pixelated;"></td>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td><?php echo number_format($p['price']); ?></td>
                                <td><code><?php echo htmlspecialchars($p['command']); ?></code></td>
                                <td><a href="admin.php?delete_product=<?php echo $p['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure?');">Delete</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="orders" class="section">
            <?php if (isset($order_msg)): ?><div class="success"><?php echo $order_msg; ?></div><?php endif; ?>
            <div class="card">
                <h2>Pending Orders</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Items & Qty</th>
                            <th>Receipt</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $db->query("SELECT * FROM orders WHERE status = 'pending'");
                        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($orders as $o):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($o['username']); ?></td>
                                <td>
                                    <?php
                                    $ordered_items = json_decode($o['items'], true);
                                    foreach ($ordered_items as $item) {
                                        $stmt_p = $db->prepare("SELECT name FROM products WHERE id = ?");
                                        $stmt_p->execute([$item['id']]);
                                        $p_name = $stmt_p->fetchColumn();
                                        echo htmlspecialchars($p_name) . " (x" . intval($item['quantity']) . ")<br>";
                                    }
                                    ?>
                                </td>
                                <td><a href="<?php echo htmlspecialchars($o['receipt_img']); ?>" target="_blank"><img src="<?php echo htmlspecialchars($o['receipt_img']); ?>" width="50"></a></td>
                                <td><?php echo number_format($o['total_price']); ?></td>
                                <td>
                                    <a href="admin.php?approve_order=<?php echo $o['id']; ?>" class="btn">Approve</a>
                                    <a href="admin.php?cancel_order=<?php echo $o['id']; ?>" class="btn btn-danger">Cancel</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tickets" class="section">
            <?php if (isset($t_success)): ?><div class="success"><?php echo $t_success; ?></div><?php endif; ?>
            <div class="card">
                <h2>Open Tickets</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Reply</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $db->query("SELECT * FROM tickets WHERE status = 'open'");
                        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($tickets as $t):
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['username']); ?></td>
                                <td><?php echo htmlspecialchars($t['subject']); ?></td>
                                <td><?php echo htmlspecialchars($t['message']); ?></td>
                                <td>
                                    <form method="POST" action="">
                                        <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                        <input type="text" name="reply_text" class="form-control" placeholder="Write reply..." required style="margin-bottom: 5px;">
                                        <button type="submit" name="reply_ticket" class="btn" style="padding: 5px 10px;">Reply & Close</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function showSection(sectionId) {
    var sections = document.querySelectorAll('.section');
    sections.forEach(function(section) {
        section.classList.remove('active');
    });
    
    var navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(function(item) {
        item.classList.remove('active');
    });
    
    document.getElementById(sectionId).classList.add('active');
    var activeNav = Array.from(navItems).find(function(item) {
        return item.getAttribute('onclick').indexOf(sectionId) !== -1;
    });
    if (activeNav) {
        activeNav.classList.add('active');
    }
}
</script>
</body>
</html>