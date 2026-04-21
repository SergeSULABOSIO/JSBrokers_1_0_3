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

        // 1. Créer un conteneur pour l'éditeur.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';

        // 2. Insérer ce conteneur juste avant le textarea original.
        this.element.parentNode.insertBefore(editorContainer, this.element);

        // 3. Initialiser Quill sur ce conteneur.
        // Quill va automatiquement créer la barre d'outils et l'injecter avant l'éditeur.
        this.quill = new Quill(editorContainer, {
            modules: { toolbar: toolbarOptions },
            theme: 'snow',
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

        // 4. Charger le contenu initial du textarea dans l'éditeur.
        this.quill.root.innerHTML = this.element.value;

        // 5. Cacher le textarea original, qui sert maintenant de stockage de données.
        this.element.style.display = 'none';

        // 6. À chaque changement dans l'éditeur, mettre à jour la valeur du textarea caché.
        this.quill.on('text-change', () => {
            this.element.value = this.quill.root.innerHTML;
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    disconnect() {
        // Pour l'instant, nous laissons cette méthode vide pour garantir que l'éditeur s'affiche.
        // Nous reviendrons sur le nettoyage dans un second temps, une fois la fonctionnalité restaurée.
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
