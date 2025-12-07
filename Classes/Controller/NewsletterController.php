<?php

namespace Pschoene\FeuserNewsletterSubscription\Controller;

use Pschoene\FeuserNewsletterSubscription\Domain\Repository\UserRepository;
use Pschoene\FeuserNewsletterSubscription\Domain\Model\User;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Mail\MailMessage;

class NewsletterController extends ActionController
{
    /**
     * @var UserRepository
     */
    protected $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * Zeigt das Formular zur Abmeldung
     *
     * @return ResponseInterface
     */
    public function showUnsubscribeAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

    /**
     * Zeigt das Formular zur Anmeldung
     *
     * @return ResponseInterface
     */
    public function showSubscribeAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

    /**
     * Unsubscribe action
     *
     * @return ResponseInterface
     */
    public function unsubscribeAction(): ResponseInterface
    {
        $email = $this->request->getArgument('email');

        // Benutzer anhand der E-Mail-Adresse finden
        $user = $this->userRepository->findOneByEmail($email);

        if ($user !== null) {
            // Prüfen, ob der Benutzer für den Newsletter angemeldet ist (mail_active === 1)
            if ($user->getMailActive() === 1) {
                // Abmelden vom Newsletter
                $user->setMailActive(0);
                $this->userRepository->update($user);

                // Überprüfen, ob usergroup NULL ist (leerer oder nicht zugewiesener Wert)
                if (count($user->getUsergroup()) === 0) {
                    // Benutzer als "gelöscht" markieren (deleted = 1)
                    $this->markUserAsDeleted($user);
                }
                // Benachrichtigung an Admin senden
                $this->sendUnsubscribeNotification($user);

                $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_success', 'feuser_newsletter_subscription'));
                return $this->redirect('showUnsubscribe');
            } else {
                $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_already', 'feuser_newsletter_subscription'));
                return $this->redirect('showUnsubscribe');
            }
        } else {
            // Benutzer wurde nicht gefunden
            $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_error', 'feuser_newsletter_subscription'), '', \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR);
            return $this->redirect('showUnsubscribe');
        }

        // Rückgabe der Ansicht nach dem Redirect
        return $this->redirect('showUnsubscribe');
    }

    /**
     * Markiert den Benutzer als gelöscht (setzt deleted auf 1).
     *
     * @param \Pschoene\FeuserNewsletterSubscription\Domain\Model\User $user
     */
    protected function markUserAsDeleted(User $user): void
    {
        $dataHandler = GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);

        $cmd = [
            'fe_users' => [
                $user->getUid() => [
                    'delete' => 1, // Dies setzt das Feld "deleted" auf 1
                ],
            ],
        ];

        // Datenverarbeitung ohne das erste Argument
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();
    }

    /**
     * Subscribe action
     *
     * @return ResponseInterface
     */
    public function subscribeAction(): ResponseInterface
    {
        $email = $this->request->getArgument('email');
        $firstName = $this->request->getArgument('first_name');
        $lastName = $this->request->getArgument('last_name');
        $mailHtml = $this->request->hasArgument('mail_html') ? 1 : 0;
        $honeypot = $this->request->getArgument('schwammerl');

        if (!empty($honeypot)) {
            // Spam erkannt
            $this->addFlashMessage('Ungültige Einsendung erkannt.', '', \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR);
            return $this->redirect('showSubscribe');
        }
        
        // Aktuelles Content Object (cObj) über das Request-Objekt abrufen
        $currentContentObject = $this->request->getAttribute('currentContentObject');
            
        // Hole den Wert der StoragePid, falls notwendig
        $storagePid = $currentContentObject->data['pages'] ?? $this->settings['storagePid'] ?? 1;

        // Benutzer anhand der E-Mail-Adresse finden
        $user = $this->userRepository->findOneByEmail($email);

        if ($user !== null) {
            if ($user->getMailActive() === 0) {
                // Anmeldung für den Newsletter
                $user->setMailActive(1);
                $this->userRepository->update($user);
                // Fallback-Text, falls die Übersetzung nicht gefunden wird
                $message = LocalizationUtility::translate('subscribe_success', 'feuser_newsletter_subscription') 
                    ?? 'You have successfully subscribed to the newsletter.';
                $this->addFlashMessage($message);
                return $this->redirect('showSubscribe');
            } else {
                $message = LocalizationUtility::translate('subscribe_already', 'feuser_newsletter_subscription') 
                       ?? 'You are already subscribed.';
                $this->addFlashMessage($message);
                return $this->redirect('showSubscribe');
            }
        } else {
            // Neuer Benutzer erstellen, wenn E-Mail nicht existiert
            $data = [
                'fe_users' => [
                    'NEW' => [
                        'pid' => $storagePid, // Verwende die dynamische storagePid
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'mail_active' => 1,
                        'mail_html' => $mailHtml,
                    ],
                ],
            ];

            /** @var DataHandler $dataHandler */
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($data, []);
            $dataHandler->process_datamap();

            $message = LocalizationUtility::translate('subscribe_success_new_user', 'feuser_newsletter_subscription') 
                ?? 'Thank you for subscribing! A new account has been created for you.';
            $this->addFlashMessage($message);
        
            return $this->redirect('showSubscribe');
        }

        return $this->redirect('showSubscribe');
    }

    protected function sendUnsubscribeNotification(User $user): void
    {
        $mail = GeneralUtility::makeInstance(MailMessage::class);

        $mail->from(new \Symfony\Component\Mime\Address('noreply@schubertlied.de', 'Newsletter-System'));
        $mail->to(new \Symfony\Component\Mime\Address('mail@schubertlied.de', 'Newsletter Admin'));
        $mail->subject('Newsletter-Abmeldung');

        $body = sprintf(
            "Ein Benutzer hat sich vom Newsletter abgemeldet:\n\nName: %s %s\nE-Mail: %s\nUsergroup: %s",
            $user->getFirstName(),
            $user->getLastName(),
            $user->getEmail(),
            $user->getUsergroup()->count() > 0 ? 'Zugeordnet' : 'Keine'
        );

        $mail->text($body);
        $mail->send();
    }
}