import { Marked } from 'marked';
import DOMPurify from 'dompurify';

import { celluleTableau, ligneTableau } from './assistant-markdown-table.js';

/**
 * Rendu Markdown restreint des réponses de l'assistant IA — jamais utilisé
 * sur la saisie utilisateur. Convention des pastilles : la syntaxe standard
 * de lien Markdown est détournée avec un href réservé (#success, #danger,
 * #warning, #info, #neutral) ; tout autre lien est dégradé en texte simple
 * (aucun besoin de lien cliquable dans ce chat interne).
 */
const BADGE_VARIANTES = new Set(['success', 'danger', 'warning', 'info', 'neutral']);

/** Échappe le texte destiné au contenu d'une balise (jamais interprété comme HTML). */
function echapperHtml(texte) {
    return String(texte ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const marked = new Marked({
    gfm: true,
    breaks: true,
    renderer: {
        link({ href, tokens }) {
            const variante = typeof href === 'string' && href.startsWith('#') ? href.slice(1) : null;
            const texte = this.parser.parseInline(tokens);
            return variante && BADGE_VARIANTES.has(variante)
                ? `<span class="aic-md-badge aic-md-badge--${variante}">${texte}</span>`
                : texte;
        },
        heading({ tokens }) {
            return `<p class="aic-md-heading">${this.parser.parseInline(tokens)}</p>`;
        },
        // Alignement GFM et ligne de totaux : la décision vit dans le module PUR
        // assistant-markdown-table.js (testé sans marked ni DOM), ce renderer ne fait
        // que lui passer l'HTML déjà rendu par marked.
        tablecell({ tokens, header, align }) {
            return celluleTableau(this.parser.parseInline(tokens), { header, align });
        },
        tablerow({ text }) {
            return ligneTableau(text);
        },
        code({ text, lang }) {
            // Bloc ```chart / ```graphique : on ne rend PAS le JSON, on dépose un
            // hôte sûr dont le TEXTE porte la spec ; assistant-chart-render.js y
            // montera un <canvas> Chart.js. Le JSON n'est jamais du HTML.
            const langue = (lang || '').trim().toLowerCase();
            if (langue === 'chart' || langue === 'graphique') {
                return `<div class="aic-chart"><code class="aic-chart-spec">${echapperHtml(text)}</code></div>`;
            }
            return `<code>${echapperHtml(text)}</code>`;
        },
    },
});

// `div` : hôte du graphique (voir renderer `code`). Aucun data-*/svg/canvas
// requis — le montage se fait en JS après sanitisation, hors allowlist.
const ALLOWED_TAGS = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'span', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'code'];
const ALLOWED_ATTR = ['class'];

/** Rend un texte Markdown assistant en HTML sûr (sanitisé, allowlist stricte). */
export function renderAssistantMarkdown(texte) {
    const html = marked.parse(String(texte ?? ''));
    return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR });
}
