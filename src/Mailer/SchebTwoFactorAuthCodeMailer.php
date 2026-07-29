<?php
declare(strict_types=1);
namespace Pixiekat\LuminaUiBundle\Mailer;

use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\UrlHelper;

class SchebTwoFactorAuthCodeMailer implements AuthCodeMailerInterface
{
  const BCC_RECIPIENTS = ['kebloom@bidmc.harvard.edu'];

  private $urlGenerator;

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly MailerInterface $mailer,
    private readonly UrlHelper $urlHelper,
  ) {  }

  public function sendAuthCode(TwoFactorInterface $user): void {
    try {
      $authCode = $user->getEmailAuthCode();
      $loginUrl = $this->urlHelper->getAbsoluteUrl('2fa_login');

      $email = (new TemplatedEmail())
        ->from("admin@dcinetwork.org")
        ->to($user->getEmailAddress())
        ->subject('Your LUMINA UI Authentication Code')
        ->htmlTemplate('@LuminaUi/user/email_auth_code.html.twig')
        ->context([
          'auth_code' => $authCode,
          'login_url' => $loginUrl,
          'user' => $user,
        ])
      ;

      $this->mailer->send($email);
    }
    catch (\Exception $e) {
      $this->logger->error('Error sending email auth code: ' . $e->getMessage());
    }
  }
}
