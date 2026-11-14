document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.querySelector(".sidebar");
  const menuButton = document.querySelector(".mobile-menu");

  if (!sidebar || !menuButton) {
    return;
  }

  menuButton.addEventListener("click", () => {
    const isOpen = sidebar.classList.toggle("is-open");
    menuButton.setAttribute("aria-expanded", String(isOpen));
  });

  document.addEventListener("click", (event) => {
    if (
      sidebar.classList.contains("is-open") &&
      !sidebar.contains(event.target) &&
      !menuButton.contains(event.target)
    ) {
      sidebar.classList.remove("is-open");
      menuButton.setAttribute("aria-expanded", "false");
    }
  });
});
