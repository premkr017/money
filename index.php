<?php
session_start();

// =============================================
// DATABASE SETUP (SQLite - no MySQL needed)
// =============================================
$db_path = __DIR__ . '/lending.db';
$pdo = new PDO("sqlite:$db_path");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create Tables
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('lend','borrow')),
    amount REAL NOT NULL,
    interest_rate REAL DEFAULT 0,
    interest_type TEXT DEFAULT 'simple' CHECK(interest_type IN ('simple','compound')),
    principal REAL NOT NULL,
    start_date DATE NOT NULL,
    due_date DATE,
    note TEXT,
    status TEXT DEFAULT 'active' CHECK(status IN ('active','partial','settled','overdue')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    payment_date DATE NOT NULL,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(transaction_id) REFERENCES transactions(id)
);

CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER NOT NULL,
    reminder_date DATE NOT NULL,
    message TEXT,
    is_done INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(transaction_id) REFERENCES transactions(id)
);
");

// =============================================
// HELPER FUNCTIONS
// =============================================
function calc_interest($principal, $rate, $start_date, $type = 'simple') {
    if ($rate == 0) return 0;
    $days = max(0, (strtotime(date('Y-m-d')) - strtotime($start_date)) / 86400);
    $years = $days / 365;
    if ($type === 'compound') {
        return round($principal * (pow(1 + $rate/100, $years) - 1), 2);
    }
    return round($principal * ($rate / 100) * $years, 2);
}

function total_paid($pdo, $txn_id) {
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE transaction_id=?");
    $s->execute([$txn_id]);
    return (float)$s->fetchColumn();
}

function format_currency($n) {
    return '₹' . number_format($n, 2);
}

function days_remaining($due_date) {
    if (!$due_date) return null;
    return (int)((strtotime($due_date) - time()) / 86400);
}

// =============================================
// AJAX / FORM ACTIONS
// =============================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$page   = $_GET['page'] ?? 'dashboard';

// Add Person
if ($action === 'add_person') {
    $stmt = $pdo->prepare("INSERT INTO users (name,phone,email,address) VALUES (?,?,?,?)");
    $stmt->execute([trim($_POST['name']), $_POST['phone'], $_POST['email'], $_POST['address']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'व्यक्ति जोड़ा गया!'];
    header("Location: index.php?page=people"); exit;
}

// Edit Person
if ($action === 'edit_person') {
    $stmt = $pdo->prepare("UPDATE users SET name=?,phone=?,email=?,address=? WHERE id=?");
    $stmt->execute([$_POST['name'],$_POST['phone'],$_POST['email'],$_POST['address'],$_POST['id']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'जानकारी अपडेट हुई!'];
    header("Location: index.php?page=people"); exit;
}

// Delete Person
if ($action === 'delete_person' && isset($_GET['id'])) {
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$_GET['id']]);
    $_SESSION['flash'] = ['type'=>'warning','msg'=>'व्यक्ति हटाया गया।'];
    header("Location: index.php?page=people"); exit;
}

// Add Transaction
if ($action === 'add_transaction') {
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id,type,amount,principal,interest_rate,interest_type,start_date,due_date,note,status) VALUES (?,?,?,?,?,?,?,?,?,'active')");
    $amt = (float)$_POST['amount'];
    $stmt->execute([$_POST['user_id'],$_POST['type'],$amt,$amt,$_POST['interest_rate'],$_POST['interest_type'],$_POST['start_date'],$_POST['due_date'],$_POST['note']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'लेन-देन जोड़ा गया!'];
    header("Location: index.php?page=transactions"); exit;
}

// Add Payment
if ($action === 'add_payment') {
    $stmt = $pdo->prepare("INSERT INTO payments (transaction_id,amount,payment_date,note) VALUES (?,?,?,?)");
    $stmt->execute([$_POST['txn_id'],$_POST['amount'],$_POST['payment_date'],$_POST['note']]);
    // Update status
    $txn = $pdo->prepare("SELECT * FROM transactions WHERE id=?");
    $txn->execute([$_POST['txn_id']]);
    $t = $txn->fetch(PDO::FETCH_ASSOC);
    $interest = calc_interest($t['principal'], $t['interest_rate'], $t['start_date'], $t['interest_type']);
    $total_due = $t['principal'] + $interest;
    $paid = total_paid($pdo, $_POST['txn_id']);
    $status = $paid >= $total_due ? 'settled' : ($paid > 0 ? 'partial' : 'active');
    $pdo->prepare("UPDATE transactions SET status=? WHERE id=?")->execute([$status, $_POST['txn_id']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'भुगतान दर्ज हुआ!'];
    header("Location: index.php?page=transactions"); exit;
}

// Add Reminder
if ($action === 'add_reminder') {
    $stmt = $pdo->prepare("INSERT INTO reminders (transaction_id,reminder_date,message) VALUES (?,?,?)");
    $stmt->execute([$_POST['txn_id'],$_POST['reminder_date'],$_POST['message']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'रिमाइंडर सेट हुआ!'];
    header("Location: index.php?page=reminders"); exit;
}

// Mark Reminder Done
if ($action === 'reminder_done' && isset($_GET['id'])) {
    $pdo->prepare("UPDATE reminders SET is_done=1 WHERE id=?")->execute([$_GET['id']]);
    header("Location: index.php?page=reminders"); exit;
}

// Delete Transaction
if ($action === 'delete_txn' && isset($_GET['id'])) {
    $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$_GET['id']]);
    $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$_GET['id']]);
    $_SESSION['flash'] = ['type'=>'warning','msg'=>'लेन-देन हटाया गया।'];
    header("Location: index.php?page=transactions"); exit;
}

// =============================================
// DATA FETCHING
// =============================================
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// Dashboard stats
$total_lent     = $pdo->query("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE type='lend' AND status!='settled'")->fetchColumn();
$total_borrowed = $pdo->query("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE type='borrow' AND status!='settled'")->fetchColumn();
$total_people   = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$overdue_count  = $pdo->query("SELECT COUNT(*) FROM transactions WHERE due_date < date('now') AND status NOT IN ('settled')")->fetchColumn();

