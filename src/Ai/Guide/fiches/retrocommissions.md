# Rétrocommissions : qui se partage la commission

> Deux bénéficiaires, deux assiettes, UN seul circuit de paiement — et un ordre de service qu'il ne faut jamais inverser.

Quand une affaire rapporte une commission au cabinet, deux familles de personnes
peuvent en recevoir une part. Elles ne se ressemblent pas, et les confondre coûte
cher.

| | **Partenaire externe** | **Agent interne** |
|---|---|---|
| Qui | Une société d'intermédiation (l'écran dit « Intermédiaires ») | Un collaborateur du cabinet |
| Assiette | La commission pure des revenus **partageables** | Ce qui **reste** après les partenaires |
| Se sert | **En premier** | Sur le reliquat |
| Paiement | **Reversement direct**, sur sa note de débit | **Reversement direct**, sans aucune note |
| Comptabilité | SYSCOHADA **632** (rétrocommissions) | SYSCOHADA **6611** (charges de personnel) |
| Maille du payé | La **tranche** | La **tranche** |

Le **circuit de paiement est le même pour les deux** : le partenaire envoie sa note de
débit, le cabinet lui reverse et garde la pièce — exactement comme pour un agent. Seules
l'assiette et le compte comptable diffèrent. Il en allait autrement avant : le partenaire
se facturait par note de crédit, et son payé s'en déduisait au prorata. Ce circuit a été
retiré, et l'historique converti en reversements.

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
- **Exigible** : la part du solde **réclamable**, c'est-à-dire née de ce que le
  cabinet a lui-même **encaissé** — et elle est **PROPORTIONNELLE**. Sur une
  échéance dont 60 % de la commission est rentrée, 60 % de la rétro est exigible ;
  le reste suivra à mesure. Ne jamais proposer de verser au-delà de l'exigible :
  ce serait avancer sa trésorerie sur une créance non recouvrée. Mais dès que
  l'argent d'une échéance est rentré, **il faut payer** : c'est le sens même de la
  règle, et l'exigible le dit au centime.

  Ce qui compte est l'encaissement de **l'ÉCHÉANCE**, pas celui de l'affaire
  entière — et il compte quel que soit le circuit : note réglée, ou bordereau de
  production imputé sur cette échéance.

### La chaîne d'exigibilité, en deux temps

L'argent ne devient réclamable qu'en descendant deux marches, jamais une seule :

1. **La commission du cabinet devient exigible dès que le CLIENT a réglé la prime.**
   Tant que la prime dort, le cabinet n'a rien à réclamer à l'assureur : sa
   commission est due, elle n'est pas encore exigible.
2. **Les TAXES et les RÉTROCOMMISSIONS assises sur cette commission deviennent
   exigibles dès que le CABINET a encaissé la commission.** Ce sont des charges
   nées de la commission : elles ne peuvent pas être réclamables avant elle.

Autrement dit : prime réglée → commission encaissable ; commission encaissée →
taxes et rétros exigibles. Et chaque marche est **proportionnelle** : une prime
réglée à moitié ne rend exigible que la moitié de la commission, et une commission
encaissée à 60 % ne rend exigibles que 60 % des rétros qu'elle porte.

⚠ **Ne jamais sauter la première marche.** Dire d'une rétro qu'elle est exigible
parce que la police est souscrite, ou parce que la prime est facturée, c'est
proposer au courtier de payer avec de l'argent qu'il n'a pas reçu. Le montant
**exigible** est le seul qui tienne compte des deux marches : c'est lui qu'il faut
lire, jamais le « dû ».

## Le bénéficiaire n'est pas le gestionnaire

Celui qui **apporte** l'affaire et celui qui la **suit** sont deux rôles
indépendants. Un agent peut apporter dix affaires et n'en gérer aucune. Ne jamais
déduire l'un de l'autre — la colonne `gestionnaire` du décompte est là pour
rappeler la différence.

## Rattacher une condition de partage à des affaires

Une affaire ne rapporte à quelqu'un que si une **condition de partage à son nom** y est
rattachée. Sans rattachement, l'affaire est réputée gagnée par le **cabinet seul** — c'est
le cas par défaut, et c'est ce qui explique la plupart des « pourquoi Alice ne touche rien ? ».

**Les DEUX familles se rattachent du même geste.** Tu ne choisis jamais une famille : tu
choisis une CONDITION, et elle porte déjà la sienne. « Ces trois affaires relèvent de
l'accord SUNU 20 % » et « ces trois affaires viennent de l'effort d'Alice » sont le même
appel, avec une condition différente.

Le rattachement s'écrit **sur l'AFFAIRE (la piste)**, toujours. Mais il peut s'ORDONNER
depuis n'importe quel objet de son arbre : une police, une proposition, une tranche. C'est
`effort_commercial_agent` qui le fait, et le serveur remonte lui-même à l'affaire.

