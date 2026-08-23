# Rétrocommissions : qui se partage la commission

> Deux bénéficiaires, deux assiettes, deux circuits de paiement — et un ordre de service qu'il ne faut jamais inverser.

Quand une affaire rapporte une commission au cabinet, deux familles de personnes
peuvent en recevoir une part. Elles ne se ressemblent pas, et les confondre coûte
cher.

| | **Partenaire externe** | **Agent interne** |
|---|---|---|
| Qui | Une société d'intermédiation (l'écran dit « Intermédiaires ») | Un collaborateur du cabinet |
| Assiette | La commission pure des revenus **partageables** | Ce qui **reste** après les partenaires |
| Se sert | **En premier** | Sur le reliquat |
| Paiement | **Note de crédit**, payée au fil des règlements | **Virement direct**, sans aucune note |
| Comptabilité | SYSCOHADA **632** (rétrocommissions) | SYSCOHADA **6611** (charges de personnel) |
| Maille du payé | La **tranche** (prorata des notes) | L'**avenant** (lecture directe) |

L'ordre est le cœur de la mécanique : le partenaire prélève sa part sur la
commission pure, puis l'agent applique **son** taux à ce qui subsiste. Un même
euro ne peut donc pas être rétrocédé deux fois.

## Un bénéficiaire se désigne par son NOM

`retrocommissions` accepte « Serge SULA » comme « SUNU Courtage » : il n'y a
aucun identifiant à aller chercher ailleurs, et l'outil dit lui-même quand un nom
en désigne plusieurs.

Attention au vocabulaire : **un agent n'est pas un intermédiaire**. Les agents
n'ont pas de rubrique dans le menu — ils relèvent de la gestion des invités — donc
chercher leur nom parmi les « Intermédiaires » ne donnera jamais rien.

## Dû, payé, solde, exigible

- **Dû** : naît à la **souscription** de l'affaire. Une proposition sans avenant
  n'est qu'une projection : elle ne doit jamais entrer dans un montant dû.
- **Payé** : ce qui a effectivement été versé (ou réglé, côté partenaire).
- **Solde** : dû − payé, jamais négatif — un trop-versé n'est pas une dette.
- **Exigible** : le solde **réclamable**, c'est-à-dire seulement une fois que le
  cabinet a lui-même encaissé. Ne jamais proposer de verser un montant non
  exigible : ce serait avancer sa trésorerie sur une créance non recouvrée.

## Le bénéficiaire n'est pas le gestionnaire

Celui qui **apporte** l'affaire et celui qui la **suit** sont deux rôles
indépendants. Un agent peut apporter dix affaires et n'en gérer aucune. Ne jamais
déduire l'un de l'autre — la colonne `gestionnaire` du décompte est là pour
rappeler la différence.

## Déclarer qu'une affaire vient de l'effort d'un agent

Une affaire ne rapporte à un agent que si une **condition de partage à son nom** y est
rattachée. Sans ce rattachement, l'affaire est réputée gagnée par le **cabinet seul** — c'est
le cas par défaut, et c'est ce qui explique la plupart des « pourquoi Alice ne touche rien ? ».

Le rattachement s'écrit **sur l'AFFAIRE (la piste)**, toujours. Mais il peut s'ORDONNER
depuis n'importe quel objet de son arbre : une police, une proposition, une tranche. C'est
`effort_commercial_agent` qui le fait, et le serveur remonte lui-même à l'affaire.

Quatre règles, et elles valent pour l'écran comme pour toi :

- **Une affaire, un agent bénéficiaire.** Rattacher à une affaire déjà prise est refusé.
- **Pour changer d'agent : détacher d'abord**, puis rattacher. Il n'y a pas de remplacement.
- **Un versement scelle l'affaire.** Dès qu'une rétrocommission a été VERSÉE, plus rien ne
  se détache — donc plus rien ne se change. On ne réécrit pas un décaissement comptabilisé.
- **En lot, c'est tout ou rien.** Plusieurs affaires d'un geste, mais si une seule est déjà
  prise, rien n'est écrit — et le refus la nomme.

N'écris JAMAIS ce rattachement par `preparer_operations` : tu devrais deviner le champ et
l'affaire, et les règles ci-dessus te refuseraient de toute façon.
## D'où vient le taux

