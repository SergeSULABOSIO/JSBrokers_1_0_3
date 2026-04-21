import { Controller } from '@hotwired/stimulus';
import Quill from 'https://cdn.jsdelivr.net/npm/quill@2.0.2/+esm';

/**
 * @class RichTextController
 * @description Transforme un textarea standard en éditeur de texte riche avec Quill.
 */
export default class extends Controller {
    connect() {
        // Chargement dynamique du CSS de Quill depuis le CDN
        this._loadCss();

        // Configuration de la barre d'outils avec les options demandées (mise en forme, couleur, etc.)
        const toolbarOptions = [
            ['bold', 'italic', 'underline', 'strike'],        // Boutons de style
            [{ 'color': [] }, { 'background': [] }],          // Couleur du texte et surlignage
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],     // Listes
            [{ 'align': [] }],                                // Alignement
            [{ 'header': [1, 2, 3, false] }],                 // Titres
            ['clean']                                         // Bouton pour effacer le formatage
        ];

        // 1. Créer un conteneur pour l'éditeur et l'insérer AVANT d'initialiser Quill.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';
        this.element.parentNode.insertBefore(editorContainer, this.element);

        // 2. Initialiser Quill sur ce conteneur.
        // Quill va automatiquement créer la barre d'outils comme un frère avant l'éditeur.
        this.quill = new Quill(editorContainer, {
            modules: { toolbar: toolbarOptions },
            theme: 'snow',
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

        // 3. Charger le contenu et cacher le textarea original.
        this.quill.root.innerHTML = this.element.value;
        this.element.style.display = 'none';

        // 4. À chaque modification dans l'éditeur, on met à jour le textarea caché.
        this.quill.on('text-change', () => {
            this.element.value = this.quill.root.innerHTML;
            this.element.dispatchEvent(new Event('change'));
        });
    }

    disconnect() {
        // Nettoyage robuste : on vérifie que l'éditeur et ses éléments existent avant de les supprimer.
        if (this.quill && this.quill.container && this.quill.container.parentNode) {
            const toolbar = this.quill.getModule('toolbar').container;
            if (toolbar && toolbar.parentNode) toolbar.remove();
            this.quill.container.remove(); // Supprime le conteneur de l'éditeur (.ql-container)
            this.element.style.display = 'block'; // Réaffiche le textarea original.
        }
    }

    /**
     * Charge le fichier CSS de Quill si ce n'est pas déjà fait.
     */
    _loadCss() {
        if (document.getElementById('quill-css')) return;
        const link = document.createElement('link');
        link.id = 'quill-css';
        link.rel = 'stylesheet';
        link.href = 'https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css';
        document.head.appendChild(link);
    }
}
