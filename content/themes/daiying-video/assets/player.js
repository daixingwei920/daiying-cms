(() => {
  const shell = document.querySelector(".player-shell");
  let player = document.querySelector("[data-player]");
  const fallback = document.querySelector(".embed-fallback");
  const key = document.body.dataset.videoId && document.body.dataset.episodeId ? `video:${document.body.dataset.videoId}:progress` : "";
  const buttons = [...document.querySelectorAll("[data-url]")];
  let failovers = 0;
  let currentUrl = player?.getAttribute("src") || "";
  const render = (button, autoplay = true) => {
    if (!shell || !button) return;
    if (fallback) fallback.hidden = true;
    const url = button.dataset.url || "";
    const type = button.dataset.type || "";
    currentUrl = url;
    const old = shell.querySelector("[data-player], [data-player-embed]");
    if (type === "embed") {
      old?.remove();
      const iframe = document.createElement("iframe");
      iframe.dataset.playerEmbed = "1";
      iframe.src = url;
      iframe.sandbox = "allow-same-origin allow-presentation";
      iframe.referrerPolicy = "no-referrer";
      shell.prepend(iframe);
      player = null;
      return;
    }
    if (!player) {
      old?.remove();
      player = document.createElement("video");
      player.controls = true;
      player.playsInline = true;
      player.preload = "metadata";
      player.dataset.player = "1";
      shell.prepend(player);
      bindFailure();
    }
    player.src = url;
    if (autoplay) player.play().catch(() => failover());
  };
  const failover = () => {
    if (fallback) fallback.hidden = false;
    if (failovers > 0) return;
    failovers += 1;
    const next = buttons.find((button) => button.dataset.url && button.dataset.url !== currentUrl);
    if (next) render(next, true);
  };
  const bindFailure = () => player?.addEventListener("error", failover, { once: true });
  buttons.forEach((button) => button.addEventListener("click", () => { failovers = 0; render(button); }));
  if (player && key) {
    const saved = JSON.parse(localStorage.getItem(key) || "{}");
    if (saved.episode_id === document.body.dataset.episodeId && saved.position_seconds) player.currentTime = saved.position_seconds;
    window.setInterval(() => localStorage.setItem(key, JSON.stringify({ episode_id: document.body.dataset.episodeId, position_seconds: Math.floor(player.currentTime || 0), updated_at: new Date().toISOString() })), 5000);
    bindFailure();
  }
})();
