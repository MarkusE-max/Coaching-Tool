const passwordInput = document.querySelector("#password");
const togglePassword = document.querySelector("#toggle-password");

togglePassword?.addEventListener("click", () => {
    const visible = passwordInput.type === "text";
    passwordInput.type = visible ? "password" : "text";
    togglePassword.textContent = visible ? "Anzeigen" : "Verbergen";
    togglePassword.setAttribute("aria-pressed", String(!visible));
});
