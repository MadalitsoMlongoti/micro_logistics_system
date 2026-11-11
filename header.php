
<?php

/*
|--------------------------------------------------------------------------
| PAGE AND USER INFORMATION
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$pageHeading = $page_title ?? $pageTitle ?? 'Dashboard';

$currentUser = current_user();

$loggedInUser = $currentUser['name'] ?? 'User';
$userRole = $currentUser['role'] ?? 'User';

$userInitial = strtoupper(substr(trim($loggedInUser), 0, 1));

if ($userInitial === '') {
    $userInitial = 'U';
}

/*
|--------------------------------------------------------------------------
| NAVIGATION HELPER
|--------------------------------------------------------------------------
*/

if (!function_exists('mlfmsNavActive')) {
    function mlfmsNavActive(string $page, string $currentPage): string
    {
        return $page === $currentPage ? 'active' : '';
    }
}

/*
|--------------------------------------------------------------------------
| ROLE DISPLAY
|--------------------------------------------------------------------------
*/

$roleLabels = [
    'administrator' => 'Administrator',
    'dispatcher' => 'Dispatcher',
    'finance' => 'Finance Officer',
    'marketing' => 'Marketing',
    'fleet_manager' => 'Fleet Manager'
];

$displayRole = $roleLabels[$userRole] ?? ucfirst(str_replace('_', ' ', $userRole));

/*
|--------------------------------------------------------------------------
| NAVIGATION MENU
|--------------------------------------------------------------------------
| Modules are displayed only when the logged-in user has permission.
|--------------------------------------------------------------------------
*/