Quatre règles, et elles valent pour l'écran comme pour toi :

- **Une affaire, un bénéficiaire PAR FAMILLE.** Un apporteur externe ET un agent interne
  peuvent donc coexister sur la même affaire — c'est même la mécanique normale : le
  partenaire se sert d'abord, l'agent partage le reliquat. Ce qui est refusé, c'est un
  SECOND bénéficiaire du même camp.
- **Pour changer : détacher d'abord**, puis rattacher. Il n'y a pas de remplacement.
- **Un versement scelle l'affaire.** Dès qu'une rétrocommission a été VERSÉE, plus rien ne
  se détache — donc plus rien ne se change. On ne réécrit pas un décaissement comptabilisé.
  Vaut pour les deux familles, depuis que le partenaire se règle par reversement.
- **En lot, c'est tout ou rien.** Plusieurs affaires d'un geste, mais si une seule refuse,
  rien n'est écrit — et le refus la nomme.

**Une règle DE PLUS pour un partenaire, et elle n'a pas d'équivalent côté agent.** L'agent
est nommé PAR sa condition ; l'apporteur, lui, est désigné par l'AFFAIRE, et la condition ne
fait que moduler son taux. Donc :

- l'affaire n'a **aucun** apporteur → le rattachement le **DÉSIGNE** du même geste. C'est
  une écriture de plus dans le plan (`partenaire`), et il faut la **DIRE** : elle change à
  qui revient l'argent, on ne la laisse pas découvrir ;
- l'affaire est déjà apportée par **quelqu'un d'autre** → **REFUS**, en nommant les deux.
  Rattacher la condition d'un autre écrirait une règle que le calcul écarterait en silence.

Détacher ne retire jamais l'apporteur : seule la règle de rémunération est défaite, et sa
part habituelle reprend ses droits.

N'écris JAMAIS ce rattachement par `preparer_operations` : tu devrais deviner le champ et
l'affaire, et les règles ci-dessus te refuseraient de toute façon — pour les deux familles.
## D'où vient le taux

Pour un partenaire, la cascade est : **condition propre à l'affaire** ▸ **condition
RATTACHÉE à l'affaire** ▸ **condition du partenaire** ▸ **sa part habituelle**. Ce qui est
porté par l'affaire l'emporte sur ce qui est porté par le partenaire ; entre les deux
étages de l'affaire, la condition propre passe d'abord — elle a été écrite POUR celle-là,
quand la rattachée sert aussi ailleurs. Sous son seuil, une condition ne partage **rien** —
et il n'y a pas de repli sur le taux par défaut.

Une condition rattachée ne paie que si elle nomme l'apporteur **du jour** : si
l'intermédiaire de l'affaire change ensuite, elle cesse de s'appliquer plutôt que de payer
le nouveau au taux de l'ancien.

⚠ **UNE PORTÉE QUI DIFFÈRE ENTRE LES DEUX FAMILLES.** Une condition d'AGENT reste sans
effet tant qu'on ne l'a pas rattachée : c'est le rattachement qui la met en service. Une
condition d'INTERMÉDIAIRE, elle, s'applique à **toutes** les affaires qu'il apporte dès
qu'elle le nomme — le rattachement ne la restreint pas. Sur une affaire qui ne désigne
aucun apporteur, il sert à le DÉSIGNER ; il ne sert pas à choisir « ces trois affaires-ci
seulement ». Ne promets donc jamais à l'utilisateur qu'un rattachement limitera la portée
d'une condition de partenaire : ce serait faux.
Pour un agent, c'est la **première condition applicable** parmi les siennes.

Le décompte détaillé rend `condition`, `taux`, `origineDuTaux`, `assiette`,
`seuilFranchi` et `uniteMesure` : de quoi **justifier** un montant contesté au
lieu de l'affirmer. Ne jamais illustrer un calcul avec un taux inventé — celui de
la ligne est là.

## La production d'un intermédiaire

**Elle a sa rubrique** : « Production intermédiaires », dans le groupe Production. Elle
montre, affaire par affaire, ce qu'un agent interne ou un partenaire externe apporte au
cabinet — la prime du client et son règlement, la commission et son encaissement, puis la
rétrocommission due, versée, restante et exigible.

Elle remplace le rapport de production, qui ne s'ouvrait que depuis une fiche. **Ne parle
donc plus d'ouvrir « le rapport de production » comme d'un écran à part** : c'est une
rubrique, et elle s'ouvre comme les autres.

`ouvrir_rubrique` la connaît, avec ses trois filtres :

- `beneficiaire` — le NOM d'un agent **ou** d'un partenaire. C'est lui qui décide de ce
  qu'on voit : **sans bénéficiaire, la rubrique s'ouvre VIDE** et invite à en choisir un.
  Ne l'ouvre donc jamais « pour voir » : ouvre-la sur quelqu'un.
