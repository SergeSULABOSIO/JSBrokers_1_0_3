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

        // 1. Créer le conteneur qui deviendra l'éditeur Quill.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';

        // 2. Initialiser Quill sur ce conteneur.
        this.quill = new Quill(editorContainer, {
            modules: { toolbar: toolbarOptions },
            theme: 'snow',
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

        // 3. Charger le contenu initial du textarea dans l'éditeur.
        // On utilise `clipboard.convert` pour interpréter le HTML existant.
        const delta = this.quill.clipboard.convert(this.element.value);
        this.quill.setContents(delta, 'silent');

        // 4. Créer un wrapper et y déplacer les éléments créés par Quill.
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('quill-wrapper');
        // On insère le wrapper avant le textarea original.
        this.element.parentNode.insertBefore(this.wrapper, this.element);
        // On déplace la barre d'outils et l'éditeur DANS le wrapper.
        this.wrapper.appendChild(this.quill.getModule('toolbar').container);
        this.wrapper.appendChild(editorContainer); // editorContainer est maintenant le .ql-container

        // 5. Cacher le textarea original.
        this.quill.root.innerHTML = this.element.value;
        this.element.style.display = 'none';

        // À chaque modification dans l'éditeur, on met à jour le textarea caché
        this.quill.on('text-change', () => {
            this.element.value = this.quill.root.innerHTML;
            // On déclenche l'événement 'change' manuellement pour que d'autres scripts le détectent
            this.element.dispatchEvent(new Event('change'));
        });
    }

    disconnect() {
        // Logique de nettoyage simplifiée et robuste.
        // On vérifie si notre wrapper a été créé et s'il est toujours dans le DOM.
        if (this.wrapper && this.wrapper.parentNode) {
            // On supprime simplement le wrapper, qui contient l'éditeur et sa barre d'outils.
            this.wrapper.remove();
            this.element.style.display = 'block'; // On s'assure de réafficher le textarea.
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
