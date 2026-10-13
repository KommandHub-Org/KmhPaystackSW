# 0.9.0-beta.3

Troisième version préliminaire. Toujours destinée au développement interne, à
l'assurance qualité et aux tests sandbox/staging ; pas encore soumise au
Shopware Store.

**Note de mise à jour : le plugin a été renommé.** Son nom technique est passé de `KommandhubPaystackSW` à `KmhPaystackSW` ; Shopware le considère donc comme un autre plugin et les installations de `0.9.0-beta.1` / `0.9.0-beta.2` ne sont pas mises à jour sur place. Installez `KmhPaystackSW`, saisissez à nouveau vos clés API et réglages Paystack (ils se trouvent désormais sous le domaine `KmhPaystackSW.config.*`), puis supprimez l'ancien plugin. L'ensemble de champs personnalisés et les champs bancaires ont aussi été renommés (`kmh_paystack_*`) ; les coordonnées bancaires enregistrées par les versions précédentes ne sont pas migrées.

- Les nouvelles installations sont immédiatement utilisables : le moyen de paiement Paystack est affecté à tous les canaux de vente lors de sa première création, et le mode sandbox est activé par défaut, de sorte qu'une nouvelle installation n'appelle jamais Paystack en production tant qu'il n'est pas désactivé. Une mise à jour du plugin ne réaffecte jamais le moyen de paiement à un canal dont il a été retiré.
- Une carte refusée est désormais enregistrée comme un paiement échoué et non comme un paiement annulé. Lorsque Paystack renvoie le client sans référence (annulation/retour, certains échecs), le plugin vérifie la référence enregistrée lors de la commande et laisse le statut réel de Paystack décider : les paiements échoués échouent, les paiements abandonnés sont annulés, les paiements réussis sont marqués comme payés et les paiements en attente restent ouverts.
- Un webhook `charge.success` tardif pour une tentative de paiement antérieure est désormais rattaché à sa commande. Chaque nouvelle tentative écrase la référence enregistrée ; l'identifiant de la transaction de commande est donc aussi envoyé dans les métadonnées Paystack et utilisé comme recherche de secours. Le paiement est toujours vérifié auprès de Paystack avant que la commande soit marquée comme payée.
- Documentation : comportement à la première installation, obligation que la devise du paiement soit activée sur votre compte Paystack, et dépannage pour « Insufficient Funds » en sandbox sur les montants élevés et pour le libellé par défaut des e-mails de statut de Shopware.

# 0.9.0-beta.2

Deuxième version préliminaire. Toujours destinée au développement interne, à
l'assurance qualité et aux tests sandbox/staging ; pas encore soumise au
Shopware Store.

- La journalisation de débogage est désormais résolue par canal de vente. `enableDebugging` et les niveaux de journalisation sont des réglages de plugin ordinaires, configurables par canal de vente ; ils n'étaient auparavant lus qu'au niveau global, de sorte que l'activation sur un seul canal restait sans effet.
- Les blocs de template de la vérification bancaire côté storefront sont désormais préfixés et n'entrent plus en conflit avec d'autres plugins étendant les mêmes templates de compte.
- Passage de la licence MIT à la licence Apache 2.0 : concession de brevet explicite, réserve explicite des droits de marque et fichier `NOTICE` transmettant l'attribution aux forks.
- Le plugin indique désormais clairement qu'il s'agit d'une intégration indépendante et tierce, non affiliée à Paystack ni approuvée par Paystack. Ajout d'une politique de marque et d'image (`TRADEMARKS.md`).
- Les logos tiers utilisés comme icônes du plugin et de l'administration ont été remplacés par l'identité visuelle KommandHub.
- Outils de build : `make prepare` fonctionne désormais avec les versions préliminaires et lors d'exécutions répétées, et le conteneur de test embarque `shopware-cli` pour la validation du plugin.

---

# 0.9.0-beta.1

Version préliminaire pour le développement interne, l'assurance qualité et les
tests sandbox/staging. Pas encore soumise au Shopware Store. L'API publique et
les espaces de noms peuvent encore changer avant la version `1.0.0`.

- Paiement Paystack pour Shopware 6 : carte, virement bancaire, USSD et mobile money.
- La vérification du paiement contrôle le statut, le montant et la devise avant qu'une commande ne soit marquée comme payée.
- Remboursements depuis la page de détail de la commande, y compris les remboursements partiels, avec une protection côté serveur contre le sur-remboursement.
- Autorisation d'administration dédiée "Remboursement Paystack" pouvant être attribuée à des rôles (dépend de l'autorisation d'édition des commandes).
- Gestion des webhooks pour `charge.success`, `refund.pending` et `refund.processed`, avec vérification de signature.
- Vérification du compte bancaire dans le compte client (résolution de compte via Paystack).
- Traitement correct des montants pour toutes les devises prises en charge par Paystack, y compris les devises à zéro et trois décimales (par ex. XOF, RWF, KWD). Le plugin ne crée pas de devises ni de langues dans la boutique.
- Interface du plugin traduite en anglais, allemand et français.
- Journalisation configurable, mode sandbox/live et montant minimum de remboursement.
- Supporte Shopware 6.6 et 6.7.
