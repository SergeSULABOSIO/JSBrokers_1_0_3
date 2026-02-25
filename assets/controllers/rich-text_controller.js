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

        // Création du conteneur visuel pour l'éditeur
        const editorContainer = document.createElement('div');
        // On définit une hauteur par défaut confortable (similaire à votre classe .editeur-riche)
        editorContainer.style.height = '200px'; 
        editorContainer.style.backgroundColor = '#fff'; // Fond blanc pour l'éditeur
        
        // On insère l'éditeur juste avant le textarea
        this.element.parentNode.insertBefore(editorContainer, this.element);
        
        // On cache le textarea original (qui servira de stockage caché)
        this.element.style.display = 'none';

        // Initialisation de Quill
        this.quill = new Quill(editorContainer, {
            modules: {
                toolbar: toolbarOptions
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
        if (this.quill) {
            const container = this.quill.container.parentNode; // Le wrapper .ql-container
            const toolbar = container.previousSibling; // La barre d'outils .ql-toolbar
            
            if (toolbar && toolbar.classList.contains('ql-toolbar')) {
                toolbar.remove();
            }
            container.remove();
            
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
