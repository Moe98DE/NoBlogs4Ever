# Encrypted contact form

The platform can show a contact form whose messages are **encrypted in the visitor's browser** with your public key, so the message text never reaches the platform, its logs or its email provider in readable form. Only you, holding the private key, can read it.

## Set it up

1. Open `tools/contact-key-tool.html` from the project repository **on your own computer** (save it and open it locally, ideally offline). Click **Generate an RSA-3072 key pair**.
2. Save the **private key** somewhere safe (a password manager or encrypted disk). Never upload it to the platform.
3. In your site go to **Settings → Encrypted contact**. Enter the email address that should receive messages and paste the **public key**. Save. The page shows the key's fingerprint.
4. Add the shortcode `[nbe_contact]` to any page (Shortcode block).
5. Publish the fingerprint somewhere your contacts can verify independently (for example in person, or on another channel you control).

## Read a message

Messages arrive by email containing an encrypted envelope between `-----BEGIN NBE ENVELOPE-----` and `-----END NBE ENVELOPE-----`. Paste the whole email into the offline tool together with your private key and click **Decrypt**.

## Rotate or remove the key

Paste a new public key to rotate; keep the old private key to read older messages. Clear the key field to switch the form off. Visitors who had the page open with the old key are asked to reload.

## What it protects — and what it does not

**Protected:** the message text is encrypted (AES-256-GCM, with the key wrapped by RSA-OAEP-SHA-256) before it leaves the visitor's browser. The server accepts only the encrypted envelope and never logs it.

**Still visible to the platform and the email provider:** that a message was sent to your site, when, its approximate size, the visitor's network connection while sending (not stored), and normal email metadata.

**Not protected against:** someone who controls the platform's servers could serve a modified form or a different key. Visitors who verify the fingerprint shown on the form against the one you published elsewhere can detect a swapped key; a modified script is harder to detect. Treat the form as strong protection against routine exposure — logs, backups, mailbox breaches — not against a malicious hosting operator.

The form requires JavaScript and HTTPS; there is no unencrypted fallback. It is rate-limited per connection and per site.
