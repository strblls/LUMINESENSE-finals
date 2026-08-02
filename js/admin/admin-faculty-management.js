const departmentsData = <?= json_encode($departments) ?>;

function openDeleteFacultyModal(facultyId, facultyName) {
    document.getElementById('deleteFacultyId').value = facultyId;
    document.getElementById('deleteFacultyName').textContent = facultyName;
    new bootstrap.Modal(document.getElementById('deleteFacultyModal')).show();
}

function openDeleteDepartmentModal(deptId, deptName) {
    document.getElementById('deleteDepartmentId').value = deptId;
    document.getElementById('deleteDepartmentName').textContent = deptName;
    new bootstrap.Modal(document.getElementById('deleteDepartmentModal')).show();
}

function openAddDepartmentModal() {
    document.getElementById('addDepartmentForm').reset();
    initSubjectAreaTags('add', []);
    new bootstrap.Modal(document.getElementById('addDepartmentModal')).show();
}

function openEditDepartmentModal(deptId, deptName, deptDesc, headId, deptStatus) {
    document.getElementById('editDeptId').value = deptId;
    document.getElementById('editDeptName').value = deptName;
    document.getElementById('editDeptDescription').value = deptDesc;

    const statusSelect = document.getElementById('editDeptStatus');
    if (deptStatus && ['active', 'inactive'].includes(deptStatus)) {
        statusSelect.value = deptStatus;
    } else {
        statusSelect.value = 'active';
    }

    const dept = departmentsData.find(d => d.id == deptId);

    initSubjectAreaTags('edit', dept && dept.subject_areas ? dept.subject_areas : []);

    document.querySelectorAll('.edit-hod-radio').forEach(r => r.checked = false);
    document.querySelectorAll('.edit-member-checkbox').forEach(c => c.checked = false);

    if (headId) {
        const radio = document.getElementById('editHod_' + headId);
        if (radio) radio.checked = true;
    }

    if (dept && dept.faculty_members) {
        dept.faculty_members.forEach(m => {
            const checkbox = document.getElementById('editMember_' + m.id);
            if (checkbox) checkbox.checked = true;
        });
    }

    new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
}

function openViewDepartmentModal(deptId, deptName, deptDesc, headId) {
    document.getElementById('viewDeptName').value = deptName;
    document.getElementById('viewDeptDescription').value = deptDesc;

    const dept = departmentsData.find(d => d.id == deptId);

    const headEl = document.getElementById('viewDeptHead');
    if (headId && dept && dept.head_first_name) {
        headEl.textContent = dept.head_first_name + ' ' + dept.head_last_name;
    } else {
        headEl.textContent = 'None assigned';
    }

    const membersEl = document.getElementById('viewDeptMembers');
    if (dept && dept.faculty_members && dept.faculty_members.length > 0) {
        const names = dept.faculty_members
            .map(m => (m.first_name || '') + ' ' + (m.last_name || ''))
            .map(n => n.trim())
            .filter(n => n);
        if (names.length > 0) {
            membersEl.innerHTML = names.map(n =>
                '<li class="list-group-item"><i class="bi bi-person-fill me-2"></i>' + n + '</li>'
            ).join('');
        } else {
            membersEl.innerHTML = '<li class="list-group-item text-muted">None</li>';
        }
    } else {
        membersEl.innerHTML = '<li class="list-group-item text-muted">None</li>';
    }

    new bootstrap.Modal(document.getElementById('viewDepartmentModal')).show();
}

