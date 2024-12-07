# LeBonCode
Le but de ce test est de réaliser une api pour un site de création de projets immobiliers.  
Vous commencez le projet avec un symfony skeleton, vous disposez aussi d'un docker-compose.

Ce test à pour but de voir votre logique de code. Il n'y a pas de mauvaise réponse, faites le comme si vous codiez naturellement.  
Merci de ne pas utiliser de librairies externes telles que api-platform.  
Vous avez quatre heures pour effectuer ce test, bien entendu nous ne pourrons pas vérifier si vous y passez 42h ou 30mn, mais ça vous donne une indication. 
Ne restez pas bloqué trop longtemps sur un sujet, n'hésitez pas à faire ce qui vous semble le plus simple en premier. 

Avant de commencer, faites un fork du projet. C'est ce fork qui servira de rendu pour ce test.

## Projects

#### Create advert `POST`
Un utilisateur pourra ajouter un appartement (`/project`) avec ces informations :
- nom du projet
- libellé du projet
- Nombre d'étages
- Adresse
- Code postal
- Date de livraison
- Photo
#### Delete project  `DELETE`
Un utilisateur pourra supprimer un projet (`/project/{id}`).
la suppression rend le projet inactif
#### Update project `PATCH`
Un utilisateur pourra modifier les informations d'une annonce (`/project/{id}`).
Le titre du projet ne peut pas être modifié.
#### List project `GET`
Un utilisateur pourra récupérer la liste des projets (`/project`).
#### Project by id `GET`
Un utilisateur pourra récupérer les informations d'un projet (`/project/{id}`) avec son `id` associé.
#### Search project `GET`
Un utilisateur pourra chercher un projet (`/project/search`).
- title
- Date de livraison min
- Date de livraison max

## User

#### Register `POST`
Un utilisateur pourra s'enregistrer (`/signup`) avec au minimum :
- Nom
- Prénom
- Numéro de téléphone
- Email
- Mot de passe
lors de la création, l'utilisateur appartient au groupe `USER`

#### Login `POST`
Par défaut, l'utilisateur appartiendra au groupe `USER`.
Un utilisateur pourra se connecter (`/signin`) avec ses identifiants :
- Email
- Password

[Installation de JWT](https://github.com/lexik/LexikJWTAuthenticationBundle/blob/3.x/Resources/doc/index.rst#installation)

#### Sécurisation des routes
Les routes ne sont accessibles qu'à des utilisateurs connectés
