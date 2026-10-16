/**
 * LE TEXTE RICHE : seuls les attributs que la barre d'outils de Quill produit survivent.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BALISES, classesAdmises, estTexteRiche, listeAdmise, styleAdmis } from '../../assets/controllers/texte-riche-regles.js';

test('les balises de la barre d\'outils, et elles seules', () => {
    assert.deepEqual(BALISES, ['p', 'br', 'strong', 'em', 'u', 's', 'ol', 'ul', 'li', 'h1', 'h2', 'h3', 'span']);
    for (const interdite of ['a', 'img', 'iframe', 'script', 'svg', 'style', 'div']) {
        assert.ok(!BALISES.includes(interdite), interdite);
    }
});

test('style : la couleur et le fond, rien d\'autre', () => {
    assert.equal(styleAdmis('color: rgb(230, 0, 0); background-color: #ffff00'), 'color: rgb(230, 0, 0); background-color: #ffff00');
    assert.equal(styleAdmis('position: fixed; top: 0; color: red'), '', 'un nom de couleur ou un positionnement ne passent pas');
    assert.equal(styleAdmis('background: url(javascript:alert(1))'), '');
    assert.equal(styleAdmis('color: rgb(1,2,3); width: 9999px'), 'color: rgb(1,2,3)');
    assert.equal(styleAdmis(null), '');
});

test('class : celles de Quill seulement', () => {
    assert.equal(classesAdmises('ql-align-center ql-ui'), 'ql-align-center ql-ui');
    assert.equal(classesAdmises('modal show ql-indent-1'), 'ql-indent-1');
    assert.equal(classesAdmises(''), '');
});

test('data-list : ordered ou bullet', () => {
    assert.equal(listeAdmise('bullet'), 'bullet');
    assert.equal(listeAdmise('ordered'), 'ordered');
    assert.equal(listeAdmise('x" onmouseover="y'), '');
});

test('le HTML de l\'éditeur se reconnaît à son bloc ; un texte simple reste du texte', () => {
    assert.equal(estTexteRiche('<p>Clients <strong>prioritaires</strong></p>'), true);
    assert.equal(estTexteRiche('<ol><li data-list="bullet">a</li></ol>'), true);
    assert.equal(estTexteRiche('  <h2>Titre</h2>'), true);
    assert.equal(estTexteRiche('Groupe <img src=x onerror=alert(1)>'), false, 'un nom piégé n\'est pas du texte riche');
    assert.equal(estTexteRiche('<img src=x onerror=alert(1)>'), false);
    assert.equal(estTexteRiche('R&D < 10'), false);
    assert.equal(estTexteRiche(42), false);
});
