(() => {
  const body = document.body;
  const key = body.dataset.novelId || "";
  if (key) {
    const allProgress = JSON.parse(localStorage.getItem("daiying_novel_reading_progress") || "{}");
    const saved = allProgress[key] || {};
    if (String(saved.chapter) === String(body.dataset.chapterId) && saved.scrollY) {
      window.setTimeout(() => window.scrollTo(0, saved.scrollY), 80);
    }
    const save = () => {
      const max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
      const scrollY = Math.max(0, window.scrollY || document.documentElement.scrollTop || 0);
      const percent = Math.min(100, Math.round(scrollY / max * 100));
      const label = document.querySelector("[data-reader-percent]");
      if (label) label.textContent = `${percent}%`;
      allProgress[key] = {
        chapter: body.dataset.chapterId || "",
        chapterTitle: body.dataset.chapterTitle || "",
        chapterUrl: location.pathname + location.search,
        bookTitle: body.dataset.bookTitle || "",
        bookUrl: body.dataset.bookUrl || "",
        scrollY,
        percent,
        updatedAt: new Date().toISOString()
      };
      localStorage.setItem("daiying_novel_reading_progress", JSON.stringify(allProgress));
    };
    window.addEventListener("scroll", () => window.requestAnimationFrame(save), { passive: true });
    window.addEventListener("beforeunload", save);
    save();
  }
  document.querySelectorAll("[data-theme]").forEach((btn) => btn.addEventListener("click", () => { body.classList.remove("paper", "green", "night"); body.classList.add(btn.dataset.theme); localStorage.setItem("novel:reader_theme", btn.dataset.theme || "paper"); }));
  document.querySelector("[data-font]")?.addEventListener("input", (event) => { document.querySelector(".reader-content")?.style.setProperty("font-size", `${event.target.value}px`); });
  document.querySelector("#chapter-search")?.addEventListener("input", (event) => document.querySelectorAll("#chapter-list a").forEach((a) => { a.hidden = !a.dataset.title.includes(event.target.value); }));
  document.querySelector("[data-fullscreen]")?.addEventListener("click", () => { if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen(); });
})();
