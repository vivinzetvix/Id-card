document.addEventListener("DOMContentLoaded", function () {
    const userMenu = document.getElementById("userMenu");
    const dropdown = document.getElementById("userDropdown");

    if (userMenu && dropdown) {
        userMenu.addEventListener("click", function (e) {
            e.stopPropagation();
            dropdown.classList.toggle("active");
        });

        document.addEventListener("click", function () {
            dropdown.classList.remove("active");
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                dropdown.classList.remove("active");
            }
        });
    }
});
