(() => {
  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(payload),
    });
    let data = null;
    try {
      data = await res.json();
    } catch (_) {}
    if (!res.ok || !data || data.success !== true) {
      const msg =
        (data && data.error && data.error.message) ||
        "Impossible de mettre à jour la wishlist.";
      throw new Error(msg);
    }
    return data;
  }

  document.addEventListener("click", async (e) => {
    const btn = closest(e.target, "[data-action='remove']");
    if (!btn) return;

    const card = closest(btn, ".wl-card");
    if (!card) return;

    const gameId = parseInt(card.getAttribute("data-game-id") || "0", 10);
    if (!gameId) return;

    btn.disabled = true;
    card.classList.add("is-busy");

    try {
      await postJson("/api/wishlist/toggle", { game_id: gameId });
      card.remove();

      const grid = document.getElementById("results-grid");
      if (grid && grid.children.length === 0) {
        // fallback simple : recharger pour afficher l'état "vide" + pagination recalculée
        window.location.reload();
      }
    } catch (err) {
      btn.disabled = false;
      card.classList.remove("is-busy");
      alert(err && err.message ? err.message : "Erreur.");
    }
  });
})();

