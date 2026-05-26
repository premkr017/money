<?php
session_start();

// ── CONFIG ──────────────────────────────────────────────
define('OWNER_NAME',    'Ritik Row');
define('COMPANY_NAME',  'Prem Enterprises');
define('COMPANY_TAGLINE','Financial Services & Lending Solutions');
define('COMPANY_CITY',  'Patna, Bihar');
define('COMPANY_PHONE', '+91 98765 43210');
define('COMPANY_EMAIL', 'rolexritikrow@gmail.com');
define('COMPANY_GST',   'GST: 10XXXXX1234X1ZX');
define('LOGIN_EMAIL',   'rolexritikrow@gmail.com');
define('LOGIN_PASS',    'Prem@2025');          // Change this password!

// ── DATABASE ────────────────────────────────────────────
$pdo = new PDO('sqlite:' . __DIR__ . '/prem.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode=WAL");
$pdo->exec("
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT,
    email TEXT,
    address TEXT,
    aadhar TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    txn_no TEXT UNIQUE,
    contact_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK(type IN('lend','borrow')),
    principal REAL NOT NULL,
    interest_rate REAL DEFAULT 0,
    interest_type TEXT DEFAULT 'simple',
    start_date DATE NOT NULL,
    due_date DATE,
    note TEXT,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(contact_id) REFERENCES contacts(id)
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pay_no TEXT UNIQUE,
    transaction_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    payment_date DATE NOT NULL,
    mode TEXT DEFAULT 'Cash',
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(transaction_id) REFERENCES transactions(id)
);
CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER,
    reminder_date DATE,
    message TEXT,
    is_done INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// ── HELPERS ─────────────────────────────────────────────
function fc($n){ return '₹'.number_format((float)$n,2); }

function calc_interest($principal,$rate,$start,$type='simple'){
    if(!$rate) return 0;
    $days = max(0,(strtotime(date('Y-m-d'))-strtotime($start))/86400);
    $y = $days/365;
    return $type==='compound'
        ? round($principal*(pow(1+$rate/100,$y)-1),2)
        : round($principal*($rate/100)*$y,2);
}

function total_paid($pdo,$tid){
    $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE transaction_id=?");
    $s->execute([$tid]); return (float)$s->fetchColumn();
}

function next_txn_no($pdo){
    $n=$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
    return 'PE-TXN-'.str_pad($n+1,5,'0',STR_PAD_LEFT);
}
function next_pay_no($pdo){
    $n=$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    return 'PE-PAY-'.str_pad($n+1,5,'0',STR_PAD_LEFT);
}

// ── AUTH ────────────────────────────────────────────────
$page   = $_GET['page'] ?? 'dashboard';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if($action==='login'){
    if($_POST['email']===LOGIN_EMAIL && $_POST['password']===LOGIN_PASS){
        $_SESSION['logged_in']=true;
        $_SESSION['user_email']=LOGIN_EMAIL;
        header("Location: index.php"); exit;
    } else { $login_error='गलत Email या Password!'; }
}
if($action==='logout'){ session_destroy(); header("Location: index.php"); exit; }

$logged_in = !empty($_SESSION['logged_in']);
if(!$logged_in && $action!=='login'){ $page='login'; }

// ── ACTIONS ─────────────────────────────────────────────
if($logged_in){

if($action==='add_contact'){
    $pdo->prepare("INSERT INTO contacts(name,phone,email,address,aadhar) VALUES(?,?,?,?,?)")
        ->execute([trim($_POST['name']),$_POST['phone'],$_POST['email'],$_POST['address'],$_POST['aadhar']]);
    $_SESSION['flash']=['s','संपर्क जोड़ा गया!'];
    header("Location: index.php?page=contacts"); exit;
}
if($action==='edit_contact'){
    $pdo->prepare("UPDATE contacts SET name=?,phone=?,email=?,address=?,aadhar=? WHERE id=?")
        ->execute([$_POST['name'],$_POST['phone'],$_POST['email'],$_POST['address'],$_POST['aadhar'],$_POST['id']]);
    $_SESSION['flash']=['s','अपडेट हो गया!']; header("Location: index.php?page=contacts"); exit;
}
if($action==='del_contact' && isset($_GET['id'])){
    $pdo->prepare("DELETE FROM contacts WHERE id=?")->execute([$_GET['id']]);
    header("Location: index.php?page=contacts"); exit;
}

if($action==='add_txn'){
    $no=next_txn_no($pdo);
    $amt=(float)$_POST['amount'];
    $pdo->prepare("INSERT INTO transactions(txn_no,contact_id,type,principal,interest_rate,interest_type,start_date,due_date,note,status) VALUES(?,?,?,?,?,?,?,?,?,'active')")
        ->execute([$no,$_POST['contact_id'],$_POST['type'],$amt,$_POST['interest_rate'],$_POST['interest_type'],$_POST['start_date'],$_POST['due_date'],$_POST['note']]);
    $tid=$pdo->lastInsertId();
    $_SESSION['flash']=['s','लेन-देन जोड़ा गया!'];
    if(isset($_POST['print_slip'])){ header("Location: index.php?page=slip&tid=$tid"); exit; }
    header("Location: index.php?page=transactions"); exit;
}
if($action==='del_txn' && isset($_GET['id'])){
    $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$_GET['id']]);
    $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$_GET['id']]);
    header("Location: index.php?page=transactions"); exit;
}

if($action==='add_payment'){
    $no=next_pay_no($pdo);
    $pdo->prepare("INSERT INTO payments(pay_no,transaction_id,amount,payment_date,mode,note) VALUES(?,?,?,?,?,?)")
        ->execute([$no,$_POST['txn_id'],(float)$_POST['amount'],$_POST['payment_date'],$_POST['mode'],$_POST['note']]);
    // update status
    $t=$pdo->prepare("SELECT * FROM transactions WHERE id=?"); $t->execute([$_POST['txn_id']]); $tx=$t->fetch(PDO::FETCH_ASSOC);
    $interest=calc_interest($tx['principal'],$tx['interest_rate'],$tx['start_date'],$tx['interest_type']);
    $due=$tx['principal']+$interest;
    $paid=total_paid($pdo,$_POST['txn_id']);
    $st=$paid>=$due?'settled':($paid>0?'partial':'active');
    $pdo->prepare("UPDATE transactions SET status=? WHERE id=?")->execute([$st,$_POST['txn_id']]);
    $pid=$pdo->query("SELECT id FROM payments ORDER BY id DESC LIMIT 1")->fetchColumn();
    $_SESSION['flash']=['s','भुगतान दर्ज हुआ!'];
    if(isset($_POST['print_slip'])){ header("Location: index.php?page=pay_slip&pid=$pid"); exit; }
    header("Location: index.php?page=transactions"); exit;
}

if($action==='add_reminder'){
    $pdo->prepare("INSERT INTO reminders(transaction_id,reminder_date,message) VALUES(?,?,?)")
        ->execute([$_POST['txn_id'],$_POST['reminder_date'],$_POST['message']]);
    $_SESSION['flash']=['s','रिमाइंडर सेट हुआ!']; header("Location: index.php?page=reminders"); exit;
}
if($action==='done_reminder' && isset($_GET['id'])){
    $pdo->prepare("UPDATE reminders SET is_done=1 WHERE id=?")->execute([$_GET['id']]);
    header("Location: index.php?page=reminders"); exit;
}

// auto overdue
$pdo->exec("UPDATE transactions SET status='overdue' WHERE due_date < date('now') AND status NOT IN('settled','overdue')");

} // end logged_in

