/**
 * Role Management Module JS
 */
document.addEventListener("DOMContentLoaded", function () {
    // Delete Role Modal Handling
    const deleteModal = document.getElementById("deleteRoleModal");
    if (deleteModal) {
        deleteModal.addEventListener("show.bs.modal", function (event) {
            const button = event.relatedTarget;
            const roleId = button.getAttribute("data-id");
            const roleName = button.getAttribute("data-name");
            const userCount = button.getAttribute("data-user-count") || 0;

            const modalNameEl = deleteModal.querySelector("#deleteRoleName");
            const modalFormEl = deleteModal.querySelector("#deleteRoleForm");
            const modalWarningEl = deleteModal.querySelector("#deleteRoleWarning");
            const submitBtn = deleteModal.querySelector("button[type='submit']");

            if (modalNameEl) modalNameEl.textContent = roleName;
            if (modalFormEl) modalFormEl.action = "delete.php?id=" + roleId;

            if (parseInt(userCount, 10) > 0) {
                if (modalWarningEl) {
                    modalWarningEl.innerHTML = `<div class="alert alert-warning mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Warning: This role is currently assigned to <strong>${userCount}</strong> user(s). You must reassign those users before deleting this role.</div>`;
                }
                if (submitBtn) submitBtn.disabled = true;
            } else {
                if (modalWarningEl) {
                    modalWarningEl.innerHTML = `<p class="text-muted small">Are you sure you want to permanently delete this role? This action cannot be undone.</p>`;
                }
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Permission Matrix Module Select All Toggles
    const moduleCheckboxes = document.querySelectorAll(".module-select-all");
    moduleCheckboxes.forEach(function (toggle) {
        toggle.addEventListener("change", function () {
            const targetModule = this.getAttribute("data-module");
            const childCheckboxes = document.querySelectorAll(`.permission-checkbox[data-module="${targetModule}"]`);
            childCheckboxes.forEach(function (cb) {
                cb.checked = toggle.checked;
            });
            updatePermissionCounters();
        });
    });

    // Individual permission checkbox click
    const permCheckboxes = document.querySelectorAll(".permission-checkbox");
    permCheckboxes.forEach(function (cb) {
        cb.addEventListener("change", function () {
            const moduleName = this.getAttribute("data-module");
            const allInModule = document.querySelectorAll(`.permission-checkbox[data-module="${moduleName}"]`);
            const allCheckedInModule = document.querySelectorAll(`.permission-checkbox[data-module="${moduleName}"]:checked`);
            const moduleToggle = document.querySelector(`.module-select-all[data-module="${moduleName}"]`);
            if (moduleToggle) {
                moduleToggle.checked = (allInModule.length === allCheckedInModule.length);
            }
            updatePermissionCounters();
        });
    });

    // Global Select All
    const btnSelectAll = document.getElementById("btnSelectAllPerms");
    if (btnSelectAll) {
        btnSelectAll.addEventListener("click", function () {
            document.querySelectorAll(".permission-checkbox, .module-select-all").forEach(function (cb) {
                cb.checked = true;
            });
            updatePermissionCounters();
        });
    }

    // Global Deselect All
    const btnDeselectAll = document.getElementById("btnDeselectAllPerms");
    if (btnDeselectAll) {
        btnDeselectAll.addEventListener("click", function () {
            document.querySelectorAll(".permission-checkbox, .module-select-all").forEach(function (cb) {
                cb.checked = false;
            });
            updatePermissionCounters();
        });
    }

    function updatePermissionCounters() {
        const total = document.querySelectorAll(".permission-checkbox").length;
        const selected = document.querySelectorAll(".permission-checkbox:checked").length;
        const counterEl = document.getElementById("selectedPermCount");
        if (counterEl) {
            counterEl.textContent = `${selected} / ${total}`;
        }
    }

    // Initial counter setup on page load
    updatePermissionCounters();
});
