# Site Liagos 3D : mise en ligne sur IONOS

Ce dossier contient tout le site : vitrine, boutique (paiement PayPal), espace client avec connexion par e-mail, accès à l'application dans le navigateur pour les détenteurs de Pro, et administration.
Il fonctionne sur un hébergement IONOS « Web Hosting » ordinaire (PHP + MySQL). Il n'utilise **ni GitHub, ni service extérieur** en dehors de PayPal.

**Les ventes restent fermées tant que vous ne les ouvrez pas** (réglage « Vente ouverte » dans l'administration). Vous pouvez tout installer et tout tester avant.

---

## Ce qu'il vous faut

| Quoi | Où | Remarque |
|---|---|---|
| Nom de domaine `liagos3d.com` | IONOS | Vérifié libre le 21/11/2026. Souvent très bas la première année, puis tarif normal : regardez le prix de renouvellement. |
| Hébergement « Web Hosting » Linux | IONOS | Il faut PHP 8.1 ou plus, une base MySQL, un accès SFTP et au moins une boîte e-mail. |
| Boîte e-mail `boutique@liagos3d.com` | IONOS | Envoie les codes de connexion et les clés. Une 2ᵉ boîte `contact@…` est utile pour les réponses des clients. |
| Compte PayPal professionnel | paypal.com | Vous créez ensuite une « application » sur developer.paypal.com (gratuit). |
| Le dossier privé `Liagos-3D-prive` | votre ordinateur | Contient la clé privée des licences et la clé « propriétaire ». **Ne jamais l'envoyer à personne.** |

## Étapes

### 1. Acheter le domaine et l'hébergement
Dans votre espace IONOS, commandez le domaine et un hébergement web Linux. Choisissez l'hébergement le plus simple qui propose une base MySQL, du SFTP et une boîte e-mail. Notez le prix **après** la première période.

### 2. Régler PHP
Espace IONOS → **Hébergement** → tuile **PHP** → **Ouvrir** → choisissez **PHP 8.3 ou 8.4** (8.1 et 8.2 conviennent aussi) → **Enregistrer**.

### 3. Créer la base de données
Espace IONOS → **Hébergement** → **Bases de données MySQL/MariaDB** → **Créer une base de données**. IONOS affiche le **nom d'hôte** (du type `db5000000000.hosting-data.io`), le **nom de la base** (`dbs…`) et l'**utilisateur** (`dbu…`) ; vous choisissez le mot de passe. Gardez ces quatre informations pour l'installation.

### 4. Créer la boîte e-mail
Espace IONOS → **E-mail** → créez `boutique@liagos3d.com` avec un bon mot de passe. Pour les logiciels : serveur d'envoi **smtp.ionos.fr**, port **465**, SSL (c'est le réglage par défaut du site). IONOS refuse qu'un message parte avec une adresse d'expéditeur différente de la boîte : le site utilise donc l'adresse de la boîte.

