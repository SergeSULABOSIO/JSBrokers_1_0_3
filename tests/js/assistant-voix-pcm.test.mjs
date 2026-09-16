/**
 * Tests du cœur PUR de la voix de Ket (assets/controllers/assistant-voix-pcm.js).
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ENTETE_WAV,
    modeDeLecture,
    pcm16VersFloat32,
    sauterOctets,
} from '../../assets/controllers/assistant-voix-pcm.js';

test('PCM 16 bits signé petit-boutiste → flottants', () => {
    // 0, 16384 (0,5), -32768 (-1), 32767 (~1)
    const { echantillons, reste } = pcm16VersFloat32(Uint8Array.from([0x00, 0x00, 0x00, 0x40, 0x00, 0x80, 0xff, 0x7f]));
    assert.equal(reste, null);
    assert.deepEqual(Array.from(echantillons), [0, 0.5, -1, 32767 / 32768]);
});

test('un échantillon coupé entre deux morceaux réseau est recollé', () => {
    const premier = pcm16VersFloat32(Uint8Array.from([0x00, 0x40, 0x00]));
    assert.deepEqual(Array.from(premier.echantillons), [0.5]);
    assert.deepEqual(Array.from(premier.reste), [0x00]);

    const second = pcm16VersFloat32(Uint8Array.from([0xc0]), premier.reste); // 0xC000 = -16384
    assert.deepEqual(Array.from(second.echantillons), [-0.5]);
    assert.equal(second.reste, null);
});

test('morceau vide ou absent : aucun échantillon', () => {
    assert.equal(pcm16VersFloat32(new Uint8Array(0)).echantillons.length, 0);
    assert.equal(pcm16VersFloat32(null).echantillons.length, 0);
});

test('l’en-tête WAV est sauté, même réparti sur plusieurs morceaux', () => {
    let etat = sauterOctets(new Uint8Array(30), ENTETE_WAV);
    assert.equal(etat.octets.length, 0);
    assert.equal(etat.aSauter, 14);

    etat = sauterOctets(Uint8Array.from({ length: 18 }, (_, i) => i), etat.aSauter);
    assert.deepEqual(Array.from(etat.octets), [14, 15, 16, 17]);
    assert.equal(etat.aSauter, 0);

    const intact = Uint8Array.from([1, 2]);
    assert.equal(sauterOctets(intact, 0).octets, intact);
});

test('le mode de lecture suit la réponse du serveur', () => {
    assert.equal(modeDeLecture(200, 'audio/L16; rate=24000; channels=1'), 'flux');
    assert.equal(modeDeLecture(200, 'audio/wav'), 'wav');
    assert.equal(modeDeLecture(503, 'application/json'), 'repli');
    assert.equal(modeDeLecture(402, 'application/json'), 'repli');
    assert.equal(modeDeLecture(200, 'text/html'), 'repli', 'une page d’erreur n’est pas de l’audio');
});
