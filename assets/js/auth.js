
// ===================================
// SMARTATTEND AUTHENTICATION JAVASCRIPT
// ===================================

// Eye icon: password is hidden
const eyeIcon = `
<svg xmlns="http://www.w3.org/2000/svg"
     width="20" height="20"
     viewBox="0 0 24 24"
     fill="none"
     stroke="currentColor"
     stroke-width="2"
     stroke-linecap="round"
     stroke-linejoin="round"
     aria-hidden="true">
    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
    <circle cx="12" cy="12" r="3"/>
</svg>
`;

// Crossed-eye icon: password is visible
const eyeOffIcon = `
<svg xmlns="http://www.w3.org/2000/svg"
     width="20" height="20"
     viewBox="0 0 24 24"
     fill="none"
     stroke="currentColor"
     stroke-width="2"
     stroke-linecap="round"
     stroke-linejoin="round"
     aria-hidden="true">
    <path d="M3 3l18 18"/>
    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
    <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a16 16 0 0 1-3.1 3.9"/>
    <path d="M6.2 6.2C3.5 8.1 2 12 2 12s3.5 7 10 7c1.3 0 2.5-.3 3.6-.8"/>
</svg>
`;

// ===================================
// SHOW AND HIDE PASSWORDS
// ===================================

document.querySelectorAll("[data-toggle-password]")
    .forEach(button => {

        const inputId = button.dataset.togglePassword;
        const passwordInput = document.getElementById(inputId);

        if (!passwordInput) return;

        // Display the correct icon when the page loads
        button.innerHTML = eyeIcon;
        button.setAttribute("aria-label", "Show password");
        button.setAttribute("title", "Show password");
        button.setAttribute("aria-pressed", "false");

        button.addEventListener("click", function () {

            if (passwordInput.type === "password") {

                passwordInput.type = "text";

                this.innerHTML = eyeOffIcon;
                this.setAttribute("aria-label", "Hide password");
                this.setAttribute("title", "Hide password");
                this.setAttribute("aria-pressed", "true");

            } else {

                passwordInput.type = "password";

                this.innerHTML = eyeIcon;
                this.setAttribute("aria-label", "Show password");
                this.setAttribute("title", "Show password");
                this.setAttribute("aria-pressed", "false");

            }
        });
    });


// ===================================
// CONFIRM PASSWORD VALIDATION
// ===================================

const registerForm = document.getElementById("registerForm");

if (registerForm) {

    const password = document.getElementById("password");

    const confirmPassword =
        document.getElementById("confirm_password");

    const message =
        document.getElementById("passwordMessage");

    if (password && confirmPassword && message) {

        function checkPasswords() {

            if (!confirmPassword.value) {

                message.textContent = "";
                message.style.color = "";

                confirmPassword.setCustomValidity("");

                return;
            }

            if (password.value === confirmPassword.value) {

                message.textContent = "Passwords match.";
                message.style.color = "green";

                confirmPassword.setCustomValidity("");

            } else {

                message.textContent = "Passwords do not match.";
                message.style.color = "red";

                confirmPassword.setCustomValidity(
                    "Passwords do not match."
                );
            }
        }

        password.addEventListener("input", checkPasswords);

        confirmPassword.addEventListener("input", checkPasswords);

        registerForm.addEventListener("submit", function(event) {

            checkPasswords();

            if (password.value !== confirmPassword.value) {

                event.preventDefault();

                alert("Password incorrect. Please check your passwords.");

                confirmPassword.focus();
            }

        });
    }
}
