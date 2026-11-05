export async function pricingRequest(url, { method = 'GET', values, key, signal } = {}) {
    const response = await fetch(url, {
        method, signal, credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(method !== 'GET' ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } : {}),
            ...(key ? { 'Idempotency-Key': key } : {}),
        },
        ...(values ? { body: JSON.stringify(values) } : {}),
    });
    const result = response.status === 204 ? null : await response.json();
    if (!response.ok) {
        const error = new Error(response.status === 409 ? 'Les données ont changé. Actualisez-les avant de recommencer.'
            : response.status === 422 ? 'Vérifiez les champs indiqués.'
            : response.status === 419 ? 'Votre session a expiré. Rechargez la page.'
            : response.status === 401 || response.status === 403 ? 'Votre accès est indisponible. Vérifiez votre connexion et vos permissions.'
            : 'L’opération n’a pas pu être confirmée. Actualisez avant de réessayer.');
        error.fields = result?.errors ?? {};
        error.status = response.status;
        throw error;
    }
    return result;
}
