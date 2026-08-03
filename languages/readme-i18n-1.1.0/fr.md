<!-- a11yfy readme 1.1.0 új blokkjai — fr (deepseek-lektorált fordítás, 2026-08-03) -->

## Description — új bekezdés

Trois modes de fonctionnement : **automatique** (chaque nouvel ajout est corrigé), **manuel** (vous choisissez ce qu’il faut corriger), et **à la demande** — les visiteurs qui cliquent sur un PDF pas encore accessible peuvent demander une version accessible dans une boîte de dialogue accessible et recevoir un e-mail lorsqu’elle est prête ; ainsi, vous ne payez que pour les documents dont les utilisateurs ont réellement besoin.

## External Services — módosult bullet

* **Lorsque vous remédiez un PDF** (manuellement, via une action groupée, par le mode automatique que vous avez activé, ou lorsqu’un visiteur demande une version accessible dans le mode à la demande que vous avez activé) : le fichier PDF lui-même, son nom de fichier et votre clé API sont envoyés à `https://a11yfy.com/v1/jobs`. Le traitement a lieu dans l’UE. L’adresse e-mail du demandeur est stockée uniquement sur votre site et n’est jamais transmise à l’API a11yfy.

## FAQ — új kérdés + válasz

= Comment fonctionne le mode « À la demande » ? =

Lorsqu’un visiteur clique sur un lien vers un PDF qui n’a pas passé le pré-contrôle d’accessibilité, une boîte de dialogue accessible apparaît. Le visiteur peut ouvrir le document tel quel ou demander une version accessible en saisissant son adresse e-mail. L’extension remédie le document une seule fois — quel que soit le nombre de visiteurs qui le demandent — et envoie un e-mail à tous ceux qui l’ont demandé dès que la version accessible est disponible. À partir de ce moment, chaque visiteur obtient le fichier accessible via le même lien, sans boîte de dialogue. L’adresse e-mail est utilisée uniquement pour cette notification, n’est jamais envoyée à l’API a11yfy et est supprimée automatiquement après 30 jours. Les textes de la boîte de dialogue, l’e-mail de notification et le style des boutons sont personnalisables dans a11yfy → Réglages.

## Changelog — 1.1.0

= 1.1.0 =
* Nouveau mode de fonctionnement « À la demande » : lorsqu’un visiteur clique sur un PDF qui n’est pas encore accessible, une boîte de dialogue accessible lui propose d’ouvrir le document tel quel ou de demander une version accessible par e-mail. La mise en conformité n’est effectuée qu’en cas de demande réelle.
* Les visiteurs sont informés par e-mail dès que la version accessible est prête ; chaque texte de la boîte de dialogue (y compris la note de confidentialité) et l’e-mail de notification sont entièrement personnalisables dans les Réglages.
* La boîte de dialogue hérite de la typographie de votre thème et, avec les thèmes de blocs, les boutons reprennent automatiquement le style des boutons du thème ; vous pouvez également choisir une couleur d’accent.
* Si le solde de crédits ne couvre pas une demande de visiteur, la demande est mise en attente, le propriétaire du site est averti par e-mail et la remédiation démarre automatiquement dès que suffisamment de crédits sont disponibles.
* Les adresses e-mail des demandeurs sont conservées uniquement jusqu’à l’envoi de la notification (rétention de 30 jours), avec la prise en charge de l’exportation et de l’effacement des données personnelles de WordPress.