// Update overdue status
$pdo->exec("UPDATE transactions SET status='overdue' WHERE due_date < date('now') AND status NOT IN ('settled','overdue')");

$people = $pdo->query("SELECT * FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$transactions = $pdo->query("
    SELECT t.*, u.name as person_name 
    FROM transactions t 
    JOIN users u ON t.user_id = u.id 
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$reminders = $pdo->query("
    SELECT r.*, t.type, u.name as person_name
    FROM reminders r
    JOIN transactions t ON r.transaction_id = t.id
    JOIN users u ON t.user_id = u.id
    ORDER BY r.is_done ASC, r.reminder_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_reminders = array_filter($reminders, fn($r) => !$r['is_done']);

// Filter transactions
$filter_type   = $_GET['filter_type'] ?? 'all';
$filter_status = $_GET['filter_status'] ?? 'all';
$search_query  = strtolower($_GET['search'] ?? '');

$filtered_txns = array_filter($transactions, function($t) use ($filter_type, $filter_status, $search_query) {
    if ($filter_type !== 'all' && $t['type'] !== $filter_type) return false;
    if ($filter_status !== 'all' && $t['status'] !== $filter_status) return false;
    if ($search_query && strpos(strtolower($t['person_name']), $search_query) === false) return false;
    return true;
});

?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>💰 PaisaLedger — लेन-देन प्रबंधन</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg: #0a0e1a;
    --surface: #111827;
    --surface2: #1a2235;
    --surface3: #1e2d45;
    --border: #2a3a55;
    --accent: #f59e0b;
    --accent2: #10b981;
    --danger: #ef4444;
    --lend: #3b82f6;
    --borrow: #8b5cf6;
    --text: #f1f5f9;
    --muted: #94a3b8;
    --radius: 14px;
    --shadow: 0 4px 24px rgba(0,0,0,0.4);
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Baloo 2',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

/* LAYOUT */
.app { display:flex; min-height:100vh; }
.sidebar {
    width: 260px; background:var(--surface); border-right:1px solid var(--border);
    display:flex; flex-direction:column; position:fixed; height:100vh; z-index:100;
    transition: transform 0.3s;
}
.logo {
    padding: 24px 20px 16px;
    font-size:1.4rem; font-weight:800; color:var(--accent);
    border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:10px;
}
.logo span { font-size:1.8rem; }
.nav { padding:16px 0; flex:1; overflow-y:auto; }
.nav a {
    display:flex; align-items:center; gap:12px;
    padding:12px 20px; color:var(--muted); text-decoration:none;
    font-weight:600; font-size:0.95rem; border-left:3px solid transparent;
    transition:all 0.2s;
}
.nav a:hover, .nav a.active { color:var(--text); background:var(--surface2); border-left-color:var(--accent); }
.nav a .icon { width:20px; text-align:center; }
.nav-badge { margin-left:auto; background:var(--danger); color:#fff; padding:2px 7px; border-radius:20px; font-size:0.72rem; }
.sidebar-footer { padding:16px 20px; border-top:1px solid var(--border); font-size:0.78rem; color:var(--muted); text-align:center; }

.main { margin-left:260px; flex:1; display:flex; flex-direction:column; }
.topbar {
    padding:16px 28px; background:var(--surface); border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50;
}
.topbar h1 { font-size:1.3rem; font-weight:700; }
.content { padding:28px; flex:1; }

/* CARDS */
.stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px; }
.stat-card {
    background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
    padding:20px; position:relative; overflow:hidden;
}
.stat-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
}
.stat-card.lend::before { background:var(--lend); }
.stat-card.borrow::before { background:var(--borrow); }
.stat-card.people::before { background:var(--accent2); }
.stat-card.overdue::before { background:var(--danger); }
.stat-label { font-size:0.8rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
.stat-val { font-size:1.7rem; font-weight:800; font-family:'JetBrains Mono',monospace; }
.stat-val.lend { color:var(--lend); }
.stat-val.borrow { color:var(--borrow); }
.stat-val.people { color:var(--accent2); }
.stat-val.overdue { color:var(--danger); }
.stat-icon { position:absolute; top:16px; right:16px; font-size:1.8rem; opacity:0.15; }

/* TABLE */
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.card-header { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.card-title { font-size:1rem; font-weight:700; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
th { background:var(--surface2); color:var(--muted); font-size:0.78rem; text-transform:uppercase; letter-spacing:1px; padding:12px 16px; text-align:left; }
td { padding:13px 16px; border-top:1px solid var(--border); font-size:0.9rem; vertical-align:middle; }
tr:hover td { background:var(--surface2); }

/* BADGES */
.badge {
    padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700;
    display:inline-block; letter-spacing:0.3px;
}
.badge.active   { background:rgba(59,130,246,0.15); color:#60a5fa; }
.badge.settled  { background:rgba(16,185,129,0.15); color:#34d399; }
.badge.partial  { background:rgba(245,158,11,0.15); color:#fbbf24; }
.badge.overdue  { background:rgba(239,68,68,0.15); color:#f87171; }
.badge.lend     { background:rgba(59,130,246,0.15); color:#60a5fa; }
.badge.borrow   { background:rgba(139,92,246,0.15); color:#a78bfa; }

/* BUTTONS */
.btn {
    padding:8px 16px; border-radius:8px; border:none; cursor:pointer;
    font-family:'Baloo 2',sans-serif; font-weight:600; font-size:0.88rem;
    display:inline-flex; align-items:center; gap:6px; transition:all 0.2s; text-decoration:none;
}
.btn-primary { background:var(--accent); color:#000; }
.btn-primary:hover { background:#d97706; }
.btn-success { background:var(--accent2); color:#fff; }
.btn-success:hover { background:#059669; }
.btn-danger  { background:var(--danger); color:#fff; }
.btn-danger:hover { background:#dc2626; }
.btn-ghost { background:var(--surface2); color:var(--text); border:1px solid var(--border); }
.btn-ghost:hover { background:var(--surface3); }
.btn-sm { padding:5px 10px; font-size:0.8rem; }
.btn-icon { padding:6px 10px; }

/* FORMS */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group.full { grid-column:1/-1; }
label { font-size:0.82rem; color:var(--muted); font-weight:600; }
input, select, textarea {
    background:var(--surface2); border:1px solid var(--border); color:var(--text);
    padding:10px 14px; border-radius:8px; font-family:'Baloo 2',sans-serif; font-size:0.9rem;
    transition:border-color 0.2s; outline:none; width:100%;
}
input:focus, select:focus, textarea:focus { border-color:var(--accent); }
textarea { resize:vertical; min-height:80px; }
select option { background:var(--surface); }

/* MODAL */
.modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7);
    z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px);
}
.modal-overlay.show { display:flex; }
.modal {
    background:var(--surface); border:1px solid var(--border); border-radius:18px;
    padding:28px; width:90%; max-width:560px; max-height:90vh; overflow-y:auto;
    box-shadow: 0 25px 80px rgba(0,0,0,0.6);
    animation: modalIn 0.2s ease;
}
@keyframes modalIn { from{transform:scale(0.95);opacity:0} to{transform:scale(1);opacity:1} }
.modal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.modal-title { font-size:1.1rem; font-weight:700; }
.modal-close { background:none; border:none; color:var(--muted); cursor:pointer; font-size:1.2rem; padding:4px; }
.modal-close:hover { color:var(--text); }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }

/* FLASH */
.flash {
    padding:12px 18px; border-radius:10px; margin-bottom:20px;
    display:flex; align-items:center; gap:10px; font-weight:600;
    animation: slideIn 0.3s ease;
}
@keyframes slideIn { from{transform:translateY(-10px);opacity:0} to{transform:translateY(0);opacity:1} }
.flash.success { background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#34d399; }
.flash.warning { background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.3); color:#fbbf24; }
.flash.danger  { background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#f87171; }

/* PROGRESS BAR */
.progress-bar { background:var(--surface3); border-radius:20px; height:6px; overflow:hidden; margin-top:4px; }
.progress-fill { height:100%; border-radius:20px; background:var(--accent2); transition:width 0.3s; }

/* SEARCH & FILTERS */
.filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.filters input { max-width:200px; }
.filters select { max-width:140px; }

/* NET BALANCE */
.net-balance { 
    background:linear-gradient(135deg, var(--surface2), var(--surface3));
    border:1px solid var(--border); border-radius:var(--radius);
    padding:20px 24px; margin-bottom:28px;
    display:flex; align-items:center; justify-content:space-between;
}
.net-label { font-size:0.85rem; color:var(--muted); font-weight:600; }
.net-val { font-size:2rem; font-weight:800; font-family:'JetBrains Mono',monospace; }
.net-val.positive { color:var(--accent2); }
.net-val.negative { color:var(--danger); }

/* PERSON CARD */
.people-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.person-card {
    background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
    padding:20px; transition:border-color 0.2s;
}
.person-card:hover { border-color:var(--accent); }
.person-name { font-size:1.05rem; font-weight:700; margin-bottom:6px; }
.person-info { font-size:0.82rem; color:var(--muted); margin-bottom:4px; }
.person-actions { display:flex; gap:8px; margin-top:14px; }

/* REMINDER CARD */
.reminder-item {
    background:var(--surface); border:1px solid var(--border); border-radius:10px;
    padding:14px 18px; display:flex; align-items:center; gap:14px; margin-bottom:10px;
}
.reminder-item.done { opacity:0.4; }

/* EMPTY STATE */
.empty { text-align:center; padding:48px 20px; color:var(--muted); }
.empty i { font-size:3rem; margin-bottom:12px; display:block; opacity:0.3; }

/* TABS */
.tabs { display:flex; gap:4px; background:var(--surface2); padding:4px; border-radius:10px; margin-bottom:20px; }
.tab { padding:8px 16px; border-radius:7px; cursor:pointer; font-weight:600; font-size:0.88rem; color:var(--muted); border:none; background:none; }
.tab.active { background:var(--accent); color:#000; }

/* RESPONSIVE */
@media(max-width:768px) {
    .sidebar { transform:translateX(-100%); }
    .sidebar.open { transform:translateX(0); }
    .main { margin-left:0; }
    .stat-grid { grid-template-columns:1fr 1fr; }
    .form-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="logo"><span>💰</span> PaisaLedger</div>
    <nav class="nav">
        <a href="?page=dashboard" class="<?= $page==='dashboard'?'active':'' ?>">
            <span class="icon"><i class="fas fa-chart-pie"></i></span> डैशबोर्ड
        </a>
        <a href="?page=transactions" class="<?= $page==='transactions'?'active':'' ?>">
            <span class="icon"><i class="fas fa-exchange-alt"></i></span> लेन-देन
            <?php if($overdue_count > 0): ?><span class="nav-badge"><?= $overdue_count ?></span><?php endif; ?>
        </a>
        <a href="?page=people" class="<?= $page==='people'?'active':'' ?>">
            <span class="icon"><i class="fas fa-users"></i></span> लोग
        </a>
        <a href="?page=reminders" class="<?= $page==='reminders'?'active':'' ?>">
            <span class="icon"><i class="fas fa-bell"></i></span> रिमाइंडर
            <?php if(count($pending_reminders)>0): ?><span class="nav-badge"><?= count($pending_reminders) ?></span><?php endif; ?>
        </a>
        <a href="?page=report" class="<?= $page==='report'?'active':'' ?>">
            <span class="icon"><i class="fas fa-file-alt"></i></span> रिपोर्ट
        </a>
        <a href="?page=calculator" class="<?= $page==='calculator'?'active':'' ?>">
            <span class="icon"><i class="fas fa-calculator"></i></span> ब्याज कैलकुलेटर
        </a>
    </nav>
    <div class="sidebar-footer">PaisaLedger v1.0 &copy; <?= date('Y') ?></div>
</aside>

<!-- MAIN -->
<main class="main">
<div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <button class="btn btn-ghost btn-icon" onclick="document.getElementById('sidebar').classList.toggle('open')" style="display:none" id="menuBtn">
            <i class="fas fa-bars"></i>
        </button>
        <h1>
        <?php
        $titles = ['dashboard'=>'डैशबोर्ड','transactions'=>'लेन-देन','people'=>'लोग',
                   'reminders'=>'रिमाइंडर','report'=>'रिपोर्ट','calculator'=>'ब्याज कैलकुलेटर'];
        echo $titles[$page] ?? 'डैशबोर्ड';
        ?>
        </h1>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <span style="font-size:0.82rem;color:var(--muted)"><i class="fas fa-calendar-alt"></i> <?= date('d M Y') ?></span>
        <?php if($page==='transactions'): ?>
        <button class="btn btn-primary" onclick="openModal('addTxnModal')"><i class="fas fa-plus"></i> नया लेन-देन</button>
        <?php elseif($page==='people'): ?>
        <button class="btn btn-primary" onclick="openModal('addPersonModal')"><i class="fas fa-user-plus"></i> व्यक्ति जोड़ें</button>
        <?php elseif($page==='reminders'): ?>
        <button class="btn btn-primary" onclick="openModal('addReminderModal')"><i class="fas fa-bell"></i> रिमाइंडर जोड़ें</button>
        <?php endif; ?>
    </div>
</div>

<div class="content">
<?php if($flash): ?>
<div class="flash <?= $flash['type'] ?>"><i class="fas fa-check-circle"></i> <?= $flash['msg'] ?></div>
<?php endif; ?>

<!-- =================== DASHBOARD =================== -->
<?php if($page === 'dashboard'): ?>

<div class="stat-grid">
    <div class="stat-card lend">
        <div class="stat-label">मैंने दिया (Lent)</div>
        <div class="stat-val lend"><?= format_currency($total_lent) ?></div>
        <div class="stat-icon"><i class="fas fa-arrow-up-right-dots"></i></div>
    </div>
    <div class="stat-card borrow">
        <div class="stat-label">मैंने लिया (Borrowed)</div>
        <div class="stat-val borrow"><?= format_currency($total_borrowed) ?></div>
        <div class="stat-icon"><i class="fas fa-hand-holding-dollar"></i></div>
    </div>
    <div class="stat-card people">
        <div class="stat-label">कुल लोग</div>
        <div class="stat-val people"><?= $total_people ?></div>
        <div class="stat-icon"><i class="fas fa-users"></i></div>
    </div>
    <div class="stat-card overdue">
        <div class="stat-label">Overdue लेन-देन</div>
        <div class="stat-val overdue"><?= $overdue_count ?></div>
        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
</div>

<?php $net = $total_lent - $total_borrowed; ?>
<div class="net-balance">
    <div>
        <div class="net-label">नेट बैलेंस (आपको मिलना है)</div>
        <div class="net-val <?= $net >= 0 ? 'positive' : 'negative' ?>"><?= format_currency(abs($net)) ?></div>
    </div>
    <div style="font-size:3rem;opacity:0.2"><?= $net >= 0 ? '📈' : '📉' ?></div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <div class="card-header">
        <span class="card-title">हाल के लेन-देन</span>
        <a href="?page=transactions" class="btn btn-ghost btn-sm">सभी देखें <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>व्यक्ति</th><th>प्रकार</th><th>मूल राशि</th><th>ब्याज</th><th>कुल देय</th><th>भुगतान</th><th>स्थिति</th><th>Due Date</th>
        </tr></thead>
        <tbody>
        <?php foreach(array_slice($transactions, 0, 8) as $t):
            $interest = calc_interest($t['principal'], $t['interest_rate'], $t['start_date'], $t['interest_type']);
            $total_due = $t['principal'] + $interest;
            $paid = total_paid($pdo, $t['id']);
            $pct = $total_due > 0 ? min(100, ($paid/$total_due)*100) : 0;
            $dr = days_remaining($t['due_date']);
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($t['person_name']) ?></strong></td>
            <td><span class="badge <?= $t['type'] ?>"><?= $t['type']==='lend'?'🟦 दिया':'🟪 लिया' ?></span></td>
            <td><span style="font-family:monospace"><?= format_currency($t['principal']) ?></span></td>
            <td><span style="color:var(--accent);font-family:monospace"><?= format_currency($interest) ?></span></td>
            <td><strong style="font-family:monospace"><?= format_currency($total_due) ?></strong></td>
            <td>
                <div><?= format_currency($paid) ?></div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td><span class="badge <?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
            <td>
            <?php if(!$t['due_date']): ?>—
            <?php elseif($dr < 0): ?><span style="color:var(--danger)"><?= abs($dr) ?> दिन पहले</span>
            <?php elseif($dr===0): ?><span style="color:var(--accent)">आज</span>
            <?php else: ?><span style="color:var(--accent2)"><?= $dr ?> दिन</span>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($transactions)): ?>
        <tr><td colspan="8" class="empty"><i class="fas fa-inbox"></i>कोई लेन-देन नहीं</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Pending Reminders -->
<?php if(count($pending_reminders) > 0): ?>
<div class="card" style="margin-top:20px">
    <div class="card-header"><span class="card-title">⏰ आज के रिमाइंडर</span></div>
    <div style="padding:16px">
    <?php foreach(array_slice(array_values($pending_reminders), 0, 5) as $r): ?>
    <div class="reminder-item">
        <i class="fas fa-bell" style="color:var(--accent);font-size:1.2rem"></i>
        <div style="flex:1">
            <strong><?= htmlspecialchars($r['person_name']) ?></strong> —
            <?= htmlspecialchars($r['message']) ?>
            <div style="font-size:0.78rem;color:var(--muted);margin-top:2px"><i class="fas fa-calendar"></i> <?= $r['reminder_date'] ?></div>
        </div>
        <a href="?action=reminder_done&id=<?= $r['id'] ?>" class="btn btn-success btn-sm">✓ Done</a>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- =================== TRANSACTIONS =================== -->
<?php elseif($page === 'transactions'): ?>

<div class="card" style="padding:16px;margin-bottom:16px">
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="page" value="transactions">
    <input type="text" name="search" placeholder="🔍 नाम खोजें..." value="<?= htmlspecialchars($search_query) ?>" style="max-width:200px">
    <select name="filter_type">
        <option value="all" <?= $filter_type==='all'?'selected':'' ?>>सभी प्रकार</option>
        <option value="lend" <?= $filter_type==='lend'?'selected':'' ?>>दिया (Lend)</option>
        <option value="borrow" <?= $filter_type==='borrow'?'selected':'' ?>>लिया (Borrow)</option>
    </select>
    <select name="filter_status">
        <option value="all" <?= $filter_status==='all'?'selected':'' ?>>सभी स्थिति</option>
        <option value="active" <?= $filter_status==='active'?'selected':'' ?>>Active</option>
        <option value="partial" <?= $filter_status==='partial'?'selected':'' ?>>Partial</option>
        <option value="settled" <?= $filter_status==='settled'?'selected':'' ?>>Settled</option>
        <option value="overdue" <?= $filter_status==='overdue'?'selected':'' ?>>Overdue</option>
    </select>
    <button class="btn btn-ghost" type="submit"><i class="fas fa-filter"></i> फ़िल्टर</button>
    <a href="?page=transactions" class="btn btn-ghost"><i class="fas fa-times"></i></a>
</form>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">लेन-देन सूची (<?= count($filtered_txns) ?>)</span>
    </div>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>व्यक्ति</th><th>प्रकार</th><th>मूल</th><th>ब्याज दर</th><th>ब्याज</th><th>कुल देय</th><th>भुगतान</th><th>बाकी</th><th>स्थिति</th><th>Due Date</th><th>कार्य</th>
        </tr></thead>
        <tbody>
        <?php foreach($filtered_txns as $t):
            $interest = calc_interest($t['principal'], $t['interest_rate'], $t['start_date'], $t['interest_type']);
            $total_due = $t['principal'] + $interest;
            $paid = total_paid($pdo, $t['id']);
            $remaining = $total_due - $paid;
            $pct = $total_due > 0 ? min(100, ($paid/$total_due)*100) : 0;
            $dr = days_remaining($t['due_date']);
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($t['person_name']) ?></strong>
                <?php if($t['note']): ?><div style="font-size:0.75rem;color:var(--muted)"><?= htmlspecialchars(mb_substr($t['note'],0,30)) ?></div><?php endif; ?>
            </td>
            <td><span class="badge <?= $t['type'] ?>"><?= $t['type']==='lend'?'दिया':'लिया' ?></span></td>
            <td style="font-family:monospace"><?= format_currency($t['principal']) ?></td>
            <td><?= $t['interest_rate'] ?>% <span style="font-size:0.7rem;color:var(--muted)">(<?= $t['interest_type'] ?>)</span></td>
            <td style="color:var(--accent);font-family:monospace"><?= format_currency($interest) ?></td>
            <td style="font-family:monospace;font-weight:700"><?= format_currency($total_due) ?></td>
            <td>
                <span style="font-family:monospace"><?= format_currency($paid) ?></span>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $t['status']==='overdue'?'var(--danger)':'' ?>"></div></div>
            </td>
            <td style="font-family:monospace;color:<?= $remaining > 0 ? 'var(--danger)' : 'var(--accent2)' ?>"><?= format_currency($remaining) ?></td>
            <td><span class="badge <?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
            <td>
            <?php if($t['due_date']): ?>
                <?php if($dr < 0): ?>
                    <span style="color:var(--danger);font-size:0.82rem"><?= abs($dr) ?> दिन पहले<br><small><?= $t['due_date'] ?></small></span>
                <?php elseif($dr===0): ?>
                    <span style="color:var(--accent)">आज</span>
                <?php else: ?>
                    <span style="color:var(--accent2);font-size:0.82rem"><?= $dr ?> दिन<br><small><?= $t['due_date'] ?></small></span>
                <?php endif; ?>
            <?php else: ?>
                —
            <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:5px">
                <?php if($t['status']!=='settled'): ?>
                <button class="btn btn-success btn-icon btn-sm" title="भुगतान दर्ज करें" onclick="openPayModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['person_name']) ?>', <?= $remaining ?>)"><i class="fas fa-money-bill"></i></button>
                <?php endif; ?>
                <a href="?action=delete_txn&id=<?= $t['id'] ?>" class="btn btn-danger btn-icon btn-sm" title="हटाएं" onclick="return confirm('क्या आप यह लेन-देन हटाना चाहते हैं?')"><i class="fas fa-trash"></i></a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($filtered_txns)): ?>
        <tr><td colspan="11" class="empty"><i class="fas fa-inbox"></i>कोई लेन-देन नहीं मिला</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- =================== PEOPLE =================== -->
<?php elseif($page === 'people'): ?>

<div class="people-grid">
<?php foreach($people as $p):
    $lent_to = $pdo->prepare("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE user_id=? AND type='lend' AND status!='settled'");
    $lent_to->execute([$p['id']]); $lt = $lent_to->fetchColumn();
    $borr_from = $pdo->prepare("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE user_id=? AND type='borrow' AND status!='settled'");
    $borr_from->execute([$p['id']]); $bf = $borr_from->fetchColumn();
?>
<div class="person-card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--lend),var(--borrow));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem">
            <?= mb_strtoupper(mb_substr($p['name'],0,1)) ?>
        </div>
        <div>
            <div class="person-name"><?= htmlspecialchars($p['name']) ?></div>
            <?php if($p['phone']): ?><div class="person-info"><i class="fas fa-phone fa-xs"></i> <?= $p['phone'] ?></div><?php endif; ?>
        </div>
    </div>
    <?php if($p['email']): ?><div class="person-info"><i class="fas fa-envelope fa-xs"></i> <?= $p['email'] ?></div><?php endif; ?>
    <?php if($p['address']): ?><div class="person-info"><i class="fas fa-map-marker-alt fa-xs"></i> <?= htmlspecialchars($p['address']) ?></div><?php endif; ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px">
        <div style="background:rgba(59,130,246,0.1);border-radius:8px;padding:8px;text-align:center">
            <div style="font-size:0.7rem;color:var(--muted)">इन्हें दिया</div>
            <div style="font-family:monospace;font-weight:700;color:var(--lend);font-size:0.9rem"><?= format_currency($lt) ?></div>
        </div>
        <div style="background:rgba(139,92,246,0.1);border-radius:8px;padding:8px;text-align:center">
            <div style="font-size:0.7rem;color:var(--muted)">इनसे लिया</div>
            <div style="font-family:monospace;font-weight:700;color:var(--borrow);font-size:0.9rem"><?= format_currency($bf) ?></div>
        </div>
    </div>
    <div class="person-actions">
        <button class="btn btn-ghost btn-sm" onclick="openEditPerson(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', '<?= $p['phone'] ?>', '<?= $p['email'] ?>', '<?= htmlspecialchars(addslashes($p['address'])) ?>')"><i class="fas fa-edit"></i> संपादित</button>
        <a href="?action=delete_person&id=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('हटाना चाहते हैं?')"><i class="fas fa-trash"></i></a>
    </div>
</div>
<?php endforeach; ?>
<?php if(empty($people)): ?>
<div class="empty" style="grid-column:1/-1"><i class="fas fa-user-slash"></i>कोई व्यक्ति नहीं जोड़ा गया</div>
<?php endif; ?>
</div>

<!-- =================== REMINDERS =================== -->
<?php elseif($page === 'reminders'): ?>

<div class="tabs">
    <button class="tab active" onclick="filterReminders('all')">सभी</button>
    <button class="tab" onclick="filterReminders('pending')">बाकी</button>
    <button class="tab" onclick="filterReminders('done')">पूरे</button>
</div>

<?php foreach($reminders as $r): ?>
<div class="reminder-item <?= $r['is_done']?'done':'' ?>" data-status="<?= $r['is_done']?'done':'pending' ?>">
    <i class="fas fa-bell fa-lg" style="color:<?= $r['is_done']?'var(--muted)':'var(--accent)' ?>"></i>
    <div style="flex:1">
        <strong><?= htmlspecialchars($r['person_name']) ?></strong>
        <span class="badge <?= $r['type'] ?>" style="margin-left:8px"><?= $r['type']==='lend'?'दिया':'लिया' ?></span>
        <div><?= htmlspecialchars($r['message']) ?></div>
        <div style="font-size:0.78rem;color:var(--muted);margin-top:4px"><i class="fas fa-calendar"></i> <?= $r['reminder_date'] ?></div>
    </div>
    <?php if(!$r['is_done']): ?>
    <a href="?action=reminder_done&id=<?= $r['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Done</a>
    <?php else: ?>
    <span class="badge settled">✓ पूरा</span>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if(empty($reminders)): ?>
<div class="empty"><i class="fas fa-bell-slash"></i>कोई रिमाइंडर नहीं</div>
<?php endif; ?>

<!-- =================== REPORT =================== -->
<?php elseif($page === 'report'): ?>

<?php
$month = $_GET['month'] ?? date('Y-m');
$month_txns = array_filter($transactions, fn($t) => substr($t['start_date'],0,7) === $month);
$month_lent = array_sum(array_column(array_filter($month_txns, fn($t)=>$t['type']==='lend'), 'principal'));
$month_borr = array_sum(array_column(array_filter($month_txns, fn($t)=>$t['type']==='borrow'), 'principal'));

// Person-wise summary
$person_summary = [];
foreach($transactions as $t) {
    $pid = $t['user_id'];
    if(!isset($person_summary[$pid])) $person_summary[$pid] = ['name'=>$t['person_name'],'lent'=>0,'borrowed'=>0,'net'=>0];
    $interest = calc_interest($t['principal'], $t['interest_rate'], $t['start_date'], $t['interest_type']);
    $total_due = $t['principal'] + $interest;
    $paid = total_paid($pdo, $t['id']);
    $remaining = $total_due - $paid;
    if($t['type']==='lend' && $t['status']!=='settled') $person_summary[$pid]['lent'] += $remaining;
    if($t['type']==='borrow' && $t['status']!=='settled') $person_summary[$pid]['borrowed'] += $remaining;
}
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">
    <div class="card" style="padding:20px">
        <div class="stat-label">इस महीने का लेन-देन</div>
        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="page" value="report">
            <input type="month" name="month" value="<?= $month ?>" onchange="this.form.submit()" style="margin-top:8px">
        </form>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div style="background:rgba(59,130,246,0.1);padding:14px;border-radius:10px">
                <div style="font-size:0.75rem;color:var(--muted)">दिया</div>
                <div style="font-family:monospace;font-size:1.2rem;font-weight:800;color:var(--lend)"><?= format_currency($month_lent) ?></div>
            </div>
            <div style="background:rgba(139,92,246,0.1);padding:14px;border-radius:10px">
                <div style="font-size:0.75rem;color:var(--muted)">लिया</div>
                <div style="font-family:monospace;font-size:1.2rem;font-weight:800;color:var(--borrow)"><?= format_currency($month_borr) ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding:20px">
        <div class="stat-label">कुल सारांश</div>
        <table style="width:100%;margin-top:12px">
            <tr><td style="color:var(--muted);font-size:0.85rem">कुल active लेन</td><td style="text-align:right;font-family:monospace;color:var(--lend)"><?= format_currency($total_lent) ?></td></tr>
            <tr><td style="color:var(--muted);font-size:0.85rem">कुल active देन</td><td style="text-align:right;font-family:monospace;color:var(--borrow)"><?= format_currency($total_borrowed) ?></td></tr>
            <tr><td style="color:var(--muted);font-size:0.85rem;border-top:1px solid var(--border);padding-top:8px">नेट बैलेंस</td>
            <td style="text-align:right;font-family:monospace;font-weight:800;border-top:1px solid var(--border);padding-top:8px;color:<?= ($total_lent-$total_borrowed)>=0?'var(--accent2)':'var(--danger)' ?>"><?= format_currency($total_lent-$total_borrowed) ?></td></tr>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title">व्यक्ति-वार बाकी राशि</span></div>
    <div class="table-wrap">
    <table>
        <thead><tr><th>व्यक्ति</th><th>उन्हें देना है (आपका बाकी)</th><th>आपको देना है (उनका बाकी)</th><th>नेट</th></tr></thead>
        <tbody>
        <?php foreach($person_summary as $ps):
            if($ps['lent']==0 && $ps['borrowed']==0) continue;
            $net = $ps['lent'] - $ps['borrowed'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($ps['name']) ?></strong></td>
            <td style="font-family:monospace;color:var(--lend)"><?= format_currency($ps['lent']) ?></td>
            <td style="font-family:monospace;color:var(--borrow)"><?= format_currency($ps['borrowed']) ?></td>
            <td style="font-family:monospace;font-weight:700;color:<?= $net>=0?'var(--accent2)':'var(--danger)' ?>"><?= format_currency(abs($net)) ?> <?= $net>=0?'(लेना है)':'(देना है)' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- =================== CALCULATOR =================== -->
<?php elseif($page === 'calculator'): ?>

<div style="max-width:600px;margin:0 auto">
<div class="card" style="padding:28px">
    <h2 style="margin-bottom:20px;font-size:1.1rem">ब्याज कैलकुलेटर</h2>
    <div class="form-grid">
        <div class="form-group">
            <label>मूल राशि (₹)</label>
            <input type="number" id="c_principal" value="10000" oninput="calcInterest()">
        </div>
        <div class="form-group">
            <label>ब्याज दर (% प्रति वर्ष)</label>
            <input type="number" id="c_rate" value="12" step="0.1" oninput="calcInterest()">
        </div>
        <div class="form-group">
            <label>अवधि</label>
            <input type="number" id="c_time" value="12" oninput="calcInterest()">
        </div>
        <div class="form-group">
            <label>अवधि का प्रकार</label>
            <select id="c_unit" onchange="calcInterest()">
                <option value="months">महीने</option>
                <option value="years">वर्ष</option>
                <option value="days">दिन</option>
            </select>
        </div>
        <div class="form-group full">
            <label>ब्याज का प्रकार</label>
            <select id="c_type" onchange="calcInterest()">
                <option value="simple">Simple Interest (साधारण ब्याज)</option>
                <option value="compound">Compound Interest (चक्रवृद्धि ब्याज)</option>
            </select>
        </div>
    </div>
    <div style="background:var(--surface2);border-radius:12px;padding:24px;margin-top:20px">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;text-align:center">
            <div>
                <div style="font-size:0.75rem;color:var(--muted);margin-bottom:4px">मूल राशि</div>
                <div id="r_principal" style="font-family:monospace;font-size:1.2rem;font-weight:800;color:var(--lend)">₹10,000</div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--muted);margin-bottom:4px">ब्याज</div>
                <div id="r_interest" style="font-family:monospace;font-size:1.2rem;font-weight:800;color:var(--accent)">₹1,200</div>
            </div>
            <div>
                <div style="font-size:0.75rem;color:var(--muted);margin-bottom:4px">कुल राशि</div>
                <div id="r_total" style="font-family:monospace;font-size:1.2rem;font-weight:800;color:var(--accent2)">₹11,200</div>
            </div>
        </div>
    </div>
</div>
</div>

<?php endif; ?>
</div><!-- content -->
</main>
</div><!-- app -->

<!-- =================== MODALS =================== -->

<!-- Add Transaction Modal -->
<div class="modal-overlay" id="addTxnModal">
<div class="modal">
    <div class="modal-header">
        <span class="modal-title">💸 नया लेन-देन जोड़ें</span>
        <button class="modal-close" onclick="closeModal('addTxnModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
    <input type="hidden" name="action" value="add_transaction">
    <div class="form-grid">
        <div class="form-group">
            <label>व्यक्ति चुनें *</label>
            <select name="user_id" required>
                <option value="">— चुनें —</option>
                <?php foreach($people as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>प्रकार *</label>
            <select name="type" required>
                <option value="lend">🟦 मैंने दिया (Lend)</option>
                <option value="borrow">🟪 मैंने लिया (Borrow)</option>
            </select>
        </div>
        <div class="form-group">
            <label>राशि (₹) *</label>
            <input type="number" name="amount" step="0.01" required placeholder="जैसे: 5000">
        </div>
        <div class="form-group">
            <label>ब्याज दर (% प्रति वर्ष)</label>
            <input type="number" name="interest_rate" step="0.1" value="0" placeholder="0 = कोई ब्याज नहीं">
        </div>
        <div class="form-group">
            <label>ब्याज प्रकार</label>
            <select name="interest_type">
                <option value="simple">Simple Interest</option>
                <option value="compound">Compound Interest</option>
            </select>
        </div>
        <div class="form-group">
            <label>शुरू की तारीख *</label>
            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label>Due Date (वापसी की तारीख)</label>
            <input type="date" name="due_date">
        </div>
        <div class="form-group full">
            <label>नोट</label>
            <textarea name="note" placeholder="कोई विशेष जानकारी..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addTxnModal')">रद्द करें</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> जोड़ें</button>
    </div>
    </form>
</div>
</div>

<!-- Add Person Modal -->
<div class="modal-overlay" id="addPersonModal">
<div class="modal">
    <div class="modal-header">
        <span class="modal-title">👤 नया व्यक्ति जोड़ें</span>
        <button class="modal-close" onclick="closeModal('addPersonModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
    <input type="hidden" name="action" value="add_person">
    <div class="form-grid">
        <div class="form-group full">
            <label>नाम *</label>
            <input type="text" name="name" required placeholder="जैसे: Ramesh Kumar">
        </div>
        <div class="form-group">
            <label>फ़ोन नंबर</label>
            <input type="tel" name="phone" placeholder="9876543210">
        </div>
        <div class="form-group">
            <label>ईमेल</label>
            <input type="email" name="email" placeholder="ramesh@example.com">
        </div>
        <div class="form-group full">
            <label>पता</label>
            <textarea name="address" placeholder="गली, मोहल्ला, शहर..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addPersonModal')">रद्द करें</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> जोड़ें</button>
    </div>
    </form>
</div>
</div>

<!-- Edit Person Modal -->
<div class="modal-overlay" id="editPersonModal">
<div class="modal">
    <div class="modal-header">
        <span class="modal-title">✏️ व्यक्ति संपादित करें</span>
        <button class="modal-close" onclick="closeModal('editPersonModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
    <input type="hidden" name="action" value="edit_person">
    <input type="hidden" name="id" id="edit_person_id">
    <div class="form-grid">
        <div class="form-group full">
            <label>नाम *</label>
            <input type="text" name="name" id="edit_person_name" required>
        </div>
        <div class="form-group">
            <label>फ़ोन</label>
            <input type="tel" name="phone" id="edit_person_phone">
        </div>
        <div class="form-group">
            <label>ईमेल</label>
            <input type="email" name="email" id="edit_person_email">
        </div>
        <div class="form-group full">
            <label>पता</label>
            <textarea name="address" id="edit_person_address"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('editPersonModal')">रद्द करें</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> सहेजें</button>
    </div>
    </form>
</div>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="payModal">
<div class="modal">
    <div class="modal-header">
        <span class="modal-title">💰 भुगतान दर्ज करें</span>
        <button class="modal-close" onclick="closeModal('payModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
    <input type="hidden" name="action" value="add_payment">
    <input type="hidden" name="txn_id" id="pay_txn_id">
    <div class="form-grid">
        <div class="form-group full">
            <label>व्यक्ति</label>
            <input type="text" id="pay_person_name" readonly style="background:var(--surface3)">
        </div>
        <div class="form-group">
            <label>भुगतान राशि (₹) *</label>
            <input type="number" name="amount" id="pay_amount" step="0.01" required>
        </div>
        <div class="form-group">
            <label>भुगतान की तारीख *</label>
            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group full">
            <label>नोट</label>
            <textarea name="note" placeholder="Cash / UPI / Cheque..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('payModal')">रद्द करें</button>
        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> भुगतान दर्ज करें</button>
    </div>
    </form>
</div>
</div>

<!-- Add Reminder Modal -->
<div class="modal-overlay" id="addReminderModal">
<div class="modal">
    <div class="modal-header">
        <span class="modal-title">⏰ रिमाइंडर जोड़ें</span>
        <button class="modal-close" onclick="closeModal('addReminderModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="post">
    <input type="hidden" name="action" value="add_reminder">
    <div class="form-grid">
        <div class="form-group full">
            <label>लेन-देन चुनें *</label>
            <select name="txn_id" required>
                <option value="">— चुनें —</option>
                <?php foreach($transactions as $t): if($t['status']==='settled') continue; ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['person_name']) ?> — <?= $t['type']==='lend'?'दिया':'लिया' ?> — <?= format_currency($t['principal']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>रिमाइंडर की तारीख *</label>
            <input type="date" name="reminder_date" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
        </div>
        <div class="form-group full">
            <label>संदेश</label>
            <textarea name="message" placeholder="जैसे: EMI याद दिलाएं, वापस मांगें..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addReminderModal')">रद्द करें</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-bell"></i> सेट करें</button>
    </div>
    </form>
</div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if(e.target===m) m.classList.remove('show'); }));

function openPayModal(id, name, remaining) {
    document.getElementById('pay_txn_id').value = id;
    document.getElementById('pay_person_name').value = name;
    document.getElementById('pay_amount').value = remaining.toFixed(2);
    openModal('payModal');
}

function openEditPerson(id, name, phone, email, address) {
    document.getElementById('edit_person_id').value = id;
    document.getElementById('edit_person_name').value = name;
    document.getElementById('edit_person_phone').value = phone;
    document.getElementById('edit_person_email').value = email;
    document.getElementById('edit_person_address').value = address;
    openModal('editPersonModal');
}

function filterReminders(type) {
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    event.target.classList.add('active');
    document.querySelectorAll('.reminder-item').forEach(item => {
        if(type==='all') item.style.display='flex';
        else item.style.display = item.dataset.status===type?'flex':'none';
    });
}

function calcInterest() {
    const p = parseFloat(document.getElementById('c_principal').value) || 0;
    const r = parseFloat(document.getElementById('c_rate').value) || 0;
    const t = parseFloat(document.getElementById('c_time').value) || 0;
    const unit = document.getElementById('c_unit').value;
    const type = document.getElementById('c_type').value;
    let years = unit==='years'? t : unit==='months'? t/12 : t/365;
    let interest;
    if(type==='compound') interest = p * (Math.pow(1 + r/100, years) - 1);
    else interest = p * (r/100) * years;
    const fmt = n => '₹' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('r_principal').textContent = fmt(p);
    document.getElementById('r_interest').textContent = fmt(interest);
    document.getElementById('r_total').textContent = fmt(p + interest);
}
calcInterest();

// Mobile menu
if(window.innerWidth <= 768) document.getElementById('menuBtn').style.display='flex';
window.addEventListener('resize', () => {
    document.getElementById('menuBtn').style.display = window.innerWidth<=768?'flex':'none';
});
</script>
</body>
</html>