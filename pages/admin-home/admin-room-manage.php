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
    <title>Room Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Shared stylesheets -->
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        /* ══════════════════════════════════════
           TOPBAR OVERRIDE (matches other admin pages)
        ══════════════════════════════════════ */
        .topbar {
            background: linear-gradient(0deg,rgba(255,255,255,0) 9%,rgba(47,0,79,.76) 40%,rgba(47,0,79,.95) 70%,rgba(47,0,79,1) 100%);
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center;
            padding: 16px 24px; gap: 12px;
        }
        .topbar button {
            background-color: var(--primary-color);
            color: var(--secondary-color-1);
            border: none; border-radius: 10px;
            height: 50px; width: 50px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; cursor: pointer;
        }
        .topbar button i { font-size: 24px; }
        .topbar-title { flex:1; color:var(--primary-color); font-size:28px; font-weight:700; margin:0; }
        .topbar-right { display:flex; align-items:center; gap:14px; }
        .topbar-admin { color:var(--primary-color); font-size:16px; white-space:nowrap; }

        /* ══════════════════════════════════════
           PAGE
        ══════════════════════════════════════ */
        .page-content { padding: 0 24px 40px; }

        .section-heading {
            color: var(--primary-color); font-size: 13px; font-weight: 600;
            letter-spacing: .10em; text-transform: uppercase;
            margin: 24px 0 14px; opacity: .75;
        }

        /* ══════════════════════════════════════
           ROOM CARDS GRID
        ══════════════════════════════════════ */
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 20px;
        }

        /* ── Room card ── */
        .room-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 6px 28px rgba(47,0,79,.16);
            overflow: hidden;
            display: flex; flex-direction: column;
            transition: transform .22s cubic-bezier(.34,1.56,.64,1), box-shadow .22s ease;
        }
        .room-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 40px rgba(47,0,79,.28);
        }

        /* colour accent strip */
        .room-card-accent { height: 6px; width: 100%; }
        .accent-occupied  { background: linear-gradient(90deg,#c0004e,#e05580); }
        .accent-vacant    { background: linear-gradient(90deg,#0a7a45,#27ae60); }
        .accent-scheduled { background: linear-gradient(90deg,#a06800,#f0a500); }

        .room-card-body {
            padding: 18px 20px 14px;
            display: flex; flex-direction: column; gap: 10px;
            flex: 1;
        }

        .room-card-header {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 10px;
        }

        .room-card-name {
            font-size: 17px; font-weight: 700;
            color: var(--secondary-color-1); line-height: 1.25;
        }
        .room-card-section { font-size: 12px; color: #999; margin-top: 2px; }

        /* status badge */
        .room-status-badge {
            display: inline-flex; align-items: center;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .04em; white-space: nowrap; flex-shrink: 0;
        }
        .badge-occupied  { background: #ffe4ec; color: #c0004e; }
        .badge-vacant    { background: #d6fbe9; color: #0a7a45; }
        .badge-scheduled { background: #fff5d6; color: #a06800; }

        /* info rows */
        .room-info-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--secondary-color-1);
        }
        .room-info-row i { font-size: 14px; color: var(--secondary-color-3); width: 16px; flex-shrink: 0; }
        .room-info-label { color: #999; font-size: 12px; }
        .room-info-val   { font-weight: 600; }

        /* lighting dot */
        .light-dot {
            display: inline-block; width: 8px; height: 8px;
            border-radius: 50%; margin-right: 4px;
        }
        .light-dot.on  { background: #27ae60; box-shadow: 0 0 5px #27ae60; }
        .light-dot.off { background: #ccc; }

        .room-card-divider { border: none; border-top: 1px solid #f0eaf8; margin: 2px 0; }

        /* action buttons */
        .room-card-actions { display: flex; gap: 8px; padding: 0 20px 18px; }

        .btn-room-view {
            flex: 1; padding: 10px 0; border-radius: 11px;
            font-family: var(--font-primary); font-size: 13px; font-weight: 600;
            border: none; cursor: pointer;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            transition: background-color .2s, transform .15s;
        }
        .btn-room-view:hover { background-color: var(--secondary-color-4); transform: scale(1.02); color: var(--primary-color); }

        .btn-room-timetable {
            flex: 1; padding: 10px 0; border-radius: 11px;
            font-family: var(--font-primary); font-size: 13px; font-weight: 600;
            border: 1.5px solid var(--secondary-color-2); cursor: pointer;
            background: transparent; color: var(--secondary-color-1);
            transition: background-color .2s, transform .15s, color .2s;
        }
        .btn-room-timetable:hover {
            background-color: var(--secondary-color-1); color: var(--primary-color); transform: scale(1.02);
        }

        /* ══════════════════════════════════════
           MODAL TWEAKS
        ══════════════════════════════════════ */
        .room-details-modal .modal-header {
            background: linear-gradient(135deg,#2d0d5f 0%,#4a1d8f 100%); color: #fff;
        }
        .room-details-modal .modal-title { font-weight: 700; font-size: 1.35rem; }

        .btn-timetable-full {
            padding: 8px 16px; border-radius: 8px;
            font-family: var(--font-primary); font-size: 13px; font-weight: 600;
            border: none; cursor: pointer;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            transition: background-color .2s, transform .15s;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px; width: auto;
        }
        .btn-timetable-full:hover { background-color: var(--secondary-color-4); color: var(--primary-color); transform: scale(1.02); }

        /* ══════════════════════════════════════
           SIDEBAR / PROFILE OFFCANVAS
        ══════════════════════════════════════ */
        .nav-btn {
            width: 52px; height: 52px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            border: none; cursor: pointer; transition: background-color .2s, transform .15s;
        }
        .nav-btn i, .nav-btn svg { font-size: 22px; }
        .nav-btn:hover { background-color: var(--secondary-color-4); transform: scale(1.06); }

        #sidebarOffcanvas { width: 100px !important; background-color: var(--primary-color); }
        #sidebarOffcanvas .offcanvas-header { justify-content: center; padding: 1rem .5rem; }
        #sidebarOffcanvas .logo { width: 75px; height: 75px; object-fit: contain; }
        #sidebarOffcanvas .offcanvas-body { display: flex; flex-direction: column; align-items: center; gap: 8px; padding-top: .5rem; }
        #sidebarOffcanvas .offcanvas-footer { display: flex; justify-content: center; padding: 1rem; }
        #sidebarOffcanvas .offcanvas-footer img { width: 4rem; }

        #profileOffcanvas { width: 240px !important; background-color: var(--primary-color); }
        #profileOffcanvas .avatar-icon { width: 80px; height: 80px; border-radius: 50%; background: #d9d6d6; color: var(--secondary-color-1); }

        .profile-btn {
            width: 100%; padding: 8px; margin: 3px 0; border-radius: 8px;
            background-color: var(--secondary-color-1); color: var(--primary-color);
            border: none; font-size: 14px; cursor: pointer;
            font-family: var(--font-primary); transition: background-color .2s, transform .15s;
        }
        .profile-btn:hover { background-color: var(--secondary-color-4); transform: scale(1.02); }

        @media(max-width:600px){
            .search-input { width: 140px; }
            .topbar-admin { display: none; }
        }
    </style>
</head>
<body class="contrast-bg">

    <!-- ═══ TOPBAR ═══ -->
    <div class="topbar">
        <button type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="topbar-title bold">Room Management</h1>
        <div class="topbar-right">
            <div class="search-container">
                <input type="text" class="search-input" id="roomSearch"
                       placeholder="Search rooms…" oninput="filterRooms(this.value)">
                <i class="bi bi-search search-icon"></i>
            </div>
            <span class="topbar-admin"><?= $admin_name ?></span>
            <div class="avatar-icon d-flex align-items-center justify-content-center"
                 data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">
                <h3 class="bold mb-0"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <!-- ═══ PAGE CONTENT ═══ -->
    <div class="page-content">
        <div class="section-heading">All Rooms</div>

        <!-- ALERT: PHP | DISPLAY — replace static cards with a DB loop -->
        <div class="rooms-grid" id="roomsGrid">

            <!-- ── Room 1: Grade 6 Narra (Occupied) ── -->
            <div class="room-card" data-room="Grade 6 Narra">
                <div class="room-card-accent accent-occupied"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">Grade 6 Narra</div>
                            <div class="room-card-section">Building A &middot; Floor 2</div>
                        </div>
                        <span class="room-status-badge badge-occupied">Occupied</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">John Doe</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">Mathematics</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Time:&nbsp;</span><span class="room-info-val">4:30 PM &ndash; 5:30 PM</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot off"></span><span class="room-info-val">OFF</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('Grade 6 Narra')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=Grade+6+Narra')">Timetable</button>
                </div>
            </div>

            <!-- ── Room 2: SEL 08 (Vacant) ── -->
            <div class="room-card" data-room="SEL 08">
                <div class="room-card-accent accent-vacant"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">SEL 08</div>
                            <div class="room-card-section">Building B &middot; Floor 1</div>
                        </div>
                        <span class="room-status-badge badge-vacant">Vacant</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">&mdash;</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">&mdash;</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Next class:&nbsp;</span><span class="room-info-val">2:00 PM</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot off"></span><span class="room-info-val">OFF</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('SEL 08')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=SEL+08')">Timetable</button>
                </div>
            </div>

            <!-- ── Room 3: SEL 11 (Scheduled) ── -->
            <div class="room-card" data-room="SEL 11">
                <div class="room-card-accent accent-scheduled"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">SEL 11</div>
                            <div class="room-card-section">Building B &middot; Floor 2</div>
                        </div>
                        <span class="room-status-badge badge-scheduled">Scheduled</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">Maria Santos</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">Filipino</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Time:&nbsp;</span><span class="room-info-val">3:00 PM &ndash; 4:00 PM</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot on"></span><span class="room-info-val">ON</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('SEL 11')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=SEL+11')">Timetable</button>
                </div>
            </div>

            <!-- ── Room 4: SEL 05 (Occupied) ── -->
            <div class="room-card" data-room="SEL 05">
                <div class="room-card-accent accent-occupied"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">SEL 05</div>
                            <div class="room-card-section">Building B &middot; Floor 1</div>
                        </div>
                        <span class="room-status-badge badge-occupied">Occupied</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">Carlos Reyes</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">Science</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Time:&nbsp;</span><span class="room-info-val">1:00 PM &ndash; 2:00 PM</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot on"></span><span class="room-info-val">ON</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('SEL 05')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=SEL+05')">Timetable</button>
                </div>
            </div>

            <!-- ── Room 5: Rich Nest (Vacant) ── -->
            <div class="room-card" data-room="Rich Nest">
                <div class="room-card-accent accent-vacant"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">Rich Nest</div>
                            <div class="room-card-section">Building C &middot; Floor 1</div>
                        </div>
                        <span class="room-status-badge badge-vacant">Vacant</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">&mdash;</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">&mdash;</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Next class:&nbsp;</span><span class="room-info-val">None today</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot off"></span><span class="room-info-val">OFF</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('Rich Nest')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=Rich+Nest')">Timetable</button>
                </div>
            </div>

            <!-- ── Room 6: Consultation Room (Scheduled) ── -->
            <div class="room-card" data-room="Consultation Room">
                <div class="room-card-accent accent-scheduled"></div>
                <div class="room-card-body">
                    <div class="room-card-header">
                        <div>
                            <div class="room-card-name">Consultation Room</div>
                            <div class="room-card-section">Building A &middot; Floor 1</div>
                        </div>
                        <span class="room-status-badge badge-scheduled">Scheduled</span>
                    </div>
                    <hr class="room-card-divider">
                    <div class="room-info-row"><i class="bi bi-person-fill"></i><span class="room-info-label">Faculty:&nbsp;</span><span class="room-info-val">Ana Lim</span></div>
                    <div class="room-info-row"><i class="bi bi-book-fill"></i><span class="room-info-label">Subject:&nbsp;</span><span class="room-info-val">Consultation</span></div>
                    <div class="room-info-row"><i class="bi bi-clock-fill"></i><span class="room-info-label">Time:&nbsp;</span><span class="room-info-val">5:00 PM &ndash; 6:00 PM</span></div>
                    <div class="room-info-row"><i class="bi bi-lightbulb-fill"></i><span class="room-info-label">Lighting:&nbsp;</span><span><span class="light-dot off"></span><span class="room-info-val">OFF</span></span></div>
                </div>
                <div class="room-card-actions">
                    <button class="btn-room-view" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="setModalRoom('Consultation Room')">View</button>
                    <button class="btn-room-timetable" onclick="dissolve('admin-timetable-manage.php?room=Consultation+Room')">Timetable</button>
                </div>
            </div>

        </div><!-- /rooms-grid -->
    </div><!-- /page-content -->


    <!-- ═══ ROOM DETAILS MODAL ═══ -->
    <div class="room-details-modal modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roomModalLabel">Room Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-row gap-3 align-items-start flex-wrap">

                        <!-- Left: Schedule + lighting -->
                        <div class="d-flex flex-column gap-3" style="flex:1;min-width:260px;">
                            <div style="background:#fff;border-radius:12px;padding:20px;border:1px solid #eee;">
                                <h6 class="bold mb-3">Current Schedule</h6>
                                <div class="d-flex align-items-start gap-3">
                                    <div class="avatar-icon d-flex align-items-center justify-content-center"
                                         style="width:54px;height:54px;flex-shrink:0;">
                                        <h5 class="mb-0">JD</h5>
                                    </div>
                                    <div style="flex:1;">
                                        <p class="bold mb-0" style="font-size:1.05rem;">John Doe</p>
                                        <small class="text-muted">Faculty Member</small>
                                        <hr style="margin:8px 0;">
                                        <p class="mb-1" style="font-size:13px;">Status: <span class="fw-bold">Occupied</span></p>
                                        <p class="mb-0" style="font-size:13px;">Time: <span class="fw-bold">4:30 PM &ndash; 5:30 PM</span></p>
                                    </div>
                                    <button class="light" style="width:auto;padding:6px 14px;border-radius:8px;font-size:12px;">Details</button>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between" style="font-size:13px;">
                                    <span>Lighting: <span class="text-success fw-bold">ON</span></span>
                                    <span>PIR Sensor: <span class="text-success fw-bold">ACTIVE</span></span>
                                </div>
                                <!-- Lighting grid -->
                                <div class="d-flex align-items-center justify-content-center mt-3 gap-3">
                                    <div class="lighting-grid">
                                        <img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png">
                                        <hr class="w-100">
                                        <img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png">
                                        <hr class="w-100">
                                        <img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png"><img src="../../images/bulb-off.png">
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach([1,2,3] as $row): ?>
                                        <div class="d-flex flex-column align-items-center">
                                            <label class="form-check-label" style="font-size:12px;">Row <?= $row ?></label>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch">
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="d-flex flex-column align-items-center mt-1">
                                            <h6 class="bold mb-0" style="font-size:12px;">All Lights</h6>
                                            <h5 class="bold mb-1" style="color:red;font-size:13px;">OFF</h5>
                                            <div class="all-lights-off d-flex align-items-center justify-content-center">
                                                <i class="bi bi-power"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Timetable + Alerts -->
                        <div class="d-flex flex-column gap-3" style="flex:1;min-width:220px;">
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Timetable</h6>
                                    <a href="admin-timetable-manage.php?room=Grade+6+Narra"
                                       class="btn-timetable-full" id="modalTimetableLink">
                                        <i class="bi bi-calendar3"></i> View Full
                                    </a>
                                </div>
                                <div style="background:#fff;border-radius:8px;padding:12px;">
                                    <p class="mb-1" style="font-size:13px;"><span class="fw-bold">08:00</span> &ndash; Mathematics &middot; John Doe</p>
                                    <p class="mb-1" style="font-size:13px;"><span class="fw-bold">10:00</span> &ndash; Science &middot; Maria Santos</p>
                                    <p class="mb-1" style="font-size:13px;"><span class="fw-bold">13:00</span> &ndash; Filipino &middot; Carlos Reyes</p>
                                    <p class="mb-0" style="font-size:13px;"><span class="fw-bold">15:30</span> &ndash; English &middot; Ana Lim</p>
                                </div>
                            </div>
                            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="bold mb-0">Room Alerts</h6>
                                    <button class="light" style="width:auto;padding:5px 14px;border-radius:8px;font-size:12px;">Details</button>
                                </div>
                                <div class="activity-list px-1">
                                    <div>
                                        <p class="mb-0 bold" style="font-size:.88rem;">Detected Motion</p>
                                        <small class="text-muted">10:30 AM &middot; Feb 9, 2026</small>
                                    </div>
                                    <hr>
                                    <div>
                                        <p class="mb-0 bold" style="font-size:.88rem;">Lights Left On</p>
                                        <small class="text-muted">11:31 AM &middot; Feb 9, 2026</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══ SIDEBAR OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
        <div class="offcanvas-header justify-content-center">
            <img src="../../images/logo.png" class="logo" alt="Logo">
        </div>
        <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
            <button class="nav-btn" title="Home"            onclick="dissolve('admin-homepage.php')"><i class="bi bi-house-door"></i></button>
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')"><i class="fa-solid fa-person-shelter"></i></button>
            <button class="nav-btn" title="Analytics"       onclick="dissolve('admin-analytics.php')"><i class="bi bi-clipboard2-data"></i></button>
            <button class="nav-btn" title="Reports"         onclick="dissolve('admin-reports.php')"><i class="bi bi-exclamation-triangle"></i></button>
            <button class="nav-btn" title="Faculty"         onclick="dissolve('admin-faculty-management.php')"><i class="bi bi-people"></i></button>
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')"><i class="bi bi-gear"></i></button>
        </div>
        <div class="offcanvas-footer">
            <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
        </div>
    </div>

    <!-- ═══ PROFILE OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
            <div class="avatar-icon d-flex align-items-center justify-content-center">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
            <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
            <h6 class="light" style="word-break:break-all;text-align:center;"><?= $admin_email ?></h6>
            <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
                <button class="profile-btn">Classroom Details</button>
                <button class="profile-btn" onclick="window.location.href='../../index.php'">Logout</button>
            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/initialize-gesture.js"></script>
    <script>
        function filterRooms(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('#roomsGrid .room-card').forEach(card => {
                const name = (card.dataset.room || '').toLowerCase();
                card.style.display = (!q || name.includes(q)) ? '' : 'none';
            });
        }

        function setModalRoom(roomName) {
            document.getElementById('roomModalLabel').textContent = roomName;
            const link = document.getElementById('modalTimetableLink');
            if (link) link.href = 'admin-timetable-manage.php?room=' + encodeURIComponent(roomName);
        }
    </script>
</body>
</html>