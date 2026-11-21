<?php
/*
 * Exemple de configuration de Liagos 3D (tous les réglages possibles).
 * Le vrai fichier s'appelle config.php : il est créé par install.php. Il contient des secrets (mot de passe de la base,
 * clé PayPal, mot de passe de la boîte e-mail) : ne le publiez jamais et ne l'envoyez à personne.
 * Après une modification de config.php, rien d'autre à faire : le site relit le fichier (l'hébergeur peut garder l'ancienne version en mémoire
 * quelques instants : si un changement n'apparaît pas tout de suite, patientez une ou deux minutes).
 */
return [
    'site_url' => 'https://liagos3d.com',          // sans « / » final
    'secret' => 'valeur-aleatoire-de-64-caracteres-creee-par-install.php',

    // Base de données MySQL créée dans l'espace IONOS (Hébergement > Bases de données).
    'db' => ['driver' => 'mysql', 'host' => 'db5000000000.hosting-data.io', 'port' => 3306, 'name' => 'dbs1234567', 'user' => 'dbu1234567', 'pass' => '...'],

    // Les seules adresses qui ouvrent l'administration (connexion par code reçu par e-mail).
    'admin_emails' => ['vous@exemple.fr'],

    // E-mails envoyés par le site (codes de connexion, clés de licence, remboursements).
    // IONOS exige que « from » soit l'adresse de la boîte qui s'authentifie.
    'mail' => [
        'transport' => 'smtp',                     // 'smtp' (recommandé), 'phpmail' (fonction mail du serveur) ou 'file' (essais)
        'host' => 'smtp.ionos.fr',                 // à vérifier dans l'aide IONOS : smtp.ionos.fr ou smtp.ionos.com
        'port' => 465, 'secure' => 'ssl',          // 465 + 'ssl'   ou   587 + 'tls'
        'user' => 'boutique@liagos3d.com', 'pass' => '...',
        'from' => 'boutique@liagos3d.com', 'from_name' => 'Liagos 3D',
        'reply_to' => 'contact@liagos3d.com',      // facultatif : où arrivent les réponses des clients
        'verify_peer' => true,
    ],

    // PayPal (developer.paypal.com > Apps & Credentials). Commencez en 'sandbox', puis passez en 'live'.
    'paypal' => [
        'env' => 'sandbox',                        // 'sandbox' = faux paiements ; 'live' = vrais paiements
        'client_id' => '', 'secret' => '',
        'webhook_id' => '',                        // identifiant du webhook créé chez PayPal (adresse : site_url/api/paypal/webhook)
        'merchant_id' => '',                       // facultatif et risqué : si renseigné, un paiement vers un autre compte est refusé.
                                                   // À changer en même temps que le mode (le compte test n'a pas le même identifiant).
        'api_base' => '',                          // réservé aux essais : adresse d'un faux PayPal
        'csp' => 'nonce',                          // 'nonce' (strict) ; 'compat' si les boutons PayPal n'apparaissent pas ; 'off' en dernier recours
    ],

    // Clé qui signe les licences. La clé publique correspondante est dans src/keys.php et dans l'application.
    'license' => ['private_key_path' => __DIR__ . '/storage/keys/license-private.pem'],

    'price' => ['cents' => 999, 'currency' => 'EUR'],   // le prix se règle aussi dans l'administration (Réglages)

    // Pages « Mentions légales » et « Conditions de vente » (modèles à faire relire par un professionnel).
    'legal' => [
        'editeur' => '', 'statut' => '', 'adresse' => '', 'immat' => '', 'tva' => '',
        'contact_email' => '', 'directeur' => '', 'hebergeur' => '',
    ],

    'downloads_dir' => null,   // par défaut : public/downloads
    'storage_dir' => null,     // par défaut : storage
    'debug' => false,
];
