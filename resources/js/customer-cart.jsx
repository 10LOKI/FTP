import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Checkout } from './customer-orders';

async function request(url, method = 'GET', values) {
    const response = await fetch(url, {
        method, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        ...(values ? { body: JSON.stringify(values) } : {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(response.status === 409 ? 'Le panier a changé. Rechargez-le et vérifiez les quantités avant de réessayer.'
            : response.status === 401 || response.status === 419 ? 'Votre session a expiré. Reconnectez-vous avant de continuer.'
            : response.status === 403 ? 'Votre compte ne dispose plus de l’accès au panier.'
            : response.status === 422 ? Object.values(payload.errors ?? {}).flat().join(' ') || 'Vérifiez les quantités indiquées.'
            : response.status === 404 ? 'Cet article ou ce conditionnement n’est plus disponible dans le panier.'
            : 'Le panier est momentanément indisponible. Réessayez.');
        error.status = response.status;
        throw error;
    }
    if (!payload.data || !Array.isArray(payload.data.lines) || !Number.isInteger(payload.data.version)) {
        throw new Error('Impossible de lire le panier. Rechargez cette page.');
    }
    return payload.data;
}

function Cart({ api, catalogue, orders, history }) {
    const [cart, setCart] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const feedback = useRef(null);
    useEffect(() => { if (message) feedback.current?.focus(); }, [message]);

    async function load() {
        setBusy(true);
        setMessage('');
        try { setCart(await request(api)); }
        catch (error) { setMessage(error instanceof TypeError ? 'Connexion interrompue. Réessayez de charger votre panier.' : error.message); }
        finally { setBusy(false); }
    }
    useEffect(() => { load(); }, []);

    async function change(line, quantity) {
        if (busy) return;
        setBusy(true);
        setMessage('');
        try {
            setCart(await request(quantity === null ? `${api}/lines/${line.id}` : `${api}/products/${line.product_id}`,
                quantity === null ? 'DELETE' : 'PUT', quantity === null ? { expected_version: cart.version }
                    : { expected_version: cart.version, quantity, units_per_package: line.units_per_package }));
            setMessage(quantity === null ? 'Article retiré du panier.' : 'Quantité mise à jour.');
        } catch (error) {
            if (error.status === 401 || error.status === 403 || error.status === 419) setCart(null);
            setMessage(error instanceof TypeError ? 'Connexion interrompue. Rechargez le panier pour vérifier si la modification a été enregistrée.' : error.message);
        } finally { setBusy(false); }
    }

    return <section aria-label="Contenu du panier" aria-busy={busy}>
        {message && <p role="status" tabIndex="-1" ref={feedback}>{message}</p>}
        <button type="button" onClick={load} disabled={busy}>Actualiser le panier</button>
        {!cart && !message && <p role="status">Chargement du panier…</p>}
        {cart && <>
            {cart.pricing_status === 'pricing_unavailable' && <p role="status">La tarification est temporairement indisponible. Vous pouvez gérer le panier ; la soumission reste désactivée.</p>}
            {cart.lines.length === 0 ? <p>Votre panier est vide. <a href={catalogue}>Consulter le catalogue</a>.</p>
                : <div className="grid">{cart.lines.map(line => <article key={line.id}>
                    <h2>{line.designation ?? 'Article retiré du catalogue'}</h2>
                    {line.sku && <p>SKU {line.sku}</p>}
                    {line.original_reference && <p>Référence : {line.original_reference}</p>}
                    <p>{line.units_per_package} unité(s) par conditionnement</p>
                    {!line.orderable && <p className="error">Cet article ne peut plus être commandé. Vous pouvez le retirer.</p>}
                    <form key={`${line.id}-${cart.version}`} onSubmit={event => {
                        event.preventDefault();
                        change(line, Number(new FormData(event.currentTarget).get('quantity')));
                    }}>
                        <label htmlFor={`quantity-${line.id}`}>Nombre de conditionnements
                            <input id={`quantity-${line.id}`} name="quantity" type="number" min="1" max="1000000" step="1" required
                                defaultValue={line.quantity} disabled={busy || !line.orderable} />
                        </label>
                        <button disabled={busy || !line.orderable}>Mettre à jour</button>
                        <button type="button" disabled={busy} onClick={() => change(line, null)}>Retirer cet article</button>
                    </form>
                    <p>Prix client par unité : {line.unit_price ?? 'à confirmer'} {line.pricing?.currency}</p>
                    {line.pricing?.applied_source === 'promotion' && <p>Promotion appliquée : {line.pricing.promotion?.title}</p>}
                    {line.pricing?.applied_source === 'customer_discount' && <p>Remise client appliquée : {line.pricing.customer_discount_percentage} %</p>}
                    <p>Sous-total estimé : {line.line_subtotal ?? 'à confirmer'}</p>
                </article>)}</div>}
            {cart.lines.length > 0 && <p><strong>Sous-total des articles : {cart.totals?.catalogue_subtotal ?? 'à confirmer'}</strong></p>}
            <p>Les frais, remises applicables et la disponibilité seront vérifiés avant confirmation de la commande.</p>
            <Checkout cart={cart} api={orders} history={history} reload={load} />
            <a href={catalogue}>Continuer mes achats</a>
        </>}
    </section>;
}

function AddToCart({ api, product, packagings, cartUrl }) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const feedback = useRef(null);
    useEffect(() => { if (message) feedback.current?.focus(); }, [message]);

    async function add(event) {
        event.preventDefault();
        if (busy) return;
        const fields = new FormData(event.currentTarget);
        const quantity = Number(fields.get('quantity'));
        const units = Number(fields.get('units_per_package'));
        setBusy(true);
        setMessage('');
        try {
            const cart = await request(api);
            const existing = cart.lines.find(line => line.product_id === product && line.units_per_package === units);
            await request(`${api}/products/${product}`, 'PUT', {
                quantity: quantity + (existing?.quantity ?? 0), units_per_package: units, expected_version: cart.version,
            });
            setMessage('Article ajouté à votre panier.');
        } catch (error) {
            setMessage(error instanceof TypeError ? 'Connexion interrompue. Vérifiez votre panier avant un nouvel ajout.' : error.message);
        } finally { setBusy(false); }
    }

    return <>
        <form onSubmit={add} aria-busy={busy}>
            <label htmlFor="add-packaging">Conditionnement
                <select id="add-packaging" name="units_per_package" disabled={busy}>
                    {packagings.map(units => <option key={units} value={units}>{units} unité(s)</option>)}
                </select>
            </label>
            <label htmlFor="add-quantity">Nombre de conditionnements
                <input id="add-quantity" name="quantity" type="number" required min="1" max="1000000" step="1" defaultValue="1" disabled={busy} />
            </label>
            <button disabled={busy}>Ajouter au panier</button>
        </form>
        {message && <p role="status" ref={feedback} tabIndex="-1">{message}</p>}
        <a href={cartUrl}>Voir mon panier</a>
    </>;
}

const cartRoot = document.getElementById('customer-cart');
if (cartRoot) createRoot(cartRoot).render(<Cart api={cartRoot.dataset.api} catalogue={cartRoot.dataset.catalogue}
    orders={cartRoot.dataset.orders} history={cartRoot.dataset.history} />);
const addRoot = document.getElementById('cart-add');
if (addRoot) createRoot(addRoot).render(<AddToCart api={addRoot.dataset.api} product={addRoot.dataset.product}
    packagings={JSON.parse(addRoot.dataset.packagings).map(Number)} cartUrl={addRoot.dataset.cart} />);
