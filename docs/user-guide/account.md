# Your account and two-factor sign-in

## One account, many sites

Your account works on every site of the platform. You can own several sites and be an editor or author on others, with a different role on each. **Your platform** (in the dashboard menu of any site you belong to) lists them all.

Each site address keeps its own sign-in: when you open the dashboard of another site, you may be asked to sign in again. This is deliberate — it keeps one site's address from ever receiving the sign-in cookie of another.

## Getting an account

How you join depends on the platform's policy, which the operators choose:

* **Invitation** — a site administrator or operator adds you; you receive an email to set your password.
* **Approval** — you register at `/wp-signup.php`; an operator reviews the request; the activation email arrives once approved.
* **Allowed domains** — you can register yourself with an email address from the listed domains (for example your school or organisation).
* **Open** — anyone can register.

## Profile, email and password

Under **Users → Profile** (or the "Howdy" menu) you can change your display name, email address and password. Changing your email sends a confirmation to the new address first. "Lost your password?" on the sign-in page always shows the same message, whether or not an account exists, so it cannot be used to discover who has an account.

## Two-factor authentication

Protect your account with a second factor under **Users → Profile → Two-Factor Options**:

* **Authenticator app (TOTP)** — scan the QR code with an app such as Aegis, 2FAS, Google Authenticator or 1Password and enter a code to confirm.
* **Security key / passkey (WebAuthn)** — register a hardware key or your device's built-in authenticator. Requires HTTPS.
* **Backup codes** — generate them and store them somewhere safe; each works once if you lose your phone or key.

Email codes are not offered as a second factor, because whoever controls your mailbox would control your account.

Operators must use TOTP or a security key in production; the platform sends them to their profile until one is set up. Operators may also require two-factor sign-in for every site administrator.

## Sign out everywhere

If you lost a device or suspect someone else used your account, open **Your platform → Account security → Sign out everywhere**. Every session on every device ends, including the current one. Then change your password.