$navigationGroups = [

    'MAIN MENU' => [
        [
            'title' => 'Dashboard',
            'page' => 'index.php',
            'icon' => 'bi-grid-1x2',
            'permission' => 'dashboard'
        ]
    ],

    'OPERATIONS' => [
        [
            'title' => 'Customers',
            'page' => 'customers.php',
            'icon' => 'bi-person-lines-fill',
            'permission' => 'customers'
        ],
        [
            'title' => 'Deliveries',
            'page' => 'deliveries.php',
            'icon' => 'bi-box-seam',
            'permission' => 'deliveries'
        ],
        [
            'title' => 'Dispatch',
            'page' => 'dispatch.php',
            'icon' => 'bi-send',
            'permission' => 'dispatch'
        ],
        [
            'title' => 'Drivers',
            'page' => 'drivers.php',
            'icon' => 'bi-people',
            'permission' => 'drivers'
        ],
        [
            'title' => 'Fleet Management',
            'page' => 'fleet.php',
            'icon' => 'bi-bicycle',
            'permission' => 'fleet'
        ],
        [
            'title' => 'Payment Management',
            'page' => 'payments.php',
            'icon' => 'bi-cash-stack',
            'permission' => 'payments'
        ]
    ],

    'MANAGEMENT' => [
        [
            'title' => 'Maintenance',
            'page' => 'maintenance.php',
            'icon' => 'bi-tools',
            'permission' => 'maintenance'
        ],
        [
            'title' => 'Financial Reports',
            'page' => 'finance.php',
            'icon' => 'bi-bar-chart-line',
            'permission' => 'finance'
        ]
    ],

    'SYSTEM' => [
        [
            'title' => 'User Management',
            'page' => 'users.php',
            'icon' => 'bi-person-gear',
            'permission' => 'users'
        ]
    ]

];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#14243a">

    <title><?= e($pageHeading) ?> | MLFMS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="assets/css/style.css">

    <style>

        :root {
            --mlfms-primary: #1769e0;
            --mlfms-primary-dark: #1053b7;
            --mlfms-sidebar: #14243a;
            --mlfms-sidebar-light: #1c304a;
            --mlfms-text-muted: #a7b5c8;
            --mlfms-border: #e9edf3;
        }

        /* SIDEBAR */

        .sidebar {
            background: var(--mlfms-sidebar);
            color: #fff;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #344962 transparent;
        }

        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            margin-bottom: 12px;
        }

        .sidebar .brand-icon {
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--mlfms-primary);
            font-size: 22px;
            box-shadow: 0 4px 12px rgba(23,105,224,.25);
        }

        .sidebar .brand-name span {
            display: block;
            font-weight: 800;
            font-size: 19px;
            letter-spacing: 1px;
            color: #fff;
        }

        .sidebar .brand-name small {
            display: block;
            color: var(--mlfms-text-muted);
            font-size: 11px;
        }

        .sidebar-label {
            padding: 19px 20px 8px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.4px;
            color: #71849e;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 13px;
            margin: 3px 12px;
            padding: 12px 14px;
            border-radius: 9px;
            color: #c4d0df;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background .2s ease, color .2s ease;
        }

        .sidebar a i {
            font-size: 17px;
            width: 21px;
            text-align: center;
        }

        .sidebar a:hover {
            background: var(--mlfms-sidebar-light);
            color: #fff;
        }

        .sidebar a.active {
            background: var(--mlfms-primary);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }

        .sidebar .sidebar-bottom {
            margin-top: auto;
            padding: 15px 12px;
            border-top: 1px solid rgba(255,255,255,.08);
        }

        .sidebar .logout-link {
            color: #ffb5b5;
        }

        .sidebar .logout-link:hover {
            background: rgba(220,53,69,.14);
            color: #fff;
        }

        /* TOPBAR */

        .topbar {
            min-height: 82px;
            padding: 15px 25px;
            background: #fff;
            border-bottom: 1px solid var(--mlfms-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .topbar-heading h1 {
            font-size: 21px;
            font-weight: 800;
            color: #172b46;
            margin: 0 0 4px;
        }

        .topbar-heading p {
            color: #8491a3;
            font-size: 12px;
            margin: 0;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 7px 12px;
            border: 1px solid var(--mlfms-border);
            border-radius: 12px;
            background: #fff;
        }

        .topbar-user .user-avatar {
            width: 39px;
            height: 39px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f1ff;
            color: var(--mlfms-primary);
            font-weight: 800;
            font-size: 16px;
        }

        .user-details strong {
            display: block;
            font-size: 12px;
            color: #26374c;
        }

        .user-details small {
            display: block;
            font-size: 11px;
            color: #8491a3;
        }

        .mobile-menu-button {
            display: none;
            width: 39px;
            height: 39px;
            border: 1px solid var(--mlfms-border);
            border-radius: 9px;
            background: #fff;
            color: var(--mlfms-sidebar);
            font-size: 20px;
        }

        .sidebar-overlay {
            display: none;
        }

        /* MOBILE RESPONSIVENESS */

        @media (max-width: 991px) {

            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 265px !important;
                max-width: 85vw;
                z-index: 1050;
                transform: translateX(-105%);
                transition: transform .25s ease;
                height: 100vh;
            }

            .sidebar.mobile-open {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(10,20,35,.55);
                z-index: 1040;
            }

            .mobile-menu-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .topbar {
                padding: 13px 16px;
            }

            .topbar-heading h1 {
                font-size: 18px;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        @media (max-width: 576px) {

            .topbar {
                gap: 8px;
            }

            .topbar-heading h1 {
                font-size: 16px;
            }

            .topbar-heading p {
                font-size: 10px;
            }

            .topbar-user {
                padding: 5px;
                border: 0;
            }

            .topbar-user .user-avatar {
                width: 35px;
                height: 35px;
            }

            .user-details {
                display: none;
            }
        }

    </style>

</head>

<body>

<!-- MOBILE SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="mainSidebar">

    <div class="brand">

        <div class="brand-icon">
            <i class="bi bi-truck"></i>
        </div>

        <div class="brand-name">
            <span>MLFMS</span>
            <small>Fleet Management</small>
        </div>

    </div>

    <?php foreach ($navigationGroups as $groupName => $items): ?>

        <?php
        $visibleItems = array_filter(
            $items,
            fn($item) => can_access($item['permission'])
        );
        ?>

        <?php if (!empty($visibleItems)): ?>

            <div class="sidebar-label">
                <?= e($groupName) ?>
            </div>

            <?php foreach ($visibleItems as $item): ?>

                <a
                    href="<?= e($item['page']) ?>"
                    class="<?= mlfmsNavActive($item['page'], $currentPage) ?>"
                >

                    <i class="bi <?= e($item['icon']) ?>"></i>

                    <span><?= e($item['title']) ?></span>

                </a>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endforeach; ?>

    <!-- LOGOUT -->
    <div class="sidebar-bottom">

        <a href="logout.php" class="logout-link">

            <i class="bi bi-box-arrow-left"></i>

            <span>Logout</span>

        </a>

    </div>

</aside>

<!-- MAIN CONTENT -->
<main class="main-content">

    <!-- TOPBAR -->
    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-button"
                id="mobileMenuButton"
                aria-label="Open navigation menu"
                aria-expanded="false"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-heading">

                <h1><?= e($pageHeading) ?></h1>

                <p>
                    Micro-Logistics Fleet Management System
                </p>

            </div>

        </div>

        <!-- USER PROFILE -->
        <div class="topbar-user">

            <div class="user-avatar">
                <?= e($userInitial) ?>
            </div>

            <div class="user-details">

                <strong><?= e($loggedInUser) ?></strong>

                <small><?= e($displayRole) ?></small>

            </div>

        </div>

    </header>

    <!-- PAGE CONTENT STARTS BELOW -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const menuButton = document.getElementById('mobileMenuButton');

    function openSidebar() {

        sidebar.classList.add('mobile-open');
        overlay.classList.add('show');

        menuButton.setAttribute('aria-expanded', 'true');

        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {

        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('show');

        menuButton.setAttribute('aria-expanded', 'false');

        document.body.style.overflow = '';
    }

    menuButton.addEventListener('click', function () {

        if (sidebar.classList.contains('mobile-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }

    });

    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', closeSidebar);

    });

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {
            closeSidebar();
        }

    });

});

</script>
</body>
</html>
