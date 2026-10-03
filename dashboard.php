<?php
require 'config.php';

/* ---------- Access control ---------- */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* Auto logout after 30 minutes of inactivity */
const IDLE_LIMIT = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > IDLE_LIMIT) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
$_SESSION['last_activity'] = time();
$_SESSION['session_started'] ??= time();

/* ---------- Load current user ---------- */
$stmt = $pdo->prepare('SELECT id, username, email, password_hash, created_at FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) { // account no longer exists
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

/* ---------- Handle form actions ---------- */
$activeTab = 'overview';
$errors    = ['profile' => [], 'password' => []];
$username  = $user['username'];
$email     = $user['email'];

// Message saved before a redirect (post/redirect/get)
$flash = $_SESSION['dash_flash'] ?? null;
unset($_SESSION['dash_flash']);
if ($flash) {
    $activeTab = $flash['tab'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['profile'][] = 'Invalid request. Please try again.';
        $activeTab = 'profile';
    }

    /* --- Update profile --- */
    elseif ($action === 'update_profile') {
        $activeTab = 'profile';
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            $errors['profile'][] = 'Username must be 3-30 characters (letters, numbers, underscore).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['profile'][] = 'Please enter a valid email address.';
        }

        if (!$errors['profile']) {
            $check = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?');
            $check->execute([$username, $email, $user['id']]);
            if ($check->fetch()) {
                $errors['profile'][] = 'That username or email is already taken.';
            }
        }

        if (!$errors['profile']) {
            $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?')
                ->execute([$username, $email, $user['id']]);
            $_SESSION['username'] = $username;
            $_SESSION['dash_flash'] = ['tab' => 'profile', 'msg' => 'Profile updated successfully.'];
            header('Location: dashboard.php');
            exit;
        }
    }

    /* --- Change password --- */
    elseif ($action === 'change_password') {
        $activeTab = 'security';
        $current   = $_POST['current'] ?? '';
        $new       = $_POST['new'] ?? '';
        $confirm   = $_POST['confirm'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            $errors['password'][] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors['password'][] = 'New password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors['password'][] = 'New passwords do not match.';
        }
        if (!$errors['password'] && password_verify($new, $user['password_hash'])) {
            $errors['password'][] = 'New password must be different from the current one.';
        }

        if (!$errors['password']) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            $_SESSION['dash_flash'] = ['tab' => 'security', 'msg' => 'Password changed successfully.'];
            header('Location: dashboard.php');
            exit;
        }
    }
}