// ── DATA ────────────────────────────────────────────────
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
if($logged_in){
    $contacts = $pdo->query("SELECT * FROM contacts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $txns     = $pdo->query("SELECT t.*,c.name as cname,c.phone as cphone FROM transactions t JOIN contacts c ON t.contact_id=c.id ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $reminders= $pdo->query("SELECT r.*,c.name as cname FROM reminders r LEFT JOIN transactions t ON r.transaction_id=t.id LEFT JOIN contacts c ON t.contact_id=c.id ORDER BY r.is_done ASC, r.reminder_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    $pending_rem = array_filter($reminders, fn($r)=>!$r['is_done']);

    $total_lent    = $pdo->query("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE type='lend' AND status!='settled'")->fetchColumn();
    $total_borrowed= $pdo->query("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE type='borrow' AND status!='settled'")->fetchColumn();
    $overdue_count = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='overdue'")->fetchColumn();
    $settled_count = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status='settled'")->fetchColumn();
    $total_people  = count($contacts);

    // For slip page
    if($page==='slip' && isset($_GET['tid'])){
        $slip_s=$pdo->prepare("SELECT t.*,c.name as cname,c.phone as cphone,c.address as caddr,c.aadhar as caadhar FROM transactions t JOIN contacts c ON t.contact_id=c.id WHERE t.id=?");
        $slip_s->execute([$_GET['tid']]); $slip=$slip_s->fetch(PDO::FETCH_ASSOC);
    }
    if($page==='pay_slip' && isset($_GET['pid'])){
        $ps_s=$pdo->prepare("SELECT p.*,t.txn_no,t.principal,t.interest_rate,t.type,c.name as cname,c.phone as cphone,c.address as caddr FROM payments p JOIN transactions t ON p.transaction_id=t.id JOIN contacts c ON t.contact_id=c.id WHERE p.id=?");
        $ps_s->execute([$_GET['pid']]); $pay_slip=$ps_s->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prem Enterprises — Finance Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ═══════════════════ CSS VARIABLES ═══════════════════ */
:root{
  --navy:#0B1D3A; --navy2:#112244; --navy3:#1a2f5a;
  --gold:#C9A84C; --gold2:#e8c96a; --gold3:#f5e3b0;
  --cream:#F8F5EE; --cream2:#EEE8DA;
  --green:#1A7F5A; --green2:#22a370; --red:#C0392B; --red2:#e74c3c;
  --white:#FFFFFF; --text:#1a1a2e; --muted:#6b7280;
  --border:#D4C5A0; --shadow:0 8px 32px rgba(11,29,58,0.15);
  --radius:12px; --radius2:20px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);min-height:100vh}

/* ═══════════════════ LOGIN PAGE ═══════════════════════ */
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 50%,#0d2847 100%);
  position:relative;overflow:hidden}
.login-bg-pattern{position:absolute;inset:0;opacity:0.04;
  background-image:repeating-linear-gradient(45deg,var(--gold) 0,var(--gold) 1px,transparent 0,transparent 50%);
  background-size:30px 30px}
.login-card{background:var(--white);border-radius:24px;padding:48px 44px;width:100%;max-width:440px;
  box-shadow:0 40px 80px rgba(0,0,0,0.4);position:relative;z-index:2;border:1px solid rgba(201,168,76,0.2)}
.login-logo{text-align:center;margin-bottom:36px}
.login-logo .emblem{width:72px;height:72px;background:linear-gradient(135deg,var(--gold),var(--gold2));
  border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
  font-size:1.8rem;margin-bottom:14px;box-shadow:0 8px 24px rgba(201,168,76,0.4)}
.login-logo h1{font-family:'Playfair Display',serif;font-size:1.8rem;color:var(--navy);font-weight:700}
.login-logo p{font-size:0.82rem;color:var(--muted);margin-top:4px;letter-spacing:1px;text-transform:uppercase}
.login-divider{height:1px;background:linear-gradient(90deg,transparent,var(--gold),transparent);margin:24px 0}
.login-form .field{margin-bottom:18px}
.login-form label{display:block;font-size:0.82rem;font-weight:600;color:var(--navy);margin-bottom:7px;letter-spacing:0.3px}
.login-form input{width:100%;padding:13px 16px;border:1.5px solid var(--border);border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:0.95rem;background:var(--cream);color:var(--text);transition:all 0.2s;outline:none}
.login-form input:focus{border-color:var(--gold);background:var(--white);box-shadow:0 0 0 3px rgba(201,168,76,0.12)}
.login-btn{width:100%;padding:14px;background:linear-gradient(135deg,var(--navy),var(--navy2));
  color:var(--gold2);border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:1rem;
  font-weight:700;cursor:pointer;letter-spacing:0.5px;transition:all 0.3s;margin-top:8px}
.login-btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(11,29,58,0.4)}
.login-error{background:#fef2f2;border:1px solid #fecaca;color:var(--red);padding:10px 14px;
  border-radius:8px;font-size:0.85rem;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.login-footer{text-align:center;margin-top:20px;font-size:0.78rem;color:var(--muted)}

/* ═══════════════════ APP LAYOUT ═══════════════════════ */
.app{display:flex;min-height:100vh}

/* SIDEBAR */
.sidebar{width:270px;background:linear-gradient(180deg,var(--navy) 0%,var(--navy2) 100%);
  position:fixed;height:100vh;display:flex;flex-direction:column;z-index:200;
  box-shadow:4px 0 24px rgba(0,0,0,0.2)}
.sidebar-brand{padding:28px 24px 20px;border-bottom:1px solid rgba(201,168,76,0.2)}
.brand-emblem{width:44px;height:44px;background:linear-gradient(135deg,var(--gold),var(--gold2));
  border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:10px}
.brand-name{font-family:'Playfair Display',serif;font-size:1.15rem;color:var(--white);font-weight:700;line-height:1.2}
.brand-sub{font-size:0.7rem;color:rgba(201,168,76,0.7);letter-spacing:1.5px;text-transform:uppercase;margin-top:2px}
.sidebar-user{padding:14px 20px;display:flex;align-items:center;gap:10px;
  background:rgba(201,168,76,0.08);border-bottom:1px solid rgba(201,168,76,0.15)}
.user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold2));
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;color:var(--navy);flex-shrink:0}
.user-info .name{font-size:0.85rem;color:var(--white);font-weight:600}
.user-info .role{font-size:0.72rem;color:rgba(201,168,76,0.7)}

.nav{padding:16px 0;flex:1;overflow-y:auto}
.nav-section{padding:8px 20px 4px;font-size:0.65rem;color:rgba(201,168,76,0.5);letter-spacing:2px;text-transform:uppercase;font-weight:600}
.nav a{display:flex;align-items:center;gap:12px;padding:11px 20px;color:rgba(255,255,255,0.6);
  text-decoration:none;font-size:0.9rem;font-weight:500;border-left:3px solid transparent;transition:all 0.2s;margin:1px 0}
.nav a:hover,.nav a.active{color:var(--white);background:rgba(201,168,76,0.12);border-left-color:var(--gold)}
.nav a .ni{width:20px;text-align:center;font-size:0.95rem}
.nav-badge{margin-left:auto;background:var(--red2);color:#fff;padding:1px 7px;border-radius:20px;font-size:0.7rem;font-weight:700}
.gold-badge{background:rgba(201,168,76,0.25);color:var(--gold2)}

.sidebar-bottom{padding:16px 20px;border-top:1px solid rgba(201,168,76,0.15)}
.sidebar-bottom a{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.5);font-size:0.82rem;text-decoration:none;padding:8px;border-radius:8px}
.sidebar-bottom a:hover{color:var(--white);background:rgba(255,255,255,0.05)}

/* MAIN */
.main{margin-left:270px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:var(--white);border-bottom:1px solid var(--cream2);padding:0 32px;
  height:68px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,0.05)}
.topbar-left h2{font-size:1.25rem;font-weight:700;color:var(--navy)}
.topbar-left p{font-size:0.78rem;color:var(--muted)}
.topbar-right{display:flex;align-items:center;gap:12px}
.content{padding:28px 32px;flex:1}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--white);border:1px solid var(--cream2);border-radius:var(--radius2);
  padding:20px 20px 16px;position:relative;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:20px 20px 0 0}
