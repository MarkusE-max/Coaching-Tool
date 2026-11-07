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
        if (response.ok && result.schema === "ready") {
            status.textContent = "Verbunden · Coaching-Tabellen erkannt";
            dot.classList.add("is-connected");
            dot.classList.remove("is-disconnected");
        } else if (result.database === "connected") {
            const missing = Array.isArray(result.missing_tables) ? result.missing_tables.join(", ") : "";
            status.textContent = missing ? `Verbunden · Tabellen fehlen: ${missing}` : "Tabellenstruktur unvollständig";
            dot.classList.add("is-disconnected");
            dot.classList.remove("is-connected");
        } else {
            status.textContent = "Keine Datenbankverbindung";
            dot.classList.add("is-disconnected");
            dot.classList.remove("is-connected");
        }
    } catch {
        status.textContent = "API nicht erreichbar";
        dot.classList.add("is-disconnected");
        dot.classList.remove("is-connected");
    } finally {
        button.disabled = false;
    }
});
