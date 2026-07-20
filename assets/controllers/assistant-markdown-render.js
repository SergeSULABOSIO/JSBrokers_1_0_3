import { Marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * Rendu Markdown restreint des réponses de l'assistant IA — jamais utilisé
 * sur la saisie utilisateur. Convention des pastilles : la syntaxe standard
 * de lien Markdown est détournée avec un href réservé (#success, #danger,
 * #warning, #info, #neutral) ; tout autre lien est dégradé en texte simple
 * (aucun besoin de lien cliquable dans ce chat interne).
 */
const BADGE_VARIANTES = new Set(['success', 'danger', 'warning', 'info', 'neutral']);

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
    },
});

const ALLOWED_TAGS = ['p', 'strong', 'em', 'ul', 'ol', 'li', 'br', 'span', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'code'];
const ALLOWED_ATTR = ['class'];

/** Rend un texte Markdown assistant en HTML sûr (sanitisé, allowlist stricte). */
export function renderAssistantMarkdown(texte) {
    const html = marked.parse(String(texte ?? ''));
    return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR });
}
