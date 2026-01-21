<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'feuser_newsletter_subscription',
    'description' => '(Un-)subscribe-form to tx_mail_newsletter in fe_users',
    'author' => 'Peter Schöne',
    'author_email' => 'mail@ps-devstudio.de',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'mail' => '2.1.0-3.99.99'
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Pschoene\\FeuserNewsletterSubscription\\' => 'Classes/',
        ],
    ],
];