Pour un partenaire, la cascade est : **condition propre à l'affaire** ▸
**condition du partenaire** ▸ **sa part habituelle**. Sous son seuil, une
condition ne partage **rien** — et il n'y a pas de repli sur le taux par défaut.
Pour un agent, c'est la **première condition applicable** parmi les siennes.

Le décompte détaillé rend `condition`, `taux`, `origineDuTaux`, `assiette`,
`seuilFranchi` et `uniteMesure` : de quoi **justifier** un montant contesté au
lieu de l'affirmer. Ne jamais illustrer un calcul avec un taux inventé — celui de
la ligne est là.

## Verser : d'où sort l'argent

Un reversement n'est pas qu'un montant, c'est une **sortie de fonds** — elle part
d'un compte. Par défaut, du **compte proposé** par le cabinet (le premier de ses
comptes bancaires), exactement comme l'écran de reversement le présélectionne :
un reversement passe par la banque dans la règle, par la caisse par exception.

Donc, avec `signaler_reversement_retro_agent` :

- l'utilisateur ne dit rien du compte → **ne rien préciser** : le compte proposé
  s'applique, et c'est le bon dans la quasi-totalité des cas ;
- il nomme une banque → `compteBancaireId` de ce compte ;
- il dit « en espèces », « de la caisse », « en cash » → `compteBancaireId: 0`,
  et la sortie est comptabilisée en caisse.

Ne jamais annoncer « versé en espèces » quand rien ne l'a été demandé : ce serait
décrire une écriture de caisse là où le cabinet a fait un virement.

**UN VERSEMENT NE S'ENREGISTRE PAS SANS JUSTIFICATIF.** C'est une sortie de fonds
réelle : sans bordereau de virement (ou reçu signé), c'est un montant que rien ne
rattache à la banque. La règle vaut à l'écran comme ici — l'assistant n'en est pas
dispensé, sinon « paie Alice » suffirait à s'en affranchir.

En pratique : `signaler_reversement_retro_agent` exige `fichierId`, la pièce jointe de
la conversation. Si l'utilisateur n'en a joint aucune, la lui demander AVANT d'appeler
l'outil — il refusera sinon, et le dira. **Une seule pièce suffit pour tout le
virement**, même s'il solde plusieurs affaires : elle est enregistrée une fois et
justifie chacune des lignes.

Pour joindre une pièce à un virement DÉJÀ enregistré, c'est `attacher_fichier` sur le
reversement (il se désigne par sa référence).

**Où se consultent les versements.** Dans la rubrique « Reversements de
rétrocommission » (module Finances) — et nulle part ailleurs : le volet du rapport de
production n'existe plus. `ouvrir_rubrique` sait l'ouvrir FILTRÉE :
`beneficiaire` (le NOM d'un agent), `justificatif` (avec/sans pièce), `periode` et
`virement` (groupé/isolé). Ce sont exactement les chips de l'écran, et le même
vocabulaire : si ta réponse écrite ne porte que sur les versements d'une personne,
ouvre la rubrique avec le MÊME filtre.

Une pièce compte pour tout son VIREMENT : un bordereau posé sur une ligne d'un lot
justifie les autres. Ne dis donc jamais d'une ligne d'un virement groupé qu'elle est
sans justificatif au seul motif que le fichier est accroché à sa voisine.

Côté **partenaire**, il n'y a rien de tel : sa rétrocommission se facture par
**note de crédit**, et l'application n'a aucun circuit de versement direct — donc
aucun outil non plus. Le dire, plutôt que de proposer un reversement d'agent pour
un intermédiaire externe.
## Recettes

- « À qui dois-je de la rétrocommission ? » → `retrocommissions` sans bénéficiaire.
- « Le décompte de ce qui est dû à Serge SULA » → `retrocommissions`
  (`beneficiaire`, `detail: par_ligne`).
- « Sa rétro par assureur / par mois » → `detail: par_axe`, `axe` au choix.
- « Et en 2026 seulement ? » → `du` / `au` (bornes sur la date d'effet).
- « Verse-lui ce qu'on lui doit » → `signaler_reversement_retro_agent` (agents
  uniquement ; un partenaire se facture par note de crédit).