- `type` — agent ou partenaire, pour lever une homonymie. Il s'aligne tout seul sur la
  famille du bénéficiaire nommé.
- `validation` — souscrites *(par défaut)*, en_attente, caduques. Le même mot que pour les
  propositions, et la même partition.

⚠ **TU NE PEUX PAS « RECHERCHER » DANS CETTE RUBRIQUE**, et ce n'est pas un oubli :
`rechercher_entites` et `indicateur_calcule` interrogent des enregistrements, or les lignes
de cette rubrique sont CALCULÉES — une affaire y figure parce que le moteur de partage lui
reconnaît une part. Pour répondre en chiffres, c'est **`retrocommissions`** qu'il faut
appeler : il lit exactement le même rapport, avec ses lignes et ses totaux. Ouvre la
rubrique pour MONTRER, appelle `retrocommissions` pour DIRE.

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
virement**, même s'il solde plusieurs échéances : elle est enregistrée une fois et
justifie chacune des lignes.

Pour joindre une pièce à un virement DÉJÀ enregistré, c'est `attacher_fichier` sur le
reversement (il se désigne par sa référence).

**Où se consultent les versements.** Dans la rubrique « Rétros intermédiaires »
(module Finances) — et nulle part ailleurs : le volet du rapport de production n'existe
plus. Elle porte les DEUX familles. `ouvrir_rubrique` sait l'ouvrir FILTRÉE :
`beneficiaire` (le NOM d'un agent **ou** d'un partenaire), `type` (agent/partenaire),
`justificatif` (avec/sans pièce), `periode` et `virement`. Ce sont exactement les chips
de l'écran, et le même vocabulaire : si ta réponse écrite ne porte que sur les
versements d'une personne, ouvre la rubrique avec le MÊME filtre.

⚠ **UNE LIGNE DE CETTE RUBRIQUE EST UN VIREMENT, PAS UNE ÉCHÉANCE.** L'écran replie
chaque lot sur une seule ligne, qui porte le TOTAL du virement et le nombre d'échéances
qu'il règle. C'est ce que fait le chip `virement` :

- rien, ou « groupé » (le défaut) : **un versement par ligne**, tel qu'il a été fait
  au bénéficiaire ;
- `virement: detail` : la même chose **ventilée par échéance** (tranche).

Les deux modes portent le **même argent** — le total ne change pas d'un mode à l'autre,
seule la maille change. Ne dis donc jamais « il y a dix versements » en comptant des
lignes de détail : compte les VIREMENTS, ou précise la maille dont tu parles.

**Corriger un virement** se fait dans la fenêtre de reversement, ouverte par « Éditer »
sur sa ligne — on y change la date, la référence, le compte, les montants, les échéances
couvertes et la pièce. « Ouvrir », lui, montre la fiche.

**Supprimer une ligne défait TOUT le virement** : ses N échéances partent ensemble, et
son écriture comptable est défaite. Ne propose jamais de « supprimer une échéance d'un
virement » — cela n'existe pas ; on retire l'échéance depuis la fenêtre d'édition.

Une pièce compte pour tout son VIREMENT : un bordereau posé sur une ligne d'un lot
justifie les autres. Ne dis donc jamais d'une ligne d'un virement groupé qu'elle est
sans justificatif au seul motif que le fichier est accroché à sa voisine.

**Le partenaire se règle par le MÊME outil**, avec `partenaireId` au lieu de `agentId` —
jamais les deux. Son justificatif naturel est **sa note de débit**. La garde diffère : payer
un agent demande de gérer les invités (personne ne se paie soi-même), payer un partenaire
demande le droit d'écriture sur la rubrique — la même règle qu'à l'écran.
## Recettes

- « À qui dois-je de la rétrocommission ? » → `retrocommissions` sans bénéficiaire.
- « Le décompte de ce qui est dû à Serge SULA » → `retrocommissions`
  (`beneficiaire`, `detail: par_ligne`).
- « Sa rétro par assureur / par mois » → `detail: par_axe`, `axe` au choix.
- « Et en 2026 seulement ? » → `du` / `au` (bornes sur la date d'effet).
- « Ces trois affaires viennent de l'accord SUNU » → `effort_commercial_agent`
  (`action: rattacher`, la condition par son NOM, les `cibles`). Même appel pour un
  agent : c'est la condition qui porte la famille.
- « Que puis-je payer maintenant ? » → `retrocommissions` : c'est la colonne
  **exigible** qui répond, jamais le « dû ». Elle suit l'encaissement échéance par
  échéance, au prorata.
- « Verse-lui ce qu'on lui doit » → `signaler_reversement_retro_agent`, avec `agentId`
  OU `partenaireId`. Sans `lignes`, tout l'exigible est réglé ; avec, une entrée par
  `trancheId` (l'échéance), ou un `avenantId` pour régler toutes celles d'une police.
