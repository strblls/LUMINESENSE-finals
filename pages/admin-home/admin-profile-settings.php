<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name  = htmlspecialchars($_SESSION['admin_name']);
$name_parts  = explode(' ', $admin_name);
$initials    = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">


    <!-- ═══ SHARED SIDEBAR & PROFILE STYLES ═══ -->
    <style>
        /* ── Sidebar nav buttons ── */
        .nav-btn {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.15s;
        }

        .nav-btn i,
        .nav-btn svg {
            font-size: 22px;
        }

        .nav-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.06);
        }

        /* ── Sidebar offcanvas shell ── */
        #sidebarOffcanvas {
            width: 100px !important;
            background-color: var(--primary-color);
        }

        #sidebarOffcanvas .offcanvas-header {
            justify-content: center;
            padding: 1rem 0.5rem;
        }

        #sidebarOffcanvas .logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            cursor: pointer;
        }

        #sidebarOffcanvas .offcanvas-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding-top: 0.5rem;
        }

        #sidebarOffcanvas .offcanvas-footer {
            display: flex;
            justify-content: center;
            padding: 1rem;
        }

        #sidebarOffcanvas .offcanvas-footer img {
            width: 4rem;
        }

        /* ── Profile offcanvas shell ── */
        #profileOffcanvas {
            width: 240px !important;
            background-color: var(--primary-color);
        }

        #profileOffcanvas .avatar-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #d9d6d6;
            color: var(--secondary-color-1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Profile offcanvas buttons ── */
        .profile-btn {
            width: 100%;
            padding: 8px;
            margin: 3px 0;
            border-radius: 8px;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-family: var(--font-primary);
            transition: background-color 0.2s, transform 0.15s;
        }

        .profile-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.02);
        }

        .info-action-btn {
            width: auto;
            white-space: nowrap;
            background-color: var(--primary-color);
            color: var(--secondary-color-1);
            border: 1px solid var(--secondary-color-2);
            transition: background-color 0.2s, transform 0.15s;
        }

        .info-action-btn:hover {
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            transform: scale(1.02);
        }
    </style>
</head>

<body class="contrast-bg">

    <div class="parent-container">
        <div class="topbar d-flex">
            <button type="button" id="sidebarTrigger" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
                <i class="bi bi-list"></i>
            </button>
            <div class="col d-flex flex-column px-3">
                <h1 class="bold">Profile Settings</h1>
            </div>
        </div>

        <div class="child-container">
            <div class="main-container p-4">
                <div class="group-container gap-3 w-100">
                    <!-- Profile Card -->
                    <div class="card w-100"
                        style="border-radius: 16px; box-shadow: 0 8px 32px rgba(47,0,79,0.18); background: #ffffff;">
                        <!-- Profile Header -->
                        <div class="d-flex align-items-center gap-4 mb-4 p-4" style="border-bottom: 1px solid #eee;">
                            <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:100px; height:100px; font-size:2rem; background:#d9d6d6; color: var(--secondary-color-1); border-radius:50%;">
                                <h2 class="bold mb-0">AD</h2>
                            </div>
                            <div>
                                <h2 class="bold mb-1" style="color: var(--secondary-color-1);"><?= $admin_name ?></h2>
                                <p class="mb-0" style="color: #666; font-size: 0.95rem;">Administrator</p>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <div class="p-4">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="bold mb-0" style="color: var(--secondary-color-1);">Contact Information</h5>
                                <button class="light info-action-btn"
                                    style="width:auto; padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;"
                                    data-bs-toggle="modal" data-bs-target="#editContactModal">
                                    <i class="bi bi-pencil me-1"></i> Edit
                                </button>
                            </div>

                            <div style="border: 1.5px solid #ddd; border-radius: 10px; padding: 20px;">
                                <div class="mb-3">
                                    <label style="color: #888; font-size: 0.85rem; font-weight: 500;">Email</label>
                                    <div
                                        style="background: #f0f0f0; padding: 10px 12px; border-radius: 6px; color: #333;">
                                        <?= $admin_email ?></div>
                                </div>
                                <div class="mb-3">
                                    <label style="color: #888; font-size: 0.85rem; font-weight: 500;">Account
                                        Created</label>
                                    <div
                                        style="background: #f0f0f0; padding: 10px 12px; border-radius: 6px; color: #333;">
                                        May 8, 2026</div>
                                </div>
                                <div class="mb-3">
                                    <label style="color: #888; font-size: 0.85rem; font-weight: 500;">Department</label>
                                    <div
                                        style="background: #f0f0f0; padding: 10px 12px; border-radius: 6px; color: #333;">
                                        N/A</div>
                                </div>
                                <div>
                                    <label style="color: #888; font-size: 0.85rem; font-weight: 500;">Address</label>
                                    <div
                                        style="background: #f0f0f0; padding: 10px 12px; border-radius: 6px; color: #333;">
                                        N/A</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script src="../../script/animations.js"></script>
            <script src="../../script/toggles.js"></script>
            <script src="../../script/initialize-gesture.js"></script>




            <!-- ═══ SIDEBAR OFFCANVAS ═══ -->
            <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas"
                aria-labelledby="sidebarOffcanvasLabel">
                <div class="offcanvas-header justify-content-center">
                    <img src="../../images/logo.png" class="logo" alt="Logo">
                </div>
                <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
                    <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')"><i
                            class="bi bi-house-door"></i></button>
                    <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')"><i
                            class="fa-solid fa-person-shelter"></i></button>
                    <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')"><i
                            class="bi bi-clipboard2-data"></i></button>
                    <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')"><i
                            class="bi bi-exclamation-triangle"></i></button>
                    <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')"><i
                            class="bi bi-people"></i></button>
                    <button class="nav-btn" title="Profile Settings"
                        onclick="dissolve('admin-profile-settings.php')"><i class="bi bi-gear"></i></button>
                </div>
                <div class="offcanvas-footer">
                    <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
                </div>
            </div>

            <!-- ═══ PROFILE OFFCANVAS ═══ -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas"
                aria-labelledby="profileOffcanvasLabel">
                <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
                    <div class="avatar-icon d-flex align-items-center justify-content-center">
                        <h3 class="bold"><?= $initials ?></h3> 
                    </div>
                    <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
                    <h6 class="light" style="word-break:break-all;text-align:center;"><?=  $admin_email ?></h6>
                    <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                        <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile
                            Settings</button>
                        <button class="profile-btn">Classroom Details</button>
                        <button class="profile-btn" onclick="window.location.href='../../index.php'">Logout</button>
                    </div>
                </div>
            </div>

</body>

</html>