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

        // 1. Création du conteneur qui deviendra l'éditeur Quill.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';

        // On insère ce conteneur temporaire pour que Quill puisse s'initialiser.
        this.element.parentNode.insertBefore(editorContainer, this.element);
        
        // On cache le textarea original (qui servira de stockage caché)
        this.element.style.display = 'none';

        // Initialisation de Quill
        this.quill = new Quill(editorContainer, {
            modules: {
                // On passe directement le tableau d'options, Quill créera la barre d'outils.
                toolbar: toolbarOptions
            },
            theme: 'snow', // Thème standard propre
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

        // 2. NOUVEAU : Création du wrapper et réorganisation du DOM.
        // Maintenant que Quill a créé l'éditeur (.ql-container) et la barre d'outils (.ql-toolbar),
        // nous les déplaçons dans notre propre wrapper pour un nettoyage fiable.
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('quill-wrapper');
        
        // On insère le wrapper juste avant l'éditeur Quill.
        editorContainer.parentNode.insertBefore(this.wrapper, editorContainer);
        // On déplace la barre d'outils et l'éditeur DANS le wrapper.
        this.wrapper.appendChild(this.quill.getModule('toolbar').container);
        this.wrapper.appendChild(editorContainer);

        // On charge le contenu initial du textarea dans l'éditeur
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
        // NOUVEAU : Logique de nettoyage simplifiée et robuste.
        // On vérifie si notre wrapper a été créé et s'il est toujours dans le DOM.
        if (this.wrapper && this.wrapper.parentNode) {
            // On supprime simplement le wrapper, qui contient l'éditeur et sa barre d'outils.
            this.wrapper.remove();
            
            this.element.style.display = 'block'; // Réafficher le textarea
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
