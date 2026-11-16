/**
 * iban-validator.js
 * Validazione IBAN con algoritmo mod-97 (ISO 13616).
 */

(function() {
    'use strict';

    const IBAN_LENGTHS = {
        'AD': 24, 'AE': 23, 'AL': 28, 'AT': 20, 'AZ': 28, 'BA': 20, 'BE': 16, 'BG': 22,
        'BH': 22, 'BR': 29, 'BY': 28, 'CH': 21, 'CR': 22, 'CY': 28, 'CZ': 24, 'DE': 22,
        'DK': 18, 'DO': 28, 'EE': 20, 'EG': 29, 'ES': 24, 'FI': 18, 'FO': 18, 'FR': 27,
        'GB': 22, 'GE': 22, 'GI': 23, 'GL': 18, 'GR': 27, 'GT': 28, 'HR': 21, 'HU': 28,
        'IE': 22, 'IL': 23, 'IQ': 23, 'IS': 26, 'IT': 27, 'JO': 30, 'KW': 30, 'KZ': 20,
        'LB': 28, 'LC': 32, 'LI': 21, 'LT': 20, 'LU': 20, 'LV': 21, 'MC': 27, 'MD': 24,
        'ME': 22, 'MK': 19, 'MR': 27, 'MT': 31, 'MU': 30, 'NL': 18, 'NO': 15, 'PK': 24,
        'PL': 28, 'PS': 29, 'PT': 25, 'QA': 29, 'RO': 24, 'RS': 22, 'SA': 24, 'SC': 31,
        'SE': 24, 'SI': 19, 'SK': 24, 'SM': 27, 'ST': 25, 'SV': 28, 'TL': 23, 'TN': 24,
        'TR': 26, 'UA': 29, 'VA': 22, 'VG': 24, 'XK': 20
    };

    function validateIBAN(iban) {
        if (!iban) return { valid: false, error: 'IBAN vuoto' };

        const clean = iban.replace(/\s+/g, '').toUpperCase();

        if (clean.length < 15 || clean.length > 34) {
            return { valid: false, error: 'Lunghezza non valida (' + clean.length + ' caratteri, attesi 15-34)' };
        }

        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(clean)) {
            return { valid: false, error: 'Formato non valido (atteso: 2 lettere + 2 cifre + alfanumerici)' };
        }

        const country = clean.substring(0, 2);

        if (IBAN_LENGTHS[country] && clean.length !== IBAN_LENGTHS[country]) {
            return {
                valid: false,
                error: 'Lunghezza errata per ' + country + ': ' + clean.length + ' (attesi ' + IBAN_LENGTHS[country] + ')'
            };
        }

        const rearranged = clean.substring(4) + clean.substring(0, 4);

        let numericString = '';
        for (let i = 0; i < rearranged.length; i++) {
            const ch = rearranged[i];
            if (/[0-9]/.test(ch)) {
                numericString += ch;
            } else {
                numericString += (ch.charCodeAt(0) - 55).toString();
            }
        }

        let remainder = 0;
        for (let i = 0; i < numericString.length; i++) {
            remainder = (remainder * 10 + parseInt(numericString[i], 10)) % 97;
        }

        if (remainder !== 1) {
            return { valid: false, error: 'Checksum non valido (controlla le cifre)' };
        }

        const formatted = clean.match(/.{1,4}/g).join(' ');
        return { valid: true, formatted: formatted, country: country };
    }

    function formatIBAN(iban) {
        if (!iban) return '';
        const clean = iban.replace(/\s+/g, '').toUpperCase();
        return clean.match(/.{1,4}/g).join(' ');
    }

    function attachToInput(inputElement) {
        if (!inputElement) return;

        inputElement.addEventListener('input', function() {
            const raw = this.value.replace(/\s+/g, '').toUpperCase();
            this.value = raw;
        });

        inputElement.addEventListener('blur', function() {
            const result = validateIBAN(this.value);

            this.classList.remove('iban-valid', 'iban-invalid');
            const feedbackEl = document.getElementById(this.id + '_feedback');
            if (feedbackEl) {
                feedbackEl.className = 'iban-feedback';
                feedbackEl.textContent = '';
            }

            if (this.value === '') return;

            if (result.valid) {
                this.classList.add('iban-valid');
                if (feedbackEl) {
                    feedbackEl.classList.add('iban-feedback-ok');
                    feedbackEl.textContent = '✓ IBAN valido (' + result.country + ')';
                }
            } else {
                this.classList.add('iban-invalid');
                if (feedbackEl) {
                    feedbackEl.classList.add('iban-feedback-err');
                    feedbackEl.textContent = '✗ ' + result.error;
                }
            }
        });
    }

    window.IBANValidator = {
        validate: validateIBAN,
        format: formatIBAN,
        attach: attachToInput
    };
})();
