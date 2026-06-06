import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    connect() {
        if (!this.urlValue) return;
        const details = this.element.closest('details');
        if (details && !details.open) {
            const handler = () => {
                if (details.open) {
                    this._load();
                    details.removeEventListener('toggle', handler);
                }
            };
            details.addEventListener('toggle', handler);
        } else {
            this._load();
        }
    }

    _load() {
        this.element.innerHTML = '<div class="db-skeleton-block" style="min-height:80px;margin:.5rem 0;"></div>';
        fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.ok ? r.text() : Promise.reject('HTTP ' + r.status))
            .then(html => { this.element.innerHTML = html; })
            .catch(err => {
                console.warn('[lazy-block] Failed to load:', this.urlValue, err);
                this.element.innerHTML = '<p class="text-muted small p-3 text-center" style="color:#6c757d;">Erreur de chargement du bloc</p>';
            });
    }
}
