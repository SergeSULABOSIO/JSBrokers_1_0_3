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

        // NOUVEAU : Création d'un wrapper global pour l'éditeur et sa barre d'outils.
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('quill-wrapper');
        
        // NOUVEAU : Création explicite du conteneur pour la barre d'outils.
        const toolbarContainer = document.createElement('div');
        this.wrapper.appendChild(toolbarContainer);

        // Création du conteneur pour l'instance Quill.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';
        this.wrapper.appendChild(editorContainer);

        // On insère le wrapper juste avant le textarea.
        if (this.element.parentNode) {
            this.element.parentNode.insertBefore(this.wrapper, this.element);
        }
        
        // On cache le textarea original (qui servira de stockage caché)
        this.element.style.display = 'none';

        // Initialisation de Quill
        this.quill = new Quill(editorContainer, {
            modules: {
                // On indique à Quill d'utiliser notre conteneur pour la barre d'outils.
                toolbar: {
                    container: toolbarOptions
                }
            },
            theme: 'snow', // Thème standard propre
            placeholder: this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...'
        });

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