/* ---------- Display values ---------- */
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$initial  = strtoupper(substr($user['username'], 0, 1));
$joined   = strtotime($user['created_at']);
$days     = max(0, (int)floor((time() - $joined) / 86400));
$sessionStart = date('g:i A', $_SESSION['session_started']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <style>
        :root {
            --bg: #ffffff; --surface: #ffffff; --text: #111827; --muted: #6b7280;
            --border: #ececec; --soft: #f7f7f8; --primary: #111827; --primary-dark: #000000;
            --link: #2563eb; --input: #ffffff;
            --ok-bg: #f0fdf4; --ok-text: #166534; --err-bg: #fef2f2; --err-text: #991b1b;
        }
        [data-theme="dark"] {
            --bg: #0e0e10; --surface: #0e0e10; --text: #f3f4f6; --muted: #9ca3af;
            --border: #232327; --soft: #17171a; --primary: #f3f4f6; --primary-dark: #ffffff;
            --link: #60a5fa; --input: #131316;
            --ok-bg: #0f2a1a; --ok-text: #86efac; --err-bg: #2a1212; --err-text: #fca5a5;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; -webkit-font-smoothing: antialiased; }
        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Sidebar */
        .sidebar { width: 230px; background: var(--bg); border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 2rem 1.1rem 1.5rem; position: sticky; top: 0; height: 100vh; }
        .brand { font-family: Georgia, "Times New Roman", serif; font-size: 1.4rem; letter-spacing: .02em; padding: 0 .75rem 2rem; }
        .nav { display: flex; flex-direction: column; gap: .2rem; flex: 1; }
        .nav button { display: flex; align-items: center; gap: .75rem; padding: .65rem .75rem; border: 0; border-radius: 8px; background: transparent; color: var(--muted); font-size: .93rem; cursor: pointer; text-align: left; transition: background .15s, color .15s; }
        .nav button:hover { background: var(--soft); color: var(--text); }
        .nav button.active { background: var(--soft); color: var(--text); font-weight: 600; }
        .nav svg { width: 17px; height: 17px; flex-shrink: 0; }
        .logout button { width: 100%; padding: .65rem; border: 1px solid var(--border); background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; font-size: .9rem; transition: all .15s; }
        .logout button:hover { color: var(--text); border-color: var(--text); }

        /* Main */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 2.5rem; border-bottom: 1px solid var(--border); gap: 1rem; }
        .topbar h2 { margin: 0; font-size: 1rem; font-weight: 600; letter-spacing: .01em; }
        .top-right { display: flex; align-items: center; gap: 1.1rem; }
        .clock { color: var(--muted); font-size: .85rem; font-variant-numeric: tabular-nums; }
        .icon-btn { border: 1px solid var(--border); background: transparent; color: var(--text); border-radius: 8px; padding: .35rem .55rem; cursor: pointer; font-size: .95rem; }
        .icon-btn:hover { background: var(--soft); }
        .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: var(--bg); display: grid; place-items: center; font-weight: 600; font-size: .85rem; }
        .user-chip { display: flex; align-items: center; gap: .6rem; font-size: .92rem; }
        .content { padding: 2.5rem; max-width: 1050px; width: 100%; }
        .tab { display: none; }
        .tab.active { display: block; }

        /* Components */
        .welcome { margin-bottom: 2rem; }
        .welcome h1 { margin: 0 0 .35rem; font-family: Georgia, "Times New Roman", serif; font-size: 2rem; font-weight: 400; letter-spacing: -.01em; }
        .welcome p { margin: 0; color: var(--muted); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 1.15rem 1.25rem; display: flex; flex-direction: column; gap: .25rem; }
        .stat-label { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
        .stat-value { font-size: 1.35rem; font-weight: 600; }
        .stat-small { font-size: .95rem; word-break: break-all; font-weight: 500; }
        .stat-note { font-size: .82rem; color: var(--muted); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 1.6rem; }
        .panel.narrow { max-width: 480px; }
        .panel h3 { margin: 0 0 1.1rem; font-size: .95rem; font-weight: 600; }
        .muted { color: var(--muted); }
        .small { font-size: .83rem; }
        .info-row { display: flex; justify-content: space-between; padding: .65rem 0; border-bottom: 1px solid var(--border); font-size: .92rem; gap: 1rem; }
        .info-row:last-child { border-bottom: 0; }
        .info-row span:last-child { text-align: right; word-break: break-all; }

        label { display: block; margin: 1.1rem 0 .35rem; font-size: .85rem; font-weight: 500; color: var(--muted); }
        input[type=text], input[type=email], input[type=password] { width: 100%; padding: .68rem .85rem; border: 1px solid var(--border); border-radius: 8px; font-size: .95rem; background: var(--input); color: var(--text); transition: border-color .15s, box-shadow .15s; }
        input:focus { outline: none; border-color: var(--text); box-shadow: 0 0 0 3px rgba(17,24,39,.07); }
        .pw-wrap { position: relative; }
        .pw-wrap button { position: absolute; right: .6rem; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: var(--muted); cursor: pointer; font-size: .78rem; }
        .btn { display: inline-block; margin-top: 1.4rem; padding: .7rem 1.3rem; background: var(--primary); color: var(--bg); border: 0; border-radius: 8px; font-size: .9rem; font-weight: 500; cursor: pointer; transition: opacity .15s; }
        .btn:hover { opacity: .85; }
        .alert { padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; }
        .alert-success { background: var(--ok-bg); color: var(--ok-text); }
        .alert-error { background: var(--err-bg); color: var(--err-text); }

        /* Password strength */
        .meter { height: 4px; background: var(--border); border-radius: 99px; margin-top: .55rem; overflow: hidden; }
        .meter span { display: block; height: 100%; width: 0; transition: width .2s, background .2s; }
        .meter-text { font-size: .78rem; color: var(--muted); margin-top: .3rem; }

        /* To-do */
        .todo-form { display: flex; gap: .5rem; }
        .todo-form input { flex: 1; }
        .todo-form .btn { margin-top: 0; }
        .todo-list { list-style: none; padding: 0; margin: 1rem 0 0; }
        .todo-list li { display: flex; align-items: center; gap: .65rem; padding: .6rem 0; border-bottom: 1px solid var(--border); font-size: .93rem; }
        .todo-list li input[type=checkbox] { accent-color: var(--primary); }
        .todo-list li.done span { text-decoration: line-through; color: var(--muted); }
        .todo-list li span { flex: 1; word-break: break-word; }
        .todo-list button { border: 0; background: transparent; color: var(--muted); cursor: pointer; font-size: 1.1rem; }
        .todo-list button:hover { color: var(--text); }

        /* Timer */
        .timer { font-family: Georgia, "Times New Roman", serif; font-size: 2.2rem; font-variant-numeric: tabular-nums; }

        @media (max-width: 800px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; flex-direction: row; flex-wrap: wrap; align-items: center; padding: .85rem 1rem; gap: .5rem; border-right: 0; border-bottom: 1px solid var(--border); }
            .brand { padding: 0; margin-right: auto; }
            .nav { flex-direction: row; flex: none; order: 3; width: 100%; overflow-x: auto; }
            .logout button { width: auto; padding: .45rem .9rem; }
            .grid-2 { grid-template-columns: 1fr; }
            .content, .topbar { padding: 1.25rem 1rem; }
            .clock { display: none; }
            .welcome h1 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand">MyApp</div>
    <div class="nav">
        <button data-tab="overview" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10"/></svg>
            Overview
        </button>
        <button data-tab="profile" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/></svg>
            My Profile
        </button>
        <button data-tab="security" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
            Security
        </button>
    </div>
    <form method="post" action="logout.php" class="logout">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit">Log out</button>
    </form>
</aside>

<div class="main">
    <header class="topbar">
        <h2 id="page-title">Overview</h2>
        <div class="top-right">
            <span class="clock" id="clock"></span>
            <button class="icon-btn" id="theme-toggle" type="button" title="Toggle dark mode">🌙</button>
            <div class="user-chip">
                <span class="avatar"><?= e($initial) ?></span>
                <span><?= e($user['username']) ?></span>
            </div>
        </div>
    </header>

    <main class="content">

        <!-- ================= OVERVIEW ================= -->
        <section class="tab" id="tab-overview">
            <div class="welcome">
                <h1><?= e($greeting) ?>, <?= e($user['username']) ?> 👋</h1>
                <p>Here's a quick overview of your account.</p>
            </div>

            <div class="stats">
                <div class="stat">
                    <span class="stat-label">Member since</span>
                    <span class="stat-value"><?= e(date('M j, Y', $joined)) ?></span>
                    <span class="stat-note"><?= $days ?> day(s) ago</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Account ID</span>
                    <span class="stat-value">#<?= (int)$user['id'] ?></span>
                    <span class="stat-note">Your unique ID</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Signed in at</span>
                    <span class="stat-value"><?= e($sessionStart) ?></span>
                    <span class="stat-note">This session</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Email</span>
                    <span class="stat-value stat-small"><?= e($user['email']) ?></span>
                    <span class="stat-note"><a href="#" data-goto="profile">Change</a></span>
                </div>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <h3>My to-do list</h3>
                    <form class="todo-form" id="todo-form">
                        <input type="text" id="todo-input" placeholder="Add a task..." maxlength="120" autocomplete="off">
                        <button class="btn" type="submit">Add</button>
                    </form>
                    <ul class="todo-list" id="todo-list"></ul>
                    <p class="muted small" id="todo-empty">No tasks yet. Add your first one above.</p>
                    <p class="muted small">Tasks are saved in this browser only.</p>
                </div>

                <div class="panel">
                    <h3>Session</h3>
                    <p class="muted small" style="margin-top:0">Time until you're signed out for inactivity:</p>
                    <div class="timer" id="timer">--:--</div>
                    <p class="muted small">Any action that reloads the page resets the timer.</p>
                    <div style="margin-top:1rem">
                        <div class="info-row"><span class="muted">Username</span><span><?= e($user['username']) ?></span></div>
                        <div class="info-row"><span class="muted">Registered</span><span><?= e(date('F j, Y', $joined)) ?></span></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= PROFILE ================= -->
        <section class="tab" id="tab-profile">
            <div class="panel narrow">
                <h3>Account details</h3>

                <?php if ($flash && $flash['tab'] === 'profile'): ?>
                    <div class="alert alert-success"><?= e($flash['msg']) ?></div>
                <?php endif; ?>
                <?php foreach ($errors['profile'] as $err): ?>
                    <div class="alert alert-error"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= e($username) ?>" required>

                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" required>

                    <button type="submit" class="btn">Save changes</button>
                </form>
            </div>
        </section>

        <!-- ================= SECURITY ================= -->
        <section class="tab" id="tab-security">
            <div class="panel narrow">
                <h3>Change password</h3>

                <?php if ($flash && $flash['tab'] === 'security'): ?>
                    <div class="alert alert-success"><?= e($flash['msg']) ?></div>
                <?php endif; ?>
                <?php foreach ($errors['password'] as $err): ?>
                    <div class="alert alert-error"><?= e($err) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <label for="current">Current password</label>
                    <div class="pw-wrap">
                        <input type="password" id="current" name="current" required>
                        <button type="button" data-toggle="current">Show</button>
                    </div>

                    <label for="new">New password</label>
                    <div class="pw-wrap">
                        <input type="password" id="new" name="new" minlength="8" required>
                        <button type="button" data-toggle="new">Show</button>
                    </div>
                    <div class="meter"><span id="meter-bar"></span></div>
                    <div class="meter-text" id="meter-text">Use 8+ characters with a mix of letters, numbers and symbols.</div>

                    <label for="confirm">Confirm new password</label>
                    <div class="pw-wrap">
                        <input type="password" id="confirm" name="confirm" minlength="8" required>
                        <button type="button" data-toggle="confirm">Show</button>
                    </div>

                    <button type="submit" class="btn">Update password</button>
                </form>
            </div>
        </section>

    </main>
</div>

<script>
(function () {
    /* ---------- Tabs ---------- */
    var titles = { overview: 'Overview', profile: 'My Profile', security: 'Security' };
    var tabs = document.querySelectorAll('.tab');
    var navBtns = document.querySelectorAll('.nav button');

    function showTab(name) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.id === 'tab-' + name); });
        navBtns.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === name); });
        document.getElementById('page-title').textContent = titles[name];
    }
    navBtns.forEach(function (b) { b.addEventListener('click', function () { showTab(b.dataset.tab); }); });
    document.querySelectorAll('[data-goto]').forEach(function (a) {
        a.addEventListener('click', function (ev) { ev.preventDefault(); showTab(a.dataset.goto); });
    });
    showTab(<?= json_encode($activeTab) ?>);

    /* ---------- Dark mode ---------- */
    var root = document.documentElement;
    var themeBtn = document.getElementById('theme-toggle');
    function applyTheme(t) {
        root.setAttribute('data-theme', t);
        themeBtn.textContent = t === 'dark' ? '☀️' : '🌙';
    }
    var saved = null;
    try { saved = localStorage.getItem('dash-theme'); } catch (e) {}
    applyTheme(saved || 'light');
    themeBtn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem('dash-theme', next); } catch (e) {}
    });

    /* ---------- Live clock ---------- */
    var clock = document.getElementById('clock');
    function tick() {
        clock.textContent = new Date().toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    tick(); setInterval(tick, 1000);

    /* ---------- Session countdown ---------- */
    var remaining = <?= (int)IDLE_LIMIT ?>;
    var timer = document.getElementById('timer');
    function renderTimer() {
        var m = Math.floor(remaining / 60), s = remaining % 60;
        timer.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        if (remaining <= 300) timer.style.color = '#dc2626';
    }
    renderTimer();
    setInterval(function () {
        remaining--;
        if (remaining <= 0) { window.location.href = 'login.php'; return; }
        renderTimer();
    }, 1000);

    /* ---------- Show / hide password ---------- */
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.toggle);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
        });
    });

    /* ---------- Password strength ---------- */
    var pw = document.getElementById('new');
    var bar = document.getElementById('meter-bar');
    var txt = document.getElementById('meter-text');
    pw.addEventListener('input', function () {
        var v = pw.value, score = 0;
        if (v.length >= 8) score++;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var levels = [
            { w: '0%',   c: 'transparent', t: 'Use 8+ characters with a mix of letters, numbers and symbols.' },
            { w: '25%',  c: '#ef4444',     t: 'Weak' },
            { w: '50%',  c: '#f59e0b',     t: 'Fair' },
            { w: '75%',  c: '#3b82f6',     t: 'Good' },
            { w: '100%', c: '#22c55e',     t: 'Strong' }
        ];
        var lvl = v.length === 0 ? 0 : Math.min(4, Math.max(1, score - 1));
        bar.style.width = levels[lvl].w;
        bar.style.background = levels[lvl].c;
        txt.textContent = levels[lvl].t;
    });

    /* ---------- To-do list (saved in this browser) ---------- */
    var todoKey = 'dash-todos-<?= (int)$user['id'] ?>';
    var todos = [];
    try { todos = JSON.parse(localStorage.getItem(todoKey)) || []; } catch (e) {}
    var list = document.getElementById('todo-list');
    var empty = document.getElementById('todo-empty');

    function saveTodos() { try { localStorage.setItem(todoKey, JSON.stringify(todos)); } catch (e) {} }
    function renderTodos() {
        list.innerHTML = '';
        empty.style.display = todos.length ? 'none' : 'block';
        todos.forEach(function (t, i) {
            var li = document.createElement('li');
            if (t.done) li.className = 'done';
            var cb = document.createElement('input');
            cb.type = 'checkbox'; cb.checked = t.done;
            cb.addEventListener('change', function () { todos[i].done = cb.checked; saveTodos(); renderTodos(); });
            var span = document.createElement('span');
            span.textContent = t.text;           // textContent keeps it XSS-safe
            var del = document.createElement('button');
            del.type = 'button'; del.textContent = '×'; del.title = 'Delete';
            del.addEventListener('click', function () { todos.splice(i, 1); saveTodos(); renderTodos(); });
            li.append(cb, span, del);
            list.appendChild(li);
        });
    }
    document.getElementById('todo-form').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var input = document.getElementById('todo-input');
        var text = input.value.trim();
        if (!text) return;
        todos.unshift({ text: text, done: false });
        input.value = '';
        saveTodos(); renderTodos();
    });
    renderTodos();
})();
</script>
</body>
</html>