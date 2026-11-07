import { useEffect, useState } from "react";

export default function App() {
  const [apiStatus, setApiStatus] = useState("Prüfung läuft …");

  useEffect(() => {
    fetch("/api/health.php", { headers: { Accept: "application/json" } })
      .then((response) => {
        if (!response.ok) throw new Error("API nicht erreichbar");
        return response.json();
      })
      .then((result) => setApiStatus(result.status === "ok" ? "Verbunden" : "Nicht verfügbar"))
      .catch(() => setApiStatus("Noch nicht verbunden"));
  }, []);

  return (
    <main className="shell">
      <header className="topbar">
        <a className="brand" href="/" aria-label="Coaching Tool Startseite">
          <span className="brand-mark" aria-hidden="true">C</span>
          <span>Coaching<span className="brand-light">Tool</span></span>
        </a>
        <span className="environment">Entwicklungsstand</span>
      </header>

      <section className="welcome">
        <p className="eyebrow">COACHING VERWALTEN</p>
        <h1>Ein klarer Rahmen für gute Entwicklung.</h1>
        <p className="intro">
          Die technische Basis für Klienten, Coaching-Aufträge und Sitzungen ist bereit.
          Weitere Bereiche folgen schrittweise.
        </p>
        <div className="status-card" role="status" aria-live="polite">
          <span className="status-dot" />
          <span><strong>API-Status</strong><small>{apiStatus}</small></span>
        </div>
      </section>

      <footer>Privater Entwicklungsstand · Noch keine Klientendaten hinterlegt</footer>
    </main>
  );
}
