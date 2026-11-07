const button = document.querySelector("#check-database");
const status = document.querySelector("#db-status");
const dot = document.querySelector("#status-dot");

button?.addEventListener("click", async () => {
    button.disabled = true;
    status.textContent = "Verbindung wird geprüft …";

    try {
        const response = await fetch("health.php", {
            headers: { Accept: "application/json" },
            cache: "no-store",
        });
        const result = await response.json();
        const connected = response.ok && result.database === "connected";
        status.textContent = connected ? "Verbunden" : "Nicht verbunden";
        dot.classList.toggle("is-connected", connected);
        dot.classList.toggle("is-disconnected", !connected);
    } catch {
        status.textContent = "API nicht erreichbar";
        dot.classList.add("is-disconnected");
        dot.classList.remove("is-connected");
    } finally {
        button.disabled = false;
    }
});
