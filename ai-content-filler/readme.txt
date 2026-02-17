=== AI Content Filler ===
Contributors: aicontentfiller
Tags: elementor, ai, content, claude, anthropic, copywriting
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Génère automatiquement le contenu des widgets Elementor (Heading, Text Editor) via l'API Claude d'Anthropic.

== Description ==

AI Content Filler utilise l'API Claude d'Anthropic pour rédiger automatiquement le contenu de vos widgets Elementor. Configurez un brief client une seule fois, puis générez du contenu contextuel pour chaque page en un clic depuis l'éditeur Elementor.

**Fonctionnalités :**

* Intégration directe avec l'éditeur Elementor via un panneau flottant
* Génération de contenu pour les widgets Heading et Text Editor
* Brief client configurable (ton, cible, mots-clés) utilisé comme contexte pour chaque génération
* Prompts personnalisés par page
* Respect du workflow natif Elementor (pas de sauvegarde automatique)
* Sécurité : clé API stockée côté serveur, nonce WP, rate limiting

**Prérequis :**

* WordPress 5.8+
* PHP 7.4+
* Elementor (version gratuite ou Pro)
* Un compte Anthropic avec une clé API

== Installation ==

1. Téléchargez le plugin et décompressez-le dans `/wp-content/plugins/ai-content-filler/`
2. Activez le plugin dans le menu Extensions de WordPress
3. Allez dans Paramètres > AI Content Filler
4. Saisissez votre clé API Anthropic
5. Rédigez le brief client (contexte métier, ton éditorial, cible, mots-clés)
6. Ouvrez une page dans l'éditeur Elementor : le panneau AI Content Filler apparaît en bas à gauche

== Frequently Asked Questions ==

= Ai-je besoin d'Elementor Pro ? =

Non, le plugin fonctionne avec la version gratuite d'Elementor. Les widgets Heading et Text Editor sont disponibles dans les deux versions.

= Où obtenir une clé API Anthropic ? =

Rendez-vous sur https://console.anthropic.com/ pour créer un compte et obtenir votre clé API.

= Le plugin modifie-t-il le code d'Elementor ? =

Non, le plugin utilise uniquement les APIs JavaScript publiques d'Elementor pour lire et mettre à jour le contenu des widgets.

= La page est-elle sauvegardée automatiquement ? =

Non, le plugin injecte le contenu dans les widgets mais ne déclenche pas la sauvegarde. Vous gardez le contrôle total et pouvez vérifier le contenu avant de sauvegarder.

== Changelog ==

= 1.0.0 =
* Version initiale
* Page de réglages avec clé API, brief client, modèle, température, max tokens
* Endpoint REST API pour la génération de contenu
* Panneau flottant dans l'éditeur Elementor
* Scan récursif des widgets Heading et Text Editor
* Rate limiting (1 appel / 10 secondes par utilisateur)
* Retry automatique en cas de JSON invalide

== Upgrade Notice ==

= 1.0.0 =
Version initiale du plugin.
