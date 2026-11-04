import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

async function send(endpoint, values) {
    return fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(values),
    });
}

function Account({ mode, endpoint, catalogueUrl, loginUrl, registerUrl }) {
    const registering = mode === 'register';
    const [type, setType] = useState('PARTICULIER');
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [received, setReceived] = useState(false);
    const feedback = useRef(null);

    useEffect(() => {
        if (message || received) feedback.current?.focus();
    }, [message, received]);

    async function submit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const values = Object.fromEntries(new FormData(form));
        setBusy(true);
        setErrors({});
        setMessage('');
        try {
            const response = await send(endpoint, values);
            if (response.status === 419) {
                setMessage('Votre session a expiré. Rechargez cette page avant de réessayer.');
                return;
            }
            const data = await response.json();
            if (!response.ok) {
                setErrors(data.errors ?? {});
                setMessage(response.status === 422 ? 'Vérifiez les champs indiqués.'
                    : response.status === 429 ? 'Trop de tentatives. Patientez avant de réessayer.'
                    : response.status >= 500 ? 'Le service est momentanément indisponible. Réessayez.'
                    : data.message ?? 'La demande n’a pas pu aboutir.');
                return;
            }
            if (registering) {
                setReceived(true);
            } else {
                window.location.assign(catalogueUrl);
            }
        } catch {
            setMessage('Impossible de joindre le service. Vérifiez votre connexion et réessayez.');
        } finally {
            form.elements.password.value = '';
            setBusy(false);
        }
    }

    function field(name, label, { inputType = 'text', autocomplete, maxLength = 255, minLength } = {}) {
        return <label key={name} htmlFor={`account-${name}`}>
            {label}
            <input id={`account-${name}`} name={name} type={inputType} autoComplete={autocomplete}
                maxLength={maxLength} minLength={minLength} required
                aria-invalid={errors[name] ? true : undefined}
                aria-describedby={errors[name] ? `account-error-${name}` : undefined} />
            {errors[name] && <span id={`account-error-${name}`} className="error">{errors[name].join(' ')}</span>}
        </label>;
    }

    if (received) return <div role="status" tabIndex="-1" ref={feedback}>
        <p>Votre demande de compte a bien été reçue. Elle est en attente d’approbation.</p>
        <p>Après approbation, vous recevrez votre code client par courriel pour vous connecter.</p>
        <a href={loginUrl}>Aller à la connexion</a>
    </div>;

    return <>
        <p>{registering ? 'L’accès à la commande nécessite l’approbation de votre compte.'
            : 'Connectez-vous avec le code client reçu après approbation de votre demande.'}</p>
        {message && <p role="alert" tabIndex="-1" ref={feedback} className="error">{message}</p>}
        <form onSubmit={submit} aria-busy={busy}>
            {registering ? <>
                <label htmlFor="account-type">Type de compte
                    <select id="account-type" name="type" value={type} onChange={event => setType(event.target.value)}>
                        <option value="PARTICULIER">Particulier</option>
                        <option value="ENTREPRISE">Entreprise</option>
                    </select>
                </label>
                {field('name', 'Nom du contact', { autocomplete: 'name' })}
                {field('phone', 'Téléphone', { inputType: 'tel', autocomplete: 'tel', maxLength: 50 })}
                {field('email', 'Courriel', { inputType: 'email', autocomplete: 'email' })}
                {field('password', 'Mot de passe', { inputType: 'password', autocomplete: 'new-password', minLength: 8, maxLength: 4096 })}
                {field('address', 'Adresse', { autocomplete: 'street-address', maxLength: 1000 })}
                {field('city', 'Ville', { autocomplete: 'address-level2' })}
                {field('country', 'Pays', { autocomplete: 'country-name' })}
                {type === 'ENTREPRISE' && <>
                    {field('company_name', 'Raison sociale', { autocomplete: 'organization' })}
                    {field('responsible_name', 'Nom du responsable')}
                </>}
            </> : <>
                {field('identifier', 'Code client', { autocomplete: 'username' })}
                {field('password', 'Mot de passe', { inputType: 'password', autocomplete: 'current-password', maxLength: 4096 })}
            </>}
            <button disabled={busy}>{busy ? 'Envoi en cours…' : registering ? 'Envoyer la demande' : 'Se connecter'}</button>
        </form>
        <p>{registering ? <a href={loginUrl}>Déjà approuvé ? Se connecter</a>
            : <a href={registerUrl}>Demander un compte</a>}</p>
    </>;
}

const root = document.getElementById('public-account');
if (root) createRoot(root).render(<Account {...JSON.parse(root.dataset.account)} />);

const logout = document.getElementById('public-logout');
logout?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = logout.querySelector('button');
    const feedback = logout.querySelector('[role="alert"]');
    button.disabled = true;
    feedback.textContent = '';
    try {
        const response = await send(logout.action, {});
        if (!response.ok) throw new Error();
        window.location.assign(logout.dataset.home);
    } catch {
        feedback.textContent = 'La déconnexion n’a pas pu être confirmée. Rechargez la page et réessayez.';
        button.disabled = false;
    }
});