function filterFacultySearch(input, listId) {
    const filter = input.value.toLowerCase();
    const list = document.getElementById(listId);
    const items = list.querySelectorAll('.faculty-search-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name') || item.textContent.toLowerCase();
        if (name.includes(filter)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function filterList(status) {
    const items = document.querySelectorAll('.faculty-list-item');
    items.forEach(item => {
        if (status === 'all' || item.getAttribute('data-status') === status) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function clearHod(type) {
    const prefix = type === 'add' ? 'add' : 'edit';
    document.querySelectorAll(`.${prefix}-hod-radio`).forEach(r => r.checked = false);
}

function showDuplicateWarning() {
    const modal = new bootstrap.Modal(document.getElementById('duplicateWarningModal'));
    modal.show();
}

document.getElementById('editDepartmentForm').addEventListener('submit', function(e) {
    const selectedHod = document.querySelector('.edit-hod-radio:checked');
    const selectedMembers = document.querySelectorAll('.edit-member-checkbox:checked');

    if (selectedHod) {
        const hodId = selectedHod.getAttribute('data-faculty-id');
        const duplicate = Array.from(selectedMembers).find(m => m.getAttribute('data-faculty-id') === hodId);

        if (duplicate) {
            e.preventDefault();
            duplicate.closest('.faculty-search-item').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            showDuplicateWarning();
            return false;
        }
    }
});

function syncScrollContainerHeight() {
    const mainContainer = document.querySelector('.main-container.faculty-management.gap-5');
    const scrollContainer = document.querySelector('.departments-scroll-container');
    if (mainContainer && scrollContainer) {
        scrollContainer.style.maxHeight = mainContainer.offsetHeight + 'px';
    }
}
document.addEventListener('DOMContentLoaded', syncScrollContainerHeight);
window.addEventListener('resize', syncScrollContainerHeight);

document.getElementById('addDepartmentForm').addEventListener('submit', function(e) {
    const selectedHod = document.querySelector('.add-hod-radio:checked');
    const selectedMembers = document.querySelectorAll('.add-member-checkbox:checked');

    if (selectedHod) {
        const hodId = selectedHod.getAttribute('data-faculty-id');
        const duplicate = Array.from(selectedMembers).find(m => m.getAttribute('data-faculty-id') === hodId);

        if (duplicate) {
            e.preventDefault();
            duplicate.closest('.faculty-search-item').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
            showDuplicateWarning();
            return false;
        }
    }
});

const subjectAreaState = {
    add: [],
    edit: []
};

function renderSubjectAreaTags(context) {
    const container = document.getElementById(context + 'SubjectAreaTags');
    const tags = subjectAreaState[context];
    container.innerHTML = tags.map((tag, i) =>
        `<span class="subject-area-tag me-1 mb-1 d-inline-flex align-items-center">
            ${escapeHtml(tag)}
            <i class="bi bi-x ms-1 subject-area-remove" style="cursor:pointer;font-size:1.1em" data-context="${context}" data-index="${i}"></i>
        </span>`
    ).join('');
}

function initSubjectAreaTags(context, initialTags) {
    const input = document.getElementById(context + 'SubjectAreaInput');
    const container = document.getElementById(context + 'SubjectAreaTags');
    subjectAreaState[context] = [...initialTags];
    renderSubjectAreaTags(context);
    input.value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    ['add', 'edit'].forEach(function(ctx) {
        const input = document.getElementById(ctx + 'SubjectAreaInput');
        if (!input) return;

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addSubjectAreaTag(ctx);
            }
        });
        input.addEventListener('blur', function() {
            addSubjectAreaTag(ctx);
        });
    });

    document.querySelectorAll('.subject-area-tags').forEach(function(container) {
        container.addEventListener('click', function(e) {
            const removeBtn = e.target.closest('.subject-area-remove');
            if (removeBtn) {
                const ctx = removeBtn.dataset.context;
                const idx = parseInt(removeBtn.dataset.index);
                subjectAreaState[ctx].splice(idx, 1);
                renderSubjectAreaTags(ctx);
            }
        });
    });
});

function addSubjectAreaTag(context) {
    const input = document.getElementById(context + 'SubjectAreaInput');
    const val = input.value.trim();
    if (!val) return;
    if (subjectAreaState[context].includes(val)) {
        input.value = '';
        return;
    }
    subjectAreaState[context].push(val);
    renderSubjectAreaTags(context);
    input.value = '';
}

document.getElementById('addDepartmentForm').addEventListener('submit', function() {
    serializeSubjectAreas('add', this);
});
document.getElementById('editDepartmentForm').addEventListener('submit', function() {
    serializeSubjectAreas('edit', this);
});

function serializeSubjectAreas(context, form) {
    form.querySelectorAll('input[name="dept_subject_areas[]"]').forEach(function(el) {
        el.remove();
    });
    subjectAreaState[context].forEach(function(name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'dept_subject_areas[]';
        input.value = name;
        form.appendChild(input);
    });
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function switchToTab(tabKey) {
    var btn = document.querySelector('.timetable-btn[data-tab="' + tabKey + '"]');
    if (btn) btn.click();
}

function goToDefaultPanel() {
    document.querySelectorAll('.timetable-btn[data-tab]').forEach(function(t) {
        t.classList.remove('active');
    });
    if (currentTab) {
        var oldPanel = document.getElementById('panel-' + currentTab);
        if (oldPanel) oldPanel.classList.remove('active');
    }
    var defaultState = document.getElementById('defaultState');
    if (defaultState) {
        defaultState.style.animation = 'none';
        void defaultState.offsetWidth;
        defaultState.classList.add('active');
        defaultState.style.animation = 'panelSlideInFromRight 0.3s ease';
    }
    tabTextSlide.style.animation = 'none';
    void tabTextSlide.offsetWidth;
    tabHeading.textContent = 'Faculty Management';
    tabSubheading.textContent = 'Select a category to get started';
    tabTextSlide.style.animation = 'slideInFromRight 0.3s ease';
    currentTab = null;
}

var tabOrder = ['pending-approvals', 'departments', 'faculty-directory'];
var activeBtn = document.querySelector('.timetable-btn[data-tab].active');
var currentTab = activeBtn ? activeBtn.getAttribute('data-tab') : null;
var tabLabels = {
    'pending-approvals': {
        heading: 'Approvals Management',
        sub: 'Account and extension approvals'
    },
    'departments': {
        heading: 'Department Management',
        sub: 'Assign designation to faculties'
    },
    'faculty-directory': {
        heading: 'Account Management',
        sub: 'Manage all accounts'
    }
};
var tabHeading = document.getElementById('tabHeading');
var tabSubheading = document.getElementById('tabSubheading');
var tabTextSlide = document.getElementById('tabTextSlide');

var savedTab = sessionStorage.getItem('activeTab');
if (savedTab) {
    sessionStorage.removeItem('activeTab');
    var restoreBtn = document.querySelector('.timetable-btn[data-tab="' + savedTab + '"]');
    if (restoreBtn) {
        setTimeout(function() { restoreBtn.click(); }, 10);
    }
}

var urlParams = new URLSearchParams(window.location.search);
var tabParam = urlParams.get('tab');
if (tabParam) {
    var targetBtn = document.querySelector('.timetable-btn[data-tab="' + tabParam + '"]');
    if (targetBtn) {
        setTimeout(function() { targetBtn.click(); }, 10);
    }
}

document.querySelectorAll('.timetable-btn[data-tab]').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var key = this.getAttribute('data-tab');
        if (key === currentTab) {
            document.querySelectorAll('.timetable-btn[data-tab]').forEach(function(t) {
                t.classList.remove('active');
            });
            var oldPanel = document.getElementById('panel-' + key);
            if (oldPanel) oldPanel.classList.remove('active');
            var defaultState = document.getElementById('defaultState');
            if (defaultState) {
                defaultState.style.animation = 'none';
                void defaultState.offsetWidth;
                defaultState.classList.add('active');
                defaultState.style.animation = 'panelSlideInFromRight 0.3s ease';
            }
            tabTextSlide.style.animation = 'none';
            void tabTextSlide.offsetWidth;
            tabHeading.textContent = 'Faculty Management';
            tabSubheading.textContent = 'Select a category to get started';
            tabTextSlide.style.animation = 'slideInFromRight 0.3s ease';
            currentTab = null;
            return;
        }
        document.querySelectorAll('.timetable-btn[data-tab]').forEach(function(t) {
            t.classList.remove('active');
        });
        this.classList.add('active');

        var currentIndex = tabOrder.indexOf(currentTab);
        var newIndex = tabOrder.indexOf(key);

        var defaultState = document.getElementById('defaultState');
        if (defaultState && currentTab === null) {
            defaultState.style.animation = 'none';
            void defaultState.offsetWidth;
            defaultState.classList.remove('active');
            defaultState.style.animation = 'panelSlideOutToLeft 0.25s ease';
        } else if (defaultState) {
            defaultState.classList.remove('active');
        }

        var oldPanel = document.getElementById('panel-' + currentTab);
        if (oldPanel) oldPanel.classList.remove('active');

        var newPanel = document.getElementById('panel-' + key);
        if (newPanel) {
            newPanel.style.animation = 'none';
            void newPanel.offsetWidth;
            newPanel.classList.add('active');
            if (newIndex > currentIndex) {
                newPanel.style.animation = 'panelSlideInFromLeft 0.3s ease';
            } else if (newIndex < currentIndex) {
                newPanel.style.animation = 'panelSlideInFromRight 0.3s ease';
            }
        }

        if (tabLabels[key]) {
            tabTextSlide.style.animation = 'none';
            void tabTextSlide.offsetWidth;
            tabHeading.textContent = tabLabels[key].heading;
            tabSubheading.textContent = tabLabels[key].sub;
            if (newIndex > currentIndex) {
                tabTextSlide.style.animation = 'slideInFromLeft 0.3s ease';
            } else if (newIndex < currentIndex) {
                tabTextSlide.style.animation = 'slideInFromRight 0.3s ease';
            }
        }
        currentTab = key;
    });
});

