import { Controller } from '@hotwired/stimulus';
// Quill et ses dépendances sont vendorisés (importmap.php, assets/vendor/) : aucun CDN.
import Quill from 'quill';
import { texteRicheSur } from './texte-riche.js';
import { estTexteRiche, FORMATS_DE_LA_BARRE, SANS_TABULATION } from './texte-riche-regles.js';
import { evenementInterne } from './evenement-interne.js';

/**
 * @class RichTextController
 * @description Transforme un textarea standard en éditeur de texte riche avec Quill.
 */
export default class extends Controller {
    connect() {
        // La CSS de Quill est posée en <link> par templates/segments/_feuilles_de_l_application.html.twig
        // (servie par nous : la CSP n'admet aucun hôte externe).

        // Configuration de la barre d'outils avec les options demandées (mise en forme, couleur, etc.)
        const toolbarOptions = [
            ['bold', 'italic', 'underline', 'strike'],        // Boutons de style
            [{ 'color': [] }, { 'background': [] }],          // Couleur du texte et surlignage
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],     // Listes
            [{ 'align': [] }],                                // Alignement
            [{ 'header': [1, 2, 3, false] }],                 // Titres
            ['clean']                                         // Bouton pour effacer le formatage
        ];

        // 1. Créer un conteneur pour l'éditeur et l'insérer dans le DOM avant le textarea.
        const editorContainer = document.createElement('div');
        editorContainer.style.height = '200px';
        this.element.parentNode.insertBefore(editorContainer, this.element);

        // UN CHAMP DÉSACTIVÉ RESTE EN LECTURE — fiche ouverte en consultation, notamment.
        // L'éditeur ignorait l'attribut : il offrait sa barre d'outils et se laissait
        // saisir, promettant un enregistrement qui ne viendrait jamais.
        const inerte = this.element.disabled;

        // 2. Initialiser Quill sur ce conteneur.
        // Quill va automatiquement créer la barre d'outils (.ql-toolbar) comme un frère juste avant l'éditeur.
        this.quill = new Quill(editorContainer, {
            // Tab et Maj+Tab SORTENT de l'éditeur (aucun piège clavier) : cf. SANS_TABULATION.
            modules: { toolbar: inerte ? false : toolbarOptions, keyboard: { bindings: SANS_TABULATION } },
            // LES FORMATS DE LA BARRE D'OUTILS, ET EUX SEULS. Sans cette liste, un COLLAGE
            // produisait tout ce que Quill sait faire — lien, image, vidéo, code — que
            // l'assainissement du serveur retire ensuite : mieux vaut ne pas l'offrir.
            formats: FORMATS_DE_LA_BARRE,
            theme: 'snow',
            readOnly: inerte,
            placeholder: inerte ? '' : (this.element.getAttribute('placeholder') || 'Saisissez votre texte ici...')
        });

        // 3. Charger le contenu initial du textarea dans l'éditeur.
        // ASSAINI, OU POSÉ COMME DU TEXTE. Le contenu stocké était injecté tel quel en HTML :
        // `<img onerror=…>` enregistré par une autre porte (POST direct, import) s'exécutait
        // à l'ouverture de la fiche. Le HTML de l'éditeur passe par la liste blanche
        // (texteRicheSur) ; un texte simple — écrit par Ket ou un import — reste du texte.
        const valeur = this.element.value;
        if (estTexteRiche(valeur)) {
            this.quill.root.innerHTML = texteRicheSur(valeur);
        } else {
            this.quill.setText(valeur);
        }

        // 4. Cacher le textarea original, qui ne sert plus que de stockage de données.
        this.element.style.display = 'none';

        // 5. Synchroniser le contenu de l'éditeur avec le textarea caché à chaque changement.
        this.quill.on('text-change', () => {
            this.element.value = this.quill.root.innerHTML;
            // On déclenche manuellement l'événement 'change' pour que les formulaires Symfony le détectent.
            this.element.dispatchEvent(evenementInterne('change'));
        });
    }

    disconnect() {
        // La méthode de nettoyage est volontairement laissée vide pour garantir que l'éditeur s'affiche.
        // Nous reviendrons sur le nettoyage dans un second temps, une fois la fonctionnalité restaurée.
    }
}
