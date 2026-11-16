/**
 * uppercase.js
 * Converte automaticamente in MAIUSCOLO:
 *   - Mentre digiti (istantaneo, mantiene la posizione del cursore)
 *   - Prima dell'invio del form (garantisce che vada al server in maiuscolo)
 *
 * Esclude: email, password, number, date, file, url, tel
 */

(function() {
    'use strict';

    // Selettori da escludere (non trasformare mai)
    const EXCLUDED_TYPES = ['password', 'email', 'number', 'date', 'time', 'datetime-local',
                            'month', 'week', 'file', 'tel', 'url', 'range', 'color', 'hidden'];

    // Selettori inclusi di default (evita di trasformare cose tipo IBAN, password, ecc.)
    const INCLUDE_NAMES = [
        'codice_fiscale', 'cf', 'cognome', 'nome', 'comune', 'comune_nascita', 'comune_cat',
        'provincia', 'prov_nascita', 'codice_comune', 'catasto', 'sezione', 'toponimo',
        'indirizzo', 'via', 'civico', 'sub', 'subalterno', 'foglio', 'particella',
        'zona_censuaria', 'classe', 'categoria', 'identificativo_interno', 'desc',
        'ufficio', 'ufficio_territoriale', 'id_cont', 'id_contratto_univoco', 'annualita',
        'serie_registrazione', 'numero_registrazione', 'sottonumero_registrazione',
        'cf_rappresentante', 'cognome_rappresentante', 'nome_rappresentante',
        'cf_garante', 'cf_garante2', 'natura', 'partita_tavolare', 'corpo_tavolare',
        'porzione_materiale', 'iban_altrui'
    ];

    function shouldUppercase(el) {
        if (!el || el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA') return false;
        if (el.classList.contains('no-uppercase')) return false;
        if (el.hasAttribute('data-no-uppercase')) return false;
        if (el.disabled || el.readOnly) return false;

        const type = (el.type || 'text').toLowerCase();
        if (EXCLUDED_TYPES.includes(type)) return false;

        // Se ha nome nella lista "include", fallo
        const name = (el.name || '').replace(/\[\d+\]/g, '').replace(/\[\]$/, '');
        if (INCLUDE_NAMES.some(n => name === n || name.startsWith(n))) return true;

        // Se ha classe "uppercase", fallo
        if (el.classList.contains('uppercase')) return true;

        // Se è un input text/search senza esplicita esclusione, fallo (default: SÌ)
        if (type === 'text' || type === 'search') return true;

        return false;
    }

    /**
     * Converte il valore in maiuscolo mantenendo la posizione del cursore.
     * Fondamentale per non far "saltare" il cursore a fine campo.
     */
    function uppercasePreserveCursor(el) {
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const oldVal = el.value;
        const newVal = oldVal.toUpperCase();

        if (oldVal === newVal) return;

        el.value = newVal;

        // Ripristina la posizione del cursore
        try {
            el.setSelectionRange(start, end);
        } catch (e) {
            // Alcuni tipi di input non supportano setSelectionRange
        }
    }

    /**
     * Applica l'uppercase a un elemento.
     */
    function applyUppercase(el) {
        if (!shouldUppercase(el)) return;

        // Evento input: trasforma in tempo reale
        el.addEventListener('input', function() {
            uppercasePreserveCursor(this);
        });

        // Evento blur: garantisce la trasformazione finale
        el.addEventListener('blur', function() {
            this.value = this.value.toUpperCase();
        });

        // Evento paste: gestisce incolla
        el.addEventListener('paste', function(e) {
            const self = this;
            setTimeout(() => uppercasePreserveCursor(self), 0);
        });

        // Se già contiene testo al load, converti
        if (el.value && el.value !== el.value.toUpperCase()) {
            el.value = el.value.toUpperCase();
        }
    }

    /**
     * Applica a tutti gli input/textarea/select presenti e futuri.
     */
    function applyToAll(root) {
        root = root || document;
        // Input e textarea
        root.querySelectorAll('input, textarea').forEach(applyUppercase);
        // Select
        root.querySelectorAll('select').forEach(el => {
            if (el.classList.contains('uppercase') || el.hasAttribute('data-uppercase')) {
                el.style.textTransform = 'uppercase';
            }
        });
    }

    /**
     * Submit handler: trasforma TUTTI i campi prima dell'invio,
     * anche se il browser non ha emesso 'input' per qualche motivo.
     */
    function prepareOnSubmit(form) {
        form.querySelectorAll('input, textarea').forEach(el => {
            if (shouldUppercase(el) && el.value) {
                el.value = el.value.toUpperCase();
            }
        });
    }

    // === INIZIALIZZAZIONE ===
    document.addEventListener('DOMContentLoaded', function() {
        applyToAll(document);

        // Applica a tutti i form
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                prepareOnSubmit(this);
            }, true); // capture phase, così parte per primo
        });

        // Osserva il DOM per input dinamici (es. SearchableSelect)
        if (window.MutationObserver) {
            const observer = new MutationObserver(mutations => {
                mutations.forEach(m => {
                    m.addedNodes.forEach(node => {
                        if (node.nodeType === 1) {
                            if (node.matches && node.matches('input, textarea, select')) {
                                applyUppercase(node);
                            }
                            if (node.querySelectorAll) {
                                applyToAll(node);
                            }
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });

    // Esponi la funzione per uso manuale
    window.applyUppercaseToAll = applyToAll;
})();