var activeDeptStatus = 'all';
var activeDeptSort = 'asc';

function filterByFacultyMember(el, memberName) {
    document.querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    var input = document.getElementById('deptSearch');
    if (memberName) {
        input.value = memberName;
    } else {
        input.value = '';
    }
    filterDepartments(input.value);
}

function filterDeptByStatus(el, status) {
    el.closest('.dept-member-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeDeptStatus = status;
    filterDepartments(document.getElementById('deptSearch').value);
}

function sortDeptsByName(el, dir) {
    el.closest('.dept-member-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeDeptSort = dir;
    filterDepartments(document.getElementById('deptSearch').value);
}

function filterDepartments(query) {
    var q = query.toLowerCase().trim();
    var cards = Array.prototype.slice.call(document.querySelectorAll('#panel-departments .room-card'));
    cards.forEach(function(card) {
        var deptName = card.getAttribute('data-dept-name') || '';
        var headName = card.getAttribute('data-head-name') || '';
        var memberNames = card.getAttribute('data-member-names') || '';
        var status = card.getAttribute('data-dept-status') || '';
        var nameEl = card.querySelector('.room-card-name');
        var headRow = card.querySelector('.room-info-row[data-search-field="head"]');
        var membersRow = card.querySelector('.room-info-row[data-search-field="members"]');
        [nameEl, headRow, membersRow].forEach(function(el) {
            if (el) el.classList.remove('search-highlight');
        });

        var matchDept = q && deptName.indexOf(q) !== -1;
        var matchHead = q && headName.indexOf(q) !== -1;
        var matchMembers = q && memberNames.indexOf(q) !== -1;
        var matchStatus = activeDeptStatus === 'all' || status === activeDeptStatus;
        var matchSearch = !q || matchDept || matchHead || matchMembers;

        if (matchDept && nameEl) nameEl.classList.add('search-highlight');
        if (matchHead && headRow) headRow.classList.add('search-highlight');
        if (matchMembers && membersRow) membersRow.classList.add('search-highlight');
        card.style.display = (matchStatus && matchSearch) ? '' : 'none';
    });

    var grid = document.querySelector('#panel-departments .departments-grid');
    if (!grid) return;
    var sorted = cards.filter(function(c) { return c.style.display !== 'none'; }).sort(function(a, b) {
        var na = (a.getAttribute('data-dept-name') || '').toLowerCase();
        var nb = (b.getAttribute('data-dept-name') || '').toLowerCase();
        return activeDeptSort === 'asc' ? na.localeCompare(nb) : nb.localeCompare(na);
    });
    sorted.forEach(function(c) { grid.appendChild(c); });
}

var activeFacultyStatus = 'all';
var activeFacultyDate = 'all';
var activeFacultySort = 'asc';

function filterFacultyCards(query) {
    var q = query.toLowerCase().trim();
    var cards = Array.prototype.slice.call(document.querySelectorAll('#panel-faculty-directory .room-card'));
    cards.forEach(function(card) {
        var name = card.getAttribute('data-faculty-name') || '';
        var email = card.getAttribute('data-faculty-email') || '';
        var status = card.getAttribute('data-faculty-status') || '';
        var created = card.getAttribute('data-faculty-created') || '';
        var nameEl = card.querySelector('.room-card-name');
        var emailEl = card.querySelector('.room-card-section');
        if (nameEl) nameEl.classList.remove('search-highlight');
        if (emailEl) emailEl.classList.remove('search-highlight');

        var matchName = q && name.indexOf(q) !== -1;
        var matchEmail = q && email.indexOf(q) !== -1;
        var matchStatus = activeFacultyStatus === 'all' || status === activeFacultyStatus;
        var matchDate = true;
        if (activeFacultyDate !== 'all' && created) {
            var d = new Date(created);
            var now = new Date();
            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay());
            var monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
            var yearStart = new Date(now.getFullYear(), 0, 1);
            if (activeFacultyDate === 'today') matchDate = d >= today;
            else if (activeFacultyDate === 'week') matchDate = d >= weekStart;
            else if (activeFacultyDate === 'month') matchDate = d >= monthStart;
            else if (activeFacultyDate === 'year') matchDate = d >= yearStart;
        }
        var matchSearch = !q || matchName || matchEmail;
        var matchType = activeFacultyType === 'all' || (activeFacultyType === (card.getAttribute('data-faculty-type') || ''));

        if (matchName && nameEl) nameEl.classList.add('search-highlight');
        if (matchEmail && emailEl) emailEl.classList.add('search-highlight');
        if (matchSearch && matchStatus && matchDate && matchType) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });

    var grid = document.querySelector('#panel-faculty-directory .faculty-grid');
    if (!grid) return;
    var visible = cards.filter(function(c) { return c.style.display !== 'none'; });
    var sorted = visible.sort(function(a, b) {
        var na = (a.getAttribute('data-faculty-name') || '').toLowerCase();
        var nb = (b.getAttribute('data-faculty-name') || '').toLowerCase();
        return activeFacultySort === 'asc' ? na.localeCompare(nb) : nb.localeCompare(na);
    });
    sorted.forEach(function(c) { grid.appendChild(c); });
    var noResults = document.getElementById('facultyNoResults');
    if (noResults) {
        noResults.style.display = visible.length === 0 ? '' : 'none';
    }
}

function filterFacultyByStatus(el, status) {
    el.closest('.faculty-side-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeFacultyStatus = status;
    filterFacultyCards(document.getElementById('facultySearch').value);
}

function filterFacultyByDate(el, dateRange) {
    el.closest('.faculty-side-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeFacultyDate = dateRange;
    filterFacultyCards(document.getElementById('facultySearch').value);
}

function sortFacultyByName(el, dir) {
    el.closest('.faculty-side-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeFacultySort = dir;
    filterFacultyCards(document.getElementById('facultySearch').value);
}

var activeFacultyType = 'all';

function filterFacultyByType(el, type) {
    el.closest('.faculty-side-filter').querySelectorAll('.dept-member-filter-item').forEach(function(i) {
        i.classList.remove('active');
    });
    el.classList.add('active');
    activeFacultyType = type;
    filterFacultyCards(document.getElementById('facultySearch').value);
}

(function() {
    var panels = ['panelGuideInfo'];
    var timers = {};
    var heading = document.getElementById('facultyHeading');
    panels.forEach(function(id) {
        var btn = document.querySelector('[data-panel="' + id + '"]');
        var panel = document.getElementById(id);
        if (!btn || !panel) return;
        timers[id] = null;

        function open() {
            if (timers[id]) {
                clearTimeout(timers[id]);
                timers[id] = null;
            }
            panel.classList.add('show');
            if (heading) heading.style.zIndex = '1050';
        }

        function close() {
            if (timers[id]) clearTimeout(timers[id]);
            timers[id] = setTimeout(function() {
                panel.classList.remove('show');
                if (heading) heading.style.zIndex = '';
            }, 150);
        }
        btn.addEventListener('mouseenter', open);
        btn.addEventListener('focus', open);
        panel.addEventListener('mouseenter', open);
        panel.addEventListener('mouseleave', close);
        btn.addEventListener('mouseleave', close);
    });
})();

window.addEventListener('scroll', function() {
    var scrollThreshold = 100;
    var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - scrollThreshold;
    document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function(el) {
        el.classList.toggle('hidden', nearBottom);
    });
});
