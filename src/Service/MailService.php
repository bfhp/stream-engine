<?php

namespace StreamEngine\Service;

use PHPMailer\PHPMailer\Exception;
use RuntimeException;
use StreamEngine\Core\Config;
use StreamEngine\Core\TranslationManager;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use PHPMailer\PHPMailer\PHPMailer;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class MailService
{
    private Environment $twig;
    private CssToInlineStyles $inliner;

    public function __construct(
        private readonly Config $config,
        private readonly SettingsService $settings,
        TranslationManager $translationManager,
        string $templatePath
    ) {
        $loader = new FilesystemLoader($templatePath);

        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addFunction(new TwigFunction('trans', [$translationManager, 'trans']));

        $this->inliner = new CssToInlineStyles();
    }

    /**
     * @param string $to
     * @param string $subject
     * @param string $template
     * @param array $context
     * @param string|null $unsubscribeUrl
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function send(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        ?string $unsubscribeUrl = null
    ): void {

        if ($unsubscribeUrl !== null) {
            $unsubscribeUrl = $this->absoluteUnsubscribeUrl($unsubscribeUrl);
        }

        $context['subject'] = $subject;
        $context['locale'] = $this->settings->getString('locale');
        $context['unsubscribeUrl'] = $unsubscribeUrl;

        $html = $this->twig->render($template . '.twig', $context);
        $html = $this->inliner->convert($html);
        $text = $this->generateText($html);

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $this->config->smtpHost();
        $mail->SMTPAuth = true;
        $mail->Username = $this->config->smtpUsername();
        $mail->Password = $this->config->smtpPassword();
        $mail->SMTPSecure = $this->config->smtpEncryption();
        $mail->Port = $this->config->smtpPort();
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($this->config->smtpFrom());
        $mail->addAddress($to);

        $mail->isHTML();
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        if ($unsubscribeUrl !== null) {
            $mail->addCustomHeader(
                'List-Unsubscribe',
                '<' . $unsubscribeUrl . '>'
            );

            $mail->addCustomHeader(
                'List-Unsubscribe-Post',
                'List-Unsubscribe=One-Click'
            );
        }

        $mail->send();
    }

    private function generateText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html)));
    }

    private function absoluteUnsubscribeUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $siteUrl = $this->config->siteUrl()
            ?? throw new RuntimeException('Canonical site URL is not configured');

        return $siteUrl.'/'.ltrim($url, '/');
    }
}
