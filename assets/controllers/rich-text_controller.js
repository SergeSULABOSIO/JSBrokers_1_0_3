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

        // 1. Créer un conteneur pour l'éditeur et l'insérer avant le textarea.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';
        this.element.parentNode.insertBefore(editorContainer, this.element);
        
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('quill-wrapper');
        
        // On insère le wrapper juste avant l'éditeur Quill.
        editorContainer.parentNode.insertBefore(this.wrapper, editorContainer);
        // On déplace la barre d'outils et l'éditeur DANS le wrapper.
        this.wrapper.appendChild(this.quill.getModule('toolbar').container);
        this.wrapper.appendChild(this.quill.container);
        
        // On cache le textarea original (qui servira de stockage caché)
        this.element.style.display = 'none';

        // 2. Initialiser Quill sur le conteneur.
        // Quill va automatiquement créer la barre d'outils comme un frère *avant* editorContainer.
        this.quill = new Quill(editorContainer, {
            modules: { toolbar: toolbarOptions },
            theme: 'snow',
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

        // 3. Charger le contenu initial du textarea dans l'éditeur.
        this.quill.root.innerHTML = this.element.value;

        // À chaque modification dans l'éditeur, on met à jour le textarea caché
        this.quill.on('text-change', () => {
            this.element.value = this.quill.root.innerHTML;
            // On déclenche l'événement 'change' manuellement pour que d'autres scripts le détectent
            this.element.dispatchEvent(new Event('change'));
        });
    }

    disconnect() {
        // Nettoyage si le contrôleur est retiré
        // On vérifie si l'instance de Quill et son conteneur existent toujours.
        if (this.quill && this.quill.container && this.quill.container.parentNode) {
            // On supprime le conteneur de l'éditeur et la barre d'outils.
            this.quill.container.previousSibling.remove(); // Supprime .ql-toolbar
            this.quill.container.remove(); // Supprime .ql-container
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
