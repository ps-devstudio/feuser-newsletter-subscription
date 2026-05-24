<?php

namespace Pschoene\FeuserNewsletterSubscription\Controller;

use Pschoene\FeuserNewsletterSubscription\Domain\Model\User;
use Pschoene\FeuserNewsletterSubscription\Domain\Repository\UserRepository;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class NewsletterController extends ActionController
{
    /**
     * @var UserRepository
     */
    protected $userRepository;

    public function __construct(
        UserRepository $userRepository,
        private readonly PersistenceManager $persistenceManager,
        private readonly ConnectionPool $connectionPool,
    )
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
        if ($this->isSpamSubmission()) {
            $this->addFlashMessage('Ungültige Einsendung erkannt.', '', AbstractMessage::ERROR);
            return $this->redirect('showUnsubscribe');
        }

        $email = $this->getNormalizedEmailArgument();
        if ($email === null) {
            $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_error', 'feuser_newsletter_subscription'), '', AbstractMessage::ERROR);
            return $this->redirect('showUnsubscribe');
        }

        // Benutzer anhand der E-Mail-Adresse finden
        $user = $this->userRepository->findOneByEmail($email);

        if ($user !== null) {
            // Prüfen, ob der Benutzer für den Newsletter angemeldet ist (mail_active === 1)
            if ($user->getMailActive() === 1) {
                $hasUsergroup = count($user->getUsergroup()) > 0;
                // Abmelden vom Newsletter
                $user->setMailActive(0);
                $this->userRepository->update($user);

                // Überprüfen, ob usergroup NULL ist (leerer oder nicht zugewiesener Wert)
                if (!$hasUsergroup) {
                    // Benutzer als "gelöscht" markieren (deleted = 1)
                    $this->markUserAsDeleted($user);
                }

                if ($hasUsergroup) {
                    // Benachrichtigung an Admin nur für echte Benutzerkonten senden.
                    $this->sendUnsubscribeNotification($user);
                }

                $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_success', 'feuser_newsletter_subscription'));
                return $this->redirect('showUnsubscribe');
            } else {
                $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_already', 'feuser_newsletter_subscription'));
                return $this->redirect('showUnsubscribe');
            }
        } else {
            // Benutzer wurde nicht gefunden
            $this->addFlashMessage(LocalizationUtility::translate('unsubscribe_error', 'feuser_newsletter_subscription'), '', AbstractMessage::ERROR);
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
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(User::TABLE_NAME);
        $queryBuilder
            ->update(User::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($user->getUid(), \PDO::PARAM_INT)
                )
            )
            ->set('deleted', 1)
            ->executeStatement();
    }

    /**
     * Subscribe action
     *
     * @return ResponseInterface
     */
    public function subscribeAction(): ResponseInterface
    {
        $email = $this->getNormalizedEmailArgument();
        $firstName = $this->getTrimmedArgument('first_name', 80);
        $lastName = $this->getTrimmedArgument('last_name', 80);
        $mailHtml = $this->request->hasArgument('mail_html') ? 1 : 0;

        if ($this->isSpamSubmission() || $email === null || $firstName === '' || $lastName === '') {
            // Spam erkannt
            $this->addFlashMessage('Ungültige Einsendung erkannt.', '', AbstractMessage::ERROR);
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
                $this->persistenceManager->persistAll();
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
            $newUser = GeneralUtility::makeInstance(User::class);
            $newUser->setPid((int)$storagePid);
            $newUser->setUsername($email);
            $newUser->setFirstName($firstName);
            $newUser->setLastName($lastName);
            $newUser->setEmail($email);
            $newUser->setMailActive(1);
            $newUser->setMailHtml((bool)$mailHtml);

            $this->userRepository->add($newUser);
            $this->persistenceManager->persistAll();

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
            $this->formatUsergroupTitles($user)
        );

        $mail->text($body);
        $mail->send();
    }

    protected function formatUsergroupTitles(User $user): string
    {
        $titles = [];
        foreach ($user->getUsergroup() as $usergroup) {
            $title = trim($usergroup->getTitle());
            $titles[] = $title !== '' ? $title : '#' . $usergroup->getUid();
        }

        return $titles !== [] ? implode(', ', $titles) : 'Keine';
    }

    protected function getNormalizedEmailArgument(): ?string
    {
        if (!$this->request->hasArgument('email')) {
            return null;
        }

        $email = mb_strtolower(trim((string)$this->request->getArgument('email')));
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    protected function getTrimmedArgument(string $name, int $maxLength): string
    {
        if (!$this->request->hasArgument($name)) {
            return '';
        }

        return mb_substr(trim((string)$this->request->getArgument($name)), 0, $maxLength);
    }

    protected function isSpamSubmission(): bool
    {
        return !$this->request->hasArgument('schwammerl')
            || trim((string)$this->request->getArgument('schwammerl')) !== '';
    }
}
