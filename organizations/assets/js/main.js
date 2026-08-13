document.addEventListener('DOMContentLoaded', function () {
    const deleteModal = document.getElementById('deleteOrganizationModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const organizationId = button.getAttribute('data-id');
            const organizationName = button.getAttribute('data-name');
            const form = document.getElementById('deleteOrganizationForm');
            const nameLabel = document.getElementById('deleteOrganizationName');
            if (form && nameLabel) {
                form.action = 'delete.php?id=' + encodeURIComponent(organizationId);
                nameLabel.textContent = organizationName;
            }
        });
    }
});
