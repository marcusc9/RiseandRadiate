(() => {
  const header = document.querySelector("[data-site-header]");
  const toggle = document.querySelector(".rar-menu-toggle");
  const navigation = document.querySelector(".rar-site-navigation");

  if (!header || !toggle || !navigation) {
    return;
  }

  const closeNavigation = () => {
    header.classList.remove("is-open");
    document.body.classList.remove("rar-nav-open");
    toggle.setAttribute("aria-expanded", "false");
  };

  toggle.addEventListener("click", () => {
    const isOpen = header.classList.toggle("is-open");
    document.body.classList.toggle("rar-nav-open", isOpen);
    toggle.setAttribute("aria-expanded", String(isOpen));
  });

  navigation.addEventListener("click", (event) => {
    if (event.target.closest("a")) {
      closeNavigation();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeNavigation();
    }
  });

  document.addEventListener("click", (event) => {
    if (!header.contains(event.target)) {
      closeNavigation();
    }
  });

  let lastScroll = window.scrollY;
  window.addEventListener(
    "scroll",
    () => {
      const currentScroll = window.scrollY;
      header.classList.toggle("is-scrolled", currentScroll > 24);
      header.classList.toggle(
        "is-hidden",
        currentScroll > lastScroll && currentScroll > 180 && !header.classList.contains("is-open")
      );
      lastScroll = currentScroll;
    },
    { passive: true }
  );
})();
