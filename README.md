# Liagos 3D

Modélisation 3D facile par assemblage de formes, pensée pour l'impression 3D : cubes, sphères, cylindres, trous, groupes, peinture, image vers objet, export STL.

- **Applications Windows et Android** : gratuites, avec Liagos 3D Pro (achat unique) pour les outils avancés.
- **Version navigateur** : réservée aux détenteurs de Liagos 3D Pro, sur https://liagos3d.com
- **Site, boutique et espace client** : dossier `site/` (PHP, hébergement IONOS), voir `site/LISEZMOI.md`.

## Contenu du dépôt

| Dossier ou fichier | Rôle |
| --- | --- |
| `index.html` | l'application, en un seul fichier |
| `lib/` | bibliothèque 3D three.js (licence MIT) |
| `manifest.webmanifest`, `sw.js`, `icons/` | installation et fonctionnement hors connexion |
| `desktop/` | application Windows (Electron) |
| `android-assets/`, `.github/workflows/` | application Android (Capacitor) et constructions automatiques |
| `site/` | liagos3d.com : vitrine, boutique, espace client, accès navigateur, back-office |

Les fichiers `.apk`, `.aab` et `.exe` sont construits automatiquement à chaque envoi sur la branche `main` (onglet Actions), puis publiés dans la version « apk » du dépôt. Le dossier `site/` n'y participe pas.

## Droits

Tous droits réservés. Le code de ce dépôt n'est pas libre : il ne peut être ni copié, ni modifié, ni réutilisé sans autorisation écrite. Voir le fichier [LICENSE](LICENSE).

Politique de confidentialité : https://liagos3d.com/confidentialite
