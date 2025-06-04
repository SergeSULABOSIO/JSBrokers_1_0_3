import { Controller } from '@hotwired/stimulus';
import tinymce from 'tinymce'; // Importé via importmap.php

export default class extends Controller {
    connect() {
        console.log('TinyMCE Stimulus Controller connected (AssetMapper)!');

        const editorId = this.element.id || `tinymce-${Date.now()}`;
        this.element.id = editorId;

        tinymce.init({
            target: this.element,
            height: 300,
            menubar: false,
            // TinyMCE cherchera ces ressources dans le dossier 'assets'
            // et AssetMapper va les trouver grâce à la configuration.
            // Assure-toi que le chemin `location.origin + '/assets/vendor/tinymce/'`
            // correspond bien à l'URL réelle de tes dossiers skins/plugins.
            // Ou simplement, pour des setups simples, TinyMCE peut les trouver
            // si les dossiers sont à la racine de 'assets'.

            // La meilleure approche est souvent de laisser TinyMCE gérer son chemin de base.
            // Par défaut, si `tinymce.min.js` est à `public/assets/tinymce.min.js`,
            // il essaiera de trouver `public/assets/skins/ui/oxide/skin.min.css`.
            // Donc il faut que tes assets soient copiés dans `public/assets/` avec leur structure.

            // Si tu utilises le `importmap:require tinymce`, le fichier `tinymce.min.js`
            // sera accessible via une URL générée par AssetMapper (ex: `/assets/tinymce-somehash.js`).
            // TinyMCE essaiera de charger ses ressources à partir de l'URL de ce fichier.
            // C'est là que ça peut être délicat avec AssetMapper.

            // C'est pourquoi un bundle Symfony dédié à TinyMCE (s'il en existe un
            // compatible AssetMapper) serait idéal, car il gère cette complexité.

            // **Essaye ceci pour les chemins, c'est le plus commun avec AssetMapper:**
            // Laisse TinyMCE deviner le chemin de base s'il est au même niveau
            // que les dossiers 'skins', 'plugins', etc.
            // Si `tinymce.min.js` est à `/assets/tinymce.min.js`,
            // et que tes dossiers `skins`, `plugins`, etc., sont aussi dans `/assets/`,
            // TinyMCE devrait les trouver.

            // Si tu as copié `skins`, `plugins` dans `assets/vendor/tinymce`,
            // alors tu dois configurer TinyMCE pour pointer vers ce dossier.
            // L'URL de base pour les assets de TinyMCE, telle qu'exposée par AssetMapper.
            // Il faut que 'public/assets/vendor/tinymce' contienne 'skins', 'plugins', etc.
            // Si tu as mappé '%kernel.project_dir%/vendor/tinymce/tinymce' comme asset,
            // alors TinyMCE cherchera ses ressources via l'URL générée par AssetMapper pour ce chemin.

            // C'est souvent le point de friction avec AssetMapper et les libs comme TinyMCE.
            // Si `tinymce.min.js` est mappé à `/assets/tinymce-xxxx.js`,
            // et que les `skins` sont mappés à `/assets/skins/ui/oxide/skin-yyyy.css`,
            // TinyMCE a du mal à le trouver automatiquement.

            // **Alternative : Utiliser un bundle qui gère TinyMCE pour AssetMapper**
            // Je te recommande de chercher un bundle Symfony existant qui gère l'intégration
            // de TinyMCE avec AssetMapper. Par exemple, une recherche rapide peut montrer
            // des bundles comme `EmilePerron/tinymce-bundle` (mentionné dans une recherche),
            // qui pourrait avoir une option pour AssetMapper.

            // Si tu n'utilises PAS de bundle Symfony dédié et que tu veux tout faire à la main
            // avec AssetMapper, le plus simple est de copier les dossiers `skins`, `plugins`, `themes`
            // de `vendor/tinymce/tinymce` vers un dossier comme `public/tinymce_assets`
            // et ensuite de dire à TinyMCE de les charger depuis ce dossier :

            // Dans le contrôleur Stimulus:
            base_url: '/tinymce_assets', // Assure-toi que public/tinymce_assets existe et contient skins, plugins, themes
            suffix: '.min',
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
                'searchreplace', 'visualblocks', 'code', 'fullscreen', 'insertdatetime', 'media',
                'table', 'paste', 'wordcount', 'help', 'emoticons'
            ],
            toolbar: 'undo redo | formatselect | bold italic backcolor | \
                      alignleft aligncenter alignright alignjustify | \
                      bullist numlist outdent indent | removeformat | link image media | code fullscreen | help',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            // skin: 'oxide', // Si tes skins sont à /tinymce_assets/skins/ui/oxide
            // content_css: 'default', // Si tes content_css sont à /tinymce_assets/skins/ui/oxide
        });
    }

    disconnect() {
        const editor = tinymce.get(this.element.id);
        if (editor) {
            editor.destroy();
        }
    }
}