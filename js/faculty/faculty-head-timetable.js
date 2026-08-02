        const deptHasCoverage = <?= json_encode($dept_has_coverage) ?>;
        const facultyHasAssignment = <?= json_encode($faculty_assignment_map) ?>;
        let noCoverageModal = null;
        let noAssignmentModal = null;

        function checkDeptCoverage(deptId, facultyId) {
            if (!facultyHasAssignment[facultyId]) {
                if (!noAssignmentModal) {
                    noAssignmentModal = new bootstrap.Modal(document.getElementById('noAssignmentModal'));
                }
                noAssignmentModal.show();
            } else if (deptHasCoverage[deptId]) {
                window.location.href = 'faculty-head-membersched.php?faculty_id=' + facultyId + '&department_id=' + deptId;
            } else {
                if (!noCoverageModal) {
                    noCoverageModal = new bootstrap.Modal(document.getElementById('noCoverageModal'));
                }
                noCoverageModal.show();
            }
        }

        const subjectAreasData = <?= json_encode($all_subject_areas) ?>;
        const subjectAreasFlatData = <?= json_encode($all_subject_areas_flat) ?>;

        let editFacultyModal = null;
        let editFacultyId = null;
        let editFacultyDeptId = null;
        let editFacultyAssignedSubjIds = []; // faculty's assigned subject IDs (from junction_faculty_subject)

        function openSubjectAreaModal(facultyId, facultyName, currentAreaIdsJson, deptId, preSelectedSubjJson) {
            if (!editFacultyModal) {
                editFacultyModal = new bootstrap.Modal(document.getElementById('subjectAreaModal'));
            }
            editFacultyId = facultyId;
            editFacultyDeptId = deptId;
            window._facultyDeptFilter = deptId;

            document.getElementById('editFacultyId').value = facultyId;
            document.getElementById('editFacultyCoverageName').textContent = facultyName;

            // Parse assigned subject area IDs
            var assignedSaIds = [];
            try {
                assignedSaIds = JSON.parse(currentAreaIdsJson) || [];
            } catch (e) {
                assignedSaIds = [];
            }
            if (!Array.isArray(assignedSaIds)) assignedSaIds = [];
            assignedSaIds = assignedSaIds.filter(function(id) {
                return id > 0;
            });

            // Parse assigned subject IDs
            editFacultyAssignedSubjIds = [];
            try {
                editFacultyAssignedSubjIds = JSON.parse(preSelectedSubjJson) || [];
            } catch (e) {
                editFacultyAssignedSubjIds = [];
            }
            if (!Array.isArray(editFacultyAssignedSubjIds)) editFacultyAssignedSubjIds = [];
            editFacultyAssignedSubjIds = editFacultyAssignedSubjIds.filter(function(id) {
                return id > 0;
            });

            renderFacultySubjectAreas(deptId, assignedSaIds);
            renderAvailableSubjectAreas(deptId, assignedSaIds);

            // Reset containers
            document.getElementById('availableFacultySubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view available subjects.</p>';
            document.getElementById('editFacultySubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('facultySubjectSearch').disabled = true;
            document.getElementById('facultySubjectSearch').placeholder = 'Select a subject area first';
            document.getElementById('editFacultySelectedSubjectAreaName').textContent = '';
            document.getElementById('editFacultySelectedSAId').value = '';
            document.getElementById('facultySaSearch').value = '';
            document.getElementById('facultySubjectSearch').value = '';

            editFacultyModal.show();
        }

        function renderFacultySubjectAreas(deptId, assignedSaIds) {
            const container = document.getElementById('editFacultyMembers');
            const sas = subjectAreasData[deptId] || [];

            // Filter to only assigned SAs (like dept modal shows only what's in the table)
            const assignedSas = sas.filter(function(sa) {
                return assignedSaIds.indexOf(sa.id) !== -1;
            });

            if (assignedSas.length === 0) {
                container.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned yet.</li>';
                return;
            }

            container.innerHTML = '<li class="list-group-item d-flex flex-wrap gap-1">' +
                assignedSas.map(function(sa) {
                    return '<span class="dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 subject-area-item" data-sa-id="' + sa.id + '" title="Select Subject Area" data-bs-toggle="tooltip" data-bs-placement="auto">' +
                        escapeHtml(sa.name) +
                        '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>' +
                        '</span>';
                }).join('') +
                '</li>';

            // Re-init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('#editFacultyMembers [data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderFacultySubjects(saId) {
            var container = document.getElementById('editFacultySubjectsContainer');
            var deptId = editFacultyDeptId;
            var sas = subjectAreasData[deptId] || [];
            var found = null;
            for (var i = 0; i < sas.length; i++) {
                if (sas[i].id == saId) {
                    found = sas[i];
                    break;
                }
            }

            if (!found) {
                container.innerHTML = '<p class="text-muted mb-0">Subject area not found.</p>';
                return;
            }

            // Show only assigned subjects under this SA (from junction_faculty_subject)
            var subjects = found.subjects || [];
            var assignedSubjects = subjects.filter(function(s) {
                return editFacultyAssignedSubjIds.indexOf(s.id) !== -1;
            });

            container.innerHTML = '';
            if (assignedSubjects.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">No assigned subjects under this subject area.</p>';
                return;
            }

            for (var j = 0; j < assignedSubjects.length; j++) {
                var sub = assignedSubjects[j];
                var span = document.createElement('span');
                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
                span.dataset.subjectId = sub.id;
                span.innerHTML = escapeHtml(sub.name) +
                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
                container.appendChild(span);
            }

            // Init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderAvailableSubjectAreas(deptId, assignedSaIds) {
            var container = document.getElementById('availableFacultySAsContainer');
            var sas = subjectAreasData[deptId] || [];

            // Filter to SAs NOT assigned to faculty
            var available = sas.filter(function(sa) {
                return assignedSaIds.indexOf(sa.id) === -1;
            });

            if (available.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">All subject areas are already assigned.</p>';
                return;
            }

            container.innerHTML = '';
            for (var i = 0; i < available.length; i++) {
                var sa = available[i];
                var span = document.createElement('span');
                span.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 available-sa-item';
                span.dataset.saId = sa.id;
                span.title = 'Click to add this subject area';
                span.setAttribute('data-bs-toggle', 'tooltip');
                span.innerHTML = escapeHtml(sa.name) +
                    '<button type="button" class="p-0 ms-1 d-inline-flex flex-shrink-0 align-items-center text-white border-0 bg-transparent" title="Add Subject Area">' +
                    '<i class="bi bi-plus-circle"></i></button>';
                container.appendChild(span);
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderAvailableSubjects(saId) {
            var container = document.getElementById('availableFacultySubjectsContainer');
            var deptId = editFacultyDeptId;
            var sas = subjectAreasData[deptId] || [];
            var found = null;
            for (var i = 0; i < sas.length; i++) {
                if (sas[i].id == saId) {
                    found = sas[i];
                    break;
                }
            }

            if (!found) {
                container.innerHTML = '<p class="text-muted small mb-0">Subject area not found.</p>';
                return;
            }

            var subjects = found.subjects || [];
            var available = subjects.filter(function(s) {
                return editFacultyAssignedSubjIds.indexOf(s.id) === -1;
            });

            if (available.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">All subjects are already assigned under this area.</p>';
                return;
            }

            container.innerHTML = '';
            for (var j = 0; j < available.length; j++) {
                var sub = available[j];
                var span = document.createElement('span');
                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3 available-subject-item';
                span.dataset.subjectId = sub.id;
                span.title = 'Click to add this subject';
                span.setAttribute('data-bs-toggle', 'tooltip');
                span.innerHTML = escapeHtml(sub.name) +
                    '<button type="button" class="btn btn-sm p-0 ms-1 d-inline-flex align-items-center text-white border-0 bg-transparent" title="Add Subject">' +
                    '<i class="bi bi-plus-circle"></i></button>';
                container.appendChild(span);
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function showConfirmFacultyModal() {
            var modal = new bootstrap.Modal(document.getElementById('confirmFacultyModal'));
            modal.show();
        }

        function confirmFacultySave() {
            bootstrap.Modal.getInstance(document.getElementById('confirmFacultyModal'))?.hide();
            saveFacultyCoverage();
        }

        async function saveFacultyCoverage() {
            var facultyId = editFacultyId;
            var deptId = editFacultyDeptId;
            if (!facultyId || !deptId) return;

            // Get keep SA IDs (non-disabled assigned SAs)
            var keepSaSpans = document.querySelectorAll('#editFacultyMembers .subject-area-item:not(.disabled)');
            var keepSaIds = Array.from(keepSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get removed SA IDs (disabled assigned SAs)
            var removedSaSpans = document.querySelectorAll('#editFacultyMembers .subject-area-item.disabled');
            var removeSaIds = Array.from(removedSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get removed subject IDs
            var removedSubjSpans = document.querySelectorAll('#editFacultySubjectsContainer .subarea-subject.disabled');
            var removeSubjIds = Array.from(removedSubjSpans).map(function(el) {
                return parseInt(el.dataset.subjectId);
            }).filter(function(id) {
                return !isNaN(id);
            });

            // Get add SA IDs (available SAs selected to add)
            var addSaSpans = document.querySelectorAll('#availableFacultySAsContainer .available-sa-item.selected');
            var addSaIds = Array.from(addSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get add subject IDs (available subjects selected to add)
            var addSubjSpans = document.querySelectorAll('#availableFacultySubjectsContainer .available-subject-item.selected');
            var addSubjIds = Array.from(addSubjSpans).map(function(el) {
                return parseInt(el.dataset.subjectId);
            });

            var body = new URLSearchParams({
                action: 'save_faculty_coverage',
                faculty_id: facultyId,
                department_id: deptId,
                keep_sa_ids: JSON.stringify(keepSaIds),
                remove_sa_ids: JSON.stringify(removeSaIds),
                remove_subject_ids: JSON.stringify(removeSubjIds),
                add_sa_ids: JSON.stringify(addSaIds),
                add_subject_ids: JSON.stringify(addSubjIds)
            });

            try {
                var res = await fetch('../../handlers/faculty-head-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body
                });
                var data = await res.json();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('confirmFacultyModal'))?.hide();
                    bootstrap.Modal.getInstance(document.getElementById('subjectAreaModal'))?.hide();
                    location.reload();
                } else {
                    alert(data.message || 'Failed to save changes.');
                }
            } catch (err) {
                alert('An error occurred while saving.');
            }
        }

        // ── Dynamic Subject Area Modals ─────────────────────────────

        function viewSubjectArea(deptId, deptName) {
            const modal = document.getElementById('viewSubjectAreaModal');
            document.getElementById('viewSubjectAreaLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Subject Area/s for ' + deptName;

            const list = document.getElementById('viewDeptMembers');
            const sas = subjectAreasData[deptId] || [];

            if (sas.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned to this department.</li>';
            } else {
                list.innerHTML = sas.map(sa => {
                    const subjects = sa.subjects || [];
                    let subjHtml = '';
                    if (subjects.length === 0) {
                        subjHtml = '<p class="text-muted mb-0" style="font-size: 14px;">Currently no assigned coverage under this subject area</p>';
                    } else {
                        subjHtml = '<div class="d-flex flex-wrap gap-1">' +
                            subjects.map(s =>
                                '<span class="subarea-subject bold dept-emphases align-self-start">' + escapeHtml(s.name) + '</span>'
                            ).join('') +
                            '</div>';
                    }
                    return '<li class="list-group-item">' +
                        '<div class="dept-info-card d-flex flex-column">' +
                        '<span class="dept-subject-area bold dept-emphases align-self-start">' + escapeHtml(sa.name) + '</span>' +
                        '<label class="bold" style="font-size: 14px;"><i class="bi bi-book me-2"></i>Subjects:</label>' +
                        subjHtml +
                        '</div></li>';
                }).join('');
            }

            new bootstrap.Modal(modal).show();
        }

        function viewFacultyCoverage(facultyId, facultyName, deptId, saIdsJson, subjectIdsJson) {
            var modal = document.getElementById('viewFacultyCoverageModal');
            document.getElementById('viewFacultyLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Coverage for ' + escapeHtml(facultyName);

            var assignedSaIds = [];
            try {
                assignedSaIds = JSON.parse(saIdsJson) || [];
            } catch (e) {
                assignedSaIds = [];
            }
            if (!Array.isArray(assignedSaIds)) assignedSaIds = [];

            var assignedSubjIds = [];
            try {
                assignedSubjIds = JSON.parse(subjectIdsJson) || [];
            } catch (e) {
                assignedSubjIds = [];
            }
            if (!Array.isArray(assignedSubjIds)) assignedSubjIds = [];

            var list = document.getElementById('viewFacultyMembers');
            var filtered = subjectAreasFlatData.filter(function(sa) {
                return sa.department_id == deptId && assignedSaIds.indexOf(sa.id) !== -1;
            });

            if (filtered.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned to this faculty member.</li>';
            } else {
                list.innerHTML = filtered.map(function(sa) {
                    var subjects = sa.subjects || [];
                    var assignedSubjects = subjects.filter(function(s) {
                        return assignedSubjIds.indexOf(s.id) !== -1;
                    });
                    var subjHtml = '';
                    if (assignedSubjects.length === 0) {
                        subjHtml = '<p class="text-muted mb-0" style="font-size: 14px;">Currently no assigned subjects under this subject area.</p>';
                    } else {
                        subjHtml = '<div class="d-flex flex-wrap gap-1">' +
                            assignedSubjects.map(function(s) {
                                return '<span class="subarea-subject bold dept-emphases align-self-start">' + escapeHtml(s.name) + '</span>';
                            }).join('') +
                            '</div>';
                    }
                    return '<li class="list-group-item">' +
                        '<div class="dept-info-card d-flex flex-column">' +
                        '<span class="dept-subject-area bold dept-emphases align-self-start">' + escapeHtml(sa.name) + '</span>' +
                        '<label class="bold" style="font-size: 14px;"><i class="bi bi-book me-2"></i>Subjects:</label>' +
                        subjHtml +
                        '</div></li>';
                }).join('');
            }

            new bootstrap.Modal(modal).show();
        }

        let currentEditDeptId = null;

        function openEditSubjectAreaModal(deptId, deptName) {
            currentEditDeptId = deptId;
            const modal = document.getElementById('editSubjectAreaModal');
            document.getElementById('editSubjectAreaLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Edit Coverage for ' + deptName;

            renderEditSubjectAreas(deptId);
            document.getElementById('editDeptSubjectArea').value = '';
            document.getElementById('editDeptSubject').value = '';
            document.getElementById('editDeptSubject').disabled = true;
            document.getElementById('editSelectedSubjectAreaName').textContent = '';

            new bootstrap.Modal(modal).show();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function renderEditSubjectAreas(deptId) {
            const container = document.getElementById('editDeptMembers');
            const sas = subjectAreasData[deptId] || [];

            if (sas.length === 0) {
                container.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned yet.</li>';
                return;
            }

            container.innerHTML = '<li class="list-group-item d-flex flex-wrap gap-1">' +
                sas.map(sa =>
                    '<span class="dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 subject-area-item" data-sa-id="' + sa.id + '" title="Select Subject Area" data-bs-toggle="tooltip" data-bs-placement="auto">' +
                    escapeHtml(sa.name) +
                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>' +
                    '</span>'
                ).join('') +
                '</li>';

            // Reset new chips and labels
            document.getElementById('newSubjectAreasContainer').innerHTML = '';
            document.getElementById('newSubjectAreasLabel').style.display = 'none';
            document.getElementById('newSubjectsContainer').innerHTML = '';
            document.getElementById('newSubjectsLabel').style.display = 'none';
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('editDeptSubject').disabled = true;
            document.getElementById('editDeptSubject').placeholder = 'Select a subject area first';
            document.getElementById('editSelectedSubjectAreaName').textContent = 'insertSubjectArea';
            document.getElementById('editSelectedSubjectAreaId').value = '';

            // Re-init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('#editDeptMembers [data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        // Handle inputs to add chips (Department + Faculty)
        document.addEventListener('DOMContentLoaded', function() {
            // Department modal inputs
            const saInput = document.getElementById('editDeptSubjectArea');
            const subjectInput = document.getElementById('editDeptSubject');

            if (saInput) {
                saInput.addEventListener('change', function() {
                    addNewSubjectAreaChip(this);
                });
                saInput.addEventListener('blur', function() {
                    if (this.value.trim()) addNewSubjectAreaChip(this);
                });
            }
            if (subjectInput) {
                subjectInput.addEventListener('change', function() {
                    addNewSubjectChip(this);
                });
                subjectInput.addEventListener('blur', function() {
                    if (this.value.trim()) addNewSubjectChip(this);
                });
            }

            // Faculty modal search
            const facultySaSearch = document.getElementById('facultySaSearch');
            if (facultySaSearch) {
                facultySaSearch.addEventListener('input', function() {
                    var filter = this.value.toLowerCase();
                    var container = document.getElementById('availableFacultySAsContainer');
                    var items = container.querySelectorAll('.available-sa-item');
                    var anyVisible = false;
                    for (var i = 0; i < items.length; i++) {
                        var show = items[i].textContent.toLowerCase().includes(filter);
                        items[i].style.display = show ? '' : 'none';
                        if (show) anyVisible = true;
                    }
                    var emptyMsg = container.querySelector('.text-muted');
                    if (!anyVisible && !emptyMsg) {
                        var msg = document.createElement('p');
                        msg.className = 'text-muted small mb-0';
                        msg.textContent = 'No matching subject areas.';
                        container.appendChild(msg);
                    } else if (anyVisible && emptyMsg) {
                        emptyMsg.remove();
                    }
                });
            }

            const facultySubSearch = document.getElementById('facultySubjectSearch');
            if (facultySubSearch) {
                facultySubSearch.addEventListener('input', function() {
                    var filter = this.value.toLowerCase();
                    var container = document.getElementById('availableFacultySubjectsContainer');
                    var items = container.querySelectorAll('.available-subject-item');
                    var anyVisible = false;
                    for (var i = 0; i < items.length; i++) {
                        var show = items[i].textContent.toLowerCase().includes(filter);
                        items[i].style.display = show ? '' : 'none';
                        if (show) anyVisible = true;
                    }
                    var emptyMsg = container.querySelector('.text-muted');
                    if (!anyVisible && !emptyMsg) {
                        var msg = document.createElement('p');
                        msg.className = 'text-muted small mb-0';
                        msg.textContent = 'No matching subjects.';
                        container.appendChild(msg);
                    } else if (anyVisible && emptyMsg) {
                        emptyMsg.remove();
                    }
                });
            }
        });

        function addNewSubjectAreaChip(input) {
            const val = input.value.trim();
            if (!val) return;

            const container = document.getElementById('newSubjectAreasContainer');
            const chip = document.createElement('span');
            chip.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3';
            chip.innerHTML = escapeHtml(val) +
                '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
            container.appendChild(chip);
            input.value = '';
            document.getElementById('newSubjectAreasLabel').style.display = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function addNewSubjectChip(input) {
            const val = input.value.trim();
            if (!val) return;

            const container = document.getElementById('newSubjectsContainer');
            const chip = document.createElement('span');
            chip.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
            chip.innerHTML = escapeHtml(val) +
                '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
            container.appendChild(chip);
            input.value = '';
            document.getElementById('newSubjectsLabel').style.display = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }



        // Reset edit modal when closed
        document.getElementById('editSubjectAreaModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('newSubjectAreasContainer').innerHTML = '';
            document.getElementById('newSubjectAreasLabel').style.display = 'none';
            document.getElementById('newSubjectsContainer').innerHTML = '';
            document.getElementById('newSubjectsLabel').style.display = 'none';
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            const subInput = document.getElementById('editDeptSubject');
            subInput.disabled = true;
            subInput.placeholder = 'Select a subject area first';
            subInput.value = '';
            document.getElementById('editSelectedSubjectAreaName').textContent = 'insertSubjectArea';
            document.getElementById('editSelectedSubjectAreaId').value = '';
            document.querySelectorAll('#editDeptMembers .subject-area-item').forEach(el => {
                el.style.boxShadow = '';
                el.style.border = '';
            });
            currentEditDeptId = null;
        });

        // Reset faculty modal when closed
        document.getElementById('subjectAreaModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('editFacultySubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('availableFacultySubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view available subjects.</p>';
            var subSearch = document.getElementById('facultySubjectSearch');
            if (subSearch) {
                subSearch.disabled = true;
                subSearch.placeholder = 'Select a subject area first';
                subSearch.value = '';
            }
            document.getElementById('editFacultySelectedSubjectAreaName').textContent = '';
            document.getElementById('editFacultySelectedSAId').value = '';
            document.getElementById('facultySaSearch').value = '';
            document.querySelectorAll('#editFacultyMembers .subject-area-item').forEach(function(el) {
                el.style.boxShadow = '';
                el.style.border = '';
            });
            editFacultyId = null;
            editFacultyDeptId = null;
            editFacultyAssignedSubjIds = [];
        });

        function disableSpan(span) {
            if (span.classList.contains('disabled')) return;
            span.classList.add('disabled');
            // Dispose tooltips on the span and its btn-close
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                bootstrap.Tooltip.getInstance(span)?.dispose();
                const close = span.querySelector('.btn-close');
                if (close) bootstrap.Tooltip.getInstance(close)?.dispose();
            }
            const close = span.querySelector('.btn-close');
            if (close) close.style.display = 'none';
            if (!span.querySelector('.bi-plus')) {
                const plusBtn = document.createElement('button');
                plusBtn.type = 'button';
                plusBtn.className = 'btn btn-sm p-0 ms-2 d-inline-flex align-items-center justify-content-center text-white border-0 bg-transparent opacity-50 opacity-100-hover';
                plusBtn.title = 'Add Back';
                plusBtn.setAttribute('data-bs-toggle', 'tooltip');
                plusBtn.innerHTML = '<i class="bi bi-plus fs-3"></i>';
                span.appendChild(plusBtn);
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    if (!bootstrap.Tooltip.getInstance(plusBtn)) new bootstrap.Tooltip(plusBtn);
                }
            }
        }

        function restoreSpan(span) {
            if (!span.classList.contains('disabled')) return;
            span.classList.remove('disabled');
            // Re-init tooltip on the span and its btn-close (only if not already active)
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                if (span.hasAttribute('data-bs-toggle') && !bootstrap.Tooltip.getInstance(span)) new bootstrap.Tooltip(span);
                const close = span.querySelector('.btn-close');
                if (close && !bootstrap.Tooltip.getInstance(close)) new bootstrap.Tooltip(close);
            }
            const close = span.querySelector('.btn-close');
            if (close) close.style.display = '';
            const plus = span.querySelector('.btn.btn-sm');
            if (plus) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    bootstrap.Tooltip.getInstance(plus)?.dispose();
                }
                plus.remove();
            }
        }

        // Delegate btn-close, restore, and subject area selection
        document.addEventListener('click', function(e) {
            // btn-close inside editDeptMembers or currentSubjectsContainer → disable
            const containerBtnClose = e.target.closest('#editDeptMembers .btn-close, #currentSubjectsContainer .btn-close');
            if (containerBtnClose) {
                e.preventDefault();
                const span = containerBtnClose.closest('span');
                if (span) disableSpan(span);
                return;
            }

            // bi-plus inside editDeptMembers or currentSubjectsContainer → restore
            const containerPlus = e.target.closest('#editDeptMembers .bi-plus, #currentSubjectsContainer .bi-plus');
            if (containerPlus) {
                const span = containerPlus.closest('span');
                if (span) restoreSpan(span);
                return;
            }

            // btn-close inside new subject areas → remove chip
            const closeNewSa = e.target.closest('#newSubjectAreasContainer .btn-close');
            if (closeNewSa) {
                const span = closeNewSa.closest('span');
                if (span) {
                    bootstrap.Tooltip.getInstance(closeNewSa)?.dispose();
                    span.remove();
                    if (!document.getElementById('newSubjectAreasContainer').hasChildNodes()) {
                        document.getElementById('newSubjectAreasLabel').style.display = 'none';
                    }
                }
                return;
            }

            // btn-close inside new subjects → remove chip
            const closeNewSub = e.target.closest('#newSubjectsContainer .btn-close');
            if (closeNewSub) {
                const span = closeNewSub.closest('span');
                if (span) {
                    bootstrap.Tooltip.getInstance(closeNewSub)?.dispose();
                    span.remove();
                    if (!document.getElementById('newSubjectsContainer').hasChildNodes()) {
                        document.getElementById('newSubjectsLabel').style.display = 'none';
                    }
                }
                return;
            }

            // Subject area selection in current list
            const saItem = e.target.closest('#editDeptMembers .subject-area-item');
            if (saItem && !saItem.classList.contains('disabled')) {
                const saId = saItem.dataset.saId;
                // Find this subject area's subjects
                for (const deptId in subjectAreasData) {
                    const sas = subjectAreasData[deptId];
                    const found = sas.find(sa => String(sa.id) === saId);
                    if (found) {
                        // Highlight selected
                        document.querySelectorAll('#editDeptMembers .subject-area-item').forEach(el => {
                            el.style.boxShadow = '';
                            el.style.border = '';
                        });
                        saItem.style.boxShadow = '0 0 0 2px #ffc107';
                        saItem.style.border = '2px solid #ffc107';

                        // Update the add-new-subjects section label
                        document.getElementById('editSelectedSubjectAreaName').textContent = ' for ' + escapeHtml(found.name);
                        document.getElementById('editSelectedSubjectAreaId').value = saId;

                        // Enable subject input
                        const subInput = document.getElementById('editDeptSubject');
                        subInput.disabled = false;
                        subInput.placeholder = 'Enter subject name for ' + found.name;
                        subInput.focus();

                        // Clear new subjects container and hide label
                        document.getElementById('newSubjectsContainer').innerHTML = '';
                        document.getElementById('newSubjectsLabel').style.display = 'none';

                        // Show current subjects for this area
                        const currentSubjectsContainer = document.getElementById('currentSubjectsContainer');
                        currentSubjectsContainer.innerHTML = '';
                        if (found.subjects && found.subjects.length > 0) {
                            found.subjects.forEach(sub => {
                                const span = document.createElement('span');
                                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
                                span.dataset.subjectId = sub.id;
                                span.innerHTML = escapeHtml(sub.name) +
                                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
                                currentSubjectsContainer.appendChild(span);
                            });
                            // Init tooltips on the newly added subject spans
                            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                                currentSubjectsContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                                });
                            }
                        } else {
                            currentSubjectsContainer.innerHTML = '<p class="text-muted small mb-0">No subjects currently under this subject area.</p>';
                        }
                        break;
                    }
                }
                return;
            }

            // ── Faculty modal handlers ──────────────────────────────────

            // btn-close inside editFacultyMembers → disable SA
            var facultySaBtnClose = e.target.closest('#editFacultyMembers .btn-close');
            if (facultySaBtnClose) {
                e.preventDefault();
                var saSpan = facultySaBtnClose.closest('span');
                if (saSpan) disableSpan(saSpan);
                return;
            }

            // bi-plus inside editFacultyMembers → restore SA
            var facultySaPlus = e.target.closest('#editFacultyMembers .bi-plus');
            if (facultySaPlus) {
                var restoredSaSpan = facultySaPlus.closest('span');
                if (restoredSaSpan) restoreSpan(restoredSaSpan);
                return;
            }

            // btn-close inside editFacultySubjectsContainer → disable subject
            var facultySubBtnClose = e.target.closest('#editFacultySubjectsContainer .btn-close');
            if (facultySubBtnClose) {
                e.preventDefault();
                var subSpan = facultySubBtnClose.closest('span');
                if (subSpan) disableSpan(subSpan);
                return;
            }

            // bi-plus inside editFacultySubjectsContainer → restore subject
            var facultySubPlus = e.target.closest('#editFacultySubjectsContainer .bi-plus');
            if (facultySubPlus) {
                var restoredSubSpan = facultySubPlus.closest('span');
                if (restoredSubSpan) restoreSpan(restoredSubSpan);
                return;
            }

            // Available SA item click → toggle add (selected state)
            var availSaItem = e.target.closest('#availableFacultySAsContainer .available-sa-item');
            if (availSaItem) {
                if (availSaItem.classList.contains('selected')) {
                    availSaItem.classList.remove('selected');
                    availSaItem.style.boxShadow = '';
                    availSaItem.style.border = '';
                } else {
                    availSaItem.classList.add('selected');
                    availSaItem.style.boxShadow = '0 0 0 2px #2ecc71';
                    availSaItem.style.border = '2px solid #2ecc71';
                }
                return;
            }

            // Available subject item click → toggle add (selected state)
            var availSubItem = e.target.closest('#availableFacultySubjectsContainer .available-subject-item');
            if (availSubItem) {
                if (availSubItem.classList.contains('selected')) {
                    availSubItem.classList.remove('selected');
                    availSubItem.style.boxShadow = '';
                    availSubItem.style.border = '';
                } else {
                    availSubItem.classList.add('selected');
                    availSubItem.style.boxShadow = '0 0 0 2px #2ecc71';
                    availSubItem.style.border = '2px solid #2ecc71';
                }
                return;
            }

            // Subject area selection in editFacultyMembers → show its subjects + available
            var facultySaItem = e.target.closest('#editFacultyMembers .subject-area-item');
            if (facultySaItem && !facultySaItem.classList.contains('disabled')) {
                var facultySaId = facultySaItem.dataset.saId;
                var deptId = editFacultyDeptId;
                var sas = subjectAreasData[deptId] || [];
                var found = null;
                for (var fi = 0; fi < sas.length; fi++) {
                    if (sas[fi].id == facultySaId) {
                        found = sas[fi];
                        break;
                    }
                }
                if (found) {
                    // Highlight selected
                    document.querySelectorAll('#editFacultyMembers .subject-area-item').forEach(function(el) {
                        el.style.boxShadow = '';
                        el.style.border = '';
                    });
                    facultySaItem.style.boxShadow = '0 0 0 2px #ffc107';
                    facultySaItem.style.border = '2px solid #ffc107';

                    // Update available subjects section
                    document.getElementById('editFacultySelectedSubjectAreaName').textContent = ' for ' + escapeHtml(found.name);
                    document.getElementById('editFacultySelectedSAId').value = facultySaId;

                    // Enable subject search
                    var subSearch = document.getElementById('facultySubjectSearch');
                    subSearch.disabled = false;
                    subSearch.placeholder = 'Search available subjects for ' + found.name;
                    subSearch.value = '';
                    subSearch.focus();

                    // Show assigned subjects + available subjects
                    renderFacultySubjects(facultySaId);
                    renderAvailableSubjects(facultySaId);
                }
                return;
            }
        });

        // Save Changes → Confirm Modal
        function confirmSaveChanges() {
            const confirmModal = new bootstrap.Modal(document.getElementById('confirmChangesModal'));
            confirmModal.show();
        }

        async function doSaveChanges() {
            // Collect data for saving
            const deptId = currentEditDeptId;
            if (!deptId) return;

            // Get current (non-disabled) subject area IDs
            const currentSaSpans = document.querySelectorAll('#editDeptMembers .subject-area-item:not(.disabled)');
            const keepSaIds = Array.from(currentSaSpans).map(el => parseInt(el.dataset.saId));

            // Get removed (disabled) subject area IDs
            const removedSaSpans = document.querySelectorAll('#editDeptMembers .subject-area-item.disabled');
            const removeSaIds = Array.from(removedSaSpans).map(el => parseInt(el.dataset.saId));

            // Get removed subject IDs (disabled subject spans)
            const removedSubjSpans = document.querySelectorAll('#currentSubjectsContainer .subarea-subject.disabled');
            const removeSubjIds = Array.from(removedSubjSpans).map(el => parseInt(el.dataset.subjectId)).filter(id => !isNaN(id));

            // Get new subject area names
            const newSaNames = Array.from(document.querySelectorAll('#newSubjectAreasContainer span')).map(el => {
                const txt = el.childNodes[0]?.textContent?.trim();
                return txt || '';
            }).filter(n => n);

            // Get new subject names (for the currently selected subject area)
            const selectedSaId = document.getElementById('editSelectedSubjectAreaId').value;
            const newSubjNames = Array.from(document.querySelectorAll('#newSubjectsContainer span')).map(el => {
                const txt = el.childNodes[0]?.textContent?.trim();
                return txt || '';
            }).filter(n => n);

            const body = new URLSearchParams({
                action: 'save_department_subject_areas',
                department_id: deptId,
                keep_sa_ids: JSON.stringify(keepSaIds),
                remove_sa_ids: JSON.stringify(removeSaIds),
                remove_subject_ids: JSON.stringify(removeSubjIds),
                new_sa_names: JSON.stringify(newSaNames),
                selected_sa_id: selectedSaId,
                new_subject_names: JSON.stringify(newSubjNames)
            });

            try {
                const res = await fetch('../../handlers/faculty-head-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                });
                const data = await res.json();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('confirmChangesModal'))?.hide();
                    bootstrap.Modal.getInstance(document.getElementById('editSubjectAreaModal'))?.hide();
                    location.reload();
                } else {
                    alert(data.message || 'Failed to save changes.');
                }
            } catch (err) {
                alert('An error occurred while saving.');
            }
        }

        var filterData = <?= json_encode([
            'sa_names' => array_values($filter_sa_names),
            'subject_names' => array_values($filter_subject_names),
            'subject_to_sa' => $filter_subject_to_sa,
        ]) ?>;

        var activeCoverage = '';
        var activeSubject = '';

        function applyFilters() {
            var q = document.getElementById('deptSearch').value.toLowerCase().trim();
            document.querySelectorAll('#deptGrid > .section-container').forEach(function(card) {
                var deptName = card.querySelector('h2.bold').textContent.toLowerCase();
                var hasFacultyMatch = false;
                card.querySelectorAll('.faculty-name').forEach(function(fn) {
                    if (fn.textContent.toLowerCase().includes(q)) hasFacultyMatch = true;
                });
                var show = deptName.includes(q) || hasFacultyMatch;

                if (show && activeCoverage) {
                    var saSpans = card.querySelectorAll('.dept-subject-area');
                    var hasSa = Array.from(saSpans).some(function(sp) {
                        return sp.textContent.trim().toLowerCase() === activeCoverage.toLowerCase();
                    });
                    show = hasSa;
                }

                if (show && activeSubject) {
                    var relatedSas = filterData.subject_to_sa[activeSubject] || [];
                    var saSpans = card.querySelectorAll('.dept-subject-area');
                    var hasSubject = Array.from(saSpans).some(function(sp) {
                        return relatedSas.some(function(rsa) {
                            return sp.textContent.trim().toLowerCase() === rsa.toLowerCase();
                        });
                    });
                    show = hasSubject;
                }

                card.style.display = show ? '' : 'none';
            });

            document.getElementById('panelCoverageFilter').classList.remove('show');
            document.getElementById('panelSubjectFilter').classList.remove('show');
        }

        document.getElementById('deptSearch').addEventListener('input', applyFilters);

        // Build coverage filter panel
        (function() {
            var menu = document.getElementById('coverageFilterMenu');
            filterData.sa_names.forEach(function(name) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'filter-option d-block px-2 py-1';
                a.href = '#';
                a.textContent = name;
                a.dataset.value = name;
                li.appendChild(a);
                menu.appendChild(li);
            });
            menu.addEventListener('click', function(e) {
                var a = e.target.closest('.filter-option');
                if (!a) return;
                menu.querySelectorAll('.filter-option').forEach(function(el) { el.classList.remove('active'); });
                a.classList.add('active');
                activeCoverage = a.dataset.value;
                activeSubject = '';
                document.querySelectorAll('#subjectFilterMenu .filter-option').forEach(function(el) { el.classList.remove('active'); });
                var allSubj = document.querySelector('#subjectFilterMenu .filter-option[data-value=""]');
                if (allSubj) allSubj.classList.add('active');
                document.querySelector('[data-panel="panelCoverageFilter"]').classList.toggle('active-filter', !!activeCoverage);
                document.querySelector('[data-panel="panelSubjectFilter"]').classList.remove('active-filter');
                applyFilters();
            });
        })();

        // Build subject filter panel
        (function() {
            var menu = document.getElementById('subjectFilterMenu');
            filterData.subject_names.forEach(function(name) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'filter-option d-block px-2 py-1';
                a.href = '#';
                a.textContent = name;
                a.dataset.value = name;
                li.appendChild(a);
                menu.appendChild(li);
            });
            menu.addEventListener('click', function(e) {
                var a = e.target.closest('.filter-option');
                if (!a) return;
                menu.querySelectorAll('.filter-option').forEach(function(el) { el.classList.remove('active'); });
                a.classList.add('active');
                activeSubject = a.dataset.value;
                activeCoverage = '';
                document.querySelectorAll('#coverageFilterMenu .filter-option').forEach(function(el) { el.classList.remove('active'); });
                var allCov = document.querySelector('#coverageFilterMenu .filter-option[data-value=""]');
                if (allCov) allCov.classList.add('active');
                document.querySelector('[data-panel="panelSubjectFilter"]').classList.toggle('active-filter', !!activeSubject);
                document.querySelector('[data-panel="panelCoverageFilter"]').classList.remove('active-filter');
                applyFilters();
            });
        })();

        // Timetable-panel toggle (hover/focus open, mouseleave close with delay)
        (function() {
            var panels = ['panelInfoSteps', 'panelCoverageFilter', 'panelSubjectFilter'];
            var timers = {};

            panels.forEach(function(id) {
                var btn = document.querySelector('[data-panel="' + id + '"]');
                var panel = document.getElementById(id);
                if (!btn || !panel) return;

                timers[id] = null;

                function open() {
                    if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
                    panel.classList.add('show');
                }

                function close() {
                    if (timers[id]) clearTimeout(timers[id]);
                    timers[id] = setTimeout(function() { panel.classList.remove('show'); }, 150);
                }

                btn.addEventListener('mouseenter', open);
                btn.addEventListener('focus', open);
                panel.addEventListener('mouseenter', open);
                panel.addEventListener('mouseleave', close);
                btn.addEventListener('mouseleave', close);
            });
        })();