/**
 * immobile_modal.js
 * Modale "Nuovo Immobile" riutilizzabile nel wizard contratto.
 *
 * Uso:
 *   ImmobileModal.open({
 *     csrfToken: '...',
 *     idLocatore: 123,                 // associa l'immobile al locatore
 *     onSave: function(immobile) { ... }
 *   });
 *   ImmobileModal.close();
 */

(function () {
    'use strict';

    const HTML_MODAL = `
        <div class="immobile-modal-backdrop" id="im-backdrop">
            <div class="immobile-modal">
                <div class="im-header">
                    <h3>Nuovo Immobile</h3>
                    <button type="button" class="im-close" onclick="ImmobileModal.close()">×</button>
                </div>
                <form id="im-form" autocomplete="off">
                    <input type="hidden" name="_csrf" id="im-csrf">
                    <input type="hidden" name="id_locatore" id="im-id_locatore" value="">

                    <label>Identificativo interno *</label>
                    <input type="text" name="identificativo_interno" id="im-identificativo_interno" required placeholder="es. Appartamento Roma Centro">

                    <div class="im-row">
                        <div style="flex:2;">
                            <label>Comune *</label>
                            <input type="text" name="comune" id="im-comune" required list="im-lista_comuni" placeholder="Es. ROMA">
                            <datalist id="im-lista_comuni"></datalist>
                        </div>
                        <div style="flex:0.6;">
                            <label>Prov. *</label>
                            <input type="text" name="provincia" id="im-provincia" maxlength="2" required placeholder="RM/EE">
                        </div>
                        <div style="flex:0.8;">
                            <label>Cod. Catastale</label>
                            <input type="text" name="codice_comune" id="im-codice_comune" maxlength="5">
                        </div>
                    </div>

                    <div class="im-row">
                        <div>
                            <label>Tipo catasto</label>
                            <select name="tipo_catasto" id="im-tipo_catasto">
                                <option value="U">Urbano</option>
                                <option value="T">Terreni</option>
                            </select>
                        </div>
                        <div>
                            <label>Categoria</label>
                            <input type="text" name="categoria" id="im-categoria" maxlength="4" placeholder="es. A2">
                        </div>
                        <div>
                            <label>Rendita €</label>
                            <input type="text" name="rendita" id="im-rendita" placeholder="es. 500,00">
                        </div>
                    </div>

                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="is_pertinenza" id="im-is_pertinenza" value="1" style="width:auto; margin:0;">
                        Pertinenza (C/2, C/6, C/7 o unità accessoria)
                    </label>

                    <div class="im-row">
                        <div><label>Sezione</label><input type="text" name="sezione" id="im-sezione" maxlength="3"></div>
                        <div><label>Foglio</label><input type="text" name="foglio" id="im-foglio" maxlength="4"></div>
                        <div><label>Particella</label><input type="text" name="particella" id="im-particella" maxlength="5"></div>
                        <div><label>Subalterno</label><input type="text" name="subalterno" id="im-subalterno" maxlength="4"></div>
                    </div>

                    <div class="im-row">
                        <div style="flex:1;"><label>Toponimo *</label><input type="text" name="toponimo" id="im-toponimo" required placeholder="es. VIA"></div>
                        <div style="flex:2;"><label>Indirizzo</label><input type="text" name="indirizzo" id="im-indirizzo"></div>
                        <div style="flex:0.6;"><label>Civico</label><input type="text" name="civico" id="im-civico" maxlength="10"></div>
                    </div>

                    <label>Note</label>
                    <textarea name="note" id="im-note" rows="2"></textarea>

                    <div class="im-error" id="im-error" style="display:none;"></div>

                    <div class="im-actions">
                        <button type="submit" class="im-btn im-btn-primary" id="im-submit">💾 Salva</button>
                        <button type="button" class="im-btn im-btn-gray" onclick="ImmobileModal.close()">Annulla</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    const CSS = `
        .immobile-modal-backdrop {
            position: fixed; inset: 0; background: rgba(26, 32, 44, 0.6);
            display: none; justify-content: center; align-items: flex-start;
            z-index: 99999; padding: 40px 15px; overflow-y: auto;
        }
        .immobile-modal-backdrop.open { display: flex; }
        .immobile-modal {
            background: #fff; border-radius: 12px; padding: 25px;
            max-width: 720px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        .im-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
        .im-header h3 { margin: 0; color: #2b6cb0; }
        .im-close { background: none; border: none; font-size: 1.6em; cursor: pointer; color: #a0aec0; line-height: 1; }
        .im-close:hover { color: #f56565; }
        .immobile-modal label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.88em; color: #4a5568; }
        .immobile-modal input, .immobile-modal select, .immobile-modal textarea {
            width: 100%; padding: 9px 12px; border: 1px solid #cbd5e0;
            border-radius: 8px; margin-bottom: 12px; font-size: 0.95em;
        }
        .immobile-modal input:focus, .immobile-modal select:focus {
            outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
        }
        .im-row { display: flex; gap: 10px; }
        .im-row > div { flex: 1; }
        .im-error { background: #fed7d7; color: #742a2a; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 0.9em; }
        .im-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 10px; padding-top: 15px; border-top: 1px solid #e2e8f0; }
        .im-btn { padding: 10px 22px; border-radius: 8px; font-weight: bold; font-size: 0.95em; cursor: pointer; border: none; }
        .im-btn-primary { background: #48bb78; color: #fff; }
        .im-btn-primary:hover { background: #38a169; }
        .im-btn-primary:disabled { background: #a0aec0; cursor: not-allowed; }
        .im-btn-gray { background: #e2e8f0; color: #2d3748; }
        .im-btn-gray:hover { background: #cbd5e0; }
    `;

    let currentOptions = {};
    let initialized = false;

    function init() {
        if (initialized) return;

        const style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);

        const div = document.createElement('div');
        div.innerHTML = HTML_MODAL;
        document.body.appendChild(div.firstElementChild);

        document.getElementById('im-form').addEventListener('submit', onSubmit);

        document.getElementById('im-backdrop').addEventListener('click', function (e) {
            if (e.target === this) ImmobileModal.close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                const bd = document.getElementById('im-backdrop');
                if (bd && bd.classList.contains('open')) ImmobileModal.close();
            }
        });

        // Autocomplete comune via endpoint locale
        const comuneField = document.getElementById('im-comune');
        if (comuneField) {
            let timer = null;
            comuneField.addEventListener('input', function () {
                const val = this.value.toUpperCase().trim();
                if (val.length < 2) return;
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetch('ajax_comuni.php?q=' + encodeURIComponent(val), { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(function (data) {
                            if (!data.ok || data.fallback) return;
                            const dl = document.getElementById('im-lista_comuni');
                            dl.innerHTML = '';
                            (data.results || []).forEach(function (m) {
                                const o = document.createElement('option');
                                o.value = m.nome.toUpperCase();
                                o.label = m.provincia;
                                dl.appendChild(o);
                            });
                            const ex = (data.results || []).find(function (m) { return m.nome.toUpperCase() === val; });
                            if (ex) {
                                document.getElementById('im-provincia').value = ex.provincia;
                                document.getElementById('im-codice_comune').value = ex.codice_belfiore;
                            }
                        })
                        .catch(function () {});
                }, 250);
            });
        }

        // Auto-suggerisci pertinenza per C/2, C/6, C/7
        const catField = document.getElementById('im-categoria');
        if (catField) {
            catField.addEventListener('input', function () {
                const cat = (this.value || '').toUpperCase().replace(/[\/\s-]/g, '');
                if (['C2', 'C6', 'C7'].includes(cat)) {
                    const cb = document.getElementById('im-is_pertinenza');
                    if (cb) cb.checked = true;
                }
            });
        }

        initialized = true;
    }

    async function onSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const submitBtn = document.getElementById('im-submit');
        const errorDiv = document.getElementById('im-error');
        errorDiv.style.display = 'none';

        const prov = (document.getElementById('im-provincia').value || '').toUpperCase().trim();
        if (!/^[A-Z]{2}$/.test(prov)) {
            errorDiv.textContent = '⚠ Provincia obbligatoria (2 lettere, es. RM, oppure EE)';
            errorDiv.style.display = 'block';
            document.getElementById('im-provincia').focus();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = '⏳ Salvataggio...';

        try {
            const formData = new FormData(form);
            const resp = await fetch('ajax_save_immobile.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });

            let data;
            try {
                data = await resp.json();
            } catch (parseErr) {
                throw new Error('Risposta server non valida (HTTP ' + resp.status + ')');
            }

            if (!data.ok) {
                throw new Error(data.error || 'Errore di salvataggio');
            }

            ImmobileModal.close();
            if (typeof currentOptions.onSave === 'function') {
                currentOptions.onSave(data.immobile);
            }
        } catch (err) {
            errorDiv.textContent = '⚠ ' + err.message;
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Salva';
        }
    }

    function resetForm() {
        const form = document.getElementById('im-form');
        if (form) form.reset();
        const errDiv = document.getElementById('im-error');
        if (errDiv) errDiv.style.display = 'none';
        const submitBtn = document.getElementById('im-submit');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Salva';
        }
    }

    window.ImmobileModal = {
        open: function (opts) {
            init();
            currentOptions = opts || {};
            resetForm();

            const csrfField = document.getElementById('im-csrf');
            if (csrfField) csrfField.value = opts.csrfToken || '';

            const locField = document.getElementById('im-id_locatore');
            if (locField) locField.value = opts.idLocatore || '';

            document.getElementById('im-backdrop').classList.add('open');
            setTimeout(function () {
                const el = document.getElementById('im-identificativo_interno');
                if (el) el.focus();
            }, 100);
        },
        close: function () {
            const bd = document.getElementById('im-backdrop');
            if (bd) bd.classList.remove('open');
        }
    };
})();
