/**
 * Validazione form con evidenziazione campi.
 */
const FormValidator = {
    /**
     * Evidenzia un campo in errore.
     */
    markError(el, message) {
        if (!el) return;
        el.classList.add('field-error');
        el.classList.remove('field-ok');
        
        // Rimuovi messaggio precedente
        const existing = el.parentElement.querySelector('.field-error-message');
        if (existing) existing.remove();
        
        // Aggiungi messaggio
        const msg = document.createElement('span');
        msg.className = 'field-error-message';
        msg.textContent = message || 'Campo obbligatorio';
        el.parentElement.appendChild(msg);
        
        // Scroll al campo
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus();
    },
    
    /**
     * Rimuove l'errore da un campo.
     */
    markOk(el) {
        if (!el) return;
        el.classList.remove('field-error');
        el.classList.add('field-ok');
        const msg = el.parentElement.querySelector('.field-error-message');
        if (msg) msg.remove();
    },
    
    /**
     * Pulisce tutti gli errori da un form.
     */
    clearAll(form) {
        form.querySelectorAll('.field-error').forEach(el => {
            el.classList.remove('field-error');
            el.classList.remove('field-ok');
        });
        form.querySelectorAll('.field-error-message').forEach(el => el.remove());
    },
    
    /**
     * Valida un elenco di campi required.
     * Ritorna true se tutti sono validi.
     */
    validateRequired(form, fields) {
        this.clearAll(form);
        let firstError = null;
        
        fields.forEach(selector => {
            const el = form.querySelector(selector);
            if (!el) return;
            
            const value = (el.value || '').trim();
            if (value === '') {
                this.markError(el, 'Campo obbligatorio');
                if (!firstError) firstError = el;
            } else {
                this.markOk(el);
            }
        });
        
        return !firstError;
    }
};

// Associa automaticamente ai campi required
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(el => {
        el.addEventListener('blur', function() {
            const value = (this.value || '').trim();
            if (value === '' && !this.classList.contains('field-error')) {
                // Non evidenziare subito, aspetta il submit
            } else if (value !== '') {
                this.classList.remove('field-error');
            }
        });
        
        el.addEventListener('input', function() {
            if (this.classList.contains('field-error') && (this.value || '').trim() !== '') {
                this.classList.remove('field-error');
                const msg = this.parentElement.querySelector('.field-error-message');
                if (msg) msg.remove();
            }
        });
    });
});
