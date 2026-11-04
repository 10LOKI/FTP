import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

function ReferenceFields({ prefix, reference = '', brand = '', errors = {}, disabled, original = false }) {
    return <>
        <label htmlFor={`${prefix}-reference`}>{original ? 'Référence originale principale' : 'Référence croisée'}
            <input id={`${prefix}-reference`} name="reference" defaultValue={reference} required
                maxLength={original ? 255 : 120} disabled={disabled} autoComplete="off"
                aria-invalid={errors.reference ? true : undefined}
                aria-describedby={errors.reference ? `${prefix}-reference-error` : undefined} />
        </label>
        {errors.reference && <p className="error" id={`${prefix}-reference-error`}>{errors.reference.join(' ')}</p>}
        {!original && <>
            <label htmlFor={`${prefix}-brand`}>Source ou marque (facultative)
                <input id={`${prefix}-brand`} name="brand" defaultValue={brand} maxLength={100} disabled={disabled}
                    aria-invalid={errors.brand ? true : undefined}
                    aria-describedby={errors.brand ? `${prefix}-brand-error` : undefined} />
            </label>
            {errors.brand && <p className="error" id={`${prefix}-brand-error`}>{errors.brand.join(' ')}</p>}
        </>}
    </>;
}

function ReferenceManager({ endpoints }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [failed, setFailed] = useState(false);
    const [errors, setErrors] = useState({});
    const [errorScope, setErrorScope] = useState(null);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const feedback = useRef(null);
    const dialog = useRef(null);
    const cancelDelete = useRef(null);

    useEffect(() => {
        if (message) feedback.current?.focus();
    }, [message]);

    useEffect(() => {
        if (deleting) {
            dialog.current.showModal();
            cancelDelete.current?.focus();
        }
    }, [deleting]);

    async function load(signal) {
        setLoading(true);
        try {
            const response = await fetch(endpoints.index, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', signal,
            });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (!signal?.aborted) {
                setData(result.data);
                return true;
            }
            return false;
        } catch {
            if (!signal?.aborted) {
                setFailed(true);
                setData(null);
                setMessage('Impossible de charger les références. Vérifiez votre accès et réessayez.');
            }
            return false;
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }

    useEffect(() => {
        const controller = new AbortController();
        load(controller.signal);
        return () => controller.abort();
    }, []);

    async function mutate(method, endpoint, values, scope, success) {
        setBusy(true);
        setMessage('');
        setErrors({});
        setErrorScope(scope);
        try {
            const response = await fetch(endpoint, {
                method, credentials: 'same-origin',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(values),
            });
            const result = response.status === 204 ? null : await response.json();
            if (!response.ok) {
                setDeleting(null);
                setFailed(true);
                setErrors(result?.errors ?? {});
                setMessage(response.status === 419 ? 'Votre session a expiré. Rechargez la page.'
                    : response.status === 409 ? 'Cette référence a changé. Les données sont actualisées : vérifiez-les avant de recommencer.'
                    : response.status === 403 ? 'Vous ne disposez plus de la permission de gérer les références.'
                    : response.status === 422 ? 'Vérifiez les champs indiqués.'
                    : 'L’opération n’a pas pu être confirmée. Actualisez les références avant de réessayer.');
                if (response.status === 409) {
                    setEditing(null);
                    setDeleting(null);
                    await load();
                }
                return false;
            }
            setEditing(null);
            setDeleting(null);
            if (!await load()) return false;
            setFailed(false);
            setMessage(response.status === 200 && method === 'POST'
                ? 'Cette référence est déjà associée au produit. Aucune copie n’a été créée.' : success);
            return true;
        } catch {
            setDeleting(null);
            setFailed(true);
            setMessage('La réponse n’a pas pu être confirmée. Actualisez les références avant de réessayer.');
            return false;
        } finally {
            setBusy(false);
        }
    }

    function scopedErrors(scope) {
        return errorScope === scope ? errors : {};
    }

    return <>
        {message && <p ref={feedback} tabIndex="-1" role={failed ? 'alert' : 'status'} className={failed ? 'error' : undefined}>{message}</p>}
        <button disabled={busy || loading} onClick={() => { setEditing(null); setMessage(''); load(); }}>Actualiser les références</button>
        {loading && <p role="status">Chargement des références…</p>}
        {data && <section aria-busy={busy || loading}>
            <h2>Référence originale</h2>
            <form key={data.original_reference.reference} onSubmit={event => {
                event.preventDefault();
                const values = Object.fromEntries(new FormData(event.currentTarget));
                mutate('PUT', endpoints.original, { reference: values.reference, expected_reference: data.original_reference.reference }, 'original', 'Référence originale enregistrée.');
            }}>
                <ReferenceFields prefix="original" reference={data.original_reference.reference} original
                    disabled={busy || loading || editing !== null} errors={scopedErrors('original')} />
                <p>Valeur de recherche : <code>{data.original_reference.normalized_reference}</code></p>
                <button disabled={busy || loading || editing !== null}>Enregistrer la référence originale</button>
            </form>
            <h2>Références croisées</h2>
            {!data.references.length && <p>Aucune référence croisée enregistrée.</p>}
            <div className="grid">
                {data.references.map(reference => <article key={reference.id}>
                    <h3>{reference.reference}</h3>
                    {reference.brand && <p>Source : {reference.brand}</p>}
                    <p>Valeur de recherche : <code>{reference.normalized_reference}</code></p>
                    <p>{reference.verified_at ? 'Vérifiée' : 'À vérifier — absente de la recherche publique'}</p>
                    {editing === reference.id ? <form key={`${reference.id}-${reference.version}`} onSubmit={event => {
                        event.preventDefault();
                        mutate('PUT', endpoints.update.replace('__REFERENCE__', reference.id), {
                            ...Object.fromEntries(new FormData(event.currentTarget)), version: reference.version,
                        }, `edit-${reference.id}`, 'Référence modifiée. Une nouvelle vérification est nécessaire.');
                    }}>
                        <ReferenceFields prefix={`edit-${reference.id}`} reference={reference.reference} brand={reference.brand}
                            disabled={busy || loading} errors={scopedErrors(`edit-${reference.id}`)} />
                        <button disabled={busy || loading}>Enregistrer</button>
                        <button type="button" disabled={busy} onClick={() => setEditing(null)}>Annuler la modification</button>
                    </form> : <>
                        <button disabled={busy || loading || editing !== null} onClick={() => { setErrors({}); setEditing(reference.id); }}>Modifier {reference.reference}</button>{' '}
                        {!reference.verified_at && <button disabled={busy || loading || editing !== null} onClick={() => {
                            mutate('PATCH', endpoints.verify.replace('__REFERENCE__', reference.id), { version: reference.version }, `verify-${reference.id}`, 'Référence vérifiée.');
                        }}>Vérifier {reference.reference}</button>}{' '}
                        <button disabled={busy || loading || editing !== null} onClick={() => setDeleting(reference)}>Supprimer {reference.reference}</button>
                    </>}
                </article>)}
            </div>
            <h2>Ajouter une référence croisée</h2>
            <form onSubmit={async event => {
                event.preventDefault();
                const form = event.currentTarget;
                if (await mutate('POST', endpoints.store, Object.fromEntries(new FormData(form)), 'new', 'Référence ajoutée. Vérifiez-la avant publication dans la recherche.')) form.reset();
            }}>
                <ReferenceFields prefix="new" disabled={busy || loading || editing !== null} errors={scopedErrors('new')} />
                <button disabled={busy || loading || editing !== null}>Ajouter la référence</button>
            </form>
        </section>}
        {deleting && <dialog ref={dialog} aria-labelledby="delete-reference-title" onCancel={event => {
            if (busy) event.preventDefault();
            else setDeleting(null);
        }}>
            <h2 id="delete-reference-title">Supprimer la référence {deleting.reference} ?</h2>
            <p>Seule cette association au produit sera supprimée. Le produit reste conservé.</p>
            <button ref={cancelDelete} disabled={busy} onClick={() => setDeleting(null)}>Annuler</button>{' '}
            <button disabled={busy} onClick={() => mutate('DELETE', endpoints.destroy.replace('__REFERENCE__', deleting.id), {
                version: deleting.version,
            }, 'delete', 'Association de référence supprimée.')}>Confirmer la suppression</button>
        </dialog>}
    </>;
}

const root = document.getElementById('product-references');
if (root) createRoot(root).render(<ReferenceManager {...JSON.parse(root.dataset.references)} />);
