/**
 * cf_utils.js
 * Utility condivise per validazione Codice Fiscale / P.IVA
 * ed estrazione automatica dati anagrafici.
 *
 * Espone:
 *   CFUtils.loadComuni()           -> carica (una volta) la lista comuni
 *   CFUtils.validateCF(cf)         -> bool (algoritmo mod-26 ufficiale, con omocodia)
 *   CFUtils.validatePIVA(piva)     -> bool (11 cifre)
 *   CFUtils.estraiDatiDaCF(cf)     -> {sesso, data_nascita, belfiore} | null
 *   CFUtils.trovaComuneDaBelfiore(belfiore) -> {nome, sigla, codiceCatastale} | null
 *   CFUtils.analizza(cf)           -> {tipo, valido, dati, messaggio}
 *   CFUtils.attach(cfInput, opts)  -> attiva validazione live su un input
 *   CFUtils.attachComuneAutocomplete(comuneInput, provField, datalistId)
 */

(function() {
    'use strict';

    // ============================================================
    // STATO INTERNO
    // ============================================================
    let dbComuni = [];
    let comuniLoading = null;

    // ============================================================
    // CARICAMENTO COMUNI (una sola volta)
    // ============================================================
    function loadComuni() {
        if (dbComuni.length > 0) return Promise.resolve(dbComuni);
        if (comuniLoading) return comuniLoading;

        comuniLoading = fetch('https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json')
            .then(r => r.json())
            .then(d => {
                dbComuni = d;
                console.log('CFUtils: comuni caricati', d.length);
                return d;
            })
            .catch(err => {
                console.warn('CFUtils: impossibile caricare comuni', err);
                return [];
            });

        return comuniLoading;
    }

    // ============================================================
    // VALIDAZIONE CF — Algoritmo mod-26 ufficiale
    // ============================================================
    // Tabelle ufficiali (Ministero Economia e Finanze).
    // La posizione è 1-based sul CF:
    //   - posizioni DISPARI (1°, 3°, 5°, ... 15°) → usano dispariValues
    //   - posizioni PARI   (2°, 4°, 6°, ... 14°) → usano pariValues
    //   - la 16ª posizione è il check digit (lettera)
    //
    // Le tabelle includono già la decodifica dell'omocodia:
    // le lettere L,M,N,P,Q,R,S,T,U,V hanno valori specifici.
    // ============================================================

    const CF_DISPARI_VALUES = {
        // Cifre
        '0':1,  '1':0,  '2':5,  '3':7,  '4':9,
        '5':13, '6':15, '7':17, '8':19, '9':21,
        // Lettere
        'A':1,  'B':0,  'C':5,  'D':7,  'E':9,
        'F':13, 'G':15, 'H':17, 'I':19, 'J':21,
        'K':2,  'L':4,  'M':18, 'N':20, 'O':11,
        'P':3,  'Q':6,  'R':8,  'S':12, 'T':14,
        'U':16, 'V':10, 'W':22, 'X':25, 'Y':24,
        'Z':23
    };

    const CF_PARI_VALUES = {
        // Cifre
        '0':0,  '1':1,  '2':2,  '3':3,  '4':4,
        '5':5,  '6':6,  '7':7,  '8':8,  '9':9,
        // Lettere
        'A':0,  'B':1,  'C':2,  'D':3,  'E':4,
        'F':5,  'G':6,  'H':7,  'I':8,  'J':9,
        'K':10, 'L':11, 'M':12, 'N':13, 'O':14,
        'P':15, 'Q':16, 'R':17, 'S':18, 'T':19,
        'U':20, 'V':21, 'W':22, 'X':23, 'Y':24,
        'Z':25
    };

    const CF_CHECK_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Valida un Codice Fiscale italiano (16 caratteri alfanumerici).
     * Usa l'algoritmo ufficiale mod-26.
     */
    function validateCF(cf) {
        if (!cf) return false;
        cf = cf.toUpperCase().trim();

        if (cf.length !== 16) return false;
        if (!/^[A-Z0-9]{16}$/.test(cf)) return false;

        let somma = 0;
        for (let i = 0; i < 15; i++) {
            const ch = cf.charAt(i);
            // i è 0-based.
            // Posizione 1-based = i+1.
            // Posizione 1-based DISPARI (i=0,2,4,...) → CF_DISPARI_VALUES
            // Posizione 1-based PARI   (i=1,3,5,...) → CF_PARI_VALUES
            if (i % 2 === 0) {
                if (!(ch in CF_DISPARI_VALUES)) return false;
                somma += CF_DISPARI_VALUES[ch];
            } else {
                if (!(ch in CF_PARI_VALUES)) return false;
                somma += CF_PARI_VALUES[ch];
            }
        }

        const resto = somma % 26;
        const letteraAttesa = CF_CHECK_LETTERS.charAt(resto);

        return cf.charAt(15) === letteraAttesa;
    }

    // ============================================================
    // VALIDAZIONE P.IVA (11 cifre)
    // ============================================================
    function validatePIVA(piva) {
        if (!piva || !/^\d{11}$/.test(piva)) return false;
        return true;
    }

    // ============================================================
    // ESTRAI DATI DA CF
    // ============================================================
    function estraiDatiDaCF(cf) {
        if (!cf || cf.length !== 16) return null;
        cf = cf.toUpperCase().trim();
        if (!/^[A-Z0-9]{16}$/.test(cf)) return null;

        // Mese (posizione 9, indice 8)
        const monthChar = cf.charAt(8);
        const months = {
            A:'01', B:'02', C:'03', D:'04', E:'05', H:'06',
            L:'07', M:'08', P:'09', R:'10', S:'11', T:'12'
        };
        const month = months[monthChar];
        if (!month) return null;

        // Giorno (posizioni 10-11, indici 9-10)
        let dayStr = parseInt(cf.substring(9, 11), 10);
        if (isNaN(dayStr)) return null;

        // Sesso: se > 40 è femmina, sottrai 40
        const sesso = (dayStr > 40) ? 'F' : 'M';
        if (dayStr > 40) dayStr -= 40;

        if (dayStr < 1 || dayStr > 31) return null;
        const day = String(dayStr).padStart(2, '0');

        // Anno (posizioni 7-8, indici 6-7)
        const yearStr = cf.substring(6, 8);
        const yearNum = parseInt(yearStr, 10);
        if (isNaN(yearNum)) return null;
        const currentYear = new Date().getFullYear() % 100;
        const fullYear = (yearNum > currentYear) ? (1900 + yearNum) : (2000 + yearNum);

        // Codice Belfiore (posizioni 12-15, indici 11-14)
        const belfiore = cf.substring(11, 15).toUpperCase();

        return {
            sesso,
            data_nascita: fullYear + '-' + month + '-' + day,
            belfiore
        };
    }

    // ============================================================
    // STATI ESTERI — codici Belfiore Zxxx (principali)
    // ============================================================
    const STATI_ESTERI_BELFIORE = {
        // Europa
        'Z100': 'ALBANIA', 'Z101': 'ANDORRA', 'Z102': 'AUSTRIA', 'Z103': 'BELGIO',
        'Z104': 'BULGARIA', 'Z105': 'CIPRO', 'Z106': 'CROAZIA', 'Z107': 'DANIMARCA',
        'Z108': 'ESTONIA', 'Z109': 'FINLANDIA', 'Z110': 'FRANCIA', 'Z111': 'GERMANIA',
        'Z112': 'GRECIA', 'Z113': 'IRLANDA', 'Z114': 'ISLANDA', 'Z115': 'LETTONIA',
        'Z116': 'LIECHTENSTEIN', 'Z117': 'LITUANIA', 'Z118': 'LUSSEMBURGO', 'Z119': 'MALTA',
        'Z120': 'MONACO', 'Z121': 'NORVEGIA', 'Z122': 'PAESI BASSI', 'Z123': 'POLONIA',
        'Z124': 'PORTOGALLO', 'Z125': 'REGNO UNITO', 'Z126': 'REPUBBLICA CECA',
        'Z127': 'ROMANIA', 'Z128': 'SAN MARINO', 'Z129': 'SLOVACCHIA', 'Z130': 'SLOVENIA',
        'Z131': 'SPAGNA', 'Z132': 'SVEZIA', 'Z133': 'SVIZZERA', 'Z134': 'UNGHERIA',
        'Z135': 'CITTA DEL VATICANO', 'Z136': 'SERBIA', 'Z137': 'MONTENEGRO',
        'Z138': 'BOSNIA ED ERZEGOVINA', 'Z139': 'MACEDONIA DEL NORD', 'Z140': 'MOLDAVIA',
        'Z141': 'UCRAINA', 'Z142': 'BIELORUSSIA', 'Z143': 'RUSSIA', 'Z144': 'TURCHIA',
        'Z145': 'KOSOVO', 'Z146': 'GIBILTERRA', 'Z147': 'ISOLA DI MAN', 'Z148': 'JERSEY',
        'Z149': 'GUERNSEY', 'Z150': 'ISOLE FAROER', 'Z151': 'SVALBARD E JAN MAYEN',
        'Z152': 'ISOLE ALAND',
        // Africa
        'Z200': 'MAROCCO', 'Z201': 'ALGERIA', 'Z202': 'TUNISIA', 'Z203': 'LIBIA',
        'Z204': 'EGITTO', 'Z205': 'SUDAN', 'Z206': 'ETIOPIA', 'Z207': 'KENYA',
        'Z208': 'TANZANIA', 'Z209': 'UGANDA', 'Z210': 'NIGERIA', 'Z211': 'GHANA',
        'Z212': 'SENEGAL', 'Z213': 'COSTA D AVORIO', 'Z214': 'CAMERUN', 'Z215': 'CONGO',
        'Z216': 'SUDAFRICA', 'Z217': 'ZIMBABWE', 'Z218': 'ZAMBIA', 'Z219': 'MOZAMBICO',
        'Z220': 'ANGOLA', 'Z221': 'NAMIBIA', 'Z222': 'BOTSWANA', 'Z223': 'MADAGASCAR',
        'Z224': 'MAURITIUS', 'Z225': 'SEYCHELLES', 'Z226': 'CAPO VERDE', 'Z227': 'GUINEA',
        'Z228': 'MALI', 'Z229': 'BURKINA FASO', 'Z230': 'NIGER', 'Z231': 'CIAD',
        'Z232': 'REPUBBLICA CENTRAFRICANA', 'Z233': 'GABON', 'Z234': 'BENIN', 'Z235': 'TOGO',
        'Z236': 'SIERRA LEONE', 'Z237': 'LIBERIA', 'Z238': 'GAMBIA', 'Z239': 'MAURITANIA',
        'Z240': 'SOMALIA', 'Z241': 'ERITREA', 'Z242': 'GIBUTI', 'Z243': 'RUANDA',
        'Z244': 'BURUNDI', 'Z245': 'MALAWI', 'Z246': 'LESOTHO', 'Z247': 'ESWATINI',
        'Z248': 'COMORE', 'Z249': 'MAYOTTE', 'Z250': 'RIUNIONE', 'Z251': 'SANT ELENA',
        'Z252': 'SAHARA OCCIDENTALE',
        // America
        'Z300': 'STATI UNITI D AMERICA', 'Z301': 'CANADA', 'Z302': 'MESSICO',
        'Z303': 'GUATEMALA', 'Z304': 'BELIZE', 'Z305': 'HONDURAS', 'Z306': 'EL SALVADOR',
        'Z307': 'NICARAGUA', 'Z308': 'COSTA RICA', 'Z309': 'PANAMA', 'Z310': 'CUBA',
        'Z311': 'GIAMAICA', 'Z312': 'HAITI', 'Z313': 'REPUBBLICA DOMINICANA',
        'Z314': 'BAHAMAS', 'Z315': 'BARBADOS', 'Z316': 'TRINIDAD E TOBAGO',
        'Z317': 'PORTO RICO', 'Z318': 'BERMUDA', 'Z319': 'GROENLANDIA',
        'Z320': 'SAINT PIERRE E MIQUELON', 'Z321': 'ANTIGUA E BARBUDA', 'Z322': 'DOMINICA',
        'Z323': 'GRENADA', 'Z324': 'SAINT KITTS E NEVIS', 'Z325': 'SAINT LUCIA',
        'Z326': 'SAINT VINCENT E GRENADINE', 'Z327': 'ISOLE CAYMAN',
        'Z328': 'TURKS E CAICOS', 'Z329': 'ISOLE VERGINI BRITANNICHE',
        'Z330': 'ISOLE VERGINI AMERICANE', 'Z331': 'ANGUILLA', 'Z332': 'MONTSERRAT',
        'Z333': 'ARUBA', 'Z334': 'CURACAO', 'Z335': 'SINT MAARTEN', 'Z336': 'BONAIRE',
        'Z337': 'SABA', 'Z338': 'SINT EUSTATIUS',
        'Z400': 'ARGENTINA', 'Z401': 'BOLIVIA', 'Z402': 'BRASILE', 'Z403': 'CILE',
        'Z404': 'COLOMBIA', 'Z405': 'ECUADOR', 'Z406': 'GUYANA', 'Z407': 'PARAGUAY',
        'Z408': 'PERU', 'Z409': 'SURINAME', 'Z410': 'URUGUAY', 'Z411': 'VENEZUELA',
        'Z412': 'ISOLE FALKLAND', 'Z413': 'GUYANA FRANCESE',
        // Asia
        'Z500': 'CINA', 'Z501': 'GIAPPONE', 'Z502': 'COREA DEL SUD', 'Z503': 'COREA DEL NORD',
        'Z504': 'MONGOLIA', 'Z505': 'TAIWAN', 'Z506': 'HONG KONG', 'Z507': 'MACAO',
        'Z508': 'INDIA', 'Z509': 'PAKISTAN', 'Z510': 'BANGLADESH', 'Z511': 'SRI LANKA',
        'Z512': 'NEPAL', 'Z513': 'BHUTAN', 'Z514': 'MALDIVE', 'Z515': 'AFGHANISTAN',
        'Z516': 'IRAN', 'Z517': 'IRAQ', 'Z518': 'SIRIA', 'Z519': 'LIBANO',
        'Z520': 'GIORDANIA', 'Z521': 'ARABIA SAUDITA', 'Z522': 'YEMEN', 'Z523': 'OMAN',
        'Z524': 'EMIRATI ARABI UNITI', 'Z525': 'QATAR', 'Z526': 'BAHREIN', 'Z527': 'KUWAIT',
        'Z528': 'ISRAELE', 'Z529': 'PALESTINA', 'Z530': 'KAZAKISTAN', 'Z531': 'UZBEKISTAN',
        'Z532': 'TURKMENISTAN', 'Z533': 'KIRGHIZISTAN', 'Z534': 'TAGIKISTAN',
        'Z535': 'AZERBAIGIAN', 'Z536': 'ARMENIA', 'Z537': 'GEORGIA',
        // Oceania
        'Z600': 'AUSTRALIA', 'Z601': 'NUOVA ZELANDA', 'Z602': 'PAPUA NUOVA GUINEA',
        'Z603': 'FIGI', 'Z604': 'ISOLE SALOMONE', 'Z605': 'VANUATU', 'Z606': 'SAMOA',
        'Z607': 'TONGA', 'Z608': 'TUVALU', 'Z609': 'KIRIBATI', 'Z610': 'NAURU',
        'Z611': 'PALAU', 'Z612': 'MICRONESIA', 'Z613': 'ISOLE MARSHALL',
        'Z614': 'NUOVA CALEDONIA', 'Z615': 'POLINESIA FRANCESE', 'Z616': 'WALLIS E FUTUNA',
        'Z617': 'GUAM', 'Z618': 'ISOLE MARIANNE SETTENTRIONALI', 'Z619': 'SAMOA AMERICANE',
        'Z620': 'ISOLE COOK', 'Z621': 'NIUE', 'Z622': 'TOKELAU', 'Z623': 'PITCAIRN',
        'Z624': 'ISOLA NORFOLK', 'Z625': 'ISOLA CHRISTMAS', 'Z626': 'ISOLE COCOS',
        // Altri territori
        'Z700': 'ANTARTIDE', 'Z701': 'TERRITORI FRANCESI DEL SUD',
        'Z702': 'ISOLA BOUVET', 'Z703': 'ISOLE MINORI DEGLI STATI UNITI',
        'Z704': 'ISOLE MINORI REMOTE DELL OCEANIA',
    };

    // ============================================================
    // TROVA COMUNE / STATO ESTERO DA CODICE BELFIORE
    // ============================================================
    function trovaComuneDaBelfiore(belfiore) {
        if (!belfiore) return null;
        const code = belfiore.toUpperCase();

        // Stati esteri (Zxxx): nome dello Stato e provincia "EE"
        if (STATI_ESTERI_BELFIORE[code]) {
            return {
                nome: STATI_ESTERI_BELFIORE[code],
                sigla: 'EE',
                codiceCatastale: code,
                estero: true
            };
        }

        if (dbComuni.length === 0) return null;
        return dbComuni.find(c => (c.codiceCatastale || '').toUpperCase() === code) || null;
    }

    // ============================================================
    // ANALISI COMPLETA
    // ============================================================
    function analizza(cfRaw) {
        const cf = (cfRaw || '').toUpperCase().trim();

        if (cf === '') {
            return { tipo: 'vuoto', valido: false, dati: null, messaggio: '' };
        }

        // P.IVA (azienda)
        if (/^\d{11}$/.test(cf)) {
            return {
                tipo: 'piva',
                valido: true,
                dati: null,
                messaggio: '✓ Partita IVA valida (11 cifre)'
            };
        }

        // Persona fisica: 16 caratteri
        if (cf.length === 16) {
            if (!/^[A-Z0-9]{16}$/.test(cf)) {
                return {
                    tipo: 'invalido',
                    valido: false,
                    dati: null,
                    messaggio: '✗ CF contiene caratteri non validi'
                };
            }
            const valido = validateCF(cf);
            const dati = estraiDatiDaCF(cf);
            return {
                tipo: 'cf',
                valido: valido,
                dati: dati,
                messaggio: valido
                    ? '✓ Codice fiscale valido'
                    : '⚠ CF formalmente non valido (possibile omocodia o errore). Verifica con attenzione.'
            };
        }

        // Lunghezza intermedia
        if (cf.length < 11) {
            return {
                tipo: 'incompleto',
                valido: false,
                dati: null,
                messaggio: '⏳ CF troppo corto (' + cf.length + ' caratteri). Attesi 16 (persona) o 11 (azienda).'
            };
        }
        if (cf.length > 11 && cf.length < 16) {
            return {
                tipo: 'incompleto',
                valido: false,
                dati: null,
                messaggio: '⏳ CF troppo corto (' + cf.length + '/16 caratteri).'
            };
        }

        return {
            tipo: 'invalido',
            valido: false,
            dati: null,
            messaggio: '✗ Lunghezza CF non valida (16 per persona, 11 per azienda).'
        };
    }

    // ============================================================
    // ATTACH VALIDAZIONE LIVE SU UN INPUT
    // ============================================================
    /**
     * Aggancia la validazione CF + auto-fill su un input.
     *
     * opts: {
     *   feedbackEl: HTMLElement  (dove mostrare il messaggio)
     *   fields: {
     *     sesso:          HTMLElement (select)  [opzionale]
     *     data_nascita:   HTMLElement (input)   [opzionale]
     *     comune_nascita: HTMLElement (input)   [opzionale]
     *     prov_nascita:   HTMLElement (input)   [opzionale]
     *   }
     *   autoFill: bool (default true)
     * }
     */
    function attach(cfInput, opts) {
        opts = opts || {};
        const feedback = opts.feedbackEl || null;
        const fields = opts.fields || {};
        const autoFill = opts.autoFill !== false;

        // Precarica comuni
        loadComuni();

        // Forza uppercase + alfanumerico
        cfInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });

        const run = function() {
            const cf = cfInput.value.toUpperCase().trim();

            // Reset classi
            cfInput.classList.remove('cf-valid', 'cf-warning', 'cf-error');
            if (feedback) { feedback.className = 'cf-feedback'; feedback.textContent = ''; }

            // Reset marker auto-fill
            ['sesso', 'data_nascita', 'comune_nascita', 'prov_nascita'].forEach(k => {
                if (fields[k]) fields[k].classList.remove('auto-filled');
            });

            const res = analizza(cf);

            if (res.tipo === 'vuoto') return;

            // Applica classe al campo
            if (res.tipo === 'piva') {
                cfInput.classList.add('cf-valid');
            } else if (res.tipo === 'cf') {
                cfInput.classList.add(res.valido ? 'cf-valid' : 'cf-warning');
            } else if (res.tipo === 'incompleto') {
                cfInput.classList.add('cf-warning');
            } else {
                cfInput.classList.add('cf-error');
            }

            // Feedback visivo
            if (feedback && res.messaggio) {
                let cls = 'cf-feedback ';
                if (res.tipo === 'piva' || (res.tipo === 'cf' && res.valido)) cls += 'ok';
                else if (res.tipo === 'cf' || res.tipo === 'incompleto') cls += 'warn';
                else cls += 'err';
                feedback.className = cls;
                feedback.textContent = res.messaggio;
            }

            // Auto-fill (solo per CF con 16 caratteri)
            if (autoFill && res.tipo === 'cf' && res.dati) {
                let applied = [];
                let comuneNonTrovato = null;

                // Sesso
                if (fields.sesso && !fields.sesso.value) {
                    fields.sesso.value = res.dati.sesso;
                    fields.sesso.classList.add('auto-filled');
                    applied.push('sesso=' + res.dati.sesso);
                }

                // Data nascita
                if (fields.data_nascita && !fields.data_nascita.value) {
                    fields.data_nascita.value = res.dati.data_nascita;
                    fields.data_nascita.classList.add('auto-filled');
                    applied.push('data=' + res.dati.data_nascita);
                }

                // Comune + provincia
                if (res.dati.belfiore && dbComuni.length > 0) {
                    const comune = trovaComuneDaBelfiore(res.dati.belfiore);
                    if (comune) {
                        if (fields.comune_nascita && !fields.comune_nascita.value) {
                            fields.comune_nascita.value = comune.nome.toUpperCase();
                            fields.comune_nascita.classList.add('auto-filled');
                            applied.push('comune=' + comune.nome + ' (' + comune.sigla + ')');
                        }
                        if (fields.prov_nascita && !fields.prov_nascita.value) {
                            fields.prov_nascita.value = comune.sigla.toUpperCase();
                            fields.prov_nascita.classList.add('auto-filled');
                        }
                    } else {
                        comuneNonTrovato = res.dati.belfiore;
                    }
                } else if (res.dati.belfiore && dbComuni.length === 0) {
                    // Comuni non ancora caricati: ri-esegui quando arrivano
                    loadComuni().then(() => run());
                }

                // Aggiorna feedback con cosa è stato compilato
                if (feedback && applied.length > 0) {
                    feedback.className = 'cf-feedback ok';
                    feedback.textContent = '✓ Compilati automaticamente: ' + applied.join(', ');
                }
                if (feedback && comuneNonTrovato) {
                    feedback.className = 'cf-feedback warn';
                    feedback.textContent = '⚠ Comune con codice Belfiore "' + comuneNonTrovato + '" non trovato. Compila manualmente.';
                }
            }
        };

        cfInput.addEventListener('blur', run);
        cfInput.addEventListener('change', run);
        // Ri-esegui quando i comuni finiscono di caricare
        loadComuni().then(() => { if (cfInput.value) run(); });
    }

    // ============================================================
    // AUTOCOMPLETE COMUNE DI NASCITA
    // ============================================================
    function attachComuneAutocomplete(comuneInput, provField, datalistId) {
        if (!comuneInput) return;
        loadComuni();

        comuneInput.addEventListener('input', function() {
            if (dbComuni.length === 0) return;
            const val = this.value.toUpperCase().trim();
            if (val.length < 2) return;

            const matches = dbComuni.filter(c => c.nome.toUpperCase().startsWith(val)).slice(0, 15);
            const dl = document.getElementById(datalistId);
            if (!dl) return;
            dl.innerHTML = '';
            matches.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.nome.toUpperCase();
                opt.label = m.sigla;
                dl.appendChild(opt);
            });

            // Auto-provincia
            const ex = dbComuni.find(c => c.nome.toUpperCase() === val);
            if (ex && provField && !provField.value) {
                provField.value = ex.sigla.toUpperCase();
                provField.classList.add('auto-filled');
            }
        });

        comuneInput.addEventListener('blur', function() {
            if (dbComuni.length === 0) return;
            const val = this.value.toUpperCase().trim();
            if (!val) return;
            const ex = dbComuni.find(c => c.nome.toUpperCase() === val);
            if (ex && provField && !provField.value) {
                provField.value = ex.sigla.toUpperCase();
                provField.classList.add('auto-filled');
            }
        });
    }

    // ============================================================
    // EXPORT
    // ============================================================
    window.CFUtils = {
        loadComuni,
        validateCF,
        validatePIVA,
        estraiDatiDaCF,
        trovaComuneDaBelfiore,
        analizza,
        attach,
        attachComuneAutocomplete,
        getComuni: () => dbComuni
    };

    console.log('CFUtils: modulo caricato (con fix algoritmo mod-26)');

})();
