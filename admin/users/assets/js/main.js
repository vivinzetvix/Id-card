/**
 * Users Management Module JS
 */
document.addEventListener("DOMContentLoaded", function () {
    // Delete User Modal Handling
    const deleteModal = document.getElementById("deleteUserModal");
    if (deleteModal) {
        deleteModal.addEventListener("show.bs.modal", function (event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute("data-id");
            const username = button.getAttribute("data-username");
            const isSuperAdmin = button.getAttribute("data-is-superadmin") === "1";
            const isSelf = button.getAttribute("data-is-self") === "1";

            const modalNameEl = deleteModal.querySelector("#deleteUsername");
            const modalFormEl = deleteModal.querySelector("#deleteUserForm");
            const modalWarningEl = deleteModal.querySelector("#deleteUserWarning");
            const submitBtn = deleteModal.querySelector("button[type='submit']");

            if (modalNameEl) modalNameEl.textContent = username;
            if (modalFormEl) modalFormEl.action = "delete.php?id=" + userId;

            if (isSelf) {
                if (modalWarningEl) {
                    modalWarningEl.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-ban me-2"></i>You cannot delete your own logged-in account.</div>`;
                }
                if (submitBtn) submitBtn.disabled = true;
            } else if (isSuperAdmin) {
                if (modalWarningEl) {
                    modalWarningEl.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-shield-alt me-2"></i>Super Admin accounts cannot be deleted.</div>`;
                }
                if (submitBtn) submitBtn.disabled = true;
            } else {
                if (modalWarningEl) {
                    modalWarningEl.innerHTML = `<p class="text-muted small">Are you sure you want to soft-delete this user account? The user will be unable to log in.</p>`;
                }
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Reset Password Modal Handling
    const resetModal = document.getElementById("resetPasswordModal");
    if (resetModal) {
        resetModal.addEventListener("show.bs.modal", function (event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute("data-id");
            const username = button.getAttribute("data-username");

            const modalNameEl = resetModal.querySelector("#resetUsername");
            const userIdInput = resetModal.querySelector("#resetUserId");

            if (modalNameEl) modalNameEl.textContent = username;
            if (userIdInput) userIdInput.value = userId;
        });

        // Generate Random Password button
        const btnGenPass = resetModal.querySelector("#btnGeneratePassword");
        const passInput = resetModal.querySelector("#new_password");
        if (btnGenPass && passInput) {
            btnGenPass.addEventListener("click", function () {
                const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
                let pass = "";
                for (let i = 0; i < 12; i++) {
                    pass += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                passInput.value = pass;
                passInput.type = "text";
            });
        }
    }

    // Avatar Image Live Preview
    const avatarFileInput = document.getElementById("avatar");
    const avatarPreviewImg = document.getElementById("avatarPreview");
    if (avatarFileInput && avatarPreviewImg) {
        avatarFileInput.addEventListener("change", function () {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    avatarPreviewImg.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
