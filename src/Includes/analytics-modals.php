<?php
// Analytics runtime controls + modals — page-level (must NOT live inside the
// toggle-hidden Live dashboard container). Expected variables: $rooms.
if (!isset($rooms)) $rooms = [];
?>
<!-- Hidden selects used by the analytics runtime -->
<select id="periodSelect" onchange="onControlChange()" hidden>
    <option value="1" selected>Today</option>
    <option value="7">Last 7 days</option>
    <option value="14">Last 14 days</option>
    <option value="30">Last 30 days</option>
</select>
<?php if (count($rooms) > 1): ?>
    <select id="roomSelect" onchange="onControlChange()" hidden>
        <option value="0">All Rooms</option>
        <?php foreach ($rooms as $room): ?>
            <option value="<?= (int)$room['id'] ?>"><?= h($room['room_name']) ?></option>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <input type="hidden" id="roomSelect" value="<?= (int)($rooms[0]['id'] ?? 0) ?>">
<?php endif; ?>

<!-- Export selection modal -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:var(--secondary-color-1);color:#fff;padding:12px 20px;">
                <h6 class="modal-title bold" id="exportModalTitle">Export</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="mb-2" style="font-size:13px;color:#666;">Select a section to export:</p>
                <div class="d-flex flex-column gap-2">
                    <button type="button" class="export-option-btn" onclick="exportSection('lineGraphCard')">
                        <i class="bi bi-graph-up"></i>
                        <span>Line Graph</span>
                    </button>
                    <button type="button" class="export-option-btn" onclick="exportSection('barGraphCard')">
                        <i class="bi bi-bar-chart"></i>
                        <span>Vertical Bar Graph</span>
                    </button>
                    <button type="button" class="export-option-btn" onclick="exportSection('historyCard')">
                        <i class="bi bi-table"></i>
                        <span>History Table</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Energy saved calculation modal -->
<div class="modal fade" id="savingsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:var(--secondary-color-1);color:#fff;padding:12px 20px;">
                <h6 class="modal-title bold"><i class="bi bi-flower1 me-1"></i>Energy Saved Calculation</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="savings-modal-hero">
                    <div class="savings-modal-value" id="savingsModalValue">&mdash;</div>
                    <span class="savings-modal-badge neutral" id="savingsModalBadge"><i class="bi bi-dash-lg"></i> No data</span>
                </div>
                <div class="savings-modal-compare">
                    <div class="savings-modal-col">
                        <div class="savings-modal-col-label">Current period</div>
                        <div class="savings-modal-col-value" id="savingsModalCurrent">&mdash;</div>
                        <div class="savings-modal-bar-wrap"><div class="savings-modal-bar" id="savingsModalCurrentBar"></div></div>
                    </div>
                    <div class="savings-modal-col">
                        <div class="savings-modal-col-label">Previous period</div>
                        <div class="savings-modal-col-value" id="savingsModalPrev">&mdash;</div>
                        <div class="savings-modal-bar-wrap"><div class="savings-modal-bar prev" id="savingsModalPrevBar"></div></div>
                    </div>
                </div>
                <div class="savings-modal-formula">
                    <span class="summary-info-label">Formula:</span>
                    <span class="summary-info-val">Saved % = (prev &minus; current) &divide; prev &times; 100</span>
                </div>
                <p class="savings-modal-note mb-0" id="savingsModalNote">&mdash;</p>
            </div>
        </div>
    </div>
</div>

<!-- Archive folder picker modal -->
<div class="modal fade" id="archiveModal" tabindex="-1">
    <div class="room-details-modal modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title bold"><i class="bi bi-folder2-open me-1"></i>Select an Archive Date</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="mb-2" style="font-size:13px;color:#666;">Search or choose a date to view its archived readings (sessions &amp; anomalies stay in the database):</p>
                <div class="findings-anomalies-search mb-2">
                    <i class="bi bi-search"></i>
                    <input type="text" id="archiveSearchInput" placeholder="Search date... e.g. Aug 2, 2026" aria-label="Search archive dates">
                </div>
                <div class="archive-date-list" id="archiveDateList" style="max-height:300px;overflow-y:auto;"></div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" id="archiveConfirmBtn" onclick="confirmArchiveSelection()">
                        <i class="bi bi-check-lg"></i> View Archive
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Issue detail modal -->
<div class="modal fade" id="issueDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:12px;overflow:hidden;">
            <div class="modal-header" style="background:#842029;color:#fff;padding:12px 20px;">
                <h6 class="modal-title bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Issue Details</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div class="issue-detail-row">
                    <span class="issue-detail-label">Status:</span>
                    <span class="issue-detail-status" id="issueStatus">Issue Raised</span>
                </div>
                <div class="issue-detail-row">
                    <span class="issue-detail-label">Room:</span>
                    <span class="issue-detail-val" id="issueRoom">&mdash;</span>
                </div>
                <div class="issue-detail-row">
                    <span class="issue-detail-label">Source:</span>
                    <span class="issue-detail-val" id="issueSource">&mdash;</span>
                </div>
                <div class="issue-detail-row">
                    <span class="issue-detail-label">Detected:</span>
                    <span class="issue-detail-val" id="issueTime">&mdash;</span>
                </div>
                <div class="issue-detail-row issue-detail-notes">
                    <span class="issue-detail-label">Notes:</span>
                    <span class="issue-detail-val" id="issueNotes">&mdash;</span>
                </div>
            </div>
        </div>
    </div>
</div>