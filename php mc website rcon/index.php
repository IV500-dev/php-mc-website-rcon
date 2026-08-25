<?php
require_once 'config.php';
require_once 'rcon.php';

$ip_address = $config['server_ip'] ?? 'mc.example.com';
$discord_link = $config['discord_link'] ?? 'https://discord.gg/example';

if (isset($_GET['reset_login'])) {
    unset($_SESSION['pending_user']);
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_step1'])) {
    $username = trim(htmlspecialchars($_POST['username']));
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $current_time = time();
    $rate_limit_seconds = 60;

    if (!empty($username)) {
        $stmt = $db->prepare("SELECT last_requested FROM otp_requests WHERE username = ? OR ip = ? ORDER BY last_requested DESC LIMIT 1");
        $stmt->execute([$username, $user_ip]);
        $last_request = $stmt->fetchColumn();

        if ($last_request && ($current_time - $last_request) < $rate_limit_seconds) {
            $remaining = $rate_limit_seconds - ($current_time - $last_request);
            $error = "Please wait " . $remaining . " seconds before requesting another code.";
        } else {
            $otp = sprintf("%08d", mt_rand(10000000, 99999999));
            $expiry = $current_time + 300;
            
            $stmt = $db->prepare("INSERT INTO users (username, otp, otp_expiry) VALUES (?, ?, ?) ON CONFLICT(username) DO UPDATE SET otp = ?, otp_expiry = ?");
            $stmt->execute([$username, $otp, $expiry, $otp, $expiry]);
            
            $rcon = new MinecraftRcon(get_setting('rcon_host'), get_setting('rcon_port'), get_setting('rcon_pass'));
            if ($rcon->connect()) {
                $rcon->sendCommand("tellraw " . $username . " [\"\",{\"text\":\"[Potato Net] \",\"color\":\"gold\"},{\"text\":\"Your login code is: \",\"color\":\"yellow\"},{\"text\":\"" . $otp . "\",\"bold\":true,\"color\":\"green\"}]");
                $rcon->disconnect();
                
                $db->prepare("DELETE FROM otp_requests WHERE username = ? OR ip = ?")->execute([$username, $user_ip]);
                $stmt = $db->prepare("INSERT INTO otp_requests (username, ip, last_requested) VALUES (?, ?, ?)");
                $stmt->execute([$username, $user_ip, $current_time]);

                $_SESSION['pending_user'] = $username;
                $success = "OTP code sent in-game to " . htmlspecialchars($username);
            } else {
                $error = "Could not connect to Minecraft server to send OTP code.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_step2'])) {
    $otp_code = trim($_POST['otp_code']);
    $username = $_SESSION['pending_user'] ?? '';
    
    if ($username && $otp_code) {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND otp = ? AND otp_expiry > ?");
        $stmt->execute([$username, $otp_code, time()]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user'] = $username;
            unset($_SESSION['pending_user']);
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid or expired code. Please check your in-game chat and try again.";
        }
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    header("Location: index.php");
    exit;
}

if (isset($_GET['add_to_cart'])) {
    $id = intval($_GET['add_to_cart']);
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]++;
    } else {
        $_SESSION['cart'][$id] = 1;
    }
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_ticket']) && isset($_SESSION['user'])) {
    $subj = trim(htmlspecialchars($_POST['subject']));
    $msg = trim(htmlspecialchars($_POST['message']));
    $current_time = time();
    $cooldown = 300;

    if (isset($_SESSION['last_ticket_time']) && ($current_time - $_SESSION['last_ticket_time']) < $cooldown) {
        $remaining = $cooldown - ($current_time - $_SESSION['last_ticket_time']);
        $ticket_error = "Please wait " . $remaining . " seconds before sending another ticket.";
    } else {
        if (!empty($subj) && !empty($msg)) {
            $stmt = $db->prepare("INSERT INTO tickets (username, subject, message) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user'], $subj, $msg]);
            $_SESSION['last_ticket_time'] = $current_time;
            $ticket_success = "Ticket sent successfully.";
        } else {
            $ticket_error = "Subject and message cannot be empty.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($config['site_title'] ?? 'Potato Net'); ?></title>
    <link rel="icon" type="image/x-icon" href="assets/fav.ico">
    <style>
        * { 
            box-sizing: border-box; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 0; 
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        body { background: url('assets/log.webp') repeat; color: #fff; }
        header { background: url('assets/dark_oak_planks.webp') repeat; border-bottom: 4px solid #1a1a1a; padding: 20px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 15px; }
        header img { width: 48px; height: 48px; image-rendering: pixelated; }
        header h1 { font-size: 32px; text-shadow: 2px 2px #000; color: #ffaa00; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .nav-bar { display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.8); border: 2px solid #555; padding: 15px; border-radius: 8px; margin-bottom: 30px; }
        .nav-links { display: flex; align-items: center; gap: 15px; }
        .nav-links a, .nav-links span.tab-link { color: #55ff55; text-decoration: none; font-weight: bold; cursor: pointer; }
        .nav-links a:hover, .nav-links span.tab-link:hover { text-decoration: underline; }
        .nav-links span.tab-link.active { color: #ffff55; border-bottom: 2px solid #ffff55; padding-bottom: 2px; }
        .ip-copy { background: #333; border: 2px solid #555; padding: 10px 15px; border-radius: 5px; cursor: pointer; color: #ffff55; font-weight: bold; }
        .ip-copy:hover { background: #444; border-color: #aaa; }
        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        @media(max-width: 768px) { .grid { grid-template-columns: 1fr; } }
        .card { background: url('assets/cracked_stone_bricks.webp') repeat; border: 3px solid #333; box-shadow: inset 0 0 10px #000; border-radius: 6px; padding: 20px; margin-bottom: 25px; }
        .card h2 { text-shadow: 2px 2px #000; margin-bottom: 15px; color: #ffff55; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 14px; }
        .form-control { width: 100%; padding: 10px; background: #222; border: 2px solid #555; color: #fff; border-radius: 4px; }
        .form-control:focus { outline: none; border-color: #55ff55; }
        .btn { background: #55ff55; color: #000; border: none; padding: 10px 15px; cursor: pointer; font-weight: bold; border-radius: 4px; display: inline-block; text-decoration: none; text-align: center; }
        .btn:hover { background: #22aa22; color: #fff; }
        .btn-secondary { background: #555; color: #fff; margin-left: 10px; }
        .btn-secondary:hover { background: #444; }
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
        .product-item { background: rgba(0,0,0,0.8); border: 2px solid #555; border-radius: 6px; padding: 15px; text-align: center; }
        .product-item img { max-width: 100%; height: 120px; object-fit: contain; margin-bottom: 10px; }
        .product-item h3 { margin-bottom: 5px; color: #ffaa00; }
        .price { color: #55ff55; font-weight: bold; margin-bottom: 10px; display: block; }
        .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .alert-error { background: #ff5555; color: #000; }
        .alert-success { background: #55ff55; color: #000; }
        .avatar-box { text-align: center; margin-bottom: 20px; }
        .avatar-box img { width: 64px; height: 64px; border-radius: 8px; border: 2px solid #555; image-rendering: pixelated; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .donate-box { text-align: center; padding: 30px 15px; background: rgba(0,0,0,0.6); border-radius: 6px; border: 2px solid #555; }
        .donate-box h3 { color: #ffaa00; font-size: 22px; margin-bottom: 15px; text-shadow: 1px 1px #000; }
        .donate-box p { color: #ccc; margin-bottom: 25px; line-height: 1.6; }
        .top-right-link {
            position: absolute;
            top: 15px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid #aa00aa;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.5);
            transition: transform 0.2s ease, border-color 0.2s ease;
            z-index: 9999;
            display: block;
            background: #000;
        }
        .top-right-link:hover {
            transform: scale(1.1);
            border-color: #ff55ff;
        }
        .top-right-link img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            image-rendering: pixelated;
            display: block;
        }
        #custom-menu ul li:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff !important;
            text-shadow: 1px 1px #000;
        }
    </style>
</head>
<body>

<audio id="stone-sound" src="assets/Stone_dig1.ogg" preload="auto"></audio>

<div id="custom-menu" style="display: none; position: absolute; background: url('assets/dark_oak_planks.webp') repeat; border: 3px solid #1a1a1a; box-shadow: 0 4px 10px rgba(0,0,0,0.8); z-index: 10000; width: 180px; font-family: monospace;">
    <ul style="list-style: none; padding: 5px; margin: 0;">
        <li onclick="window.location.href='index.php'" style="padding: 10px; color: #ffff55; cursor: pointer; border-bottom: 2px solid #1a1a1a;">» Store Home</li>
        <li onclick="window.location.href='<?php echo htmlspecialchars($discord_link); ?>'" style="padding: 10px; color: #aa00aa; text-shadow: 1px 1px #000; font-weight: bold; cursor: pointer; border-bottom: 2px solid #1a1a1a;">» Discord</li>
        <li onclick="copyIP()" style="padding: 10px; color: #55ff55; cursor: pointer; border-bottom: 2px solid #1a1a1a;">» Copy Server IP</li>
        <li onclick="copyCardNumber()" style="padding: 10px; color: #ffaa00; cursor: pointer;">» Copy Card Info</li>
    </ul>
</div>


<header>
    <img src="assets/fav.ico" alt="Logo">
    <h1><?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?></h1>
</header>

<div class="container">
    <div class="nav-bar">
        <div class="nav-links">
            <?php if (isset($_SESSION['user'])): ?>
                <span class="tab-link active" onclick="switchTab('shop-tab', this)">Shop</span> |
                <span class="tab-link" onclick="switchTab('donate-tab', this)">Donate</span> |
                <span class="tab-link" onclick="switchTab('tickets-tab', this)">Tickets</span> |
                <span style="color: #ffaa00; font-weight: normal; margin-left: 5px;">Player: <?php echo htmlspecialchars($_SESSION['user']); ?></span> |
                <a href="checkout.php" style="color: #ffff55;">Cart </a> |
                <a href="index.php?logout=1" style="color: #ff5555;">Logout</a>
            <?php else: ?>
                <a href="index.php">Home</a>
            <?php endif; ?>
        </div>
        <button class="ip-copy" onclick="copyIP()">IP: <?php echo $ip_address; ?></button>
    </div>

    <div class="grid">
        <div class="left-col">
            <?php if (!isset($_SESSION['user'])): ?>
                <div class="card" style="max-width: 500px; margin: 0 auto 30px;">
                    <div class="avatar-box">
                        <img src="assets/steve.webp" alt="Player Skin">
                    </div>
                    <h2>Login</h2>
                    <?php if (isset($error)): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
                    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

                    <?php if (!isset($_SESSION['pending_user'])): ?>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label>Minecraft Username</label>
                                <input type="text" name="username" class="form-control" placeholder="Enter your in-game name" required autocomplete="off">
                            </div>
                            <button type="submit" name="login_step1" class="btn">Send Verification Code</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="">
                            <div class="form-group">
                                <label>Verification Code (8-digit OTP)</label>
                                <input type="text" name="otp_code" class="form-control" placeholder="12345678" required autocomplete="off" autofocus>
                            </div>
                            <button type="submit" name="login_step2" class="btn">Login</button>
                            <a href="index.php?reset_login=1" class="btn btn-secondary">Change Username</a>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                
                <div id="shop-tab" class="tab-content active">
                    <div class="card">
                        <h2>Server Store</h2>
                        <div class="product-grid">
                            <?php
                            $products = $db->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($products as $p):
                            ?>
                                <div class="product-item">
                                    <img src="<?php echo htmlspecialchars($p['image']); ?>" alt="Product">
                                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                                    <span class="price"><?php echo number_format($p['price']); ?> Tomans</span>
                                    <a href="index.php?add_to_cart=<?php echo $p['id']; ?>" class="btn">Add to Cart</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div id="donate-tab" class="tab-content">
                    <div class="card">
                        <h2>Support Potato Net</h2>
                        <div class="donate-box">
                            <h3>Donate & Support</h3>
                            <p>If you want to support our server hosting, development, and help us add more cool features, you can donate directly using the link below.</p>
                            <a href="https://example" target="_blank" class="btn" style="background: #ffaa00; font-size: 16px; padding: 12px 25px;">Go to Daramet Portal</a>
                        </div>
                    </div>
                </div>

                <div id="tickets-tab" class="tab-content">
                    <div class="card">
                        <h2>Support Tickets</h2>
                        <?php if (isset($ticket_success)): ?><div class="alert alert-success"><?php echo $ticket_success; ?></div><?php endif; ?>
                        <?php if (isset($ticket_error)): ?><div class="alert alert-error"><?php echo $ticket_error; ?></div><?php endif; ?>
                        <form method="POST" action="" style="margin-bottom: 20px;">
                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="message" class="form-control" rows="3" required></textarea>
                            </div>
                            <button type="submit" name="send_ticket" class="btn">Open Ticket</button>
                        </form>

                        <table style="width: 100%; border-collapse: collapse; text-align: left; background: rgba(0,0,0,0.5);">
                            <thead>
                                <tr style="background: #222; border-bottom: 2px solid #555;">
                                    <th style="padding: 10px;">Subject</th>
                                    <th style="padding: 10px;">Message</th>
                                    <th style="padding: 10px;">Reply</th>
                                    <th style="padding: 10px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = $db->prepare("SELECT * FROM tickets WHERE username = ? ORDER BY id DESC");
                                $stmt->execute([$_SESSION['user']]);
                                $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($tickets as $t):
                                ?>
                                    <tr style="border-bottom: 1px solid #333;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($t['subject']); ?></td>
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($t['message']); ?></td>
                                        <td style="padding: 10px; color: #ffff55;"><?php echo $t['reply'] ? htmlspecialchars($t['reply']) : '-'; ?></td>
                                        <td style="padding: 10px;"><span style="color: <?php echo $t['status'] == 'open' ? '#ff5555' : '#55ff55'; ?>;"><?php echo $t['status']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="right-col">
            <div class="card">
                <h2>Server Info</h2>
                <p>Welcome to <strong><?php echo htmlspecialchars($config['site_name'] ?? 'Potato Net'); ?> Store</strong>.</p>
                <p style="margin-top: 10px;">Connect at:</p>
                <div style="background: #000; padding: 10px; border-radius: 4px; font-family: monospace; color: #ffff55; margin-top: 5px; text-align: center;">
                    <?php echo htmlspecialchars($ip_address); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyIP() {
    navigator.clipboard.writeText("<?php echo $ip_address; ?>");
    alert("Server IP address copied to clipboard!");
}

function copyCardNumber() {
    const cardNumber = "6037-9918-1234-5678";
    navigator.clipboard.writeText(cardNumber);
    alert("Card number copied to clipboard: " + cardNumber);
}

function switchTab(tabId, element) {
    var contents = document.getElementsByClassName('tab-content');
    for (var i = 0; i < contents.length; i++) {
        contents[i].classList.remove('active');
    }
    
    var links = document.getElementsByClassName('tab-link');
    for (var i = 0; i < links.length; i++) {
        links[i].classList.remove('active');
    }
    
    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
}

const stoneSound = document.getElementById('stone-sound');
const customMenu = document.getElementById('custom-menu');

document.addEventListener('click', function(e) {
    if (e.target.tagName !== 'A' && e.target.tagName !== 'BUTTON' && !e.target.closest('#custom-menu')) {
        stoneSound.currentTime = 0;
        stoneSound.play().catch(err => console.log("Audio play blocked until interaction."));
    }
    customMenu.style.display = 'none';
});

document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    customMenu.style.display = 'block';
    customMenu.style.left = e.pageX + 'px';
    customMenu.style.top = e.pageY + 'px';
});
</script>
</body>
</html>
