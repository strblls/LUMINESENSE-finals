<?php
require_once '../../php/includes/admin-head.php';

$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description,
           COALESCE(l.event_type, 'off') AS light_status
    FROM classrooms c
    LEFT JOIN lighting_logs l
           ON l.id = (SELECT MAX(id) FROM lighting_logs WHERE classroom_id = c.id)
    ORDER BY c.room_name
");
while ($row = $r->fetch_assoc()) $classrooms[] = $row;
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

    <?php include '../../php/includes/admin-topbar.php'; ?>

    <!-- ═══ PAGE CONTENT ═══ -->
    <div class="page-content">
        <div class="section-heading">All Rooms</div>

        <div class="rooms-grid" id="roomsGrid">
        <?php foreach ($classrooms as $c):
            $on         = ($c['light_status'] === 'on');
            $curSched   = getCurrentSchedule($conn, $c['id']);
            $isOccupied = !empty($curSched);
            $fName      = $isOccupied ? $curSched['faculty_name'] : '—';

            if ($isOccupied) {
                $accentClass = 'accent-occupied';
                $badgeClass  = 'badge-occupied';
                $badgeLabel  = 'Occupied';
            } elseif (!empty(getRoomSchedules($conn, $c['id']))) {
                $accentClass = 'accent-scheduled';
                $badgeClass  = 'badge-scheduled';
                $badgeLabel  = 'Scheduled';
            } else {
                $accentClass = 'accent-vacant';
                $badgeClass  = 'badge-vacant';
                $badgeLabel  = 'Vacant';
            }

            $nextSched = null;
            if (!$isOccupied) {
                $day  = date('l');
                $time = date('H:i:s');
                $st = $conn->prepare("SELECT start_time FROM schedules WHERE classroom_id=? AND day_of_week=? AND start_time>? ORDER BY start_time LIMIT 1");
                $st->bind_param('iss', $c['id'], $day, $time);
                $st->execute();
                $st->bind_result($nextTime);
                $st->fetch();
                $st->close();
                if ($nextTime) $nextSched = date('g:i A', strtotime($nextTime));
            }
        ?>
        <div class="room-card" data-room="<?= htmlspecialchars(strtolower($c['room_name'])) ?>">
            <div class="room-card-accent <?= $accentClass ?>"></div>
            <div class="room-card-body">
                <div class="room-card-header">
                    <div>
                        <div class="room-card-name"><?= htmlspecialchars($c['room_name']) ?></div>
                        <div class="room-card-section">
                            <?= ucfirst($c['room_size']) ?> room
                            <?php if (!empty($c['description'])): ?>
                                &middot; <?= htmlspecialchars($c['description']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <button style="background:none;border:none;cursor:pointer;color:#aaa;font-size:14px;padding:2px 5px;"
                            title="Edit"
                            onclick="openEditModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button style="background:none;border:none;cursor:pointer;color:#aaa;font-size:14px;padding:2px 5px;"
                            title="Delete"
                            onclick="openDeleteModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                        <span class="room-status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                    </div>
                </div>
                <hr class="room-card-divider">
                <div class="room-info-row">
                    <i class="bi bi-person-fill"></i>
                    <span class="room-info-label">Faculty:&nbsp;</span>
                    <span class="room-info-val"><?= htmlspecialchars($fName) ?></span>
                </div>
                <div class="room-info-row">
                    <i class="bi bi-clock-fill"></i>
                    <span class="room-info-label">
                        <?= $isOccupied ? 'Time:' : 'Next class:' ?>&nbsp;
                    </span>
                    <span class="room-info-val">
                        <?php if ($isOccupied): ?>
                            <?= date('g:i A', strtotime($curSched['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($curSched['end_time'])) ?>
                        <?php else: ?>
                            <?= $nextSched ?? 'None today' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="room-info-row">
                    <i class="bi bi-lightbulb-fill"></i>
                    <span class="room-info-label">Lighting:&nbsp;</span>
                    <span>
                        <span class="light-dot <?= $on ? 'on' : 'off' ?>"></span>
                        <span class="room-info-val"><?= $on ? 'ON' : 'OFF' ?></span>
                    </span>
                </div>
            </div>
            <div class="room-card-actions">
                <button class="btn-room-view"
                    onclick="openRoomModal(<?= $c['id'] ?>, '<?= addslashes($c['room_name']) ?>', '<?= $c['room_size'] ?>', '<?= addslashes($c['description']) ?>')">
                    View
                </button>
                <button class="btn-room-timetable"
                    onclick="dissolve('admin-timetable-manage.php?room=<?= urlencode($c['room_name']) ?>')">
                    Timetable
                </button>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Add Room card -->
        <div class="room-card" style="border:2px dashed #bbb;background:#fafafa;box-shadow:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:#aaa;min-height:200px;"
            onclick="new bootstrap.Modal(document.getElementById('addRoomModal')).show()">
            <i class="bi bi-plus-circle" style="font-size:2rem;"></i>
            <span style="font-size:.85rem;font-weight:600;">Add Room</span>
        </div>

        </div><!-- /rooms-grid -->
    </div><!-- /page-content -->

    <!-- ═══ ADD ROOM MODAL ═══ -->
    <div class="modal fade" id="addRoomModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Add New Room</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST" action="../../php/handlers/room-handler.php">
            <input type="hidden" name="action" value="add_room">
            <div class="modal-body d-flex flex-column gap-3">
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                <input type="text" name="room_name" class="form-control" placeholder="e.g. Grade 7 – Acacia" required>
              </div>
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                <select name="room_size" class="form-select">
                  <option value="small">Small (7×7 m)</option>
                  <option value="medium" selected>Medium (7×9 m)</option>
                  <option value="large">Large (9×10 m+)</option>
                </select>
              </div>
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Description <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="description" class="form-control" placeholder="e.g. Near library, 2nd floor">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="medium">Add Room</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ═══ EDIT ROOM MODAL ═══ -->
    <div class="modal fade" id="editRoomModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Edit Room</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form method="POST" action="../../php/handlers/room-handler.php">
            <input type="hidden" name="action" value="edit_room">
            <input type="hidden" name="room_id" id="editRoomId">
            <div class="modal-body d-flex flex-column gap-3">
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Name</label>
                <input type="text" name="room_name" id="editRoomName" class="form-control" required>
              </div>
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Room Size</label>
                <select name="room_size" id="editRoomSize" class="form-select">
                  <option value="small">Small (7×7 m)</option>
                  <option value="medium">Medium (7×9 m)</option>
                  <option value="large">Large (9×10 m+)</option>
                </select>
              </div>
              <div>
                <label class="form-label" style="font-size:.85rem;font-weight:600;">Description</label>
                <input type="text" name="description" id="editRoomDesc" class="form-control">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="medium">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ═══ DELETE ROOM MODAL ═══ -->
    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Delete Room</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" style="min-height:420px;">
            Are you sure you want to delete <strong id="deleteRoomName"></strong>?
            This will also remove all schedules and logs for this room.
          </div>
          <form method="POST" action="../../php/handlers/room-handler.php">
            <input type="hidden" name="action" value="delete_room">
            <input type="hidden" name="room_id" id="deleteRoomId">
            <div class="modal-footer">
              <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="medium" style="background:#c0392b;">Delete</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    

    <!-- ═══ ROOM DETAILS MODAL ═══ -->
    <div class="room-details-modal modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roomModalLabel">Room Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
    <div class="d-flex flex-row gap-3 align-items-start flex-wrap">

        <!-- Left: Schedule + lighting -->
        <div class="d-flex flex-column gap-3" style="flex:1;min-width:280px;max-width:380px;">
            <div style="background:#fff;border-radius:12px;padding:20px;border:1px solid #eee;">
                <h6 class="bold mb-3">Current Schedule</h6>
                <div id="modalCurrentSched">
                    <p class="text-muted" style="font-size:.85rem;">Loading…</p>
                </div>
                <hr>

                <!-- Lighting grid -->
                <div class="d-flex align-items-center justify-content-center mt-3 gap-3">
                    <div class="lighting-grid">
                        <?php for ($i = 0; $i < 9; $i++): ?>
                            <img src="../../images/bulb-off.png" id="bulb<?= $i ?>"
                                style="width:36px;height:36px;object-fit:contain;">
                        <?php endfor; ?>
                    </div>
                    <div class="d-flex flex-column justify-content-between" style="gap:6px;">
                        <?php foreach([1,2,3] as $row): ?>
                        <div class="d-flex align-items-center gap-2">
                            <label style="font-size:11px;font-weight:600;width:36px;color:#555;">Row <?= $row ?></label>
                            <div class="form-check form-switch mb-0" style="transform:scale(0.8);transform-origin:left;">
                                <input class="form-check-input" type="checkbox" role="switch"
                                    id="row<?= $row ?>sw"
                                    onchange="toggleRow(<?= $row ?>, this.checked)">
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="d-flex align-items-center gap-2 mt-1" style="cursor:pointer;" onclick="toggleAllLights()">
                            <span style="font-size:11px;font-weight:700;color:#555;">All</span>
                            <div class="all-lights-off d-flex align-items-center justify-content-center">
                                <i class="bi bi-power" id="all-lights"></i>
                            </div>
                            <span class="bold" id="allLightsLabel" style="font-size:11px;color:red;">OFF</span>
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
                    <button class="btn-timetable-full" data-bs-toggle="collapse" data-bs-target="#timetableCollapse">
                        <i class="bi bi-calendar3"></i> View Full
                    </button>
                </div>
                <div id="modalTodaySched" style="background:#fff;border-radius:8px;padding:12px;font-size:13px;">
                    <em class="text-muted">Loading…</em>
                </div>
                <div class="collapse mt-2" id="timetableCollapse">
                    <table class="table table-sm mt-1" style="font-size:.82rem;">
                        <thead><tr>
                            <th style="background:var(--secondary-color-1);color:#fff;">Day</th>
                            <th style="background:var(--secondary-color-1);color:#fff;">Time</th>
                            <th style="background:var(--secondary-color-1);color:#fff;">Faculty</th>
                        </tr></thead>
                        <tbody id="modalTimetableBody">
                            <tr><td colspan="3" class="text-muted text-center">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div style="background:#f8f9fa;border-radius:12px;padding:16px;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="bold mb-0">Room Alerts</h6>
                    <button class="light" style="width:auto;padding:5px 14px;border-radius:8px;font-size:12px;"
                        data-bs-toggle="collapse" data-bs-target="#alertsCollapse">Details</button>
                </div>
                <div class="activity-list px-1" id="modalAlertsPreview">
                    <em class="text-muted" style="font-size:.82rem;">Loading…</em>
                </div>
                <div class="collapse mt-2" id="alertsCollapse">
                    <div id="modalAlertsFull" style="max-height:200px;overflow-y:auto;" class="activity-list px-1"></div>
                </div>
            </div>
        </div>

    </div>
</div>

                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/initialize-gesture.js"></script>
    <script>
        function filterRooms(q) {
        const query = q.toLowerCase().trim();
        document.querySelectorAll('#roomsGrid .room-card').forEach(card => {
            const name = (card.dataset.room || '').toLowerCase();
            card.style.display = (!query || name.includes(query)) ? '' : 'none';
        });
    }

        function setModalRoom(roomName) {
            document.getElementById('roomModalLabel').textContent = roomName;
            const link = document.getElementById('modalTimetableLink');
            if (link) link.href = 'admin-timetable-manage.php?room=' + encodeURIComponent(roomName);
        }

                // ── Search filter ──────────────────────────────────────────────────────────
        const roomSearchEl = document.getElementById('roomSearch');
        if (roomSearchEl) roomSearchEl.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.room-card').forEach(card => {
                card.style.display = card.dataset.roomName.includes(q) ? '' : 'none';
            });
        });

        // ── Edit modal ─────────────────────────────────────────────────────────────
        function openEditModal(id, name, size, desc) {
            document.getElementById('editRoomId').value   = id;
            document.getElementById('editRoomName').value = name;
            document.getElementById('editRoomDesc').value = desc;
            const sel = document.getElementById('editRoomSize');
            for (let o of sel.options) o.selected = (o.value === size);
            new bootstrap.Modal(document.getElementById('editRoomModal')).show();
        }

        // ── Delete modal ───────────────────────────────────────────────────────────
        function openDeleteModal(id, name) {
            document.getElementById('deleteRoomId').value    = id;
            document.getElementById('deleteRoomName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteRoomModal')).show();
        }

        // ── Room details modal ─────────────────────────────────────────────────────
        function openRoomModal(id, name, size, desc) {
            // Set header info
            document.getElementById('roomModalLabel').textContent = name;
            
            document.getElementById('modalCurrentSched').innerHTML    = '<p class="text-muted" style="font-size:.85rem;">Loading…</p>';
            document.getElementById('modalTodaySched').innerHTML      = '<em class="text-muted">Loading…</em>';
            document.getElementById('modalTimetableBody').innerHTML   = '<tr><td colspan="3" class="text-muted text-center">Loading…</td></tr>';
            document.getElementById('modalAlertsPreview').innerHTML   = '<em class="text-muted">Loading…</em>';
            document.getElementById('modalAlertsFull').innerHTML      = '<em class="text-muted">Loading…</em>';

            new bootstrap.Modal(document.getElementById('roomModal')).show();

            // Fetch room data from AJAX endpoint
            fetch('ajax-room-data.php?room_id=' + id)
                .then(r => r.json())
                .then(data => renderRoomModal(data))
                .catch(() => {
                    document.getElementById('modalCurrentSched').innerHTML = '<p class="text-danger">Failed to load data.</p>';
                });
        }

        function renderRoomModal(data) {
            // ── Current Schedule ──
            const schedEl = document.getElementById('modalCurrentSched');
            if (data.current_schedule) {
                const s = data.current_schedule;
                schedEl.innerHTML = `
                    <div class="d-flex align-items-center gap-3">
                        <div class="avatar-icon d-flex align-items-center justify-content-center" style="width:48px;height:48px;font-size:1rem;">
                            <span class="bold">${s.initials}</span>
                        </div>
                        <div>
                            <p class="bold mb-0" style="font-size:.9rem;">${s.faculty_name}</p>
                            <small class="text-muted">Faculty Member</small>
                            <div style="font-size:.82rem; margin-top:.3rem;">
                                Room: <strong>Occupied</strong> &nbsp;·&nbsp;
                                ${s.start_time} – ${s.end_time}
                            </div>
                        </div>
                    </div>`;
            } else {
                schedEl.innerHTML = '<p class="text-muted" style="font-size:.85rem;">No active class right now.</p>';
            }

            // ── Sensor / camera status ──
            const setStatus = (elId, ok, onText, offText) => {
                const el = document.getElementById(elId);
                el.textContent = ok ? onText : offText;
                el.className = 'status-pill ' + (ok ? 'pill-ok' : 'pill-off');
            };
            setStatus('modalLightStatus', data.light_on,  'ON',      'OFF');
            setStatus('modalPirStatus',   data.pir_active, 'Active',  'Disconnected');
            setStatus('modalCamStatus',   data.cam_active, 'Active',  'Disabled');

            // ── Bulb grid ──
            for (let i = 0; i < 9; i++) {
                const img = document.getElementById('bulb' + i);
                if (img) img.src = data.light_on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
            }

            // ── Today's schedule summary ──
            const todayEl = document.getElementById('modalTodaySched');
            if (data.today_schedules && data.today_schedules.length > 0) {
                todayEl.innerHTML = data.today_schedules.map(s =>
                    `<div class="sched-block">
                        <div style="font-weight:600;">${s.start_time} – ${s.end_time}</div>
                        <small>${s.faculty_name}</small>
                    </div>`
                ).join('');
            } else {
                todayEl.innerHTML = '<p class="text-muted mb-0" style="font-size:.82rem;">No classes scheduled today.</p>';
            }

            // ── Full timetable ──
            const tBody = document.getElementById('modalTimetableBody');
            if (data.all_schedules && data.all_schedules.length > 0) {
                tBody.innerHTML = data.all_schedules.map(s =>
                    `<tr>
                        <td>${s.day_of_week}</td>
                        <td>${s.start_time} – ${s.end_time}</td>
                        <td>${s.faculty_name}</td>
                    </tr>`
                ).join('');
            } else {
                tBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center">No schedules yet.</td></tr>';
            }

            // ── Alerts / Activity ──
            const previewEl = document.getElementById('modalAlertsPreview');
            const fullEl    = document.getElementById('modalAlertsFull');

            if (data.alerts && data.alerts.length > 0) {
                const renderAlert = a => `
                    <div class="alert-log-item">
                        <span class="status-pill ${a.event_type === 'security_alert' ? 'pill-warn' : 'pill-ok'}" style="margin-right:.4rem;">
                            ${a.event_type.replace('_',' ')}
                        </span>
                        ${a.triggered_by ? '<span style="color:#555;">' + a.triggered_by + '</span>' : ''}
                        <span class="text-muted ms-1">${a.event_time}</span>
                    </div>`;
                previewEl.innerHTML = data.alerts.slice(0, 3).map(renderAlert).join('');
                fullEl.innerHTML    = data.alerts.map(renderAlert).join('');
            } else {
                previewEl.innerHTML = '<p class="text-muted mb-0" style="font-size:.82rem;">No activity today.</p>';
                fullEl.innerHTML    = previewEl.innerHTML;
            }
        }

        // ── Light controls ─────────────────────────────────────────────────────────
        let rowState = { 1: false, 2: false, 3: false };
        const rowBulbs = { 1: [0,1,2], 2: [3,4,5], 3: [6,7,8] };

        function setBulb(index, on) {
            const img = document.getElementById('bulb' + index);
            if (img) img.src = on ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
        }

        function toggleRow(row, on) {
            rowState[row] = on;
            rowBulbs[row].forEach(i => setBulb(i, on));
            syncAllLightsLabel();
        }

        function toggleAllLights() {
            const anyOff = Object.values(rowState).some(v => !v);
            const newState = anyOff;
            for (let row = 1; row <= 3; row++) {
                rowState[row] = newState;
                rowBulbs[row].forEach(i => setBulb(i, newState));
                const sw = document.getElementById('row' + row + 'sw');
                if (sw) sw.checked = newState;
            }
            syncAllLightsLabel();
        }

        function syncAllLightsLabel() {
            const allOn = Object.values(rowState).every(v => v);
            const lbl = document.getElementById('allLightsLabel');
            if (lbl) {
                lbl.textContent = allOn ? 'ON' : 'OFF';
                lbl.style.color = allOn ? '#27ae60' : 'red';
            }
        }

        // Reset row state when modal opens so it matches actual bulb state
        document.getElementById('roomModal').addEventListener('show.bs.modal', function () {
            rowState = { 1: false, 2: false, 3: false };
            for (let row = 1; row <= 3; row++) {
                const sw = document.getElementById('row' + row + 'sw');
                if (sw) sw.checked = false;
            }
        });
        </script>
</body>
</html>