.sc-lend::after{background:linear-gradient(90deg,#1d6fa4,#2196f3)}
.sc-borrow::after{background:linear-gradient(90deg,#7b1fa2,#9c27b0)}
.sc-net::after{background:linear-gradient(90deg,var(--green),var(--green2))}
.sc-over::after{background:linear-gradient(90deg,var(--red),var(--red2))}
.sc-people::after{background:linear-gradient(90deg,var(--gold),var(--gold2))}
.sc-label{font-size:0.72rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}
.sc-val{font-size:1.5rem;font-weight:800;font-family:'JetBrains Mono',monospace;color:var(--navy)}
.sc-sub{font-size:0.72rem;color:var(--muted);margin-top:4px}
.sc-icon{position:absolute;top:14px;right:16px;font-size:1.6rem;opacity:0.1;color:var(--navy)}

/* NET BALANCE BANNER */
.net-banner{background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:var(--radius2);
  padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:var(--shadow);border:1px solid rgba(201,168,76,0.2)}
.net-banner-left .label{font-size:0.78rem;color:rgba(201,168,76,0.7);letter-spacing:1px;text-transform:uppercase;margin-bottom:6px}
.net-banner-left .val{font-size:2.4rem;font-weight:800;font-family:'JetBrains Mono',monospace;color:var(--gold2)}
.net-banner-left .sub{font-size:0.82rem;color:rgba(255,255,255,0.4);margin-top:4px}
.net-banner-right{font-size:4rem;opacity:0.15}

/* CARDS */
.card{background:var(--white);border:1px solid var(--cream2);border-radius:var(--radius2);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
.card-header{padding:18px 22px;border-bottom:1px solid var(--cream2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.card-title{font-size:0.95rem;font-weight:700;color:var(--navy)}

/* TABLE */
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{background:var(--cream);color:var(--muted);font-size:0.72rem;text-transform:uppercase;letter-spacing:1px;padding:11px 16px;text-align:left;white-space:nowrap}
td{padding:13px 16px;border-top:1px solid var(--cream2);font-size:0.875rem;vertical-align:middle}
tr:hover td{background:#fafaf8}

/* BADGES */
.badge{padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;display:inline-block;white-space:nowrap}
.b-active  {background:#dbeafe;color:#1d4ed8}
.b-settled {background:#d1fae5;color:#065f46}
.b-partial {background:#fef3c7;color:#92400e}
.b-overdue {background:#fee2e2;color:#991b1b}
.b-lend    {background:#dbeafe;color:#1e40af}
.b-borrow  {background:#ede9fe;color:#5b21b6}

/* BUTTONS */
.btn{padding:9px 18px;border-radius:9px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;
  font-weight:600;font-size:0.875rem;display:inline-flex;align-items:center;gap:7px;transition:all 0.2s;text-decoration:none;white-space:nowrap}
.btn-primary{background:linear-gradient(135deg,var(--navy),var(--navy2));color:var(--gold2)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(11,29,58,0.3)}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold2));color:var(--navy);font-weight:700}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(201,168,76,0.4)}
.btn-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.btn-success:hover{background:#a7f3d0}
.btn-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.btn-danger:hover{background:#fecaca}
.btn-ghost{background:var(--cream);color:var(--navy);border:1px solid var(--cream2)}
.btn-ghost:hover{background:var(--cream2)}
.btn-sm{padding:5px 12px;font-size:0.8rem}
.btn-icon{padding:7px 11px}

/* FORMS */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fg{display:flex;flex-direction:column;gap:6px}
.fg.full{grid-column:1/-1}
label{font-size:0.8rem;color:var(--navy);font-weight:600;letter-spacing:0.2px}
input,select,textarea{background:var(--cream);border:1.5px solid var(--border);color:var(--text);
  padding:10px 14px;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:0.9rem;
  transition:border-color 0.2s;outline:none;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--gold);background:var(--white);box-shadow:0 0 0 3px rgba(201,168,76,0.1)}
textarea{resize:vertical;min-height:70px}

/* MODAL */
.overlay{display:none;position:fixed;inset:0;background:rgba(11,29,58,0.6);z-index:1000;
  align-items:center;justify-content:center;backdrop-filter:blur(6px)}
.overlay.show{display:flex}
.modal{background:var(--white);border-radius:20px;padding:28px;width:90%;max-width:580px;
  max-height:92vh;overflow-y:auto;box-shadow:0 30px 80px rgba(0,0,0,0.3);
  animation:mIn 0.25s ease;border:1px solid var(--cream2)}
@keyframes mIn{from{transform:scale(0.94) translateY(12px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--cream2)}
.modal-title{font-size:1.05rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px}
.mclose{background:none;border:none;font-size:1.1rem;color:var(--muted);cursor:pointer;padding:4px;border-radius:6px}
.mclose:hover{background:var(--cream);color:var(--navy)}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid var(--cream2)}

/* FLASH */
.flash{padding:12px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-weight:600;font-size:0.9rem;animation:sIn 0.3s ease}
@keyframes sIn{from{transform:translateY(-8px);opacity:0}to{transform:translateY(0);opacity:1}}
.flash.s{background:#d1fae5;border:1px solid #a7f3d0;color:#065f46}
.flash.e{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}

/* PROGRESS */
.pbar{background:var(--cream2);border-radius:20px;height:5px;overflow:hidden;margin-top:4px}
.pfill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--green),var(--green2))}
.pfill.over{background:linear-gradient(90deg,var(--red),var(--red2))}

/* PEOPLE GRID */
.pg{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px}
.pcard{background:var(--white);border:1px solid var(--cream2);border-radius:var(--radius2);padding:20px;transition:all 0.2s;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
.pcard:hover{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 8px 24px rgba(201,168,76,0.15)}
.pavatar{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,var(--navy),var(--navy2));
  display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;color:var(--gold2);flex-shrink:0}

/* FILTER BAR */
.filter-bar{background:var(--white);border:1px solid var(--cream2);border-radius:var(--radius);
  padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filter-bar input,.filter-bar select{max-width:180px}

/* REMINDER */
.rem-item{background:var(--white);border:1px solid var(--cream2);border-radius:12px;
  padding:14px 18px;display:flex;align-items:center;gap:14px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,0.04)}
.rem-item.done{opacity:0.45}

/* EMPTY */
.empty{text-align:center;padding:48px 20px;color:var(--muted)}
.empty i{font-size:2.5rem;margin-bottom:12px;display:block;opacity:0.3}

/* ═══════════════════ SLIP / PRINT ══════════════════════ */
@media print{
  .sidebar,.topbar,.no-print,.overlay{display:none!important}
  .main{margin-left:0}
  .content{padding:0}
  body{background:white}
  .slip-wrap{box-shadow:none!important;border:none!important;max-width:100%!important}
}
.slip-wrap{max-width:680px;margin:0 auto;background:var(--white);border-radius:16px;
  box-shadow:0 8px 40px rgba(0,0,0,0.12);overflow:hidden;border:1px solid var(--cream2)}
.slip-header{background:linear-gradient(135deg,var(--navy),var(--navy2));padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;gap:16px}
.slip-brand h1{font-family:'Playfair Display',serif;color:var(--gold2);font-size:1.5rem;font-weight:700}
.slip-brand p{color:rgba(201,168,76,0.6);font-size:0.75rem;margin-top:2px;letter-spacing:0.5px}
.slip-logo-box{width:54px;height:54px;background:rgba(201,168,76,0.2);border:2px solid rgba(201,168,76,0.4);
  border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.6rem}
.slip-type-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:0.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px}
.slip-body{padding:28px 32px}
.slip-no-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;
  padding-bottom:16px;border-bottom:2px dashed var(--cream2)}
.slip-no{font-family:'JetBrains Mono',monospace;font-size:0.82rem;color:var(--muted)}
.slip-no strong{color:var(--navy);font-size:0.95rem}
.slip-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.slip-field{padding:12px 0;border-bottom:1px solid var(--cream2)}
.slip-field:nth-child(odd){padding-right:24px;border-right:1px solid var(--cream2)}
.slip-field:nth-child(even){padding-left:24px}
.sf-label{font-size:0.72rem;color:var(--muted);font-weight:600;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:4px}
.sf-val{font-size:0.95rem;color:var(--navy);font-weight:600}
.sf-val.mono{font-family:'JetBrains Mono',monospace;font-size:1rem}
.sf-val.big{font-size:1.3rem;font-weight:800;color:var(--green)}
.sf-val.big.pay{color:var(--red)}
.slip-total-box{background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:14px;
  padding:18px 24px;margin:20px 0;display:flex;align-items:center;justify-content:space-between}
.slip-total-box .tl{color:rgba(201,168,76,0.7);font-size:0.8rem;text-transform:uppercase;letter-spacing:1px}
.slip-total-box .tv{font-family:'JetBrains Mono',monospace;font-size:1.6rem;color:var(--gold2);font-weight:800}
.slip-note{background:var(--cream);border-radius:10px;padding:14px 16px;margin:16px 0;font-size:0.85rem;color:var(--muted);font-style:italic}
.slip-footer{background:var(--cream);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;border-top:2px solid var(--cream2)}
.slip-footer .auth{text-align:right}
.slip-footer .auth .line{width:120px;height:1px;background:var(--navy);margin:0 0 4px auto}
.slip-footer .auth small{font-size:0.72rem;color:var(--muted)}
.slip-watermark{position:relative}
.slip-watermark::after{content:'PREM ENTERPRISES';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);
  font-size:3rem;font-weight:800;color:rgba(11,29,58,0.04);white-space:nowrap;pointer-events:none;letter-spacing:4px}

/* Utility */
.row{display:flex;gap:10px;align-items:center}
.ml-auto{margin-left:auto}
.mt16{margin-top:16px}
.mt24{margin-top:24px}
.text-muted{color:var(--muted);font-size:0.82rem}
.gold-text{color:var(--gold)}
</style>
</head>
<body>

<?php if($page==='login'): ?>
<!-- ═══════════ LOGIN PAGE ═══════════ -->
<div class="login-page">
  <div class="login-bg-pattern"></div>
  <div class="login-card">
    <div class="login-logo">
      <div class="emblem">🏦</div>
      <h1>Prem Enterprises</h1>
      <p>Finance & Lending Portal</p>
    </div>
    <div class="login-divider"></div>
    <?php if(!empty($login_error)): ?>
    <div class="login-error"><i class="fas fa-exclamation-circle"></i><?= $login_error ?></div>
    <?php endif; ?>
    <form class="login-form" method="post">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label><i class="fas fa-envelope" style="margin-right:6px;color:var(--gold)"></i>Email Address</label>
        <input type="email" name="email" placeholder="your@email.com" value="<?= LOGIN_EMAIL ?>" required>
      </div>
      <div class="field">
        <label><i class="fas fa-lock" style="margin-right:6px;color:var(--gold)"></i>Password</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button class="login-btn" type="submit">
        <i class="fas fa-sign-in-alt"></i> Secure Login
      </button>
    </form>
    <div class="login-footer">
      🔒 Secured Portal &nbsp;|&nbsp; <?= COMPANY_NAME ?> &copy; <?= date('Y') ?>
    </div>
  </div>
</div>

<?php elseif($page==='slip' && $logged_in && !empty($slip)): ?>
<!-- ═══════════ LOAN SLIP ═══════════ -->
<div class="app">
<aside class="sidebar"><?php include_once(''); ?></aside>
<?php
$interest = calc_interest($slip['principal'], $slip['interest_rate'], $slip['start_date'], $slip['interest_type']);
$total_due = $slip['principal'] + $interest;
?>
<main class="main">
<div class="topbar">
  <div class="topbar-left"><h2>📄 Loan Slip</h2><p><?= $slip['txn_no'] ?></p></div>
  <div class="topbar-right">
    <button class="btn btn-gold no-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
    <a href="index.php?page=transactions" class="btn btn-ghost no-print"><i class="fas fa-arrow-left"></i> वापस</a>
  </div>
</div>
<div class="content">
<div class="slip-wrap slip-watermark">
  <div class="slip-header">
    <div class="slip-brand">
      <h1><?= COMPANY_NAME ?></h1>
      <p><?= COMPANY_TAGLINE ?></p>
      <p style="margin-top:4px;color:rgba(201,168,76,0.5);font-size:0.72rem"><?= COMPANY_CITY ?> &nbsp;|&nbsp; <?= COMPANY_EMAIL ?></p>
    </div>
    <div class="slip-logo-box">🏦</div>
  </div>
  <div class="slip-body">
    <div class="slip-no-row">
      <div>
        <span class="slip-type-badge" style="background:<?= $slip['type']==='lend'?'#dbeafe':'#ede9fe' ?>;color:<?= $slip['type']==='lend'?'#1e40af':'#5b21b6' ?>">
          <?= $slip['type']==='lend'?'💙 LOAN ISSUED':'💜 AMOUNT RECEIVED' ?>
        </span>
        <div class="slip-no">Transaction No: <strong><?= $slip['txn_no'] ?></strong></div>
      </div>
      <div class="slip-no" style="text-align:right">
        Date: <strong><?= date('d M Y', strtotime($slip['start_date'])) ?></strong><br>
        <span style="font-size:0.72rem">Generated: <?= date('d M Y, h:i A') ?></span>
      </div>
    </div>

    <div class="slip-grid">
      <div class="slip-field">
        <div class="sf-label"><?= $slip['type']==='lend'?'Borrower Name':'Lender Name' ?></div>
        <div class="sf-val"><?= htmlspecialchars($slip['cname']) ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Contact No.</div>
        <div class="sf-val"><?= $slip['cphone'] ?: '—' ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Principal Amount</div>
        <div class="sf-val mono big <?= $slip['type']==='borrow'?'pay':'' ?>"><?= fc($slip['principal']) ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Interest Rate</div>
        <div class="sf-val"><?= $slip['interest_rate'] ?>% p.a. <span style="color:var(--muted);font-size:0.8rem">(<?= ucfirst($slip['interest_type']) ?>)</span></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Start Date</div>
        <div class="sf-val"><?= date('d M Y', strtotime($slip['start_date'])) ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Due Date</div>
        <div class="sf-val"><?= $slip['due_date'] ? date('d M Y', strtotime($slip['due_date'])) : 'Not specified' ?></div>
      </div>
      <?php if($slip['caddr']): ?>
      <div class="slip-field full" style="grid-column:1/-1;border-right:none;padding-right:0">
        <div class="sf-label">Address</div>
        <div class="sf-val"><?= htmlspecialchars($slip['caddr']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="slip-total-box">
      <div><div class="tl">Total Amount Due (with interest)</div><div style="color:rgba(255,255,255,0.4);font-size:0.75rem;margin-top:2px">Principal + Accrued Interest</div></div>
      <div class="tv"><?= fc($total_due) ?></div>
    </div>

    <?php if($slip['note']): ?>
    <div class="slip-note"><i class="fas fa-sticky-note" style="margin-right:6px"></i><?= htmlspecialchars($slip['note']) ?></div>
    <?php endif; ?>

    <div style="background:var(--cream);border-radius:10px;padding:12px 16px;font-size:0.78rem;color:var(--muted);border:1px dashed var(--border)">
      <i class="fas fa-info-circle" style="color:var(--gold)"></i>
      यह एक आधिकारिक लेन-देन रसीद है। <?= COMPANY_NAME ?> द्वारा जारी।
    </div>
  </div>
  <div class="slip-footer">
    <div style="font-size:0.78rem;color:var(--muted)">
      <strong style="color:var(--navy)"><?= COMPANY_NAME ?></strong><br>
      <?= COMPANY_CITY ?><br><?= COMPANY_EMAIL ?>
    </div>
    <div class="auth">
      <div class="line"></div>
      <small>Authorised Signatory</small><br>
      <small style="color:var(--navy);font-weight:600"><?= OWNER_NAME ?></small>
    </div>
  </div>
</div>
</div>
</main>
</div>

<?php elseif($page==='pay_slip' && $logged_in && !empty($pay_slip)): ?>
<!-- ═══════════ PAYMENT RECEIPT SLIP ═══════════ -->
<div class="app">
<main class="main" style="margin-left:0">
<div class="topbar">
  <div class="topbar-left"><h2>🧾 Payment Receipt</h2><p><?= $pay_slip['pay_no'] ?></p></div>
  <div class="topbar-right">
    <button class="btn btn-gold no-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
    <a href="index.php?page=transactions" class="btn btn-ghost no-print"><i class="fas fa-arrow-left"></i> वापस</a>
  </div>
</div>
<div class="content">
<div class="slip-wrap slip-watermark">
  <div class="slip-header">
    <div class="slip-brand">
      <h1><?= COMPANY_NAME ?></h1>
      <p>Payment Receipt — Official Document</p>
      <p style="margin-top:4px;color:rgba(201,168,76,0.5);font-size:0.72rem"><?= COMPANY_CITY ?> &nbsp;|&nbsp; <?= COMPANY_EMAIL ?></p>
    </div>
    <div class="slip-logo-box">🧾</div>
  </div>
  <div class="slip-body">
    <div class="slip-no-row">
      <div>
        <span class="slip-type-badge" style="background:#d1fae5;color:#065f46">✅ PAYMENT RECEIVED</span>
        <div class="slip-no">Receipt No: <strong><?= $pay_slip['pay_no'] ?></strong></div>
        <div class="slip-no">Against Txn: <strong><?= $pay_slip['txn_no'] ?></strong></div>
      </div>
      <div class="slip-no" style="text-align:right">
        Date: <strong><?= date('d M Y', strtotime($pay_slip['payment_date'])) ?></strong><br>
        <span style="font-size:0.72rem">Generated: <?= date('d M Y, h:i A') ?></span>
      </div>
    </div>

    <div class="slip-grid">
      <div class="slip-field">
        <div class="sf-label">Party Name</div>
        <div class="sf-val"><?= htmlspecialchars($pay_slip['cname']) ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Contact No.</div>
        <div class="sf-val"><?= $pay_slip['cphone'] ?: '—' ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Loan Principal</div>
        <div class="sf-val mono"><?= fc($pay_slip['principal']) ?></div>
      </div>
      <div class="slip-field">
        <div class="sf-label">Payment Mode</div>
        <div class="sf-val"><?= htmlspecialchars($pay_slip['mode']) ?></div>
      </div>
      <?php if($pay_slip['caddr']): ?>
      <div class="slip-field" style="grid-column:1/-1;border-right:none;padding-right:0">
        <div class="sf-label">Address</div>
        <div class="sf-val"><?= htmlspecialchars($pay_slip['caddr']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="slip-total-box">
      <div><div class="tl">Amount Paid</div><div style="color:rgba(255,255,255,0.4);font-size:0.75rem;margin-top:2px">This payment only</div></div>
      <div class="tv"><?= fc($pay_slip['amount']) ?></div>
    </div>

    <?php if($pay_slip['note']): ?>
    <div class="slip-note"><i class="fas fa-sticky-note" style="margin-right:6px"></i><?= htmlspecialchars($pay_slip['note']) ?></div>
    <?php endif; ?>

    <div style="background:var(--cream);border-radius:10px;padding:12px 16px;font-size:0.78rem;color:var(--muted);border:1px dashed var(--border)">
      <i class="fas fa-check-circle" style="color:var(--green)"></i>
      यह एक आधिकारिक भुगतान रसीद है। <?= COMPANY_NAME ?> द्वारा जारी। कृपया इसे सुरक्षित रखें।
    </div>
  </div>
  <div class="slip-footer">
    <div style="font-size:0.78rem;color:var(--muted)">
      <strong style="color:var(--navy)"><?= COMPANY_NAME ?></strong><br>
      <?= COMPANY_CITY ?><br><?= COMPANY_EMAIL ?>
    </div>
    <div class="auth">
      <div class="line"></div>
      <small>Authorised Signatory</small><br>
      <small style="color:var(--navy);font-weight:600"><?= OWNER_NAME ?></small>
    </div>
  </div>
</div>
</div>
</main>
</div>

<?php elseif($logged_in): ?>
<!-- ═══════════ MAIN APP ═══════════ -->
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-emblem">🏦</div>
    <div class="brand-name"><?= COMPANY_NAME ?></div>
    <div class="brand-sub">Finance Portal</div>
  </div>
  <div class="sidebar-user">
    <div class="user-avatar">R</div>
    <div class="user-info">
      <div class="name"><?= OWNER_NAME ?></div>
      <div class="role">Administrator</div>
    </div>
  </div>
  <nav class="nav">
    <div class="nav-section">Main</div>
    <a href="?page=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><span class="ni"><i class="fas fa-th-large"></i></span> Dashboard</a>
    <a href="?page=transactions" class="<?= $page==='transactions'?'active':'' ?>"><span class="ni"><i class="fas fa-exchange-alt"></i></span> Transactions <?php if($overdue_count>0): ?><span class="nav-badge"><?= $overdue_count ?></span><?php endif; ?></a>
    <a href="?page=contacts" class="<?= $page==='contacts'?'active':'' ?>"><span class="ni"><i class="fas fa-address-book"></i></span> Contacts</a>
    <div class="nav-section">Tools</div>
    <a href="?page=reminders" class="<?= $page==='reminders'?'active':'' ?>"><span class="ni"><i class="fas fa-bell"></i></span> Reminders <?php if(count($pending_rem)>0): ?><span class="nav-badge gold-badge"><?= count($pending_rem) ?></span><?php endif; ?></a>
    <a href="?page=report" class="<?= $page==='report'?'active':'' ?>"><span class="ni"><i class="fas fa-chart-bar"></i></span> Reports</a>
    <a href="?page=calculator" class="<?= $page==='calculator'?'active':'' ?>"><span class="ni"><i class="fas fa-calculator"></i></span> Calculator</a>
  </nav>
  <div class="sidebar-bottom">
    <a href="?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<!-- MAIN CONTENT -->
<main class="main">
<div class="topbar">
  <div class="topbar-left">
    <h2><?php $ts=['dashboard'=>'Dashboard','transactions'=>'Transactions','contacts'=>'Contacts','reminders'=>'Reminders','report'=>'Reports','calculator'=>'Calculator']; echo $ts[$page]??'Dashboard'; ?></h2>
    <p><?= COMPANY_NAME ?> &nbsp;•&nbsp; <?= date('l, d F Y') ?></p>
  </div>
  <div class="topbar-right">
    <?php if($page==='transactions'): ?>
    <button class="btn btn-gold" onclick="om('addTxn')"><i class="fas fa-plus"></i> New Transaction</button>
    <?php elseif($page==='contacts'): ?>
    <button class="btn btn-primary" onclick="om('addContact')"><i class="fas fa-user-plus"></i> Add Contact</button>
    <?php elseif($page==='reminders'): ?>
    <button class="btn btn-primary" onclick="om('addReminder')"><i class="fas fa-bell"></i> Set Reminder</button>
    <?php endif; ?>
  </div>
</div>

<div class="content">
<?php if($flash): ?>
<div class="flash <?= $flash[0] ?>"><i class="fas fa-<?= $flash[0]==='s'?'check-circle':'exclamation-circle' ?>"></i><?= $flash[1] ?></div>
<?php endif; ?>

<!-- ───── DASHBOARD ───── -->
<?php if($page==='dashboard'): ?>

<div class="stat-grid">
  <div class="stat-card sc-lend">
    <div class="sc-label">Total Lent Out</div>
    <div class="sc-val"><?= fc($total_lent) ?></div>
    <div class="sc-sub"><?= $pdo->query("SELECT COUNT(*) FROM transactions WHERE type='lend' AND status!='settled'")->fetchColumn() ?> active loans</div>
    <div class="sc-icon"><i class="fas fa-arrow-up"></i></div>
  </div>
  <div class="stat-card sc-borrow">
    <div class="sc-label">Total Borrowed</div>
    <div class="sc-val"><?= fc($total_borrowed) ?></div>
    <div class="sc-sub"><?= $pdo->query("SELECT COUNT(*) FROM transactions WHERE type='borrow' AND status!='settled'")->fetchColumn() ?> active</div>
    <div class="sc-icon"><i class="fas fa-arrow-down"></i></div>
  </div>
  <div class="stat-card sc-net">
    <div class="sc-label">Net Receivable</div>
    <?php $net=$total_lent-$total_borrowed; ?>
    <div class="sc-val" style="color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= fc(abs($net)) ?></div>
    <div class="sc-sub"><?= $net>=0?'You will receive':'You owe' ?></div>
    <div class="sc-icon"><i class="fas fa-balance-scale"></i></div>
  </div>
  <div class="stat-card sc-over">
    <div class="sc-label">Overdue</div>
    <div class="sc-val" style="color:var(--red)"><?= $overdue_count ?></div>
    <div class="sc-sub">Need attention</div>
    <div class="sc-icon"><i class="fas fa-exclamation-triangle"></i></div>
  </div>
  <div class="stat-card sc-people">
    <div class="sc-label">Total Contacts</div>
    <div class="sc-val"><?= $total_people ?></div>
    <div class="sc-sub"><?= $settled_count ?> settled</div>
    <div class="sc-icon"><i class="fas fa-users"></i></div>
  </div>
</div>

<div class="net-banner">
  <div class="net-banner-left">
    <div class="label">Current Net Position</div>
    <div class="val"><?= fc(abs($net)) ?></div>
    <div class="sub"><?= $net>=0?'You are owed this amount':'You owe this amount' ?></div>
  </div>
  <div class="net-banner-right"><?= $net>=0?'📈':'📉' ?></div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Recent Transactions</span>
    <a href="?page=transactions" class="btn btn-ghost btn-sm">View All <i class="fas fa-arrow-right"></i></a>
  </div>
  <div class="tw">
  <table>
    <thead><tr><th>Party</th><th>Type</th><th>Principal</th><th>Interest</th><th>Total Due</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach(array_slice($txns,0,10) as $t):
      $int=calc_interest($t['principal'],$t['interest_rate'],$t['start_date'],$t['interest_type']);
      $due=$t['principal']+$int; $paid=total_paid($pdo,$t['id']); $bal=$due-$paid;
      $pct=min(100,$due>0?($paid/$due)*100:0);
      $dr=$t['due_date']?(int)((strtotime($t['due_date'])-time())/86400):null;
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($t['cname']) ?></strong><div class="text-muted"><?= $t['cphone'] ?></div></td>
      <td><span class="badge <?= $t['type']==='lend'?'b-lend':'b-borrow' ?>"><?= $t['type']==='lend'?'Lent':'Borrowed' ?></span></td>
      <td style="font-family:monospace"><?= fc($t['principal']) ?></td>
      <td style="font-family:monospace;color:var(--gold)"><?= fc($int) ?></td>
      <td style="font-family:monospace;font-weight:700"><?= fc($due) ?></td>
      <td><div style="font-family:monospace"><?= fc($paid) ?></div><div class="pbar"><div class="pfill <?= $t['status']==='overdue'?'over':'' ?>" style="width:<?= $pct ?>%"></div></div></td>
      <td style="font-family:monospace;color:<?= $bal>0?'var(--red)':'var(--green)' ?>;font-weight:700"><?= fc($bal) ?></td>
      <td><span class="badge b-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
      <td class="text-muted"><?php if($dr!==null): ?><?= $dr<0?'<span style="color:var(--red)">'.abs($dr).'d ago</span>':($dr===0?'<span style="color:var(--gold)">Today</span>':'<span style="color:var(--green)">'.$dr.'d</span>') ?><?php else: ?>—<?php endif; ?></td>
      <td>
        <div class="row">
          <a href="?page=slip&tid=<?= $t['id'] ?>" class="btn btn-ghost btn-sm btn-icon" title="Loan Slip"><i class="fas fa-file-alt"></i></a>
          <?php if($t['status']!=='settled'): ?>
          <button class="btn btn-success btn-sm btn-icon" title="Add Payment" onclick="openPay(<?= $t['id'] ?>,'<?= addslashes($t['cname']) ?>',<?= $bal ?>)"><i class="fas fa-money-bill-wave"></i></button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($txns)): ?><tr><td colspan="10" class="empty"><i class="fas fa-inbox"></i>No transactions yet</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if(count($pending_rem)>0): ?>
<div class="card mt24">
  <div class="card-header"><span class="card-title">⏰ Upcoming Reminders</span></div>
  <div style="padding:16px">
    <?php foreach(array_slice(array_values($pending_rem),0,4) as $r): ?>
    <div class="rem-item">
      <i class="fas fa-bell" style="color:var(--gold);font-size:1.1rem"></i>
      <div style="flex:1"><strong><?= htmlspecialchars($r['cname']??'') ?></strong> — <?= htmlspecialchars($r['message']) ?><div class="text-muted"><i class="fas fa-calendar"></i> <?= $r['reminder_date'] ?></div></div>
      <a href="?action=done_reminder&id=<?= $r['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i></a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ───── TRANSACTIONS ───── -->
<?php elseif($page==='transactions'): ?>

<div class="filter-bar">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%">
    <input type="hidden" name="page" value="transactions">
    <input type="text" name="s" placeholder="🔍 Search party..." value="<?= htmlspecialchars($_GET['s']??'') ?>" style="max-width:200px">
    <select name="ft"><option value="">All Types</option><option value="lend" <?= ($_GET['ft']??'')==='lend'?'selected':'' ?>>Lent Out</option><option value="borrow" <?= ($_GET['ft']??'')==='borrow'?'selected':'' ?>>Borrowed</option></select>
    <select name="fs"><option value="">All Status</option><option value="active">Active</option><option value="partial">Partial</option><option value="settled">Settled</option><option value="overdue">Overdue</option></select>
    <button class="btn btn-ghost" type="submit"><i class="fas fa-filter"></i> Filter</button>
    <a href="?page=transactions" class="btn btn-ghost"><i class="fas fa-times"></i></a>
    <button class="btn btn-gold ml-auto" type="button" onclick="om('addTxn')"><i class="fas fa-plus"></i> New Transaction</button>
  </form>
</div>

<?php
$ft=$_GET['ft']??''; $fs=$_GET['fs']??''; $s=strtolower($_GET['s']??'');
$ftxns=array_filter($txns,fn($t)=>
  (!$ft||$t['type']===$ft)&&(!$fs||$t['status']===$fs)&&(!$s||strpos(strtolower($t['cname']),$s)!==false)
);
?>

<div class="card">
  <div class="card-header"><span class="card-title">All Transactions (<?= count($ftxns) ?>)</span></div>
  <div class="tw">
  <table>
    <thead><tr><th>Txn No</th><th>Party</th><th>Type</th><th>Principal</th><th>Rate</th><th>Interest</th><th>Total Due</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($ftxns as $t):
      $int=calc_interest($t['principal'],$t['interest_rate'],$t['start_date'],$t['interest_type']);
      $due=$t['principal']+$int; $paid=total_paid($pdo,$t['id']); $bal=$due-$paid;
      $pct=min(100,$due>0?($paid/$due)*100:0);
      $dr=$t['due_date']?(int)((strtotime($t['due_date'])-time())/86400):null;
    ?>
    <tr>
      <td style="font-family:monospace;font-size:0.78rem;color:var(--muted)"><?= $t['txn_no'] ?></td>
      <td><strong><?= htmlspecialchars($t['cname']) ?></strong><div class="text-muted"><?= $t['cphone'] ?></div></td>
      <td><span class="badge <?= $t['type']==='lend'?'b-lend':'b-borrow' ?>"><?= $t['type']==='lend'?'Lent':'Borrowed' ?></span></td>
      <td style="font-family:monospace"><?= fc($t['principal']) ?></td>
      <td><?= $t['interest_rate'] ?>%</td>
      <td style="font-family:monospace;color:var(--gold)"><?= fc($int) ?></td>
      <td style="font-family:monospace;font-weight:700"><?= fc($due) ?></td>
      <td><div style="font-family:monospace"><?= fc($paid) ?></div><div class="pbar"><div class="pfill <?= $t['status']==='overdue'?'over':'' ?>" style="width:<?= $pct ?>%"></div></div></td>
      <td style="font-family:monospace;color:<?= $bal>0?'var(--red)':'var(--green)' ?>;font-weight:700"><?= fc($bal) ?></td>
      <td><span class="badge b-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span></td>
      <td><?php if($dr!==null): ?><?= $dr<0?'<span style="color:var(--red);font-size:0.8rem">'.abs($dr).'d ago</span>':($dr===0?'<span style="color:var(--gold)">Today</span>':'<span style="color:var(--green);font-size:0.8rem">'.$dr.'d left</span>') ?><?php else: ?>—<?php endif; ?></td>
      <td>
        <div class="row" style="gap:4px">
          <a href="?page=slip&tid=<?= $t['id'] ?>" class="btn btn-ghost btn-sm btn-icon" title="Loan Slip"><i class="fas fa-file-invoice"></i></a>
          <?php if($t['status']!=='settled'): ?>
          <button class="btn btn-success btn-sm btn-icon" title="Record Payment" onclick="openPay(<?= $t['id'] ?>,'<?= addslashes($t['cname']) ?>',<?= $bal ?>)"><i class="fas fa-money-bill-wave"></i></button>
          <?php endif; ?>
          <a href="?action=del_txn&id=<?= $t['id'] ?>" class="btn btn-danger btn-sm btn-icon" onclick="return confirm('Delete this transaction?')"><i class="fas fa-trash"></i></a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($ftxns)): ?><tr><td colspan="12" class="empty"><i class="fas fa-inbox"></i>No transactions found</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ───── CONTACTS ───── -->
<?php elseif($page==='contacts'): ?>
<div class="pg">
<?php foreach($contacts as $c):
  $ls=$pdo->prepare("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE contact_id=? AND type='lend' AND status!='settled'");
  $ls->execute([$c['id']]); $cl=$ls->fetchColumn();
  $bs=$pdo->prepare("SELECT COALESCE(SUM(principal),0) FROM transactions WHERE contact_id=? AND type='borrow' AND status!='settled'");
  $bs->execute([$c['id']]); $cb=$bs->fetchColumn();
?>
<div class="pcard">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
    <div class="pavatar"><?= mb_strtoupper(mb_substr($c['name'],0,1)) ?></div>
    <div>
      <div style="font-weight:700;font-size:1rem;color:var(--navy)"><?= htmlspecialchars($c['name']) ?></div>
      <?php if($c['phone']): ?><div class="text-muted"><i class="fas fa-phone fa-xs"></i> <?= $c['phone'] ?></div><?php endif; ?>
    </div>
  </div>
  <?php if($c['email']): ?><div class="text-muted mb1"><i class="fas fa-envelope fa-xs"></i> <?= $c['email'] ?></div><?php endif; ?>
  <?php if($c['address']): ?><div class="text-muted" style="margin-bottom:8px"><i class="fas fa-map-marker-alt fa-xs"></i> <?= htmlspecialchars($c['address']) ?></div><?php endif; ?>
  <?php if($c['aadhar']): ?><div class="text-muted"><i class="fas fa-id-card fa-xs"></i> Aadhar: <?= $c['aadhar'] ?></div><?php endif; ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px">
    <div style="background:var(--cream);border-radius:9px;padding:10px;text-align:center">
      <div style="font-size:0.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px">Lent To</div>
      <div style="font-family:monospace;font-weight:800;color:#1d4ed8;font-size:0.92rem"><?= fc($cl) ?></div>
    </div>
    <div style="background:var(--cream);border-radius:9px;padding:10px;text-align:center">
      <div style="font-size:0.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px">Borrowed</div>
      <div style="font-family:monospace;font-weight:800;color:#5b21b6;font-size:0.92rem"><?= fc($cb) ?></div>
    </div>
  </div>
  <div class="row mt16" style="gap:8px">
    <button class="btn btn-ghost btn-sm" onclick="editContact(<?= $c['id'] ?>,'<?= addslashes($c['name']) ?>','<?= $c['phone'] ?>','<?= $c['email'] ?>','<?= addslashes($c['address']) ?>','<?= $c['aadhar'] ?>')"><i class="fas fa-edit"></i> Edit</button>
    <a href="?action=del_contact&id=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete contact?')"><i class="fas fa-trash"></i></a>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($contacts)): ?><div class="empty" style="grid-column:1/-1"><i class="fas fa-user-slash"></i>No contacts added yet</div><?php endif; ?>
</div>

<!-- ───── REMINDERS ───── -->
<?php elseif($page==='reminders'): ?>
<?php if(empty($reminders)): ?>
<div class="empty"><i class="fas fa-bell-slash"></i>No reminders set</div>
<?php else: foreach($reminders as $r): ?>
<div class="rem-item <?= $r['is_done']?'done':'' ?>">
  <i class="fas fa-bell fa-lg" style="color:<?= $r['is_done']?'var(--muted)':'var(--gold)' ?>"></i>
  <div style="flex:1">
    <strong><?= htmlspecialchars($r['cname']??'—') ?></strong>
    <span class="text-muted">— <?= htmlspecialchars($r['message']) ?></span>
    <div class="text-muted"><i class="fas fa-calendar"></i> <?= date('d M Y', strtotime($r['reminder_date'])) ?></div>
  </div>
  <?php if(!$r['is_done']): ?>
  <a href="?action=done_reminder&id=<?= $r['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Done</a>
  <?php else: ?><span class="badge b-settled">✓ Done</span><?php endif; ?>
</div>
<?php endforeach; endif; ?>

<!-- ───── REPORT ───── -->
<?php elseif($page==='report'): ?>
<?php
$month=$_GET['m']??date('Y-m');
$ml=array_filter($txns,fn($t)=>substr($t['start_date'],0,7)===$month&&$t['type']==='lend');
$mb=array_filter($txns,fn($t)=>substr($t['start_date'],0,7)===$month&&$t['type']==='borrow');
?>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:24px">
  <div class="card" style="padding:20px">
    <div style="font-size:0.75rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Filter Month</div>
    <form method="get"><input type="hidden" name="page" value="report"><input type="month" name="m" value="<?= $month ?>" onchange="this.form.submit()"></form>
    <div style="margin-top:14px;display:flex;gap:10px">
      <div style="flex:1;background:var(--cream);padding:12px;border-radius:9px;text-align:center">
        <div class="text-muted" style="font-size:0.7rem">Lent</div>
        <div style="font-family:monospace;font-weight:800;color:#1d4ed8"><?= fc(array_sum(array_column(iterator_to_array((function($ml){yield from $ml;})($ml)),'principal'))) ?></div>
      </div>
      <div style="flex:1;background:var(--cream);padding:12px;border-radius:9px;text-align:center">
        <div class="text-muted" style="font-size:0.7rem">Borrowed</div>
        <div style="font-family:monospace;font-weight:800;color:#5b21b6"><?= fc(array_sum(array_column(iterator_to_array((function($mb){yield from $mb;})($mb)),'principal'))) ?></div>
      </div>
    </div>
  </div>
  <div class="card" style="padding:20px">
    <div class="text-muted" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">Overall Summary</div>
    <table style="width:100%">
      <tr><td class="text-muted" style="padding:6px 0;font-size:0.82rem">Total Lent (Active)</td><td style="text-align:right;font-family:monospace;color:#1d4ed8;font-weight:700"><?= fc($total_lent) ?></td></tr>
      <tr><td class="text-muted" style="padding:6px 0;font-size:0.82rem">Total Borrowed (Active)</td><td style="text-align:right;font-family:monospace;color:#5b21b6;font-weight:700"><?= fc($total_borrowed) ?></td></tr>
      <tr style="border-top:1px solid var(--cream2)"><td style="padding:8px 0;font-size:0.85rem;font-weight:600">Net Balance</td><td style="text-align:right;font-family:monospace;font-size:1.1rem;font-weight:800;color:<?= $net>=0?'var(--green)':'var(--red)' ?>"><?= fc(abs($net)) ?></td></tr>
    </table>
  </div>
  <div class="card" style="padding:20px">
    <div class="text-muted" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">Transaction Stats</div>
    <table style="width:100%">
      <?php
      $cnt_active=$pdo->query("SELECT COUNT(*) FROM transactions WHERE status='active'")->fetchColumn();
      $cnt_over=$pdo->query("SELECT COUNT(*) FROM transactions WHERE status='overdue'")->fetchColumn();
      $cnt_settled=$pdo->query("SELECT COUNT(*) FROM transactions WHERE status='settled'")->fetchColumn();
      ?>
      <tr><td class="text-muted" style="padding:5px 0;font-size:0.82rem">Active</td><td style="text-align:right;font-weight:700;color:#1d4ed8"><?= $cnt_active ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;font-size:0.82rem">Overdue</td><td style="text-align:right;font-weight:700;color:var(--red)"><?= $cnt_over ?></td></tr>
      <tr><td class="text-muted" style="padding:5px 0;font-size:0.82rem">Settled</td><td style="text-align:right;font-weight:700;color:var(--green)"><?= $cnt_settled ?></td></tr>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Party-wise Outstanding</span></div>
  <div class="tw"><table>
    <thead><tr><th>Party</th><th>Phone</th><th>They Owe You</th><th>You Owe Them</th><th>Net</th></tr></thead>
    <tbody>
    <?php
    $parties=[];
    foreach($txns as $t){
      $pid=$t['contact_id'];
      if(!isset($parties[$pid])) $parties[$pid]=['name'=>$t['cname'],'phone'=>$t['cphone'],'owe_me'=>0,'i_owe'=>0];
      $int=calc_interest($t['principal'],$t['interest_rate'],$t['start_date'],$t['interest_type']);
      $due=$t['principal']+$int; $paid=total_paid($pdo,$t['id']); $rem=$due-$paid;
      if($rem>0){
        if($t['type']==='lend') $parties[$pid]['owe_me']+=$rem;
        else $parties[$pid]['i_owe']+=$rem;
      }
    }
    foreach($parties as $p): if(!$p['owe_me']&&!$p['i_owe']) continue;
      $n=$p['owe_me']-$p['i_owe'];
    ?>
    <tr>
      <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
      <td class="text-muted"><?= $p['phone'] ?></td>
      <td style="font-family:monospace;font-weight:700;color:var(--green)"><?= fc($p['owe_me']) ?></td>
      <td style="font-family:monospace;font-weight:700;color:var(--red)"><?= fc($p['i_owe']) ?></td>
      <td style="font-family:monospace;font-weight:800;color:<?= $n>=0?'var(--green)':'var(--red)' ?>"><?= fc(abs($n)) ?> <?= $n>=0?'↑':'↓' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- ───── CALCULATOR ───── -->
<?php elseif($page==='calculator'): ?>
<div style="max-width:620px;margin:0 auto">
<div class="card" style="padding:32px">
  <h3 style="font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--navy);margin-bottom:24px">📐 Interest Calculator</h3>
  <div class="form-grid">
    <div class="fg"><label>Principal (₹)</label><input type="number" id="cp" value="100000" oninput="calc()"></div>
    <div class="fg"><label>Rate (% per year)</label><input type="number" id="cr" value="12" step="0.1" oninput="calc()"></div>
    <div class="fg"><label>Duration</label><input type="number" id="ct" value="12" oninput="calc()"></div>
    <div class="fg"><label>Unit</label><select id="cu" onchange="calc()"><option value="months">Months</option><option value="years">Years</option><option value="days">Days</option></select></div>
    <div class="fg full"><label>Type</label><select id="ctype" onchange="calc()"><option value="simple">Simple Interest</option><option value="compound">Compound Interest</option></select></div>
  </div>
  <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:16px;padding:24px;margin-top:24px">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center">
      <div><div style="font-size:0.72rem;color:rgba(201,168,76,0.6);text-transform:uppercase;margin-bottom:6px">Principal</div><div id="rp" style="font-family:'JetBrains Mono',monospace;font-size:1.2rem;font-weight:800;color:var(--white)">₹1,00,000</div></div>
      <div><div style="font-size:0.72rem;color:rgba(201,168,76,0.6);text-transform:uppercase;margin-bottom:6px">Interest</div><div id="ri" style="font-family:'JetBrains Mono',monospace;font-size:1.2rem;font-weight:800;color:var(--gold2)">₹12,000</div></div>
      <div><div style="font-size:0.72rem;color:rgba(201,168,76,0.6);text-transform:uppercase;margin-bottom:6px">Total Amount</div><div id="rt" style="font-family:'JetBrains Mono',monospace;font-size:1.2rem;font-weight:800;color:var(--green2)">₹1,12,000</div></div>
    </div>
    <div style="border-top:1px solid rgba(255,255,255,0.1);margin-top:16px;padding-top:16px;text-align:center">
      <div style="font-size:0.72rem;color:rgba(255,255,255,0.4)">Monthly EMI (approx)</div>
      <div id="remi" style="font-family:'JetBrains Mono',monospace;font-size:1.5rem;font-weight:800;color:var(--white);margin-top:4px">₹9,333</div>
    </div>
  </div>
</div>
</div>
<?php endif; ?>

</div><!-- content -->
</main>
</div><!-- app -->

<!-- ═══════════ MODALS ═══════════ -->

<!-- Add Transaction -->
<div class="overlay" id="addTxn">
<div class="modal">
  <div class="modal-header">
    <span class="modal-title">💸 New Transaction</span>
    <button class="mclose" onclick="cm('addTxn')"><i class="fas fa-times"></i></button>
  </div>
  <form method="post">
  <input type="hidden" name="action" value="add_txn">
  <div class="form-grid">
    <div class="fg"><label>Party / Contact *</label>
      <select name="contact_id" required>
        <option value="">— Select Contact —</option>
        <?php foreach($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> <?= $c['phone']?'('.$c['phone'].')':'' ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>Transaction Type *</label>
      <select name="type" required>
        <option value="lend">💙 I Lent Money (दिया)</option>
        <option value="borrow">💜 I Borrowed Money (लिया)</option>
      </select>
    </div>
    <div class="fg"><label>Amount (₹) *</label><input type="number" name="amount" step="0.01" required placeholder="e.g. 50000"></div>
    <div class="fg"><label>Interest Rate (% p.a.)</label><input type="number" name="interest_rate" step="0.1" value="0"></div>
    <div class="fg"><label>Interest Type</label><select name="interest_type"><option value="simple">Simple Interest</option><option value="compound">Compound Interest</option></select></div>
    <div class="fg"><label>Start Date *</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="fg"><label>Due / Return Date</label><input type="date" name="due_date"></div>
    <div class="fg full"><label>Note / Purpose</label><textarea name="note" placeholder="Loan purpose, conditions..."></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" onclick="cm('addTxn')">Cancel</button>
    <button type="submit" class="btn btn-ghost btn-gold"><i class="fas fa-file-invoice"></i> Save & Print Slip</button>
    <input type="hidden" name="print_slip" value="1" id="print_slip_flag">
    <button type="submit" class="btn btn-primary" onclick="document.getElementById('print_slip_flag').name='no_print'"><i class="fas fa-save"></i> Save Only</button>
  </div>
  </form>
</div>
</div>

<!-- Add Payment Modal -->
<div class="overlay" id="payModal">
<div class="modal">
  <div class="modal-header">
    <span class="modal-title">💰 Record Payment</span>
    <button class="mclose" onclick="cm('payModal')"><i class="fas fa-times"></i></button>
  </div>
  <form method="post">
  <input type="hidden" name="action" value="add_payment">
  <input type="hidden" name="txn_id" id="pay_tid">
  <div class="form-grid">
    <div class="fg full"><label>Party</label><input id="pay_pname" readonly style="background:var(--cream2)"></div>
    <div class="fg"><label>Amount (₹) *</label><input type="number" name="amount" id="pay_amt" step="0.01" required></div>
    <div class="fg"><label>Payment Date *</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="fg"><label>Payment Mode</label>
      <select name="mode"><option>Cash</option><option>UPI</option><option>Bank Transfer</option><option>Cheque</option><option>Online</option></select>
    </div>
    <div class="fg full"><label>Note</label><textarea name="note" placeholder="Reference no, remarks..."></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" onclick="cm('payModal')">Cancel</button>
    <button type="submit" class="btn btn-ghost btn-gold"><i class="fas fa-receipt"></i> Save & Print Receipt</button>
    <input type="hidden" name="print_slip" value="1" id="pay_print_flag">
    <button type="submit" class="btn btn-success" onclick="document.getElementById('pay_print_flag').name='nop'"><i class="fas fa-check"></i> Save Only</button>
  </div>
  </form>
</div>
</div>

<!-- Add Contact Modal -->
<div class="overlay" id="addContact">
<div class="modal">
  <div class="modal-header">
    <span class="modal-title">👤 Add New Contact</span>
    <button class="mclose" onclick="cm('addContact')"><i class="fas fa-times"></i></button>
  </div>
  <form method="post">
  <input type="hidden" name="action" value="add_contact">
  <div class="form-grid">
    <div class="fg full"><label>Full Name *</label><input type="text" name="name" required placeholder="e.g. Ramesh Kumar"></div>
    <div class="fg"><label>Phone</label><input type="tel" name="phone" placeholder="9876543210"></div>
    <div class="fg"><label>Email</label><input type="email" name="email" placeholder="email@example.com"></div>
    <div class="fg"><label>Aadhar / ID No.</label><input type="text" name="aadhar" placeholder="XXXX XXXX XXXX"></div>
    <div class="fg full"><label>Address</label><textarea name="address" placeholder="House no, street, city..."></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" onclick="cm('addContact')">Cancel</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Contact</button>
  </div>
  </form>
</div>
</div>

<!-- Edit Contact -->
<div class="overlay" id="editContact">
<div class="modal">
  <div class="modal-header">
    <span class="modal-title">✏️ Edit Contact</span>
    <button class="mclose" onclick="cm('editContact')"><i class="fas fa-times"></i></button>
  </div>
  <form method="post">
  <input type="hidden" name="action" value="edit_contact">
  <input type="hidden" name="id" id="ec_id">
  <div class="form-grid">
    <div class="fg full"><label>Full Name *</label><input type="text" name="name" id="ec_name" required></div>
    <div class="fg"><label>Phone</label><input type="tel" name="phone" id="ec_phone"></div>
    <div class="fg"><label>Email</label><input type="email" name="email" id="ec_email"></div>
    <div class="fg"><label>Aadhar / ID</label><input type="text" name="aadhar" id="ec_aadhar"></div>
    <div class="fg full"><label>Address</label><textarea name="address" id="ec_address"></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" onclick="cm('editContact')">Cancel</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
  </div>
  </form>
</div>
</div>

<!-- Add Reminder -->
<div class="overlay" id="addReminder">
<div class="modal">
  <div class="modal-header">
    <span class="modal-title">⏰ Set Reminder</span>
    <button class="mclose" onclick="cm('addReminder')"><i class="fas fa-times"></i></button>
  </div>
  <form method="post">
  <input type="hidden" name="action" value="add_reminder">
  <div class="form-grid">
    <div class="fg full"><label>Linked Transaction</label>
      <select name="txn_id">
        <option value="">— Optional —</option>
        <?php foreach($txns as $t): if($t['status']==='settled') continue; ?>
        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['cname']) ?> — <?= $t['type']==='lend'?'Lent':'Borrowed' ?> — <?= fc($t['principal']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>Reminder Date *</label><input type="date" name="reminder_date" required value="<?= date('Y-m-d',strtotime('+7 days')) ?>"></div>
    <div class="fg full"><label>Message *</label><textarea name="message" required placeholder="e.g. Collect EMI from Ramesh"></textarea></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-ghost" onclick="cm('addReminder')">Cancel</button>
    <button type="submit" class="btn btn-primary"><i class="fas fa-bell"></i> Set Reminder</button>
  </div>
  </form>
</div>
</div>

<?php endif; ?>

<script>
function om(id){document.getElementById(id).classList.add('show')}
function cm(id){document.getElementById(id).classList.remove('show')}
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('show')}));

function openPay(id,name,bal){
  document.getElementById('pay_tid').value=id;
  document.getElementById('pay_pname').value=name;
  document.getElementById('pay_amt').value=bal.toFixed(2);
  om('payModal');
}
function editContact(id,name,phone,email,address,aadhar){
  document.getElementById('ec_id').value=id;
  document.getElementById('ec_name').value=name;
  document.getElementById('ec_phone').value=phone;
  document.getElementById('ec_email').value=email;
  document.getElementById('ec_address').value=address;
  document.getElementById('ec_aadhar').value=aadhar;
  om('editContact');
}
function calc(){
  const p=parseFloat(document.getElementById('cp').value)||0;
  const r=parseFloat(document.getElementById('cr').value)||0;
  const t=parseFloat(document.getElementById('ct').value)||0;
  const u=document.getElementById('cu').value;
  const tp=document.getElementById('ctype').value;
  let y=u==='years'?t:u==='months'?t/12:t/365;
  let i=tp==='compound'?p*(Math.pow(1+r/100,y)-1):p*(r/100)*y;
  const f=n=>'₹'+n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
  document.getElementById('rp').textContent=f(p);
  document.getElementById('ri').textContent=f(i);
  document.getElementById('rt').textContent=f(p+i);
  const months=u==='years'?t*12:u==='months'?t:t/30;
  document.getElementById('remi').textContent=months>0?f((p+i)/months):'—';
}
if(document.getElementById('cp')) calc();

// Auto-hide flash
setTimeout(()=>{const f=document.querySelector('.flash');if(f)f.style.opacity='0'},4000);
</script>
</body>
</html>