### 5. Envoyer les fichiers (SFTP)
Espace IONOS → **Hébergement** → tuile **SFTP & SSH** → **Gérer** : créez un compte SFTP (ou utilisez celui de l'hébergement) avec un mot de passe long et unique, puis notez le serveur (du type `access12345678.webspace-host.com`), le port **22** et l'utilisateur.
Avec **WinSCP** ou **FileZilla** (affichez les fichiers cachés : `.htaccess` doit partir aussi) :

1. Envoyez à la **racine de l'espace web** le contenu du dossier `a-envoyer` du paquet : les dossiers `public`, `src`, `storage`, et les fichiers `.htaccess` et `config.sample.php`. Les dossiers `src` et `storage` se trouvent ainsi **au-dessus** du dossier public : internet ne les atteint pas.
2. N'envoyez **pas** de fichier `config.php` : il sera créé par l'installateur.

### 6. Relier le domaine au dossier public
Espace IONOS → **Domaines & SSL** → votre domaine → **Destination** (ou « Connecter à un dossier de l'espace web ») → choisissez le dossier **`/public`** (celui qui contient `index.php`).
Si votre hébergement vous oblige à choisir la racine, le fichier `.htaccess` placé à la racine redirige alors tout vers `public` et bloque les dossiers privés (mais le choix `/public` est préférable).
Le certificat HTTPS est fourni avec l'hébergement. La propagation d'un nouveau domaine peut prendre quelques heures.

### 7. Envoyer la clé privée des licences
Depuis votre dossier `Liagos-3D-prive`, envoyez le fichier **`license-private.pem`** dans le dossier **`storage/keys/`** de l'hébergement (même nom). C'est ce fichier qui fabrique les clés Pro. Il ne doit exister qu'à trois endroits : votre dossier privé, une copie hors ligne (clé USB), et ce dossier de l'hébergement (inaccessible depuis internet).

### 8. Lancer l'installateur
Ouvrez **https://liagos3d.com/install.php** (adresse de votre site + `/install.php`).
La page vérifie PHP, la base, la clé… puis demande :

- le **code d'installation** (dans le fichier `LISEZMOI-cles.txt` de votre dossier privé) ;
- l'adresse du site, vos adresses d'administrateur (la vôtre) et le prix ;
- les informations de la base (étape 3) et de la boîte e-mail (étape 4) ;
- PayPal : vous pouvez laisser vide pour l'instant.

L'installateur écrit `config.php`, crée les tables, envoie un e-mail d'essai puis **se supprime**. S'il n'y arrive pas, supprimez vous-même `public/install.php`.

### 9. Entrer dans l'administration
Allez sur **https://liagos3d.com/admin**, saisissez votre adresse d'administrateur : un code arrive par e-mail (pas de mot de passe). Le **tableau de bord** affiche la liste « Avant d'ouvrir la vente ».

### 10. Brancher PayPal (d'abord en test)
1. Sur **developer.paypal.com** → *Apps & Credentials* → mode **Sandbox** → *Create App*. Notez le **Client ID** et le **Secret**.
2. Dans la même application → *Webhooks* → *Add Webhook* → adresse **`https://liagos3d.com/api/paypal/webhook`** → cochez au minimum : `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`, `CUSTOMER.DISPUTE.CREATED`. Notez le **Webhook ID**.
3. Ouvrez `config.php` (SFTP) et complétez la section `paypal` : `env` = `sandbox`, `client_id`, `secret`, `webhook_id`.
4. Administration → **Réglages** → cochez **Vente ouverte** → enregistrer.
5. Faites un achat d'essai sur `/pro` avec un **compte acheteur de test** (developer.paypal.com → *Sandbox accounts*). Vérifiez : la clé s'affiche, l'e-mail arrive, la commande apparaît dans **Commandes**, puis testez **Rembourser via PayPal** depuis la fiche de la commande.

> Les événements envoyés par le « simulateur » de webhooks de PayPal ne passent pas la vérification de signature : c'est normal. Les vrais achats de test, eux, passent.

### 11. Passer en réel
1. Sur developer.paypal.com → mode **Live** → créez l'application → **Client ID** et **Secret** réels ; créez le webhook réel (même adresse, mêmes événements) → **Webhook ID** réel.
2. Dans `config.php` : `env` = `live` + les trois valeurs réelles.
3. Faites un vrai achat (vous-même, avec votre prix) puis remboursez-le depuis l'administration pour vérifier le circuit complet.

### 12. Mettre en place les téléchargements et l'application navigateur
- Windows et Android : les fichiers `Liagos-3D-Installation.exe`, `Liagos-3D-Portable.exe` et `liagos-3d.apk` (produits par la construction automatique, ou envoyés par moi) se déposent dans **`public/downloads/`** par SFTP (les gros fichiers dépassent la limite d'envoi du navigateur). La page « Télécharger » les détecte toute seule et affiche leur empreinte SHA-256.
- Application dans le navigateur (réservée aux détenteurs de Pro) : Administration → **Fichiers** → *Application navigateur* → envoyez le fichier `index.html` de la version voulue.

### 13. Pages légales
Les pages « Mentions légales », « Conditions de vente » et « Confidentialité » sont des **modèles**. Complétez la section `legal` de `config.php`, relisez le texte avec un professionnel, **avant d'ouvrir la vente en réel**.

---

## Au quotidien

- **Un client n'a pas reçu sa clé** : Administration → Commandes → la commande → *Vérifier chez PayPal*. La clé est de toute façon dans son espace client (« Mon compte »).
- **Donner une clé à un testeur** : Administration → Licences → *Créer une licence* (liée à son e-mail : il retrouve la clé et l'accès navigateur en se connectant).
- **Révoquer une clé** : Administration → Licences. Dans le navigateur c'est immédiat ; dans Windows/Android une clé déjà installée continue de fonctionner (la vérification est hors ligne, c'est le prix de l'usage sans connexion).
- **Exporter** les commandes et les clients : boutons « Exporter en CSV » de l'administration (utiles pour votre comptable).
- **Supprimer un compte** à la demande d'un client : il peut le faire lui-même dans « Mon compte » ; les commandes restent, sans son e-mail, pour la comptabilité.

## Sauvegardes
- IONOS fait des sauvegardes de l'espace web et des bases : vérifiez dans votre contrat à quelle fréquence et combien de temps.
- En plus : exportez la base une fois par mois (IONOS → Bases de données → *phpMyAdmin* → Exporter) et gardez la clé privée hors ligne.
- Si vous perdez la clé privée : plus aucune nouvelle clé ne pourra être créée avec la même identité ; il faudrait recréer une paire (outil `cles-licence.html`, dans le dossier privé) et reconstruire les applications.

## Sécurité
- `config.php`, `storage/` (clé privée, journaux), `src/` ne sont pas accessibles depuis internet (dossiers hors du dossier public, ou bloqués par `.htaccess`).
- Les connexions se font par code à 6 chiffres envoyé par e-mail (10 minutes, 5 essais), les sessions durent 30 jours, tout formulaire est protégé contre les requêtes venues d'autres sites, et les essais répétés sont limités.
- Les paiements sont vérifiés côté serveur auprès de PayPal (montant, devise, référence) ; la clé n'est délivrée qu'après un paiement validé.
- Les mots de passe PayPal/IONOS ne doivent jamais passer par ce site : n'y saisissez que les identifiants d'application demandés plus haut.
- Le code des applications est public ou copiable : un utilisateur très motivé peut contourner la vérification des clés. Le site réduit ce risque (navigateur protégé par connexion) mais ne peut pas l'éliminer.

## Dépannage
| Symptôme | Piste |
|---|---|
| Page blanche ou erreur 500 | Vérifiez PHP ≥ 8.1, la présence des fichiers `.htaccess`, puis `storage/logs/app.log`. |
| `/install.php` répond « 404 » | Le domaine ne pointe pas sur `public` (ou sur `liagos3d` avec le `.htaccess` racine). |
| L'e-mail d'essai ne part pas | Mot de passe de la boîte, serveur `smtp.ionos.fr`, port 465. Essayez 587 + STARTTLS. Voir `storage/logs/app.log`. |
| Les boutons PayPal n'apparaissent pas | Client ID du bon mode (test/réel) ? Console du navigateur : erreur « Content Security Policy » → dans `config.php`, `paypal` → `csp` = `compat`. |
| Paiement fait mais pas de clé | Commandes → la commande → *Vérifier chez PayPal*. Cela arrive si le webhook n'est pas configuré et que le client ferme la page. |
| Le client ne reçoit pas le code | Courriers indésirables ; vérifiez que la boîte `boutique@…` peut envoyer ; limite de 3 demandes par 10 minutes. |

## Ce que le site ne fait pas (à voir avec un expert-comptable)
- **TVA** : PayPal n'est pas un « vendeur pour votre compte ». La TVA des ventes de logiciels à des particuliers de l'Union européenne dépend de votre statut et du pays où vous êtes établi (régime « guichet unique » possible). Le site sait **extraire** la TVA du prix TTC selon le pays de l'acheteur (Administration → Réglages), mais c'est vous qui choisissez les taux.
- **Factures** : le site n'en émet pas ; PayPal envoie un reçu à l'acheteur.
- **Pays du compte PayPal** : le pays du compte PayPal doit correspondre à l'endroit où votre activité est établie. Ouvrez-le après avoir choisi votre structure.
