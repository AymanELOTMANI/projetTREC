🐴 projetTREC

Application web de gestion de compétitions TREC (Techniques de Randonnée Équestre de Compétition), développée dans le cadre du BTS CIEL au Lycée Polyvalent Pierre Mendès France d'Épinal.


📋 Présentation

Le TREC est une discipline équestre qui évalue les capacités du cavalier et de sa monture à travers trois épreuves :


POR (Parcours d'Orientation et de Régularité) — maîtrise de l'allure sur un tracé chronométré
MA (Maîtrise des Allures) — contrôle des allures du cheval
PTV (Parcours en Terrain Varié) — franchissement d'obstacles naturels


projetTREC permet de gérer l'intégralité d'une compétition TREC, de l'inscription des cavaliers jusqu'au classement final.


✨ Fonctionnalités

👤 Espace Cavalier


Inscription aux compétitions
Consultation des résultats et classements personnels
Messagerie avec l'organisateur


🗂️ Espace Organisateur


Création et gestion des compétitions
Validation des comptes cavaliers
Gestion des épreuves (POR / MA / PTV)


🧭 Espace Chef de Piste


Affichage du parcours théorique sur carte interactive (Leaflet.js)
Gestion des tronçons avec temps idéaux (MM'SS")
Enregistrement des passages cavaliers en temps réel
Calcul automatique des statuts de passage


🏆 Classement


Calcul des scores par épreuve et global
Filtres par type d'épreuve
Affichage des pénalités


🔧 Espace Administrateur


Gestion complète des utilisateurs et rôles
Suppression en cascade sécurisée
Gestion des boîtiers GPS



🛠️ Stack technique

TechnologieUsagePHP 8+Backend, logique métierMySQLBase de donnéesBootstrap 5Interface responsiveBootstrap IconsIcônesLeaflet.jsCarte interactive des parcoursChart.jsVisualisation des scoresTOTP (2FA)Double authentification


🗄️ Base de données (tables principales)

utilisateur, cavalier, organisateur, chef_piste
competition, epreuve, inscription
parcours_theorique, point_parcours
passage, dossard
session_gps, affectation_boitier, pointGPS


👥 Équipe

MembreRôleAyman EL OTMANIInterface web — authentification, inscriptions, tronçons, classementÉtudiant 1Module GPS matériel — boîtier GPS, génération fichiers GPX

Projet réalisé en BTS CIEL — Lycée Polyvalent Pierre Mendès France, Épinal.


🚀 Installation

Prérequis


PHP 8.0+
MySQL 5.7+
Serveur web (Apache / Nginx)


Mise en place

bash# Cloner le dépôt
git clone https://github.com/AymanELOTMANI/projetTREC.git

# Importer la base de données
mysql -u root -p < database/projetTREC.sql

# Configurer la connexion BDD
cp config.example.php config.php
# Éditer config.php avec tes identifiants


📁 Structure du projet

projetTREC/
├── index.php
├── login.php
├── config.php              ← (ignoré par Git)
├── competition.php
├── classement.php
├── troncons.php
├── espace_admin.php
├── espace_cavalier.php
├── espace_organisateur.php
├── espace_chef.php
├── assets/
│   ├── css/
│   └── js/
└── database/
    └── projetTREC.sql


🔒 Sécurité


Authentification avec double facteur (TOTP)
Requêtes préparées MySQLi sur toutes les entrées utilisateur
Fichier config.php exclu du dépôt Git

Compte - Admin

<img width="581" height="860" alt="Capture d&#39;écran 2026-06-14 192839" src="https://github.com/user-attachments/assets/5edf8991-1b01-48a5-b96a-2eaef551c2ef" />

ID: admin
MDP: OralTrec88&



Projet scolaire — BTS CIEL 2024/2025
