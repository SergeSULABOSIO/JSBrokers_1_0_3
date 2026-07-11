# Bordereaux

> Import, analyse et validation d'un bordereau, puis facturation via la note liée.

Un **bordereau** est un relevé (généralement reçu d'un assureur ou d'un partenaire)
qui récapitule des affaires et des montants sur une période : primes émises,
commissions dues, etc.

Circuit dans JS Brokers :

1. **Import** : le fichier du bordereau est importé dans l'espace de travail puis
   analysé ligne à ligne (rapprochement avec les clients, risques et avenants
   existants de l'entreprise).
2. **Validation** : une fois l'analyse vérifiée, la validation intègre réellement
   les données (création/mise à jour d'avenants). C'est une opération réelle, pas
   une simulation : elle engage les chiffres de production.
3. **Facturation** : depuis un bordereau VALIDÉ, on peut générer la **note liée**
   (note de débit/crédit) qui matérialise la facturation des montants du bordereau.

Conseils d'assistant : pour répondre sur les bordereaux, les compter/lister via les
outils de données ; la validation et la facturation restent des gestes de
l'utilisateur dans l'interface (proposer d'ouvrir la rubrique, ne jamais laisser
croire que l'assistant valide lui-même).
