<?php
declare(strict_types=1);

/* Pages légales : MODÈLES à faire relire (avocat / expert-comptable) et à compléter dans config.php, rubrique « legal ». */
function lg(string $k): string
{
    $v = trim((string)cfg('legal.' . $k, ''));
    return $v !== '' ? h($v) : '<mark class="todo">[À compléter]</mark>';
}

function page_legal(string $which): void
{
    $p = Shop::price();
    $price = h(money($p['cents'], $p['currency']));
    ob_start();
    echo '<section class="wrap narrow prose">';
    if ($which === 'mentions') { ?>
<h1>Mentions légales</h1>
<h2>Éditeur du site</h2>
<p>Nom ou raison sociale : <?= lg('editeur') ?><br>Statut : <?= lg('statut') ?><br>Adresse : <?= lg('adresse') ?><br>Immatriculation : <?= lg('immat') ?><br>N° de TVA : <?= lg('tva') ?><br>Contact : <?= lg('contact_email') ?><br>Directeur de la publication : <?= lg('directeur') ?></p>
<h2>Hébergeur</h2>
<p><?= lg('hebergeur') ?></p>
<h2>Propriété intellectuelle</h2>
<p>Le logiciel Liagos 3D, son nom, son logo et le contenu de ce site appartiennent à l'éditeur. Les modèles que vous créez avec le logiciel vous appartiennent.</p>
<p class="fine">Document modèle : à faire relire et compléter avant l'ouverture de la vente.</p>
<?php } elseif ($which === 'cgv') { ?>
<h1>Conditions générales de vente</h1>
<p class="fine">Dernière mise à jour : <?= h(date('d/m/Y')) ?>. Document modèle : à faire relire et compléter avant l'ouverture de la vente.</p>
<h2>1. Vendeur et objet</h2>
<p>Ces conditions régissent la vente en ligne de licences du logiciel Liagos 3D Pro par <?= lg('editeur') ?> (« le vendeur », <?= lg('adresse') ?>), à des consommateurs. Contact : <?= lg('contact_email') ?>.</p>
<h2>2. Prix</h2>
<p>Liagos 3D Pro est vendu <?= $price ?> toutes taxes comprises, en achat unique, sans abonnement. Le prix applicable est celui affiché au moment de la commande.</p>
<h2>3. Commande et paiement</h2>
<p>La commande exige un compte client (identification par e-mail). Le paiement s'effectue par PayPal. La commande est confirmée une fois le paiement validé ; un e-mail de confirmation est envoyé à l'adresse du compte.</p>
<h2>4. Livraison</h2>
<p>La livraison est immédiate et dématérialisée : la clé de licence s'affiche dans l'espace client et est envoyée par e-mail dès la validation du paiement.</p>
<h2>5. Licence</h2>
<p>La licence est personnelle et non cessible. Elle permet d'utiliser Liagos 3D Pro sur les appareils personnels de l'acheteur (Windows, Android) et dans le navigateur après connexion à son compte. Il est interdit de revendre ou de diffuser la clé. Le vendeur peut désactiver une clé diffusée publiquement ou obtenue frauduleusement. Les mises à jour de Liagos 3D sont incluses.</p>
<h2>6. Droit de rétractation</h2>
<p>La clé donne accès à un contenu numérique fourni sans support matériel. En cochant la case prévue lors de la commande, l'acheteur demande l'exécution immédiate du contrat et reconnaît qu'il perd son droit de rétractation dès la fourniture de la clé.</p>
<h2>7. Remboursement et garanties</h2>
<p><mark class="todo">[À confirmer : engagement commercial]</mark> Si Liagos 3D Pro ne fonctionne pas sur votre appareil malgré l'assistance du vendeur, vous êtes remboursé dans les 14 jours suivant l'achat. Les garanties légales de conformité des contenus numériques et des vices cachés s'appliquent en tout état de cause.</p>
<h2>8. Assistance</h2>
<p>Pour toute question : <?= lg('contact_email') ?>.</p>
<h2>9. Données personnelles</h2>
<p>Voir la <a href="/confidentialite">politique de confidentialité</a>.</p>
<h2>10. Médiation et droit applicable</h2>
<p>Médiateur de la consommation : <?= lg('mediateur') ?>. Droit applicable et juridiction : <?= lg('droit') ?>, sans préjudice des règles impératives de protection du consommateur de son pays de résidence.</p>
<?php } else { ?>
<h1>Politique de confidentialité</h1>
<p class="fine">Dernière mise à jour : <?= h(date('d/m/Y')) ?>. Document modèle : à faire relire et compléter.</p>
<h2>L'application Liagos 3D</h2>
<p>L'application ne collecte ni ne transmet aucune donnée personnelle. Elle fonctionne sur votre appareil, sans publicité ni outil de mesure d'audience. Vos modèles, les fichiers importés (STL, 3MF, images) et vos réglages restent dans le stockage local de l'application et ne sont envoyés à aucun serveur.</p>
<p>L'assistant de création peut, si vous le décidez, être relié à un service d'intelligence artificielle de votre choix. Le texte saisi dans l'assistant est alors envoyé à ce service et à lui seul ; sa propre politique s'applique. Votre clé d'API reste sur votre appareil. L'appareil photo n'est utilisé que si vous choisissez de photographier un dessin à transformer en objet.</p>
<p>La clé de licence Pro est vérifiée sur votre appareil, sans connexion à nos serveurs.</p>
<h2>Le site liagos3d</h2>
<p><strong>Responsable du traitement :</strong> <?= lg('editeur') ?>, <?= lg('adresse') ?>, <?= lg('contact_email') ?>.</p>
<p><strong>Données traitées :</strong> adresse e-mail (création et connexion au compte), commandes (référence, date, montant, pays et adresse e-mail PayPal de l'acheteur), licences, adresse IP et navigateur des sessions de connexion (30 jours), journaux techniques du serveur.</p>
<p><strong>Finalités et bases légales :</strong> fournir le compte et la licence (exécution du contrat) ; conserver les commandes (obligation comptable et légale) ; sécuriser le site et limiter les abus (intérêt légitime).</p>
<p><strong>Cookies :</strong> un seul cookie technique, nécessaire à votre connexion. Aucun cookie publicitaire ni de mesure d'audience. Lors du paiement, PayPal dépose ses propres cookies, selon sa politique.</p>
<p><strong>Destinataires :</strong> PayPal (paiement), IONOS (hébergement et messagerie). Aucune vente ni cession de données.</p>
<p><strong>Durées :</strong> compte : jusqu'à sa suppression ; commandes : <?= lg('duree_commandes') ?> (durée légale de conservation comptable) ; sessions : 30 jours.</p>
<p><strong>Vos droits :</strong> accès, rectification, effacement, portabilité, opposition. Depuis votre <a href="/compte">espace client</a> vous pouvez télécharger vos données et supprimer votre compte ; pour le reste, écrivez à <?= lg('contact_email') ?>. Vous pouvez aussi saisir l'autorité de protection des données de votre pays (<?= lg('autorite') ?>).</p>
<?php }
    echo '</section>';
    $t = ['mentions' => 'Mentions légales', 'cgv' => 'Conditions générales de vente', 'confidentialite' => 'Politique de confidentialité'][$which];
    layout($t . ' — Liagos 3D', (string)ob_get_clean(), ['page' => $which]);
}
