'use strict';
for (const form of document.querySelectorAll('.nbe-contact')) {
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const status = form.querySelector('[role="status"]');
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      if (!window.isSecureContext || !crypto.subtle) throw new Error('Use HTTPS to encrypt this message.');
      const bytes = value => Uint8Array.from(atob(value), c => c.charCodeAt(0));
      const base64 = value => btoa(String.fromCharCode(...new Uint8Array(value)));
      const pem = atob(form.dataset.key).replace(/-----[^-]+-----|\s/g, '');
      const publicKey = await crypto.subtle.importKey('spki', bytes(pem), {name:'RSA-OAEP',hash:'SHA-256'}, false, ['encrypt']);
      const key = await crypto.subtle.generateKey({name:'AES-GCM',length:256}, true, ['encrypt']);
      const iv = crypto.getRandomValues(new Uint8Array(12));
      const plaintext = new TextEncoder().encode(form.querySelector('textarea').value);
      const ciphertext = await crypto.subtle.encrypt({name:'AES-GCM',iv}, key, plaintext);
      const wrapped = await crypto.subtle.encrypt({name:'RSA-OAEP'}, publicKey, await crypto.subtle.exportKey('raw', key));
      const response = await fetch(form.dataset.endpoint, {method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify({version:1,fingerprint:form.dataset.fingerprint,iv:base64(iv),key:base64(wrapped),ciphertext:base64(ciphertext)})});
      if (!response.ok) throw new Error('Delivery failed. Reload to check for a changed recipient key, then try again.');
      form.querySelector('textarea').value = '';
      status.textContent = 'Your encrypted message was sent.';
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  });
}
