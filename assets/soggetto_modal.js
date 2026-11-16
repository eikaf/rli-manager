/**
 * soggetto_modal.js
 * Modale "Nuovo Soggetto" / "Modifica Soggetto" riutilizzabile.
 *
 * Dipende da: assets/cf_utils.js (window.CFUtils)
 *
 * Uso:
 *   SoggettoModal.open({
 *     csrfToken: '...',                        // obbligatorio (dal PHP)
 *     tipologiaDefault: 'LOCATORE',            // opzionale
 *     soggetto: null | { ... },                // per modalità modifica
 *     onSave: function(soggetto) { ... }       // callback dopo salvataggio
 *   });
 *
 *   SoggettoModal.close();
 */

(function() {
    'use strict';

    // ============================================================
    // HTML DEL MODALE
    // ============================================================
    const HTML_MODAL = `
        <div class="soggetto-modal-backdrop" id="sm-backdrop">
            <div class="soggetto-modal">
                <div class="sm-header">
                    <h3 id="sm-title">Nuovo Soggetto</h3>
                    <button type="button" class="sm-close" onclick="SoggettoModal.close()">×</button>
                </div>
                <form id="sm-form" autocomplete="off">
                    <input type="hidden" name="_csrf" id="sm-csrf">
                    <input type="hidden" name="id_soggetto" id="sm-id_soggetto" value="">

                    <div class="sm-row">
                        <div style="flex:1;">
                            <label>Tipologia *</label>
                            <select name="tipologia_default" id="sm-tipologia_default" required>
                                <option value="ENTRAMBI">Entrambi</option>
                                <option value="LOCATORE">Solo Locatore</option>
                                <option value="CONDUTTORE">Solo Conduttore</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label>Ruolo conduttore</label>
                            <select name="tipologia_conduttore" id="sm-tipologia_conduttore">
                                <option value="">— Non conduttore —</option>
                                <option value="3">3 - Generico</option>
                                <option value="1">1 - Coop/Studenti</option>
                                <option value="2">2 - CF Provvisorio</option>
                            </select>
                        </div>
                    </div>

                    <label>Codice Fiscale / P.IVA *</label>
                    <input type="text" name="cf" id="sm-cf" required maxlength="16"
                           placeholder="16 caratteri per persona fisica, 11 cifre per azienda"
                           style="text-transform:uppercase;">
                    <span class="sm-hint" id="sm-cf-hint"></span>
                    <div id="sm-cf-feedback" class="cf-feedback"></div>

                    <div class="sm-row">
                        <div style="flex:2;">
                            <label>Cognome / Ragione Sociale *</label>
                            <input type="text" name="cognome" id="sm-cognome" required>
                        </div>
                        <div style="flex:1.5;">
                            <label>Nome</label>
                            <input type="text" name="nome" id="sm-nome">
                        </div>
                        <div style="flex:0.7;">
                            <label>Sesso</label>
                            <select name="sesso" id="sm-sesso">
                                <option value="">—</option>
                                <option value="M">M</option>
                                <option value="F">F</option>
                            </select>
                        </div>
                    </div>

                    <div class="sm-row">
                        <div>
                            <label>Data nascita</label>
                            <input type="date" name="data_nascita" id="sm-data_nascita">
                        </div>
                        <div>
                            <label>Comune nascita</label>
                            <input type="text" name="comune_nascita" id="sm-comune_nascita"
                                   placeholder="Es. ROMA" list="sm-lista_comuni_nascita">
                            <datalist id="sm-lista_comuni_nascita"></datalist>
                        </div>
                        <div style="flex:0.6;">
                            <label>Prov.</label>
                            <input type="text" name="prov_nascita" id="sm-prov_nascita" maxlength="2" placeholder="RM">
                        </div>
                    </div>

                    <div class="sm-row">
                        <div><label>Email</label><input type="email" name="email" id="sm-email"></div>
                        <div><label>PEC</label><input type="email" name="pec" id="sm-pec" placeholder="es. studio@pec.it"></div>
                        <div><label>Telefono</label><input type="text" name="telefono" id="sm-telefono"></div>
                    </div>

                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="soggettivita_iva" id="sm-soggettivita_iva" value="1" style="width:auto; margin:0;">
                        Soggettività IVA
                    </label>

                    <details style="margin-top:10px;">
                        <summary style="cursor:pointer; color:#4299e1; font-weight:600;">Rappresentante legale (per persone giuridiche)</summary>
                        <div style="margin-top:10px; padding:12px; background:#f7fafc; border-radius:8px;">
                            <label>Legale rappresentante (persona fisica già censita)</label>
                            <select name="id_rappresentante" id="sm-id_rappresentante">
                                <option value="">— Nessuno / compila manualmente —</option>
                            </select>
                            <label>CF Rappresentante</label>
                            <input type="text" name="cf_rappresentante" id="sm-cf_rappresentante" maxlength="16">
                            <div class="sm-row">
                                <div><label>Cognome</label><input type="text" name="cognome_rappresentante" id="sm-cognome_rappresentante"></div>
                                <div><label>Nome</label><input type="text" name="nome_rappresentante" id="sm-nome_rappresentante"></div>
                            </div>
                            <label>Codice Carica</label>
                            <select name="codice_carica" id="sm-codice_carica">
                                <option value="">—</option>
                                <option value="1">1 - Rappresentante legale</option>
                                <option value="2">2 - Minore/inabilitato</option>
                                <option value="3">3 - Curatore fallimentare</option>
                                <option value="4">4 - Commissario liquidatore</option>
                                <option value="7">7 - Erede</option>
                                <option value="8">8 - Liquidatore</option>
                                <option value="13">13 - Amministratore condominio</option>
                                <option value="14">14 - Pubblica amministrazione</option>
                            </select>
                        </div>
                    </details>

                    <label style="margin-top:10px;">Note interne</label>
                    <textarea name="note" id="sm-note" rows="2"></textarea>

                    <div class="sm-error" id="sm-error" style="display:none;"></div>

                    <div class="sm-actions">
                        <button type="submit" class="sm-btn sm-btn-primary" id="sm-submit">💾 Salva</button>
                        <button type="button" class="sm-btn sm-btn-gray" onclick="SoggettoModal.close()">Annulla</button>
                    </div>
                </form>
            </div>
        </div>
    `;

    // ============================================================
    // CSS INIETTATO
    // ============================================================
    const CSS = `
        .soggetto-modal-backdrop {
            position: fixed; inset: 0; background: rgba(26, 32, 44, 0.6);
            display: none; justify-content: center; align-items: flex-start;
            z-index: 99999; padding: 40px 15px; overflow-y: auto;
        }
        .soggetto-modal-backdrop.open { display: flex; }
        .soggetto-modal {
            background: #fff; border-radius: 12px; padding: 25px;
            max-width: 640px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }
        .sm-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
        .sm-header h3 { margin: 0; color: #2b6cb0; }
        .sm-close { background: none; border: none; font-size: 1.6em; cursor: pointer; color: #a0aec0; line-height: 1; }
        .sm-close:hover { color: #f56565; }
        .soggetto-modal label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.88em; color: #4a5568; }
        .soggetto-modal input, .soggetto-modal select, .soggetto-modal textarea {
            width: 100%; padding: 9px 12px; border: 1px solid #cbd5e0;
            border-radius: 8px; margin-bottom: 12px; font-size: 0.95em;
            transition: border-color 0.2s, background-color 0.2s;
        }
        .soggetto-modal input:focus, .soggetto-modal select:focus {
            outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
        }
        .sm-row { display: flex; gap: 10px; }
        .sm-row > div { flex: 1; }
        .sm-hint { font-size: 0.8em; color: #718096; margin-top: -8px; margin-bottom: 10px; display: block; min-height: 14px; }
        .sm-hint.ok { color: #38a169; }
        .sm-hint.err { color: #e53e3e; }
        .sm-error { background: #fed7d7; color: #742a2a; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 0.9em; }
        .sm-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 10px; padding-top: 15px; border-top: 1px solid #e2e8f0; }
        .sm-btn { padding: 10px 22px; border-radius: 8px; font-weight: bold; font-size: 0.95em; cursor: pointer; border: none; }
        .sm-btn-primary { background: #48bb78; color: #fff; }
        .sm-btn-primary:hover { background: #38a169; }
        .sm-btn-primary:disabled { background: #a0aec0; cursor: not-allowed; }
        .sm-btn-gray { background: #e2e8f0; color: #2d3748; }
        .sm-btn-gray:hover { background: #cbd5e0; }

        /* Classi CF (usate da cf_utils.js) */
        .cf-feedback { padding: 8px 12px; border-radius: 6px; font-size: 0.85em; margin-top: -6px; margin-bottom: 12px; display: none; }
        .cf-feedback.ok { background: #c6f6d5; color: #22543d; display: block; }
        .cf-feedback.warn { background: #feebc8; color: #7b341e; display: block; }
        .cf-feedback.err { background: #fed7d7; color: #742a2a; display: block; }
        .cf-valid { border-color: #48bb78 !important; }
        .cf-warning { border-color: #ed8936 !important; background-color: #fffaf0 !important; }
        .cf-error { border-color: #e53e3e !important; background-color: #fff5f5 !important; }
        .auto-filled { background-color: #f0fff4 !important; border-color: #68d391 !important; }
    `;

    // ============================================================
    // STATO INTERNO
    // ============================================================
    let currentOptions = {};
    let initialized = false;
    let cfAttached = false;

    // ============================================================
    // INIZIALIZZAZIONE (una volta sola)
    // ============================================================
    function init() {
        if (initialized) return;

        // Inietta CSS
        const style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);

        // Inietta HTML modale
        const div = document.createElement('div');
        div.innerHTML = HTML_MODAL;
        document.body.appendChild(div.firstElementChild);

        // === Eventi base ===
        const form = document.getElementById('sm-form');
        form.addEventListener('submit', onSubmit);

        // Autofill del legale rappresentante dalla persona fisica selezionata
        const selRapp = document.getElementById('sm-id_rappresentante');
        if (selRapp) {
            selRapp.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                if (!this.value) return;
                const cfR = document.getElementById('sm-cf_rappresentante');
                const cogR = document.getElementById('sm-cognome_rappresentante');
                const nomR = document.getElementById('sm-nome_rappresentante');
                const carica = document.getElementById('sm-codice_carica');
                if (cfR) cfR.value = opt.dataset.cf || '';
                if (cogR) cogR.value = opt.dataset.cognome || '';
                if (nomR) nomR.value = opt.dataset.nome || '';
                if (carica) carica.value = '1';
            });
        }

        document.getElementById('sm-backdrop').addEventListener('click', function(e) {
            if (e.target === this) SoggettoModal.close();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const bd = document.getElementById('sm-backdrop');
                if (bd && bd.classList.contains('open')) SoggettoModal.close();
            }
        });

        initialized = true;
    }

    // ============================================================
    // AGGANCIO CF UTILS (una volta sola, dopo init)
    // ============================================================
    function attachCfUtils() {
        if (cfAttached) return;

        const cfField = document.getElementById('sm-cf');
        if (!cfField) return;

        if (window.CFUtils) {
            // Validazione + auto-fill
            CFUtils.attach(cfField, {
                feedbackEl: document.getElementById('sm-cf-feedback'),
                fields: {
                    sesso:          document.getElementById('sm-sesso'),
                    data_nascita:   document.getElementById('sm-data_nascita'),
                    comune_nascita: document.getElementById('sm-comune_nascita'),
                    prov_nascita:   document.getElementById('sm-prov_nascita')
                },
                autoFill: true
            });

            // Autocomplete comune nascita + provincia
            CFUtils.attachComuneAutocomplete(
                document.getElementById('sm-comune_nascita'),
                document.getElementById('sm-prov_nascita'),
                'sm-lista_comuni_nascita'
            );
        } else {
            // Fallback: almeno uppercase
            cfField.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            });
            console.warn('SoggettoModal: CFUtils non disponibile, validazione CF disattivata');
        }

        cfAttached = true;
    }

    // ============================================================
    // SUBMIT
    // ============================================================
    async function onSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const submitBtn = document.getElementById('sm-submit');
        const errorDiv = document.getElementById('sm-error');
        errorDiv.style.display = 'none';

        // === Validazione client-side ===
        const cfVal = (document.getElementById('sm-cf').value || '').toUpperCase().trim();
        if (cfVal === '') {
            errorDiv.textContent = '⚠ Inserisci il Codice Fiscale / P.IVA';
            errorDiv.style.display = 'block';
            document.getElementById('sm-cf').focus();
            return;
        }
        if (cfVal.length !== 16 && cfVal.length !== 11) {
            errorDiv.textContent = '⚠ CF deve avere 16 caratteri (persona fisica) o 11 cifre (azienda)';
            errorDiv.style.display = 'block';
            document.getElementById('sm-cf').focus();
            return;
        }
        if (cfVal.length === 16 && window.CFUtils && !CFUtils.validateCF(cfVal)) {
            if (!confirm('Il CF non supera il controllo formale (possibile omocodia).\nSalvare comunque?')) {
                return;
            }
        }
        const cognomeVal = (document.getElementById('sm-cognome').value || '').trim();
        if (cognomeVal === '') {
            errorDiv.textContent = '⚠ Inserisci il Cognome / Ragione Sociale';
            errorDiv.style.display = 'block';
            document.getElementById('sm-cognome').focus();
            return;
        }

        // === Submit ===
        submitBtn.disabled = true;
        submitBtn.textContent = '⏳ Salvataggio...';

        try {
            const formData = new FormData(form);
            const resp = await fetch('ajax_save_soggetto.php', {
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

            // Chiudi modale
            SoggettoModal.close();

            // Callback
            if (typeof currentOptions.onSave === 'function') {
                currentOptions.onSave(data.soggetto);
            }

        } catch (err) {
            errorDiv.textContent = '⚠ ' + err.message;
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Salva';
        }
    }

    // ============================================================
    // FILL / RESET
    // ============================================================
    function fillForm(soggetto) {
        const map = {
            'id_soggetto':            soggetto.id,
            'cf':                     soggetto.codice_fiscale,
            'cognome':                soggetto.cognome,
            'nome':                   soggetto.nome,
            'sesso':                  soggetto.sesso,
            'data_nascita':           soggetto.data_nascita,
            'comune_nascita':         soggetto.comune_nascita,
            'prov_nascita':           soggetto.prov_nascita,
            'email':                  soggetto.email,
            'pec':                    soggetto.pec,
            'telefono':               soggetto.telefono,
            'tipologia_default':      soggetto.tipologia_default || 'ENTRAMBI',
            'tipologia_conduttore':   soggetto.tipologia_conduttore || '',
            'cf_rappresentante':      soggetto.cf_rappresentante,
            'cognome_rappresentante': soggetto.cognome_rappresentante,
            'nome_rappresentante':    soggetto.nome_rappresentante,
            'codice_carica':          soggetto.codice_carica,
            'id_rappresentante':      soggetto.id_rappresentante,
            'note':                   soggetto.note
        };
        Object.keys(map).forEach(k => {
            const el = document.getElementById('sm-' + k);
            if (el) el.value = map[k] ?? '';
        });
        const siva = document.getElementById('sm-soggettivita_iva');
        if (siva) siva.checked = !!parseInt(soggetto.soggettivita_iva || 0);
    }

    function resetForm() {
        const form = document.getElementById('sm-form');
        if (form) form.reset();

        const idField = document.getElementById('sm-id_soggetto');
        if (idField) idField.value = '';

        const errDiv = document.getElementById('sm-error');
        if (errDiv) errDiv.style.display = 'none';

        const feedback = document.getElementById('sm-cf-feedback');
        if (feedback) { feedback.className = 'cf-feedback'; feedback.textContent = ''; }

        const hint = document.getElementById('sm-cf-hint');
        if (hint) hint.textContent = '';

        const cfField = document.getElementById('sm-cf');
        if (cfField) cfField.classList.remove('cf-valid', 'cf-warning', 'cf-error');

        // Reset classi auto-filled
        ['sm-sesso', 'sm-data_nascita', 'sm-comune_nascita', 'sm-prov_nascita'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('auto-filled');
        });

        const submitBtn = document.getElementById('sm-submit');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '💾 Salva';
        }
    }

    // ============================================================
    // API PUBBLICA
    // ============================================================
    window.SoggettoModal = {

        open: function(opts) {
            init();
            attachCfUtils();

            currentOptions = opts || {};
            resetForm();

            // Popola il selettore del legale rappresentante
            const selRapp = document.getElementById('sm-id_rappresentante');
            if (selRapp) {
                selRapp.innerHTML = '<option value="">— Nessuno / compila manualmente —</option>';
                (opts.personeFisiche || []).forEach(function(pf) {
                    const o = document.createElement('option');
                    o.value = pf.id;
                    o.dataset.cf = pf.codice_fiscale || '';
                    o.dataset.cognome = pf.cognome || '';
                    o.dataset.nome = pf.nome || '';
                    o.textContent = (pf.cognome || '') + ' ' + (pf.nome || '') + ' — ' + (pf.codice_fiscale || '');
                    selRapp.appendChild(o);
                });
            }

            // Token CSRF
            const csrfField = document.getElementById('sm-csrf');
            if (csrfField) csrfField.value = opts.csrfToken || '';

            // Titolo + fill se modifica
            if (opts.soggetto) {
                document.getElementById('sm-title').textContent = 'Modifica Soggetto';
                fillForm(opts.soggetto);
            } else {
                document.getElementById('sm-title').textContent = 'Nuovo Soggetto';
                if (opts.tipologiaDefault) {
                    const sel = document.getElementById('sm-tipologia_default');
                    if (sel) sel.value = opts.tipologiaDefault;
                }
            }

            // Mostra modale
            document.getElementById('sm-backdrop').classList.add('open');

            // Focus sul CF
            setTimeout(function() {
                const cf = document.getElementById('sm-cf');
                if (cf) cf.focus();
            }, 100);
        },

        close: function() {
            const bd = document.getElementById('sm-backdrop');
            if (bd) bd.classList.remove('open');
        }
    };

})();
